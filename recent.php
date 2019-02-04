<?php
	require_once 'config.php';
	require_once 'SupOAuthClient.php';
	require_once 'AuthJiraCert.php';
	require "predis/autoload.php";

	$redis = new Predis\Client(array('host' => 'redis'));

	$obj = new AuthJiraCert();
	$client = new SupOAuthClient($obj->consumerKey, $obj->privateKeyFile, $_COOKIE[$COOKIE_PREFIX."_jira_oauth_token"], $_COOKIE[$COOKIE_PREFIX."_jira_oauth_secret"]);
	$url = $obj->jiraBaseUrl . 'rest/api/2/myself';
	$myself = $client->performRequest($url, array("expand"=>"groups"), "GET");

	if ($_SERVER['REQUEST_METHOD'] == "POST")
	{
		$data = json_decode(file_get_contents('php://input'), true);
		foreach ($data as $dkey => $dvalue)
		{
			$redis->hSet($myself["key"].'_recentTaskMetadata', $dkey, $dvalue);
		}
	}
	else
	{
		$data = $redis->hGetAll($myself["key"].'_recentTaskMetadata');
		echo json_encode($data);
	}
	
?>
