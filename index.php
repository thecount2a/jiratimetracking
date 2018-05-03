<?php
require_once 'config.php';
require_once 'SupOAuthClient.php';
require_once 'RedLock.php';
require_once 'reportlib.php';
require_once 'AuthJiraCert.php';

register_shutdown_function( "fatal_handler" );

function fatal_handler() {
	if ($GLOBALS['redLock'])
	{
		$GLOBALS['redLock']->unlock($GLOBALS['lock']);
	}
}
$redLock = null;
$lock = null;

function updateIssueDatabase($redis, $jira, $cert, $issue = null, $fullRebuild = false)
{
	$servers = array(array('127.0.0.1', 6379, 0.01));
	// Max number of seconds we will wait before assuming that a previous update died and take over rebuilding the database
	$maxSeconds = 300;
	if ($fullRebuild)
	{
		// Assume a full rebuild could take half an hour... not likely but it's hard to tell how fast Jira servers will respond.
		$maxSeconds = 1800;
	}
	$GLOBALS['redLock'] = new RedLock($servers, 500, 2*($maxSeconds + 2));
	$GLOBALS['lock'] = $GLOBALS['redLock']->lock('my_resource_name', $maxSeconds * 1000);
	if ($GLOBALS['lock'])
	{
		try
		{
			$issueList = array();
			if ($issue === null)
			{
				$lastUpdate = 0;
				if (!$fullRebuild && $redis->exists("lastIssueUpdate"))
				{
					$lastUpdate = $redis->get("lastIssueUpdate");
				}
				$url = $cert->jiraBaseUrl . 'rest/api/2/serverInfo';
				$serverInfo = $jira->performRequest($url, "GET");
				$serverTime = DateTime::createFromFormat('Y-m-d\TH:i:s.uO', $serverInfo["serverTime"]);
				$serverTime->setTimezone(new DateTimeZone($GLOBALS['DEFAULT_TIMEZONE']));
				$serverTime->sub(new DateInterval("PT".(((int)$serverTime->format('s')) + 60). "S"));
				$serverTimeEarly = $serverTime->format("Y/m/d H:i");
				
				$queryDate = date("Y/m/d H:i", $lastUpdate);
				$url = $cert->jiraBaseUrl . 'rest/api/2/search';
				$startAt = 0;
				$issueCount = 0;
				$maxResults = 1000;
				while($startAt == 0 || count($issueList) < $issueCount)
				{
					$issues = $jira->performRequest($url, array("jql" => 'updated >= \''.$queryDate.'\' and updated < \''.$serverTimeEarly.'\' order by updated ASC', "maxResults" => $maxResults, "startAt" => $startAt), "GET");
					if ($issues["maxResults"] != $maxResults)
					{
						$maxResults = $issues["maxResults"];
					}
					if ($issues["total"] != $issueCount)
					{
						$issueCount = $issues["total"];
					}
					$startAt += $maxResults;

					//print_r($issues);
					for ($i = 0; $i < count($issues["issues"]); $i++)
					{
						$issueList[] = $issues["issues"][$i]["key"];
					}
					// Should never happen but just to prevent infinite loops
					if (empty($issues["issues"]))
					{
						break;
					}
				}
			}
			else
			{
				$issueList = array($issue);
			}
			if ($fullRebuild)
			{
				$redis->del('issue.wl.s.index');
				$redis->del('issue.wl.seen');
			}
			$userDailyTotals = array();
			for ($i = 0; $i < count($issueList); $i++)
			{
				$url = $cert->jiraBaseUrl . 'rest/api/2/issue/'. $issueList[$i];
				$issueInfo = $jira->performRequest($url, "GET");
				if ($issueInfo["key"] && $issueInfo["key"] == $issueList[$i])
				{
					$rebuildChildren = false;
					if ($redis->get('issue.'.$issueList[$i].'.summary') != $issueInfo["fields"]["summary"])
					{
						$rebuildChildren = true;
					}
					$redis->set('issue.'.$issueList[$i].'.summary', $issueInfo["fields"]["summary"]);
					if ($redis->get('issue.'.$issueList[$i].'.labels') != json_encode($issueInfo["fields"]["labels"]))
					{
						$rebuildChildren = true;
					}
					$redis->set('issue.'.$issueList[$i].'.labels', json_encode($issueInfo["fields"]["labels"]));
					if ($redis->get('issue.'.$issueList[$i].'.epic') != $issueInfo["fields"]["customfield_10006"])
					{
						$rebuildChildren = true;
					}
					$redis->set('issue.'.$issueList[$i].'.epic', $issueInfo["fields"]["customfield_10006"]);
					if (!$fullRebuild && $rebuildChildren)
					{
						$additionalIssues = array();
						$children = $redis->smembers('issue.'.$issueList[$i].'.children');
						for ($j = 0; $j < count($children); $j++)
						{
							$additionalIssues[] = $children[$j];
							$grandchildren = $redis->smembers('issue.'.$children[$j].'.children');
							for ($k = 0; $k < count($grandchildren); $k++)
							{
								$additionalIssues[] = $grandchildren[$k];
							}
						}
						$issueList = array_merge($issueList, $additionalIssues);
					}
					$url = $cert->jiraBaseUrl . 'rest/api/2/issue/'. $issueList[$i]. '/worklog';
					$workLog = $jira->performRequest($url, "GET");
					if ($redis->exists('issue.'.$issueList[$i].'.wl.count'))
					{
						$numwl = $redis->get('issue.'.$issueList[$i].'.wl.count');
						$delkeys = array();
						for ($j = 0; $j < $numwl; $j++)
						{
							$wlKey = 'issue.'.$issueList[$i].'.wl.'.$j;
							$redis->zRem('issue.wl.s.index', $wlKey);
							$delkeys[] = $wlKey;
						}
						if (count($delkeys) > 0)
						{
							$redis->del($delkeys);
						}
					}
					$accountName = $issueInfo["fields"]["summary"];
					$billingCodes = array();
					// Make copy because we plan to climb the tree and reassign the "issue"
					$issueInfoRecursive = $issueInfo;
					$billingCodes = $issueInfoRecursive["fields"]["labels"];
					if ($issueInfoRecursive["fields"]["parent"])
					{
						//$url = $cert->jiraBaseUrl . 'rest/api/2/issue/'. $issueInfoRecursive["fields"]["parent"]["key"];
						//$issueInfoRecursive = $jira->performRequest($url, "GET");
						$issueInfoRecursive = array("key" => $issueInfoRecursive["fields"]["parent"]["key"], "fields" => array( "summary" => $redis->get('issue.'.$issueInfoRecursive["fields"]["parent"]["key"].".summary"), "labels" => ($redis->get('issue.'.$issueInfoRecursive["fields"]["parent"]["key"].".labels") ? json_decode($redis->get('issue.'.$issueInfoRecursive["fields"]["parent"]["key"].".labels")) : array()), "customfield_10006" => $redis->get('issue.'.$issueInfoRecursive["fields"]["parent"]["key"].".epic")));
						$accountName = $issueInfoRecursive["fields"]["summary"] . ":" . $accountName;
						$billingCodes = array_merge($billingCodes, $issueInfoRecursive["fields"]["labels"]);
						$redis->sadd('issue.'.$issueInfoRecursive["key"].'.children', $issueList[$i]);
					}
					if ($issueInfoRecursive["fields"]["customfield_10006"])
					{
						//$url = $cert->jiraBaseUrl . 'rest/api/2/issue/'. $issueInfoRecursive["fields"]["customfield_10006"];
						//$issueInfoRecursive = $jira->performRequest($url, "GET");
						$issueInfoRecursive = array("key" => $issueInfoRecursive["fields"]["customfield_10006"], "fields" => array( "summary" => $redis->get('issue.'.$issueInfoRecursive["fields"]["customfield_10006"].".summary"), "labels" => ($redis->get('issue.'.$issueInfoRecursive["fields"]["customfield_10006"].".labels") ? json_decode($redis->get('issue.'.$issueInfoRecursive["fields"]["customfield_10006"].".labels")) : array()), "customfield_10006" => $redis->get('issue.'.$issueInfoRecursive["fields"]["customfield_10006"].".epic")));
						$accountName = $issueInfoRecursive["fields"]["summary"] . ":" . $accountName;
						$billingCodes = array_merge($billingCodes, $issueInfoRecursive["fields"]["labels"]);
						$redis->sadd('issue.'.$issueInfoRecursive["key"].'.children', $issueList[$i]);
					}
					// Look for billing codes in specific format LETTERS_numbers
					$billingCodeFound = FALSE;
					for ($k = 0; $k < count($billingCodes); $k++)
					{
						if (strpos($billingCodes[$k], "_") !== FALSE)
						{
							$parts = explode("_", $billingCodes[$k]);
							if (is_numeric($parts[1]))
							{
								$billingCodeFound = $billingCodes[$k];
								break;
							}
						}
					}
					for ($j = 0; $j < count($workLog["worklogs"]); $j++)
					{
						if ($billingCodeFound === FALSE)
						{
							$billingCodeFound = "UNKNOWN_0000";
						}
						$logTime = DateTime::createFromFormat('Y-m-d\TH:i:s.uO', $workLog["worklogs"][$j]["started"]);
						$logTime->setTimezone(new DateTimeZone($GLOBALS['DEFAULT_TIMEZONE']));
						$endLogTime = clone $logTime;
						$endLogTime->add(new DateInterval("PT".((int)$workLog["worklogs"][$j]["timeSpentSeconds"]). "S"));
						$timeclock = "i ".$logTime->format("Y/m/d H:i:s")." ".$accountName. "\no ".$endLogTime->format("Y/m/d H:i:s");
						$ledgerreturn = runHledger("-f - print", $timeclock, $ledger);
						$richerLedger = array();
						$transactions = explode("\n\n", $ledger);
						$dailyTotalKey = "dailytotal.".$logTime->format("Y.m.d").".".$workLog["worklogs"][$j]["author"]["key"];
						if ($fullRebuild || !$redis->sIsMember('issue.wl.seen', $issueList[$i].".".$logTime->format("U")))
						{
							if (array_key_exists($dailyTotalKey, $userDailyTotals))
							{
								$userDailyTotals[$dailyTotalKey] += $workLog["worklogs"][$j]["timeSpentSeconds"];
							}
							else
							{
								$userDailyTotals[$dailyTotalKey] = $workLog["worklogs"][$j]["timeSpentSeconds"];
							}
						}
						// Trim off the last, always empty transaction
						for ($k = 0; $k < count($transactions)-1; $k++)
						{
							$newLines = array();
							$lines = explode("\n", $transactions[$k]);
							$commentlines = explode("\n", $workLog["worklogs"][$j]["comment"]);
							$newLines[] = $lines[0]." ".$commentlines[0];
							$newLines[] = "    ; user:".$workLog["worklogs"][$j]["author"]["key"];
							$newLines[] = "    ; billing:".$billingCodeFound;
							$newLines = array_merge($newLines, array_slice($lines, 1));
							$richerLedger[] = implode("\n", $newLines);
						}
						
						$wlEntry = array(
							"a" => $workLog["worklogs"][$j]["author"]["key"],
							"s" => $logTime->format("U"),
							"d" => $workLog["worklogs"][$j]["timeSpentSeconds"],
							"c" => $workLog["worklogs"][$j]["comment"],
							"l" => implode("\n\n", $richerLedger)
						);
						
						$wlKey = 'issue.'.$issueList[$i].'.wl.'.$j;
						$redis->zAdd('issue.wl.s.index', $wlEntry["s"], $wlKey);
						$redis->sAdd('issue.wl.seen', $issueList[$i].".".$logTime->format("U"));
						$redis->set($wlKey, json_encode($wlEntry));
					}
					$redis->set('issue.'.$issueList[$i].'.wl.count', count($workLog["worklogs"]));
				}
			}
			foreach($userDailyTotals as $dtkey => $dtvalue)
			{
				if ($fullRebuild)
				{
					$redis->set($dtkey, $dtvalue);
				}
				else
				{
					$redis->incrBy($dtkey, $dtvalue);
				}
			}
			if ($issue === null)
			{
				$redis->set("lastIssueUpdate", $serverTime->format('U'));
			}
			$GLOBALS['redLock']->unlock($GLOBALS['lock']);
		}
		catch (Exception $e)
		{
			// Limit damage of exception
			$GLOBALS['redLock']->unlock($GLOBALS['lock']);
			throw $e;
		}
	}
	else
	{
		echo "Failed to get redis lock";
		exit;
	}
}

