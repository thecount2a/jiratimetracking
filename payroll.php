<?php
require_once 'config.php';

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
		if ($_GET["blob"])
		{
			if (empty($_COOKIE['XSRF-TOKEN'])) {
			    if (function_exists('mcrypt_create_iv')) {
				$csrftoken = bin2hex(mcrypt_create_iv(32, MCRYPT_DEV_URANDOM));
			    } else {
				$csrftoken = bin2hex(openssl_random_pseudo_bytes(32));
			    }
			    setcookie('XSRF-TOKEN', $csrftoken);
			} 
			else
			{
				$csrftoken = $_COOKIE['XSRF-TOKEN'];
			}
			if (empty($_COOKIE['timetracking_user']))
			{
			    setcookie('timetracking_user', $myself['key']);
			}
			
			if ($_SERVER['REQUEST_METHOD'] == "GET")
			{
				$path = $STORAGE_PATH;
				$file = $myself['key'].'.blob';
				
				if ((file_exists($path.'/'.$file) && is_readable($path.'/'.$file) && is_writable($path.'/'.$file)) || (!file_exists($path.'/'.$file) && is_readable($path) && is_writable($path)))
				{
					echo file_get_contents($path.'/'.$file);
				}
				else
				{
					header('HTTP/1.1 500 Internal Server Error', true, 500);
					if (!is_readable($path)) { echo "Storage dir not readable"; }
					else if (!is_writable($path)) { echo "Storage dir not writable"; }
					else if (!is_writable($path.'/'.$file)) { echo "Storage file not readable"; }
					else if (!is_writable($path.'/'.$file)) { echo "Storage file not writable"; }
				}
			}
			else if ($_SERVER['REQUEST_METHOD'] == "POST")
			{
				$path = $STORAGE_PATH;
				$file = $myself['key'].'.blob';
				if ($_SERVER['HTTP_X_XSRF_TOKEN'] != $csrftoken)
				{
					header('HTTP/1.1 500 Internal Server Error', true, 500);
					echo "CSRF check failed";
				}
				else
				{
					$contents = file_get_contents("php://input");
					// Make sure it is just base64 data
					$validate = '/^[a-zA-Z0-9=\/+:]*$/';
					if (preg_match($validate, $contents) && ((file_exists($path.'/'.$file) && is_readable($path.'/'.$file) && is_writable($path.'/'.$file)) || (!file_exists($path.'/'.$file) && is_readable($path) && is_writable($path))))
					{
						$iter = 0;
						while(file_exists($path.'/'.$file.".".$iter))
						{
							$iter++;
						}
						rename($path.'/'.$file, $path.'/'.$file.".".$iter);
						$fp = fopen($path.'/'.$file, 'w');
						fwrite($fp, $contents);
						fclose($fp);
					}
					else
					{
						header('HTTP/1.1 500 Internal Server Error', true, 500);
						if (!preg_match($validate, $contents)) { echo "Data has invalid characters in it"; }
						else if (!is_readable($path)) { echo "Storage dir not readable"; }
						else if (!is_writable($path)) { echo "Storage dir not writable"; }
						else if (!is_writable($path.'/'.$file)) { echo "Storage file not readable"; }
						else if (!is_writable($path.'/'.$file)) { echo "Storage file not writable"; }
					}
				}
			}
		}
		else
		{
			?>
<!doctype html>
			<html><head><title><?php echo $REPORTING_WEBSITE_TITLE; ?></title>
			<style>
			    table.niceborder { border-collapse: collapse; }
			    table.niceborder,th.niceborder,td.niceborder { border: 1px solid black; }
			    table.hours { }
			    td.hours { padding-right: 5px; padding-left: 5px;}
			    form{ display:inline; margin:0px; padding:0px;}
			    #buttonpair { overflow: hidden; }
			    #buttonpair input { float:right }
			    @media print { .no-print, .no-print * { display: none !important; } }
			    @media print { div.hours { text-align: center; } table.hours { width: 60%; font-size: 70%; margin: 0 auto; } }
			    .grid {width: 1400px;height: 600px;}
			    div.sign { border-top: 1px solid black; }
			</style>
			<script src="angular.js"></script>
			<script src="angular-touch.js"></script>
			<script src="angular-animate.js"></script>
			<script src="angular-cookies.js"></script>
			<script src="csv.js"></script>
			<script src="pdfmake.js"></script>
			<script src="vfs_fonts.js"></script>
			<script src="ui-grid.js"></script>
			<link type="text/css" href="ui-grid.css" rel="stylesheet" />
			<script src="nacl-fast.min.js"></script>
			<script src="nacl-util.min.js"></script>
			<script src="sha256.min.js"></script>
			<script src="payroll.js"></script>
			<script>
				var QUICKBOOKS_COMPANY_NAME = '<?php echo $QUICKBOOKS_COMPANY_NAME; ?>';
				var QUICKBOOKS_CREATE_TIME  = '<?php echo $QUICKBOOKS_CREATE_TIME; ?>';
			</script>

			<?php echo $EXTRA_HEAD_HTML; ?>
			</head><body ng-app="payrollApp">
			<div ng-controller="payrollController">
			<div ng-show="editRaw">
			<textarea style="width: 400px; height: 100px;" ng-model="editRawTextarea"></textarea>
			<input type="button" value="Save" ng-click="saveRawData();"/>
			</div>
			<div ng-show="blob_loaded && !payroll_decrypted">
				<p>Warning: If you lose this password, your payroll data is gone forever.  There is no way to recover it due to the nature of the encryption being used in this tool.</p>
				<input type="password" ng-model="payroll_key" /><input type="button" value="Decrypt" ng-click="decryptBlob();"/>
			</div>
			<div ng-show="blob_loaded && payroll_decrypted">
			<div class="no-print">
			<select ng-model="currenttimespan" ng-change="computePayroll(false);">
				<option ng-repeat="timespan in timespans" ng-value="timespan">{{timespan}}</option>
			</select>
			<input type="button" value="Save" ng-click="savePayroll();">
			<input type="button" value="Force Recompute" ng-click="computePayroll(true);">
			<input type="button" value="Edit Raw Data" ng-click="editRawData();">
			<input type="button" value="Edit Employee Info" ng-click="editEmployeeInfo();">
			<input type="button" value="Edit Vacation History" ng-click="editVacationHistory();">
			<input type="button" value="Edit QuickBooks Mapping" ng-click="editQuickbooksMapping();">
			<input type="button" value="Generate QuickBooks" ng-click="generateQuickbooks();">
			<div class="well" ng-hide="hideGrid">
			  <div ui-grid="gridOptions" ui-grid-edit ui-grid-selection class="grid"></div>
			  <input type="button" value="Add" ng-click="addRow();">
			  <input type="button" value="Delete" ng-click="deleteRow();">
			</div>
			</div>
			<div ng-repeat="employee in payroll_data.employee_info | orderBy:'id'" ng-if="employee.active">
				<table cellpadding="3"><tr><td>
				<?php echo $COMPANY_PAYROLL_HEADER ?>
				</td><td>
				<table cellpadding="3">
				<tr><th align="right">Employee:</td><td>{{ employee.name }}</td></tr>
				<tr><th align="right">Monthly Rate:</td><td>{{ employee.rate | currency }}</td></tr>
				<tr><th align="right">Monthly Benefits:</td><td>{{ employee.monthly_benefits | currency }}</td></tr>
				<tr><th align="right">Vacation Rate:</td><td>{{ employee.annual_vacation_days / (365 * 5 / 7 - employee.annual_vacation_days) | number : 6 }}</td></tr>
				</table>
				</td><td valign="top">{{ getCurrentMonth() }}</td></tr></table>
				<br/>
				<div class="hours"><table cellpadding="2" border="1" class="niceborder hours">
				<tr><th>Date \ Project #</th><th ng-repeat="col in users[employee.id][0]" ng-if="$index > 0">{{ col.split('_')[col.split('_').length-1] }}</th></tr>
				<tr ng-repeat="row in users[employee.id] track by $index" ng-if="$index > 0 && !allitemszero(row)"><th>{{ row[0] }}</th><td class="hours" align="right" ng-repeat="col in row track by $index" ng-if="$index > 0"><div ng-if="col != '0'">{{ col }}h</div></td></tr>
				<tr><td></td><td align="center" style="color: gray;"  ng-repeat="col in users[employee.id][0] track by $index" ng-if="$index > 0 && $index < users[employee.id][0].length-1">{{ lookupCode(col.split('_')[col.split('_').length-1], employee.project_payable_cutoff) }}</td><td></td></tr>
				<tr><th align="center">Prorated Contract Billing</th><td align="center" style="color: gray;"  ng-repeat="col in users[employee.id][users[employee.id].length-1] track by $index" ng-if="$index > 0 && $index < users[employee.id][0].length-1">{{ Number(col) * Math.min(laborHours(employee.id) / payableLaborHours(employee.id, employee.project_payable_cutoff), 1) | number : 2 }}h</td><td></td></tr>
				</table></div>
				<br/>
				<table cellpadding="1" class="hours">
				<tr><th align="right">Labor Hours:</td><td class="hours">{{ laborHours(employee.id) }}</td><td>{{ laborHours(employee.id, true) > laborHours(employee.id) ? (employee.end_date ? "(End date: "+formatDate(employee.end_date)+")" : "(Start date: "+formatDate(employee.start_date)+")") : "" }}</td></tr>
				<tr><th align="right">Payable Labor Hours:</td><td class="hours">{{ payableLaborHours(employee.id, employee.project_payable_cutoff) | number : 2 }}</td></tr>
				<tr><th align="right">Hours Paid:</td><td class="hours">{{ hoursPaid(employee.id, employee.project_payable_cutoff) | number : 2 }}</td></tr>
				<tr><th align="right">Hours Short:</td><td class="hours">{{ hoursShort(employee.id, employee.project_payable_cutoff) | number : 2 }}</td><th align="right">Starting Vacation:</th><td class="hours">{{ vacationTally(employee.id, beginningoftime, currenttimespanstart) | number : 2 }}</td></tr>
				<tr><th align="right">Usable Vacation:</td><td class="hours">{{ (vacationTally(employee.id, beginningoftime, currenttimespanstart) + vacationTally(employee.id, currenttimespanstart, currenttimespanend, 1)) | number : 2 }}</td><th align="right">Earned Vacation:</th><td class="hours">{{ vacationTally(employee.id, currenttimespanstart, currenttimespanend, 1) | number : 2 }}</td></tr>
				<tr><th align="right">Vacation Debt:</td><td class="hours">{{ Math.max(0, hoursShort(employee.id, employee.project_payable_cutoff) - (vacationTally(employee.id, beginningoftime, currenttimespanstart) + vacationTally(employee.id, currenttimespanstart, currenttimespanend, 1))) | number : 2 }}</td><th align="right">Vacation Available:</th><td class="hours">{{ vacationTally(employee.id, beginningoftime, currenttimespanend) | number : 2 }}</td></tr>
				<!--tr><th align="right">Total Paid Fraction:</td><td class="hours">{{ totalPaidFraction(employee.id, employee.project_payable_cutoff) | number : 3 }}</td></tr-->
				<tr><th align="right">Benefits:</td><td class="hours">{{ employee.monthly_benefits * laborHours(employee.id) / laborHours(employee.id, true) | currency }}</td><th align="right">Gross Pay:</th><td class="hours">{{ ((employee.rate * laborHours(employee.id) / laborHours(employee.id, true)) + employee.monthly_benefits * laborHours(employee.id) / laborHours(employee.id, true)) | currency }}</td></tr>
				</table>
			<br/><br/>
			<div class="sign">
			Employee Signature<span style="float: right;padding-right: 250px;">Date</span>
			</div>
			<br/>
			<div class="sign">
			Supervisor Signature<span style="float: right;padding-right: 250px;">Date</span>
			</div>
				
				
			<p style="page-break-after:always;">&nbsp;</p></div>
			</div>
			</div>
			</body></html>
			<?php
		}
	}
}
?>
