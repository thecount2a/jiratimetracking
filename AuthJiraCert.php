<?php
require_once 'config.php';

/**
 * Script for generating a valid oAuth access token for Jira API access.
 *
 * Preparation:
 *  1) Create a certificate by calling
 *      openssl req -x509 -nodes -days 3650 -newkey rsa:2048 -sha1 -subj '/C=US/ST=CA/L=Mountain View/CN=www.example.com' -keyout ~/jira_private.pem -out ~/jira_public.pem
 *  2) Put the certificate files into this folder and adjust {@link AuthJiraCert#privateKeyFile}
 *  3) Setup a Jira Application link in https://YOUR-JIRA-URL/plugins/servlet/applinks/listApplicationLinks
 *      - incoming authentication
 *      - randomized consumer key
 *      - Public key: copy paste the contents of jira_public.pem into that field
 *      - save
 *  4) run this script in the browser and follow the steps.
 *      - On the second page, scroll down it should print out the oauth_token and oauth_token_secret to give you access to the jira api.
 *
 * @author Christopher
 * @since Nov 2016
 */
class AuthJiraCert
{

    /**
     * @var string - The URL of the Jira installiation to talk to
     */
    public $jiraBaseUrl;
    /**
     * Randomized string as chosen in https://YOUR-JIRA-URL/plugins/servlet/applinks/listApplicationLinks
     * @var string
     */
    public $consumerKey;
    /**
     * Fully qualified path of the private key file as generated using the openssl statement in the class comment.
     * @var string
     */
    public $privateKeyFile;

    /**
     * AuthJiraCert constructor.
     */
    function __construct()
    {
	$this->jiraBaseUrl = "https://".$GLOBALS['JIRA_DOMAIN']."/";
	$this->consumerKey = $GLOBALS['OAUTH_CONSUMER_KEY'];
	$this->privateKeyFile = $GLOBALS['OAUTH_PRIVATE_KEY_FILE'];
        $this->requestTokenUrl = $this->jiraBaseUrl . 'plugins/servlet/oauth/request-token';
        $this->accessTokenUrl = $this->jiraBaseUrl . 'plugins/servlet/oauth/access-token';
    }

    /**
     * Request a short lived request token from the Jira API
     * @return array|null
     */
    private function step1_getRequestToken()
    {
        //$this->printJiraSteps(array(
        //    'IN PROGRESS: Request (short-lived) "request-token"',
        //    'Authorize request-token and retrieve oauth_verifier',
        //    'Send retrieved oauth_verifier back to jira to get (long-lived) access-token',
        //    'Performing test API request with access-token'
        //), 1);


        $client = new SupOAuthClient($this->consumerKey, $this->privateKeyFile);
        $currentUrl = 'https://' . $_SERVER['SERVER_NAME'] . $_SERVER['REQUEST_URI'];
        $postParams = array(
            'oauth_callback' => $currentUrl //If called in a browser, the browser will redirect here
        );
        $res = $client->performRequest($this->requestTokenUrl, $postParams, 'POST');
        //echo 'Sent request:';
        //var_dump($client->getDebugInfo());

        if (strlen($res) > 0) {
            $params = array();
            parse_str($res, $params);
            //echo 'Result:';
            //var_dump($params);
            if (isset($params['oauth_problem'])) {
		echo "Problem with auth step 1: ".$params['oauth_problem'];
                exit;
            }
            return $params;
        }
        return null;
    }

    /**
     * Authenticate the request token with a logged in account
     * Jira will open the current URL again and attach an oauth_verifier used in step 3.
     */
    public function step2_authRequesttoken($paramsFromStep1) {
        //$this->printJiraSteps(array(
        //    'COMPLETED: Request (short-lived) "request-token"',
        //    'IN PROGRESS: Authorize request-token and retrieve oauth_verifier',
        //    'Send retrieved oauth_verifier back to jira to get (long-lived) access-token',
        //    'Performing test API request with access-token'
        //), 2);

        $authorizeUrl = $this->jiraBaseUrl . 'plugins/servlet/oauth/authorize?oauth_token=' . $paramsFromStep1['oauth_token'];
        echo 'Please grant authorization to your Jira account to '.$GLOBALS['WEBSITE_TITLE'].' by clicking here: <a href="' . $authorizeUrl . '">' . $authorizeUrl . '</a>';
    }

