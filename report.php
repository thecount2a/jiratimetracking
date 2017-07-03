<?php
require_once 'config.php';
require_once 'reportlib.php';

require_once "predis/autoload.php";

require_once 'SupOAuthClient.php';
require_once 'AuthJiraCert.php';

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
	if ($authorizedReporter)
	{
		if ($_GET["output"] != "json")
		{
			echo "<html><head><title>".$REPORTING_WEBSITE_TITLE."</title>";
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
			echo "<a href=\"index.php\">Home</a> &nbsp;&nbsp;&ndash;&nbsp;&nbsp;";
			echo "<a href=\"index.php?logout=true\">Logout</a><br/><br/></p>";
		}

		// Generate timecard
		$startTime = new DateTime("now", new DateTimeZone("America/New_York"));
		$endTime = new DateTime("now", new DateTimeZone("America/New_York"));
		$startTime->modify('first day of last month')->setTime(0,0,0);
		$dataStartTime = $startTime->format('U');
		$dataEndTime = $endTime->format('U');
		if ($_GET["period"] == "This Week")
		{
			$startTime = new DateTime("now", new DateTimeZone("America/New_York"));
			$startTime->modify('Last Sunday')->setTime(0,0,0);
			$startTime->modify('+1 day')->setTime(0,0,0);
		}
		else if ($_GET["period"] == "Last Week")
		{
			$startTime = new DateTime("now", new DateTimeZone("America/New_York"));
			$startTime->modify('Last Sunday')->setTime(0,0,0);
			$startTime->modify('Last Sunday')->setTime(0,0,0);
			$startTime->modify('+1 day')->setTime(0,0,0);
			$endTime = new DateTime("now", new DateTimeZone("America/New_York"));
			$endTime->modify('Last Sunday')->setTime(0,0,0);
			$endTime->modify('+1 day')->setTime(0,0,0);
		}
		else if ($_GET["period"] == "This Month")
		{
			$startTime = new DateTime("now", new DateTimeZone("America/New_York"));
			$startTime->modify('first day of this month')->setTime(0,0,0);
		}
		else if ($_GET["period"] == "Last Month")
		{
			$startTime = new DateTime("now", new DateTimeZone("America/New_York"));
			$startTime->modify('first day of last month')->setTime(0,0,0);
			$endTime = new DateTime("now", new DateTimeZone("America/New_York"));
			$endTime->modify('first day of this month')->setTime(0,0,0);
		}
		else if ($_GET["period"] == "Year to Date")
		{
			$startTime = new DateTime("now", new DateTimeZone("America/New_York"));
			$startTime->setDate($startTime->format('Y'), 1, 1)->setTime(0,0,0);
		}
		else if ($_GET["period"] == "Last Year")
		{
			$startTime = new DateTime("now", new DateTimeZone("America/New_York"));
			$startTime->setDate($startTime->format('Y'), 1, 1)->setTime(0,0,0);
			$startTime->modify('-1 year');
			$endTime = new DateTime("now", new DateTimeZone("America/New_York"));
			$endTime->setDate($endTime->format('Y'), 1, 1)->setTime(0,0,0);
		}
		else if (strpos($_GET["period"], ' - ') !== FALSE)
		{
			$parts = explode(' - ', $_GET["period"]);
			$startTime = new DateTime($parts[0], new DateTimeZone("America/New_York"));
			$startTime->setTime(0,0,0);
			$endTime = new DateTime($parts[1], new DateTimeZone("America/New_York"));
			$endTime->setTime(23,59,59);
		}
		$reportStartTime = (int) $startTime->format('U');
		$reportEndTime = (int) $endTime->format('U');
		//echo $reportStartTime. " " . $startTime->format("Y/m/d H:i:s");
		//echo "<br/>";
		//echo $reportEndTime. " " . $endTime->format("Y/m/d H:i:s");
		$items = $redis->zRangeByScore('issue.wl.s.index', (int) $dataStartTime, (int) $dataEndTime);
		$itemObjs = $redis->mGet($items);
		$users = array();
		$decodedObjs = array();
		for ($i = 0; $i < count($itemObjs); $i++)
		{
			$obj = json_decode($itemObjs[$i]);
			if (array_search($obj->a, $users) === FALSE)
			{
				$users[] = $obj->a;
			}
			$decodedObjs[] = $obj;
		}
		if ($_GET["output"] != "json")
		{
			$reports = array("Project Summary", "Billing Summary", "User Summary", "Individual Entries");
			echo "<h3>Current Report:</h3><form action=\"".$_SERVER['REQUEST_URI']."\" method=\"GET\"><table cellpadding=\"10\"><tr>";
			echo "<td valign=\"top\">Report:<br/><select name=\"report\" onChange=\"this.form.submit();\">";
			for ($i = 0; $i < count($reports); $i++)
			{
				echo "<option value=\"".$reports[$i]."\"".($_GET["report"] == $reports[$i]?" selected=\"selected\"":"").">".$reports[$i]."</option>";
			}
			echo "</select>";
			echo "<br/><br/>Period:<br/><select name=\"period\" onChange=\"this.form.submit();\">";
			$periods = array("This Week", "Last Week", "This Month", "Last Month", "Year to Date", "Last Year");
			for ($i = 0; $i < count($periods); $i++)
			{
				echo "<option value=\"".$periods[$i]."\"".($_GET["period"] == $periods[$i]?" selected=\"selected\"":"").">".$periods[$i]."</option>";
			}
			echo "</select>";
			echo "<br/><br/>Grouping:<br/><select name=\"grouping\" onChange=\"this.form.submit();\">";
			$groupings = array("Daily", "Weekly", "Monthly", "Yearly");
			for ($i = 0; $i < count($groupings); $i++)
			{
				echo "<option value=\"".$groupings[$i]."\"".($_GET["grouping"] == $groupings[$i]?" selected=\"selected\"":"").">".$groupings[$i]."</option>";
			}
			echo "</select></td>";
			echo "<td>User(s):<br/><select name=\"user[]\" size=\"10\" multiple=\"on\" onChange=\"this.form.submit();\">";
			for ($i = 0; $i < count($users); $i++)
			{
				echo "<option value=\"".$users[$i]."\"".(gettype($_GET["user"]) == "array" && array_search($users[$i], $_GET["user"]) !== FALSE?" selected=\"on\"": "").">".$users[$i]."</option>";
			}
			echo "</select></td>";
			echo "<td valign=\"top\">Merge:<br/><select name=\"merge\" onChange=\"this.form.submit();\">";
			$merge = array("Split Users", "Merge Users");
			for ($i = 0; $i < count($merge); $i++)
			{
				echo "<option value=\"".$merge[$i]."\"".($_GET["merge"] == $merge[$i]?" selected=\"selected\"":"").">".$merge[$i]."</option>";
			}
			echo "</select>";
			echo "<br/><br/>Transpose:<br/><select name=\"transpose\" onChange=\"this.form.submit();\">";
			$transpose = array("Off", "On");
			for ($i = 0; $i < count($transpose); $i++)
			{
				echo "<option value=\"".$transpose[$i]."\"".($_GET["transpose"] == $transpose[$i]?" selected=\"selected\"":"").">".$transpose[$i]."</option>";
			}
			echo "</select>";
			echo "<br/><br/>Task Filter:<br/><input type=\"text\" size=\"15\" value=\"".htmlentities($_GET["taskfilter"])."\" name=\"taskfilter\" onChange=\"this.form.submit();\">";
			echo "</td>";
			echo "<td>Project(s):<br/><select name=\"project[]\" size=\"10\" multiple=\"on\" onChange=\"this.form.submit();\">";
			for ($i = 0; $i < count($projectList); $i++)
			{
				echo "<option value=\"".$projectList[$i]->key."\"".(gettype($_GET["project"]) == "array" && array_search($projectList[$i]->key, $_GET["project"]) !== FALSE?" selected=\"on\"": "").">".$projectList[$i]->key."</option>";
			}
			echo "</select></td>";
			echo "</tr></table>";
		}

		if (!$_GET["report"])
		{
			if ($_GET["output"] != "json")
			{
				echo "<input type=\"submit\" value=\"Run\"></form>";
			}
		}
		else
		{
			if ($_GET["output"] != "json")
			{
				echo "</form>";
			}
			$userList = $users;
			if ($_GET["merge"] == "Merge Users" || $_GET["report"] == "User Summary")
			{
				if (gettype($_GET["user"]) == "array")
				{
					$userList = array("all selected users");
				}
				else
				{
					$userList = array("all users");
				}
			}
			else if (gettype($_GET["user"]) == "array")
			{
				$userList = $_GET["user"];
			}
			$jsonResult = array();
			for ($u = 0; $u < count($userList); $u++)
			{
				$ledgerStr = "";
				$issueReference = array();
				$dataReference = array();
				$includeUserColumn = false;
				if ($_GET["report"] == "Individual Entries" && $_GET["merge"] == "Merge Users")
				{
					$includeUserColumn = true;
				}
				for ($i = 0; $i < count($decodedObjs); $i++)
				{
					$obj = $decodedObjs[$i];
					if ($obj->s >= $reportStartTime && $obj->s < $reportEndTime)
					{
						if ($userList[$u] == "all users" || $obj->a == $userList[$u] || ($userList[$u] == "all selected users" && array_search($obj->a, $_GET["user"]) !== FALSE))
						{
							$wlkey = $items[$i];
							$parts = explode(".", $wlkey);
							$subparts = explode("-", $parts[1]);
							if ((!$_GET["taskfilter"] && gettype($_GET["project"]) != "array") 
							  || ($_GET["taskfilter"] && strpos($_GET["taskfilter"].",", $parts[1].",") !== FALSE) 
							  || (gettype($_GET["project"]) == "array" && array_search($subparts[0], $_GET["project"]) !== FALSE))
							{

								$ledgerStr = $ledgerStr.$obj->l."\n";
								// There can be multiple transactions if the worklog entry spans multiple days
								for ($j = 0; $j < (substr_count($ledgerStr, "\n\n")+1); $j++)
								{
									$issueReference[] = $parts[1];
									$dataReference[] = $obj;
								}
							}
						}
					}
				}
				if ($_GET["grouping"] == "Daily")
				{
					$aggregation = "-D";
				}
				else if ($_GET["grouping"] == "Weekly")
				{
					$aggregation = "-W";
				}
				else if ($_GET["grouping"] == "Monthly")
				{
					$aggregation = "-M";
				}
				else if ($_GET["grouping"] == "Yearly")
				{
					$aggregation = "-Y";
				}
				if ($_GET["report"] == "Project Summary")
				{
					$ledgerreturn = runHledger("-f - bal -O csv -T ".$aggregation, $ledgerStr, $ledgerCsv);
					$result = generateLedgerTable($ledgerCsv, $_GET["transpose"] == "On" ? true : false);
				}
				else if ($_GET["report"] == "Billing Summary")
				{
					$ledgerreturn = runHledger("-f - bal --pivot billing -O csv -T ".$aggregation, $ledgerStr, $ledgerCsv);
					$result = generateLedgerTable($ledgerCsv, $_GET["transpose"] == "On" ? true : false);
				}
				else if ($_GET["report"] == "User Summary")
				{
					$ledgerreturn = runHledger("-f - bal --pivot user -O csv -T ".$aggregation, $ledgerStr, $ledgerCsv);
					$result = generateLedgerTable($ledgerCsv, $_GET["transpose"] == "On" ? true : false);
				}
				else if ($_GET["report"] == "Individual Entries")
				{
					$ledgerreturn = runHledger("-f - reg -O csv ", $ledgerStr, $ledgerCsv);
					$result = generateLedgerTable($ledgerCsv, false, $issueReference, $dataReference, $includeUserColumn);
				}
				if ($_GET["output"] != "json")
				{
					echo "<h3>".$_GET["report"]." for ".$userList[$u].":</h3>".$result->output;
					echo "<button onClick=\"this.nextSibling.nextSibling.style.display = '';\">Get CSV</button><br/><textarea style=\"width: 90%; height: 500px;display: none\">".$result->csv."</textarea>";
				}
				else
				{
					$jsonResult[$userList[$u]] = array_map("str_getcsv", explode("\n", trim($result->csv)));
				}
			}
			if ($_GET["output"] == "json")
			{
				header('Content-Type: application/json');
				echo json_encode($jsonResult);
			}
		}
	}
	else
	{
		echo "You do not have permission to do reports.  Please see the jira administrator for access.";
	}

}
?>