function getCurrentWorklog($myself, $redis, $worklog_date_string)
{
	$entries = array();
	$recentData = $redis->hGetAll($myself["key"].'_recentTaskMetadata');
	$recentTasks = array();
	if ($redis->exists($myself["key"].'_recentTasks'))
	{
		$recentTasks = json_decode($redis->get($myself["key"].'_recentTasks'));
	}

	$worklog_date = date_parse($worklog_date_string);
	$arrival_time = date_parse($recentData["arrival_time"]);
	$break_time = date_parse($recentData["break_time"]);
	$break_duration = date_parse($recentData["break_duration"]);
	$work_duration = date_parse($recentData["work_duration"]);
	$work_duration_seconds = $work_duration["hour"] * 60 * 60 + $work_duration["minute"] * 60;
	$break_duration_seconds = $break_duration["hour"] * 60 * 60 + $break_duration["minute"] * 60;
	$startTime = new DateTime($worklog_date["year"]."/".$worklog_date["month"]."/".$worklog_date["day"]."T".$arrival_time["hour"].":".$arrival_time["minute"].":00", new DateTimeZone($GLOBALS['DEFAULT_TIMEZONE']));
	$startTimeSeconds = $startTime->format("U");
	$breakTime = 0;
	if ($break_duration_seconds)
	{
		$breakTime = new DateTime($worklog_date["year"]."/".$worklog_date["month"]."/".$worklog_date["day"]."T".$break_time["hour"].":".$break_time["minute"].":00", new DateTimeZone($GLOBALS['DEFAULT_TIMEZONE']));
		$breakTime = $breakTime->format("U");
	}
	$recentTasksToLog = array();
	$weightSum = 0.0;
	foreach ($recentTasks as $task)
	{
		if (array_key_exists($task."-sel-check", $recentData) && $recentData[$task."-sel-check"])
		{
			$recentTasksToLog[] = $task;
			if (array_key_exists($task."-sel-weight", $recentData) && is_numeric($recentData[$task."-sel-weight"]))
			{
				$weightSum += (double) $recentData[$task."-sel-weight"];
			}
			else
			{
				$weightSum += 1.0;
			}
		}
	}
	$totalDuration = 0;
	foreach ($recentTasksToLog as $task)
	{
		$entry = array();
		$entry["task"] = $task;
		$entry["memo"] = $recentData[$task."-sel-memo"];
		$entry["startTime"] = $startTimeSeconds;
		// If we line up perfectly with the start of break, just start task after break
		if ($startTimeSeconds == $breakTime)
		{
			$breakentry = array();
			$breakentry["task"] = "BREAK";
			$breakentry["memo"] = "";
			$breakentry["startTime"] = $breakTime;
			$breakentry["duration"] = $break_duration_seconds;
			$entries[] = $breakentry;
			$startTimeSeconds = $breakTime + $break_duration_seconds;
			$entry["startTime"] = $startTimeSeconds;
			$breakTime = 0;
		}

		$weight = 1.0;
		if (array_key_exists($task."-sel-weight", $recentData) && is_numeric($recentData[$task."-sel-weight"]))
		{
			$weight = (double) $recentData[$task."-sel-weight"];
		}
		$endTime = $entry["startTime"] + ((int) (round($weight / $weightSum * $work_duration_seconds / 900.0) * 900));
		if ($breakTime && $endTime > $breakTime)
		{
			$entry["duration"] = $breakTime - $entry["startTime"];
			$totalDuration += $entry["duration"];
			$entries[] = $entry;

			$breakentry = array();
			$breakentry["task"] = "BREAK";
			$breakentry["memo"] = "";
			$breakentry["startTime"] = $breakTime;
			$breakentry["duration"] = $break_duration_seconds;
			$entries[] = $breakentry;

			$entry = array();
			$entry["task"] = $task;
			$entry["memo"] = $recentData[$task."-sel-memo"];
			$startTimeSeconds = $breakTime + $break_duration_seconds;
			$entry["startTime"] = $startTimeSeconds;
			$endTime = $entry["startTime"] + $endTime - $breakTime;
			$breakTime = 0;
		}
		$entry["duration"] = max(900, $endTime - $entry["startTime"]);
		$totalDuration += $entry["duration"];
		$entries[] = $entry;

		$startTimeSeconds = $startTimeSeconds + $entry["duration"];
	}

	$entryToTweak = count($entries) - 1;
	while($totalDuration != $work_duration_seconds && $entryToTweak >= 0)
	{
		if ($entries[$entryToTweak]["task"] != "BREAK")
		{
			$adjustment = max($entries[$entryToTweak]["duration"] + $work_duration_seconds - $totalDuration, 900);
			$adjustmentAmount = $adjustment - $entries[$entryToTweak]["duration"];
			$entries[$entryToTweak]["duration"] = $adjustment;
			for($i = $entryToTweak + 1; $i < count($entries); $i++)
			{
				$entries[$i]["startTime"] += $adjustmentAmount;
			}
		}
		$totalDuration = 0;
		$entryToTweak--;
		foreach ($entries as $entry)
		{
			if ($entry["task"] != "BREAK")
			{
				$totalDuration += $entry["duration"];
			}
		}
	}

	return $entries;
}

