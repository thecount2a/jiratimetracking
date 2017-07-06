<?php
require_once 'config.php';
require_once 'SupOAuthClient.php';
require_once 'RedLock.php';
require_once 'reportlib.php';
require_once 'AuthJiraCert.php';

function updateIssueDatabase($redis, $jira, $cert, $issue = null)
{
	$servers = array(array('127.0.0.1', 6379, 0.01));
	// Max number of seconds we will wait before assuming that a previous update died and take over rebuilding the database
	$maxSeconds = 120;
	$redLock = new RedLock($servers, 500, 2*($maxSeconds + 2));
	$lock = $redLock->lock('my_resource_name', $maxSeconds * 1000);
	if ($lock)
	{
		$issueList = array();
		if ($issue === null)
		{
			$lastUpdate = 0;
			if ($redis->exists("lastIssueUpdate"))
			{
				$lastUpdate = $redis->get("lastIssueUpdate");
			}
			$url = $cert->jiraBaseUrl . 'rest/api/2/serverInfo';
			$serverInfo = $jira->performRequest($url, "GET");
			$serverTime = DateTime::createFromFormat('Y-m-d\TH:i:s.uO', $serverInfo["serverTime"]);
			$serverTime->sub(new DateInterval("PT".(((int)$serverTime->format('s')) + 60). "S"));
			$serverTimeEarly = $serverTime->format("Y/m/d H:i");
			
			$queryDate = date("Y/m/d H:i", $lastUpdate);
			$url = $cert->jiraBaseUrl . 'rest/api/2/search';
			$issues = $jira->performRequest($url, array("jql" => 'updated >= \''.$queryDate.'\' and updated < \''.$serverTimeEarly.'\' order by updated ASC', "maxResults" => 1000), "GET");
			for ($i = 0; $i < count($issues["issues"]); $i++)
			{
				$issueList[] = $issues["issues"][$i]["key"];
			}
		}
		else
		{
			$issueList = array($issue);
		}
		$additionalIssues = array();
		for ($i = 0; $i < count($issueList); $i++)
		{
			$children = $redis->smembers('issue.'.$issueList[$i].'.children');
			for ($j = 0; $j < count($children); $j++)
			{
				$additionalIssues[] = $children[$j];
			}
		}
		$issueList = array_merge($additionalIssues, $issueList);
		for ($i = 0; $i < count($issueList); $i++)
		{
			$url = $cert->jiraBaseUrl . 'rest/api/2/issue/'. $issueList[$i];
			$issueInfo = $jira->performRequest($url, "GET");
			if ($issueInfo["key"] && $issueInfo["key"] == $issueList[$i])
			{
				$redis->set('issue.'.$issueList[$i].'.summary', $issueInfo["fields"]["summary"]);
				$redis->set('issue.'.$issueList[$i].'.labels', json_encode($issueInfo["fields"]["labels"]));
				$redis->set('issue.'.$issueList[$i].'.epic', $issueInfo["fields"]["customfield_10006"]);
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
					$url = $cert->jiraBaseUrl . 'rest/api/2/issue/'. $issueInfoRecursive["fields"]["parent"]["key"];
					$issueInfoRecursive = $jira->performRequest($url, "GET");
					$accountName = $issueInfoRecursive["fields"]["summary"] . ":" . $accountName;
					$billingCodes = array_merge($billingCodes, $issueInfoRecursive["fields"]["labels"]);
					$redis->sadd('issue.'.$issueInfoRecursive["key"].'.children', $issueList[$i]);
				}
				if ($issueInfoRecursive["fields"]["customfield_10006"])
				{
					$url = $cert->jiraBaseUrl . 'rest/api/2/issue/'. $issueInfoRecursive["fields"]["customfield_10006"];
					$issueInfoRecursive = $jira->performRequest($url, "GET");
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
					$endLogTime = clone $logTime;
					$endLogTime->add(new DateInterval("PT".((int)$workLog["worklogs"][$j]["timeSpentSeconds"]). "S"));
					$timeclock = "i ".$logTime->format("Y/m/d H:i:s")." ".$accountName. "\no ".$endLogTime->format("Y/m/d H:i:s");
					$ledgerreturn = runHledger("-f - print", $timeclock, $ledger);
					$richerLedger = array();
					$transactions = explode("\n\n", $ledger);
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
					$redis->set($wlKey, json_encode($wlEntry));
				}
				$redis->set('issue.'.$issueList[$i].'.wl.count', count($workLog["worklogs"]));
			}
		}
		if ($issue === null)
		{
			$redis->set("lastIssueUpdate", $serverTime->format('U'));
			//$redis->set("lastIssueUpdate", 0);
		}

		$redLock->unlock($lock);
	}
	else
	{
		echo "Failed to get redis lock";
		exit;
	}
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
	echo $EXTRA_HEAD_HTML;
	echo "</head><body>";
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
	if ($authorizedReporter)
	{
		echo "<a href=\"report.php\">Reports</a> &nbsp;&nbsp;&ndash;&nbsp;&nbsp;";
	}
	echo "<a href=\"index.php?logout=true\">Logout</a><br/><br/></p>";

	updateIssueDatabase($redis, $client, $obj);
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
		if (count($recentTasks) > 5)
		{
			$recentTasks = array_slice($recentTasks, -10, 10);
		}

		$redis->set($myself["key"].'_recentTasks', json_encode($recentTasks));
	}
	if ($currentTask)
	{
		if ($_POST['action'] == "Cancel Task")
		{
			$redis->set($myself["key"].'_currentTask', "");
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
		echo "<table width=\"100%\" class=\"niceborder\" cellpadding=\"8\" border=\"1\">";
		echo "<tr><td align=\"right\" width=\"20%\">Current task:</td><td><a href=\"https://".$JIRA_DOMAIN."/browse/".$currentTask."\">".$currentTask."</a></td></tr><tr><td align=\"right\">Started at:</td><td>".date("Y-m-d H:i:s", $currentTaskStartTime)."</td></tr><tr><td align=\"right\">Elapsed time:</td><td><b>".sprintf('%02d:%02d:%02d', ($workTime/3600),($workTime/60%60), $workTime%60)."</b></td></tr><tr><td align=\"right\">Task summary:</td><td>".htmlentities($redis->get('issue.'.$currentTask.'.summary')). "</td></tr>";
		echo "<tr><td>&nbsp;</td><td><form action=\"index.php\" method=\"POST\" onsubmit=\"if(this.act == 'cancel') {return confirm('Do you really want to cancel your current time entry?');}\">Stop: <input type=\"text\" name=\"offset\" size=\"6\"> minutes ago.<br/>Memo: <input type=\"text\" name=\"memo\" size=\"70\"><br/><input type=\"hidden\" name=\"task\" value=\"".$currentTask."\"><div style=\"width: 280px\"><span id=\"buttonpair\"><input type=\"submit\" name=\"action\" onclick=\"this.form.act='log';\" value=\"Stop Task and Log Time\"><input type=\"submit\" name=\"action\" value=\"Cancel Task\" onclick=\"this.form.act='cancel';\"></span></div></form></td></tr>";
		echo "</table>";
	}
	if (!$currentTask)
	{
		echo "<h3>Recently visited tasks: </h3><table width=\"100%\" class=\"niceborder\" cellpadding=\"8\" border=\"1\">";
		for ($i = 0; $i < count($recentTasks); $i++)
		{
			echo "<tr>";
			echo "<td width=\"15%\" align=\"right\"><a href=\"https://".$JIRA_DOMAIN."/browse/".$recentTasks[$i]."\">".$recentTasks[$i]."</a></td>";
			echo "<td>".htmlentities($redis->get('issue.'.$recentTasks[$i].'.summary'))."</td>";
			echo "<td align=\"right\" valign=\"center\" width=\"30%\"><form action=\"index.php\" method=\"POST\">Start <input type=\"text\" name=\"offset\" size=\"6\"> minutes ago. <input type=\"submit\" name=\"action\" value=\"Start Task\"><input type=\"hidden\" name=\"task\" value=\"".$recentTasks[$i]."\"></form></td>";
			echo "</tr>";
		}
		if (!$recentTasks)
		{
			echo "<tr><td colspan=\"3\">You have not recently visited any tasks.  Please log into Jira and select the time tracking link in the Jira ticket.</td></tr>";
		}
		echo "<tr><td colspan=\"3\" align=\"right\"><form action=\"index.php\" method=\"POST\">Manual task entry: <input type=\"text\" name=\"task\" size=\"15\"> Start <input type=\"text\" name=\"offset\" size=\"6\"> minutes ago. <input type=\"submit\" name=\"action\" value=\"Start Task\"></form></td></tr>";
		echo "</table>";
	}
	echo "<h3>Current Report:</h3><form action=\"".$_SERVER['REQUEST_URI']."\" method=\"GET\"><select name=\"report\" onChange=\"this.form.submit();\">";
	echo "<option value=\"week\"".($_GET["report"] == "week"?" selected=\"selected\"":"").">My Weekly Summary</option>";
	echo "<option value=\"month\"".($_GET["report"] == "month"?" selected=\"selected\"":"").">My Monthly Summary</option>";
	echo "</select></form>";
	// Generate timecard
	$endTime = new DateTime("now", new DateTimeZone("America/New_York"));
	$startTime = new DateTime("now", new DateTimeZone("America/New_York"));
	$period = "week";
	if ($_GET["report"] == "month")
	{
		$period = "month";
		$startTime->modify('first day of this month')->setTime(0,0,0);
	}
	else
	{
		$startTime->modify('Last Sunday')->setTime(0,0,0);
		$startTime->modify('+1 day')->setTime(0,0,0);
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
			$ledgerStr = $ledgerStr.$obj->l."\n";

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
	echo "<h3>Summary for this ".$period.":</h3>".$result->output;

	$ledgerreturn = runHledger("-f - bal --pivot billing -D -T -O csv", $ledgerStr, $ledgerCsv);
	$result = generateLedgerTable($ledgerCsv, true);
	echo "<h3>Billing for this ".$period.":</h3>".$result->output;

	$ledgerreturn = runHledger("-f - reg -O csv", $ledgerStr, $ledgerCsv);
	$result = generateLedgerTable($ledgerCsv, false, $issueReference, $dataReference);
	echo "<h3>Individual timecard entries this ".$period.":</h3>".$result->output;
}