    /**
     * Use the oauth_verifier retrieved in step2 to retrieve a long-lived access token which can be used for 5 years (by default).
     */
    private function step3_retrieveAccessTokenForVerifier() {
        //------------ STEP 3 ----------------------

        //$this->printJiraSteps(array(
        //    'COMPLETED: Request (short-lived) "request-token" ==> completed',
        //    'COMPLETED: Authorize request-token and retrieve oauth_verifier',
        //    'IN PROGRESS: Send retrieved oauth_verifier back to jira to get (long-lived) access-token',
        //    'Performing test API request with access-token'
        //), 3);

        $oauthToken = $_GET['oauth_token'];
        unset($_GET['oauth_token']); //TODO needed?
        $postParams = array(
            'oauth_verifier' => $_GET['oauth_verifier']
        );

        $client = new SupOAuthClient($this->consumerKey, $this->privateKeyFile, $oauthToken);
        $res = $client->performRequest($this->accessTokenUrl, $postParams, 'POST');
        //echo 'Sent request:';
        //var_dump($client->getDebugInfo());
        $parsed = array();
        parse_str($res, $parsed);
        //echo 'Result:';
        //var_dump($parsed);
        if (isset($parsed['oauth_problem'])) {
            echo 'The oauth_verifier can only be used once. Close this tab and try again from the beginning.';
            exit;
        }
        return $parsed;
    }

    /**
     * Makes a test request to the Jira API using the long-living access token
     * @param string $oAuthToken
     * @param string $oauthSecret
     */
    private function step4_performTestApiCall($oAuthToken, $oauthSecret) {
        //$this->printJiraSteps(array(
        //    'COMPLETED: Request (short-lived) "request-token" ==> completed',
        //    'COMPLETED: Authorize request-token and retrieve oauth_verifier',
        //    'COMPLETED: Send retrieved oauth_verifier back to jira to get (long-lived) access-token',
        //    'IN PROGRESS: Performing test API request with access-token'
        //), 4);

        $client = new SupOAuthClient($this->consumerKey, $this->privateKeyFile, $oAuthToken, $oauthSecret);
        $url = $this->jiraBaseUrl . 'rest/api/2/mypermissions';
        $res = $client->performRequest($url);
        //echo 'Sent request:';
        //var_dump($client->getDebugInfo());
        //echo 'Result (permissions granted to the oauth user):';
        //var_dump($res);
        if (isset($res['oauth_problem'])) {
            echo 'Step 4 auth problem:' + $res['oauth_problem'];
            exit;
        }
    }

    private function step5_saveToken($oAuthToken, $oauthSecret) {
	setcookie($GLOBALS['COOKIE_PREFIX']."_jira_oauth_token", $oAuthToken, time() + (10 * 365 * 24 * 60 * 60), '/');
	setcookie($GLOBALS['COOKIE_PREFIX']."_jira_oauth_secret", $oauthSecret, time() + (10 * 365 * 24 * 60 * 60), '/');
	header('Location: https://'.$GLOBALS['HOSTED_DOMAIN'].$_SERVER['REQUEST_URI']);
        //$this->printJiraSteps(array(
        //    'COMPLETED: Request (short-lived) "request-token" ==> completed',
        //    'COMPLETED: Authorize request-token and retrieve oauth_verifier',
        //    'COMPLETED: Send retrieved oauth_verifier back to jira to get (long-lived) access-token',
        //    'COMPLETED: Performing test API request with access-token'
        //));

//        $manageUrl = $this->jiraBaseUrl . 'plugins/servlet/oauth/users/access-tokens';
//        echo '<h1>Perform requests using these (long-lived) credentials</h1>
//<table style="font-weight: bold;">
//    <tr>
//        <td>oauth_token</td>
//        <td>' . $oAuthToken . '</td>
//    </tr><tr>
//        <td>oauth_token_secret</td>
//        <td>' . $oauthSecret . '</td>
//    </tr>
//</table>
//<p style="font-weight: bold;">Click <a target="_blank" href="' . $manageUrl . '">here</a> to see/manage/revoke authorized access-tokens in Jira.</p>
//';
    }

    /**
     * Runs through steps 1-5
     */
    public function run() {
        if (!($_GET['oauth_token'] && $_GET['oauth_verifier'])) {
            $params = $this->step1_getRequestToken();
            $this->step2_authRequesttoken($params);
        } else {
            $params = $this->step3_retrieveAccessTokenForVerifier();
            $oAuthToken = $params['oauth_token'];
            $oauthSecret = $params['oauth_token_secret'];
            $this->step4_performTestApiCall($oAuthToken, $oauthSecret);
            $this->step5_saveToken($oAuthToken, $oauthSecret);
        }
    }


    /**
     * Tiny output formatting for Jira oauth steps
     * @param $steps
     * @param int $currentStep
     */
    private function printJiraSteps($steps, $currentStep = 0)
    {
        if ($currentStep > 0) {
            echo '<p><h1>Step ' . $currentStep . '</h1>';
        } else {
            echo '<p><h1>Done</h1>';
        }
        foreach ($steps as $index => $value) {
            $style = '';
            if (stripos($value, 'completed') !== false) {
                $style = 'color: green; font-weight: bold;';
            } else if (stripos($value, 'in progress') !== false) {
                $style = 'color: orange; font-weight: bold;';
            }
            echo '<div style="' . $style . '">Step ' . ($index + 1) . ': ' . $value . '</div>';

        }
        echo '</p>';
    }
}

?>