if (isset($_GET["logout"]) && $_GET["logout"] == "true")
{
	setcookie($COOKIE_PREFIX."_jira_oauth_token", "", time() - 3600);
	setcookie($COOKIE_PREFIX."_jira_oauth_secret", "", time() - 3600);
	setcookie($COOKIE_PREFIX."_jira_oauth_token", "", time() - 3600, '/');
	setcookie($COOKIE_PREFIX."_jira_oauth_secret", "", time() - 3600, '/');
	echo "Logged out successfully.  Thank you!";
}
else if(!isset($_COOKIE[$COOKIE_PREFIX."_jira_oauth_token"]) || !isset($_COOKIE[$COOKIE_PREFIX."_jira_oauth_secret"]))
{
	$obj = new AuthJiraCert();
	$obj->run();
}
else
{
	// Get connection to redis server
	require "predis/autoload.php";

	$redis = new Predis\Client();

	$obj = new AuthJiraCert();
	$client = new SupOAuthClient($obj->consumerKey, $obj->privateKeyFile, $_COOKIE[$COOKIE_PREFIX."_jira_oauth_token"], $_COOKIE[$COOKIE_PREFIX."_jira_oauth_secret"]);
	$url = $obj->jiraBaseUrl . 'rest/api/2/myself';
	$myself = $client->performRequest($url, array("expand"=>"groups"), "GET");
	$authorizedReporter = false;
	for ($i = 0; $i < count($myself["groups"]["items"]); $i++)
	{
		if ($myself["groups"]["items"][$i]["name"] == "timereporting")
		{
			$authorizedReporter = true;
		}
	}

	$currentTask = "";
	if ($redis->exists($myself["key"].'_currentTask'))
	{
		$currentTask = $redis->get($myself["key"].'_currentTask');
	}

	$rebuild = false;
	if ($_POST['rebuild'] && $authorizedReporter)
	{
		$rebuild = true;
	}
	$rebuildStartTime = new DateTime("now", new DateTimeZone($DEFAULT_TIMEZONE));
	updateIssueDatabase($redis, $client, $obj, null, $rebuild);
	$rebuildEndTime = new DateTime("now", new DateTimeZone($DEFAULT_TIMEZONE));
	if ($rebuild)
	{
		$duration = $rebuildEndTime->format("U") - $rebuildStartTime->format("U");
		$redis->set('lastRebuildDuration', $duration);
		$redis->set('lastRebuildTime', $rebuildStartTime->format('Y/m/d H:i'));
		header('Location: https://'.$HOSTED_DOMAIN.$_SERVER['REQUEST_URI']);
	}
	else
	{
		echo "<html><head><title>".$WEBSITE_TITLE."</title>";
		if ($currentTask)
		{
			echo "<meta http-equiv=\"refresh\" content=\"300;URL='".$_SERVER['REQUEST_URI']."'\">";
		}
		echo "<style>";
		echo "table.niceborder { border-collapse: collapse; }";
		echo "table.niceborder,th.niceborder,td.niceborder { border: 1px solid black; }";
		echo "form{ display:inline; margin:0px; padding:0px;}";
		echo "#buttonpair { overflow: hidden; }";
		echo "#buttonpair input { float:right }";
		echo "</style>";
		?>
<script>
var lastSaved = {};
function refreshSelection()
{
	var allInputs = document.getElementsByTagName("input");
	var somethingChecked = false;
	for (var i = 0; i < allInputs.length; i++)
	{
		if (allInputs[i].type == 'checkbox')
		{
			if(allInputs[i].checked)
			{
				document.getElementById(allInputs[i].getAttribute("name") + "-edit").style.display = "inline";
				somethingChecked = true;
			}
			else
			{
				document.getElementById(allInputs[i].getAttribute("name") + "-edit").style.display = "none";
			}
		}
	}
	if (document.getElementById("submitMultiple"))
	{
		if (somethingChecked)
		{
			document.getElementById("submitMultiple").style.display = "inline";
		}
		else
		{
			document.getElementById("submitMultiple").style.display = "none";
		}
	}
	saveRecent();
}
var saveTimeout = null;
var dataToSave = null;

function actuallySave()
{
	console.log(dataToSave);
	var xmlhttp = new XMLHttpRequest();   // new HttpRequest instance 
	xmlhttp.open("POST", "recent.php");
	xmlhttp.setRequestHeader("Content-Type", "application/json;charset=UTF-8");
	xmlhttp.send(JSON.stringify(dataToSave));
	dataToSave = {};
}

function saveRecent()
{
	if (saveTimeout)
	{
		window.clearTimeout(saveTimeout);
		saveTimeout = null;
		dataToSave = null;
	}

	var allInputs = document.getElementsByTagName("input");
	var saveData = {};
	for (var i = 0; i < allInputs.length; i++)
	{
		if (allInputs[i].type == 'checkbox')
		{
			if (allInputs[i].checked)
			{
				saveData[allInputs[i].getAttribute("name") + "-check"] = "checked";
			}
			else
			{
				saveData[allInputs[i].getAttribute("name") + "-check"] = "";
			}
			if (document.getElementById(allInputs[i].getAttribute("name") + "-memo"))
			{
				saveData[allInputs[i].getAttribute("name") + "-memo"] = document.getElementById(allInputs[i].getAttribute("name") + "-memo").value;
			}
			if (document.getElementById(allInputs[i].getAttribute("name") + "-weight"))
			{
				saveData[allInputs[i].getAttribute("name") + "-weight"] = document.getElementById(allInputs[i].getAttribute("name") + "-weight").value;
			}
		}
	}
	if (document.getElementById("current_memo"))
	{
		saveData["current_offset"] = document.getElementById("current_offset").value;
		saveData["current_memo"] = document.getElementById("current_memo").value;
	}
	if (document.getElementById("arrival_time"))
	{
		if (document.getElementById("todays_date").value != document.getElementById("worklog_date").value)
		{
			saveData["worklog_date"] = document.getElementById("worklog_date").value;
		}
		else
		{
			saveData["worklog_date"] = "today";
		}
		saveData["arrival_time"] = document.getElementById("arrival_time").value;
		saveData["break_time"] = document.getElementById("break_time").value;
		saveData["break_duration"] = document.getElementById("break_duration").value;
		saveData["work_duration"] = document.getElementById("work_duration").value;
	}

	dataToSave = saveData;
	saveTimeout = window.setTimeout(actuallySave, 1000);
}

window.onload = function() {
	var req = new XMLHttpRequest();
	req.overrideMimeType("application/json");
	req.open('GET', 'recent.php', true);
	req.onload  = function() {
		var dataToLoad = JSON.parse(req.responseText);
		for (var prop in dataToLoad)
		{
			if (document.getElementById(prop))
			{
				if (prop.indexOf('-check') > 0)
				{
					if (dataToLoad[prop])
					{
						document.getElementById(prop).checked = true;
					}
					else
					{
						document.getElementById(prop).checked = false;
					}
				}
				else if (prop == "worklog_date")
				{
					if (dataToLoad[prop] == "today")
					{
						document.getElementById(prop).value = document.getElementById("todays_date").value;
					}
					else
					{
						document.getElementById(prop).value = dataToLoad[prop];
					}
				}
				else
				{
					document.getElementById(prop).value = dataToLoad[prop];
				}
			}
		}
		refreshSelection();
	};
	req.send(null);
}
</script>
		<?php
		echo $EXTRA_HEAD_HTML;
		echo "</head><body>";
		if ($authorizedReporter)
		{
			echo "<form style=\"display: inline;\" action=\"index.php\" method=\"POST\" id=\"rebuildForm\"><input type=\"hidden\" name=\"rebuild\" value=\"true\" /></form>";
		}
		echo "<p align=\"right\">";
		if (!$redis->exists($myself["key"].'_projects'))
		{
			$url = $obj->jiraBaseUrl . 'rest/api/2/project';
			$projectListJson = json_encode($client->performRequest($url, "GET"));
			$projectList = json_decode($projectListJson);
			$redis->set($myself["key"].'_projects', $projectListJson);
			$redis->expire($myself["key"].'_projects', 600);
		}
		else
		{
			$projectList = json_decode($redis->get($myself["key"].'_projects'));
		}
		echo "<b>Projects:</b> ";
		for ($i = 0; $i < count($projectList); $i++)
		{
			if ($projectList[$i]->key != "TEST")
			{
				echo "<a href=\"https://".$JIRA_DOMAIN."/projects/".$projectList[$i]->key."/issues\">".$projectList[$i]->key."</a> &nbsp;&nbsp;";
			}
		}

		echo "<b> &ndash; &nbsp;&nbsp;".$myself["displayName"]." &nbsp;&nbsp;&ndash;&nbsp;&nbsp; </b>";

		$lastRebuildDuration = "N/A";
		$lastRebuildTime = "never";
		if ($redis->exists('lastRebuildDuration'))
		{
			$lastRebuildDuration = $redis->get('lastRebuildDuration');
		}
		if ($redis->exists('lastRebuildTime'))
		{
			$lastRebuildTime = $redis->get('lastRebuildTime');
		}
		if ($authorizedReporter)
		{
			echo "<a href=\"report.php\">Reports</a> &nbsp;&nbsp;&ndash;&nbsp;&nbsp;<a href=\"javascript:{}\" onclick=\"if (confirm('Do you really want rebuild the redis cache?  This can take a long time and the system will be unusable during the rebuild process.')) {document.getElementById('rebuildForm').submit();}\" title=\"Last rebuild took ".$lastRebuildDuration." seconds on ".$lastRebuildTime."\">Rebuild Cache</a>&nbsp;&nbsp;&ndash;&nbsp;&nbsp;";
		}
		echo "<a href=\"index.php?logout=true\">Logout</a><br/><br/></p>";

		echo $EXTRA_BODY_HTML;
		
		$offset = 0;
		// set recent task
		if ($_POST['offset'])
		{
			$offset = (int) $_POST['offset'] * 60;
		}
		# See if there is a recent task stored
		$recentTasks = array();
		if ($redis->exists($myself["key"].'_recentTasks'))
		{
			$recentTasks = json_decode($redis->get($myself["key"].'_recentTasks'));
		}
		$newTask = "";
		if ($_SERVER['HTTP_REFERER'] && strpos($_SERVER['HTTP_REFERER'], "https://".$JIRA_DOMAIN."/browse/") === 0)
		{
			$parts = explode("/", $_SERVER['HTTP_REFERER']);
			$removeQuestionMark = explode("?", $parts[count($parts)-1]);
			$newTask = $removeQuestionMark[0];
		}
		if ($_SERVER['HTTP_REFERER'] && strpos($_SERVER['HTTP_REFERER'], "https://".$JIRA_DOMAIN."/secure/RapidBoard.jspa") === 0 && strpos($_SERVER['HTTP_REFERER'], 'selectedIssue=') !== FALSE)
		{
			$parts = explode("=", $_SERVER['HTTP_REFERER']);
			$newTask = $parts[count($parts)-1];
		}
		if ($_SERVER['HTTP_REFERER'] && strpos($_SERVER['HTTP_REFERER'], "https://".$JIRA_DOMAIN."/projects/") === 0 && strpos($_SERVER['HTTP_REFERER'], '/issues/') !== FALSE)
		{
			$parts = explode("/", $_SERVER['HTTP_REFERER']);
			$removeQuestionMark = explode("?", $parts[count($parts)-1]);
			$newTask = $removeQuestionMark[0];
		}
		if ($_POST["task"] && $redis->exists('issue.'.$_POST["task"].'.summary'))
		{
			$newTask = $_POST["task"];
		}
		if ($newTask)
		{
			$idx = array_search($newTask, $recentTasks);
			if ($idx !== FALSE)
			{
				array_splice($recentTasks, $idx, 1);
			}
			$recentTasks[] = $newTask;
			$newRecentTasks = array();
			for ($i = count($recentTasks)-1 ; $i >= 0; $i--)
			{
				if ($redis->hExists($myself["key"].'_recentTaskMetadata', $recentTasks[$i]."-sel-check"))
				{
					array_unshift($newRecentTasks, $recentTasks[$i]);
				}
				else if (count($newRecentTasks) < 10)
				{
					array_unshift($newRecentTasks, $recentTasks[$i]);
				}
			}

			$redis->set($myself["key"].'_recentTasks', json_encode($newRecentTasks));
		}
		if ($currentTask)
		{
			if ($_POST['action'] == "Cancel Task")
			{
				$redis->set($myself["key"].'_currentTask', "");
				$redis->del($myself["key"].'_recentTaskMetadata');
				header('Location: https://'.$HOSTED_DOMAIN.$_SERVER['REQUEST_URI']);
			}
			else if ($_POST['action'] == "Stop Task and Log Time")
			{
				$redis->set($myself["key"].'_currentTask', "");
				$startTime = (int) $redis->get($myself["key"].'_currentTaskStartTime');

				$endTime = time() - $offset;

				$url = $obj->jiraBaseUrl . 'rest/api/2/issue/'.$currentTask.'/worklog';
				$timeStarted = date("Y-m-d\TH:i:s.000O", $startTime);
				$res = $client->performRequest($url, json_encode(array("comment"=>$_POST["memo"], "started"=>$timeStarted, "timeSpentSeconds"=> (string)($endTime - $startTime))), "POST");
				updateIssueDatabase($redis, $client, $obj, $currentTask);
				$redis->del($myself["key"].'_recentTaskMetadata');

				header('Location: https://'.$HOSTED_DOMAIN.$_SERVER['REQUEST_URI']);
			}
			else if ($_POST['action'] == "Stop Task and Log Rounded Time")
			{
				$redis->set($myself["key"].'_currentTask', "");
				$startTime = (int) $redis->get($myself["key"].'_currentTaskStartTime');

				$endTime = $startTime + ((int) $_POST['roundedseconds']) - $offset;

				$url = $obj->jiraBaseUrl . 'rest/api/2/issue/'.$currentTask.'/worklog';
				$timeStarted = date("Y-m-d\TH:i:s.000O", $startTime);
				$res = $client->performRequest($url, json_encode(array("comment"=>$_POST["memo"], "started"=>$timeStarted, "timeSpentSeconds"=> (string)($endTime - $startTime))), "POST");
				updateIssueDatabase($redis, $client, $obj, $currentTask);
				$redis->del($myself["key"].'_recentTaskMetadata');

				header('Location: https://'.$HOSTED_DOMAIN.$_SERVER['REQUEST_URI']);
			}
		}
		else
		{
			if ($_POST['action'] == "Start Task")
			{
				if ($newTask)
				{
					$redis->set($myself["key"].'_currentTask', $newTask);
					$redis->set($myself["key"].'_currentTaskStartTime', time() - $offset);
					header('Location: https://'.$HOSTED_DOMAIN.$_SERVER['REQUEST_URI']);
				}
				else
				{
					echo "<h3>Error: No valid task was provided</h3>";
				}
			}
			else if ($_POST['action'] == "Cancel")
			{
				header('Location: https://'.$HOSTED_DOMAIN.$_SERVER['REQUEST_URI']);
			}
			else if ($_POST['action'] == "Save Worklog")
			{
				if ($_POST['confirm_early_submit'] == "on" && $_POST['confirm_date'] == "on")
				{
					$worklog = getCurrentWorklog($myself, $redis, $_POST['worklog_date']);
					foreach($worklog as $entry)
					{
						if ($entry["task"] != "BREAK")
						{
							$url = $obj->jiraBaseUrl . 'rest/api/2/issue/'.$entry["task"].'/worklog';
							$timeStarted = date("Y-m-d\TH:i:s.000O", $entry["startTime"]);
							$res = $client->performRequest($url, json_encode(array("comment"=>$entry["memo"], "started"=>$timeStarted, "timeSpentSeconds"=> (string)($entry["duration"]))), "POST");
							updateIssueDatabase($redis, $client, $obj, $entry["task"]);
						}
					}
					$redis->del($myself["key"].'_recentTaskMetadata');
				}

				header('Location: https://'.$HOSTED_DOMAIN.$_SERVER['REQUEST_URI']);
			}
			else if ($_POST['action'] == "Submit Worklog")
			{
				echo "<form action=\"index.php\" method=\"POST\"><h3>Please confirm your worklog for ".$_POST['worklog_date'].": </h3>";
				$currentTime = new DateTime("now", new DateTimeZone($DEFAULT_TIMEZONE));
				$recentData = $redis->hGetAll($myself["key"].'_recentTaskMetadata');
				$worklog_date = date_parse($_POST['worklog_date']);
				$arrival_time = date_parse($recentData["arrival_time"]);
				$break_time = date_parse($recentData["break_time"]);
				$break_duration = date_parse($recentData["break_duration"]);
				$work_duration = date_parse($recentData["work_duration"]);
				$work_duration_seconds = $work_duration["hour"] * 60 * 60 + $work_duration["minute"] * 60;
				$errors = false;
				if (gettype($worklog_date["year"]) != "integer" || gettype($worklog_date["month"]) != "integer" || gettype($worklog_date["day"]) != "integer" || $worklog_date["warning_count"] > 0 || $worklog_date["error_count"] > 0)
				{
					echo "Improperly formatted date for worklog date.<br><br>";
					foreach($worklog_date["warnings"] as $warning)
					{
						echo $warning."<br>";
					}
					foreach($worklog_date["warnings"] as $error)
					{
						echo $error."<br>";
					}
					$errors = true;
				}
				if (gettype($arrival_time["hour"]) != "integer" || gettype($arrival_time["minute"]) != "integer" || $arrival_time["warning_count"] > 0 || $arrival_time["error_count"] > 0)
				{
					echo "Improperly formatted time for arrival time.<br><br>";
					foreach($arrival_time["warnings"] as $warning)
					{
						echo $warning."<br>";
					}
					foreach($arrival_time["warnings"] as $error)
					{
						echo $error."<br>";
					}
					$errors = true;
				}
				if (gettype($break_time["hour"]) != "integer" || gettype($break_time["minute"]) != "integer" || $break_time["warning_count"] > 0 || $break_time["error_count"] > 0)
				{
					echo "Improperly formatted time for break time.<br><br>";
					foreach($break_time["warnings"] as $warning)
					{
						echo $warning."<br>";
					}
					foreach($break_time["warnings"] as $error)
					{
						echo $error."<br>";
					}
					$errors = true;
				}
				if (gettype($break_duration["hour"]) != "integer" || gettype($break_duration["minute"]) != "integer" || $break_duration["warning_count"] > 0 || $break_duration["error_count"] > 0)
				{
					echo "Improperly formatted time for break duration.<br><br>";
					foreach($break_duration["warnings"] as $warning)
					{
						echo $warning."<br>";
					}
					foreach($break_duration["warnings"] as $error)
					{
						echo $error."<br>";
					}
					$errors = true;
				}
				if (gettype($work_duration["hour"]) != "integer" || gettype($work_duration["minute"]) != "integer" || $work_duration["warning_count"] > 0 || $work_duration["error_count"] > 0)
				{
					echo "Improperly formatted time for work duration.<br><br>";
					foreach($work_duration["warnings"] as $warning)
					{
						echo $warning."<br>";
					}
					foreach($work_duration["warnings"] as $error)
					{
						echo $error."<br>";
					}
					$errors = true;
				}
				if (!$errors)
				{
					$savedisabled = false;
					if ($_POST['worklog_date'] == $currentTime->format("Y/m/d"))
					{
						echo "<input type=\"hidden\" name=\"confirm_date\" value=\"on\"/>";
					}
					else
					{
						$savedisabled = true;
						echo "You have submitted a worklog for a date that is not today.  Did you intend to do this? Please select this checkbox to confirm: <input type=\"checkbox\" name=\"confirm_date\" onchange=\"document.getElementById('save_worklog_button').disabled = !this.checked;\"/><br><br>";
					}
					$worklog = getCurrentWorklog($myself, $redis, $_POST['worklog_date']);
					if ($worklog[count($worklog)-1]["startTime"] + $worklog[count($worklog)-1]["duration"] > time() + 20*60)
					{
						$savedisabled = true;
						echo "You have submitted a worklog for a future end time.  Did you intend to do this? Please select this checkbox to confirm: <input type=\"checkbox\" name=\"confirm_early_submit\" onchange=\"document.getElementById('save_worklog_button').disabled = !this.checked;\"/><br><br>";
					}
					else
					{
						echo "<input type=\"hidden\" name=\"confirm_early_submit\" value=\"on\"/>";
					}
					echo "<input type=\"hidden\" name=\"worklog_date\" value=\"".htmlentities($_POST['worklog_date'])."\"/>";
					echo "<table width=\"1000\" class=\"niceborder\" cellpadding=\"8\" border=\"1\">";
					echo "<tr><th width=\"15%\">Begin Time</th><th width=\"15%\">End Time</th><th>Task</th><th>Memo</th></tr>";
					$totalDuration = 0;
					foreach($worklog as $worklogentry)
					{
						echo "<tr><td>".date("Y/m/d H:i", $worklogentry["startTime"])."</td><td>".date("Y/m/d H:i", $worklogentry["startTime"]+$worklogentry["duration"])."</td><td>".htmlentities($worklogentry["task"])."</td><td>".htmlentities($worklogentry["memo"])."</td></tr>";
						if ($worklogentry["task"] != "BREAK")
						{
							$totalDuration += $worklogentry["duration"];
						}
					}
					echo "</table><br><b>Work Duration: ".sprintf('%02d:%02d:%02d', ($totalDuration/3600),($totalDuration/60%60), $totalDuration%60)."</b><br><br>";
					if ($totalDuration != $work_duration_seconds)
					{
						echo "The computed work duration does not match your stated work duration.  Please go adjust your task weights to allow the duration to be computed properly.";
						$errors = true;
					}
				}
				echo "<input type=\"submit\" name=\"action\" value=\"Cancel\">";
				if (!$errors)
				{
					echo "&nbsp;&nbsp;&nbsp;&nbsp;<input type=\"submit\" name=\"action\" id=\"save_worklog_button\" value=\"Save Worklog\"".($savedisabled?" disabled=\"1\"":"")."></form>";
				}
				exit(0);
			}
		}
		// Now get current task again (it may have changed)
		$currentTask = "";
		if ($redis->exists($myself["key"].'_currentTask'))
		{
			$currentTask = $redis->get($myself["key"].'_currentTask');
		}

		if ($currentTask)
		{
			$currentTaskStartTime = (int) $redis->get($myself["key"].'_currentTaskStartTime');

			$workTime = time() - $currentTaskStartTime;
			$dailyTotal = (int) $redis->get("dailytotal.".date("Y.m.d", $currentTaskStartTime).".".$myself["key"]);
			$rounded = max(0, (round(($dailyTotal + $workTime) / 1800.0) * 1800) - $dailyTotal);
			echo "<table width=\"100%\" class=\"niceborder\" cellpadding=\"8\" border=\"1\">";
			echo "<tr><td align=\"right\" width=\"20%\">Current task:</td><td><a href=\"https://".$JIRA_DOMAIN."/browse/".$currentTask."\">".$currentTask."</a></td></tr><tr><td align=\"right\">Started at:</td><td>".date("Y-m-d H:i:s", $currentTaskStartTime)."</td></tr><tr><td align=\"right\">Elapsed time:</td><td><b>".sprintf('%02d:%02d:%02d', ($workTime/3600),($workTime/60%60), $workTime%60)."</b></td></tr><tr><td align=\"right\">Rounded time:</td><td><b>".sprintf('%02d:%02d:%02d', ($rounded/3600),($rounded/60%60), $rounded%60)." for a total of ".sprintf('%02d:%02d:%02d', (($rounded+$dailyTotal)/3600),(($rounded+$dailyTotal)/60%60), ($rounded+$dailyTotal)%60)." worked today</b></td></tr><tr><td align=\"right\">Task summary:</td><td>".htmlentities($redis->get('issue.'.$currentTask.'.summary')). "</td></tr>";
			echo "<tr><td>&nbsp;</td><td><form action=\"index.php\" method=\"POST\" onsubmit=\"if(this.act == 'cancel') {return confirm('Do you really want to cancel your current time entry?') };if(this.act == 'loground') {return confirm('Are you sure you want to log rounded time?');}\"><input type=\"hidden\" name=\"roundedseconds\" value=\"".$rounded."\">Stop: <input type=\"text\" name=\"offset\" size=\"6\" id=\"current_offset\" onKeyUp=\"saveRecent();\"> minutes ago.<br/>Memo: <input type=\"text\" name=\"memo\" id=\"current_memo\" onKeyUp=\"saveRecent();\" size=\"70\"><br/><input type=\"hidden\" name=\"task\" value=\"".$currentTask."\"><div style=\"width: 500px\"><span id=\"buttonpair\"><input type=\"submit\" name=\"action\" onclick=\"this.form.act='loground';\" style=\"margin-left:10px; margin-right:10px;\" value=\"Stop Task and Log Rounded Time\"><input type=\"submit\" name=\"action\" onclick=\"this.form.act='log';\" value=\"Stop Task and Log Time\"><input type=\"submit\" name=\"action\" value=\"Cancel Task\" onclick=\"this.form.act='cancel';\"></span></div></form></td></tr>";
			echo "</table>";
		}
		if (!$currentTask)
		{
			echo "<h3>Recently visited tasks: </h3><table width=\"100%\" class=\"niceborder\" cellpadding=\"8\" border=\"1\">";
			for ($i = 0; $i < count($recentTasks); $i++)
			{
				echo "<tr>";
				echo "<td width=\"15%\" align=\"right\"><a href=\"https://".$JIRA_DOMAIN."/browse/".$recentTasks[$i]."\">".$recentTasks[$i]."</a></td>";
				echo "<td><input type=\"checkbox\" name=\"".$recentTasks[$i]."-sel\" onClick=\"refreshSelection();\" id=\"".$recentTasks[$i]."-sel-check\">&nbsp;&nbsp;".htmlentities($redis->get('issue.'.$recentTasks[$i].'.summary'))."<span id=\"".$recentTasks[$i]."-sel-edit\" style=\"display: none;\"><br><br><div style=\"margin-left: 30px\">Memo: <input onKeyUp=\"saveRecent();\" type=\"text\" id=\"".$recentTasks[$i]."-sel-memo\" size=\"80\">&nbsp;&nbsp;&nbsp;&nbsp;Time Weight: <input onKeyUp=\"saveRecent();\" type=\"text\" id=\"".$recentTasks[$i]."-sel-weight\" size=\"8\" value=\"1.0\"></div></span></td>";
				echo "<td align=\"right\" valign=\"center\" width=\"30%\"><form action=\"index.php\" method=\"POST\">Start <input type=\"text\" name=\"offset\" size=\"6\"> minutes ago. <input type=\"submit\" name=\"action\" value=\"Start Task\"><input type=\"hidden\" name=\"task\" value=\"".$recentTasks[$i]."\"></form></td>";
				echo "</tr>";
			}
			if (!$recentTasks)
			{
				echo "<tr><td colspan=\"3\">You have not recently visited any tasks.  Please log into Jira and select the time tracking link in the Jira ticket.</td></tr>";
			}
			echo "<tr><td colspan=\"3\" align=\"right\"><form action=\"index.php\" method=\"POST\">Manual task entry: <input type=\"text\" name=\"task\" size=\"15\"> Start <input type=\"text\" name=\"offset\" size=\"6\"> minutes ago. <input type=\"submit\" name=\"action\" value=\"Start Task\"></form></td></tr>";
			echo "</table>";
			echo "<div id=\"submitMultiple\" style=\"display: none\"><h3>Multiple Worklog Submission: </h3><form action=\"index.php\" method=\"POST\"><table width=\"350px\" class=\"niceborder\" cellpadding=\"8\" border=\"1\">";
			$currentTime = new DateTime("now", new DateTimeZone($DEFAULT_TIMEZONE));
			echo "<tr><td align=\"right\">Date:</td><td><input type=\"hidden\" id=\"todays_date\" name=\"todays_date\" value=\"".$currentTime->format("Y/m/d")."\"><input type=\"text\" id=\"worklog_date\" name=\"worklog_date\" value=\"".$currentTime->format("Y/m/d")."\" onKeyUp=\"saveRecent();\"></td></tr>";
			echo "";
			echo "<tr><td align=\"right\">Arrival Time:</td><td><input type=\"text\" id=\"arrival_time\" value=\"09:00\" onKeyUp=\"saveRecent();\"></td></tr>";
			echo "<tr><td align=\"right\">Break Time:</td><td><input type=\"text\" id=\"break_time\" value=\"12:00\" onKeyUp=\"saveRecent();\"></td></tr>";
			echo "<tr><td align=\"right\">Break Duration:</td><td><input type=\"text\" id=\"break_duration\" value=\"00:30\" onKeyUp=\"saveRecent();\"></td></tr>";
			echo "<tr><td align=\"right\">Work Duration:</td><td><input type=\"text\" id=\"work_duration\" value=\"08:00\" onKeyUp=\"saveRecent();\"></td></tr>";
			echo "<tr><td>&nbsp;</td><td><input type=\"submit\" name=\"action\" id=\"multiple_worklog_submit\" value=\"Submit Worklog\" onClick=\"submitWorklog();\"></td></tr>";
			
			echo "</table></form></div>";
			
		}
		echo "<h3>Current Report:</h3><form action=\"".$_SERVER['REQUEST_URI']."\" method=\"GET\"><select name=\"report\" onChange=\"this.form.submit();\">";
		echo "<option value=\"week\"".($_GET["report"] == "week"?" selected=\"selected\"":"").">My Weekly Summary</option>";
		echo "<option value=\"month\"".($_GET["report"] == "month"?" selected=\"selected\"":"").">My Monthly Summary</option>";
		echo "<option value=\"lastweek\"".($_GET["report"] == "lastweek"?" selected=\"selected\"":"").">My Summary for Last Week</option>";
		echo "<option value=\"lastmonth\"".($_GET["report"] == "lastmonth"?" selected=\"selected\"":"").">My Summary for Last Month</option>";
		echo "</select></form>";
		// Generate timecard
		$endTime = new DateTime("now", new DateTimeZone($DEFAULT_TIMEZONE));
		$startTime = new DateTime("now", new DateTimeZone($DEFAULT_TIMEZONE));
		$period = "week";
		$which = "this";
		if ($_GET["report"] == "month")
		{
			$period = "month";
			$startTime->modify('first day of this month')->setTime(0,0,0);
			$endTime->modify('last day of this month')->setTime(23,59,59);
		}
		else if ($_GET["report"] == "lastmonth")
		{
			$period = "month";
			$which = "last";
			$startTime = new DateTime("now", new DateTimeZone($DEFAULT_TIMEZONE));
			$startTime->modify('first day of last month')->setTime(0,0,0);
			$endTime = new DateTime("now", new DateTimeZone($DEFAULT_TIMEZONE));
			$endTime->modify('first day of this month')->setTime(0,0,0);
		}
		else if ($_GET["report"] == "lastweek")
		{
			$which = "last";
			$startTime = new DateTime("now", new DateTimeZone($DEFAULT_TIMEZONE));
			$startTime->modify('Last Sunday')->setTime(0,0,0);
			$startTime->modify('Last Sunday')->setTime(0,0,0);
			$startTime->modify('+1 day')->setTime(0,0,0);
			$endTime = new DateTime("now", new DateTimeZone($DEFAULT_TIMEZONE));
			$endTime->modify('Last Sunday')->setTime(0,0,0);
			$endTime->modify('+1 day')->setTime(0,0,0);
		}
		else
		{
			$startTime->modify('Last Sunday')->setTime(0,0,0);
			$startTime->modify('+1 day')->setTime(0,0,0);
			$endTime->modify('Next Sunday')->setTime(0,0,0);
			$endTime->modify('+1 day')->setTime(0,0,0);
		}
		$items = $redis->zRangeByScore('issue.wl.s.index', (int) $startTime->format('U'), (int) $endTime->format('U'));
		$itemObjs = array();
		if (count($items) > 0)
		{
			$itemObjs = $redis->mGet($items);
		}
		$ledgerStr = "";
		$issueReference = array();
		$dataReference = array();
		for ($i = 0; $i < count($itemObjs); $i++)
		{
			$obj = json_decode($itemObjs[$i]);
			if ($obj->a == $myself["key"])
			{
				$ledgerStr = preg_replace("/billing:[A-Z]*_/", "billing:", $ledgerStr.$obj->l)."\n";

				$wlkey = $items[$i];
				$parts = explode(".", $wlkey);
				// There can be multiple transactions if the worklog entry spans multiple days
				for ($j = 0; $j < (substr_count($ledgerStr, "\n\n")+1); $j++)
				{
					$issueReference[] = $parts[1];
					$dataReference[] = $obj;
				}
			}
		}
		if ($period == "week")
		{
			$ledgerreturn = runHledger("-f - bal -D -O csv -T", $ledgerStr, $ledgerCsv);
		}
		else
		{
			$ledgerreturn = runHledger("-f - bal -W -O csv -T", $ledgerStr, $ledgerCsv);
		}
		$result = generateLedgerTable($ledgerCsv);
		echo "<h3>Summary for ".$which." ".$period.":</h3>".$result->output;

		$ledgerreturn = runHledger("-f - bal --pivot billing -D -T -O csv", $ledgerStr, $ledgerCsv);
		$result = generateLedgerTable($ledgerCsv, true);
		echo "<h3>Billing for ".$which." ".$period.":</h3>".$result->output;

		$ledgerreturn = runHledger("-f - reg -O csv", $ledgerStr, $ledgerCsv);
		$result = generateLedgerTable($ledgerCsv, false, $issueReference, $dataReference);
		echo "<h3>Individual timecard entries ".$which." ".$period.":</h3>".$result->output;
	}
}
