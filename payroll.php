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
			    form{ display:inline; margin:0px; padding:0px;}
			    #buttonpair { overflow: hidden; }
			    #buttonpair input { float:right }
			    @media print { .no-print, .no-print * { display: none !important; } }
			    .grid {width: 1400px;height: 600px;}
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

			<?php echo $EXTRA_HEAD_HTML; ?>
			</head><body ng-app="payrollApp">
			<div ng-controller="payrollController">
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
			<input type="button" value="Edit Employee Info" ng-click="editEmployeeInfo();">
			<input type="button" value="Edit Vacation History" ng-click="editVacationHistory();">
			<input type="button" value="Edit QuickBooks Mapping" ng-click="editQuickbooksMapping();">
			<div class="well" ng-hide="hideGrid">
			  <div ui-grid="gridOptions" ui-grid-edit ui-grid-selection class="grid"></div>
			  <input type="button" value="Add" ng-click="addRow();">
			  <input type="button" value="Delete" ng-click="deleteRow();">
			</div>
			</div>
			<div ng-repeat="employee in payroll_data.employee_info"><p style="page-break-after:always;">
				<img src="logo.png">
				<?php echo $COMPANY_PAYROLL_HEADER ?>
				<span>{{employee.name}}</span>
				
			</p></div>
			</div>
			</div>
			</body></html>
			<?php
		}
	}
}
?>
