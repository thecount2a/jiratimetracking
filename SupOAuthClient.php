<?php
require_once 'config.php';

/**
 * OAuth client
 *
 * Implementation was extracted via reverse engineering from the guzzle http library and the Jira OAuth php example.
 * This class has no external requirements but curl.
 *
 * @link https://bitbucket.org/atlassian_tutorial/atlassian-oauth-examples/src/0c6b54f6fefe996535fb0bdb87ad937e5ffc402d/php/?at=default
 * @link http://oauth.net/core/1.0/#rfc.section.9.1.1
 *
 * @author Christopher
 * @since Nov 2016
 */
class SupOAuthClient {

    private $_consumerKey = '';
    //private $_consumer_secret = ''; //currently not used
    private $_oauthToken = '';
    private $_oauthTokenSecret = '';
    private $_signatureMethod = 'RSA-SHA1';
    private $_oauthVersion = '1.0';
    private $_privateKeyFile = '';
    /**
     * Set this to true to enable curl logging into a curl.log and to var_dump certain curl results/info.
     * Do not set true in production.
     * @var bool
     */
    private $_verbose = false;

    /**
     * Array collecting debug info.
     * If you get an error from the call, check the debugInfo here for details.
     * @see SupOAuthClient::getDebugInfo()
     * @var array
     */
    private $_debugInfo = array();

    /**
     * Params which are sent together with the actual request.
     * They will be signed as well
     * @var string|null
     * @see SupOAuthClient::getSignature()
     */
    private $_queryParams = null;

    /**
     * SupOAuthClient constructor.
     * @param string $consumerKey - ID of the oAuth user
     * @param string $privateKeyFile - Filesystem location as a string where to find the private key stored as a file
     * @param string $oauthToken - The token to use for the requests (works both for request or other access tokens)
     * @param string $oauthTokenSecret - The secret fitting to the $oauthToken
     */
    public function __construct($consumerKey, $privateKeyFile, $oauthToken = '', $oauthTokenSecret = '') {
        $this->_consumerKey = $consumerKey;
        $this->_privateKeyFile = $privateKeyFile;
        $this->_oauthToken = $oauthToken;
        $this->_oauthTokenSecret = $oauthTokenSecret;
    }

    /**
     * Use this method to attach arbitrary parameters to a query
     * @param array $params - assoc key value array
     */
    public function setQueryParams($params) {
        $this->_queryParams = $params;
    }

    /**
     * If you run into problems, this method returns a bit of debug data to inspect.
     * @return array
     */
    public function getDebugInfo() {
        return $this->_debugInfo;
    }

    /**
     * Send OAuth authenticated request
     * @param string $endpointUrl - The API url to call (without any additional query parameters! For sending parameters, use the $queryParams!)
     * @param null|array [$queryParams] - If set to assoc array, the parameters will be send along with the request
     * @param string [$requestType] REST type (GET|POST|PUT|DELETE)
     * @return array|string|null - if json_decode worked, this returns an array. Otherwise the raw string
     */
    public function performRequest($endpointUrl, $queryParams = null, $requestType = 'GET', $additionalGetParams = array()) {
        $this->_queryParams = $queryParams;

        $ch = curl_init();
	$extraheaders = array();
	$originalEndpointUrl = $endpointUrl;
        if ($requestType == 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            if (!is_null($this->_queryParams)) {
		if (is_array($this->_queryParams))
		{
			curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($this->_queryParams));
		}
		else
		{
		    $extraheaders = array('Content-Type: application/json','Content-Length: ' . strlen($this->_queryParams));
		    curl_setopt($ch, CURLOPT_POSTFIELDS, $this->_queryParams);
		}
            }
        } else if ($requestType == 'PUT') {
            //curl_setopt($ch, CURLOPT_PUT, true);
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PUT');
            if (!is_null($this->_queryParams)) {
		if (is_array($this->_queryParams))
		{
		    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($this->_queryParams));
		}
		else
		{
		    $extraheaders = array('Content-Type: application/json','Content-Length: ' . strlen($this->_queryParams));
		    curl_setopt($ch, CURLOPT_POSTFIELDS, $this->_queryParams);
		}
            }
        } else if (!is_null($this->_queryParams)) {
            $endpointUrl .= '?' . http_build_query($this->_queryParams);
        }
        if (($requestType == "POST" || $requestType == "PUT") && !empty($additionalGetParams)) {
            $endpointUrl .= '?' . http_build_query($additionalGetParams);
	}
	if (is_array($this->_queryParams))
	{
            $this->_queryParams = array_merge($additionalGetParams, $this->_queryParams);
	}
	else
	{
            $this->_queryParams = $additionalGetParams;
	}
        $header = array(
            'Authorization: ' . $this->buildAuthorizationHeader($originalEndpointUrl, $requestType)
        );
        curl_setopt($ch, CURLOPT_URL, $endpointUrl);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array_merge($header, $extraheaders));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, true);

        if ($this->_verbose) {
            curl_setopt($ch, CURLOPT_VERBOSE, true);
            $errorFh = fopen('/tmp/curl.log', 'a');
            curl_setopt($ch, CURLOPT_STDERR, $errorFh);
        }

        $response = curl_exec($ch);
        $res = null;
        if ($response === false) {
            if ($this->_verbose) {
                $info = curl_getinfo($ch);
                var_dump($info);
            }
        } else {
            if ($this->_verbose) {
                var_dump($response);
            }
            $res = json_decode($response, true);
            if ($response && !$res) {
                $res = $response; //if json_encode did not work, return the raw thing
            }
        }
        curl_close($ch);

        $this->_debugInfo['method'] = $requestType;
        $this->_debugInfo['url'] = $endpointUrl;
        $this->_debugInfo['postParameters'] = $this->_queryParams;
        $this->_debugInfo['header'] = $header[0];

        return $res;
    }

    /**
     * Builds the oAuth authorization header for a request containing the signature and nonce
     * @return string
     */
    private function buildAuthorizationHeader($endpointUrl, $requestType) {
        $timestamp = time();
        $nonce = $this->generateNonce($endpointUrl);
        $authorizationParams = array(
            'oauth_consumer_key'     => $this->_consumerKey,
            'oauth_nonce'            => $nonce,
            'oauth_signature'        => $this->getSignature($requestType, $endpointUrl, $timestamp, $nonce),
            'oauth_signature_method' => $this->_signatureMethod,
            'oauth_timestamp'        => $timestamp,
            'oauth_token'            => $this->_oauthToken,
            'oauth_version'          => $this->_oauthVersion,
        );
        $this->_debugInfo['usedAuthParams'] = $authorizationParams;

        $authorizationString = 'OAuth ';
        foreach ($authorizationParams as $key => $val) {
            if ($val) {
                $authorizationString .= $key . '="' . urlencode($val) . '", ';
            }
        }
        return substr($authorizationString, 0, -2);
    }

    /**
     * Calculate signature for request
     *
     * @param string $requestType   REST type (GET|POST|PUT|DELETE)
     * @param int $timestamp timestamp in seconds
     * @param string $nonce
     * @return string
     */
    private function getSignature($requestType, $url, $timestamp, $nonce) {
        $signature = null;
        $stringToSign = $this->getSignatureBaseString($requestType, $url, $timestamp, $nonce);
        $this->_debugInfo['signatureBaseString'] = $stringToSign;
        $certificate = openssl_pkey_get_private('file://' . $this->_privateKeyFile);
        $privateKeyId = openssl_get_privatekey($certificate);
        openssl_sign($stringToSign, $signature, $privateKeyId);
        openssl_free_key($privateKeyId);
        return base64_encode($signature);
    }

    /**
     * Calculate string to sign
     *
     * @param string $requestType   REST type (GET|POST|PUT|DELETE)
     * @param int $timestamp timestamp in seconds
     * @param string $nonce
     * @return string
     */
    private function getSignatureBaseString($requestType, $url, $timestamp, $nonce) {
        $params = $this->getParamsToSign($timestamp, $nonce);

        // Build signing string from combined params
        $parameterString = array();
        foreach ($params as $key => $values) {
            $key = rawurlencode($key);
            $values = (array) $values;
            sort($values);
            foreach ($values as $value) {
                if (is_bool($value)) {
                    $value = $value ? 'true' : 'false';
                }
                $parameterString[] = $key . '=' . rawurlencode($value);
            }
        }

        return strtoupper($requestType) . '&'
        . rawurlencode($url) . '&'
        . rawurlencode(implode('&', $parameterString));
    }

    /**
     * Parameters sorted and filtered in order to properly sign a request
     *
     * @param integer          $timestamp Timestamp to use for nonce
     * @param string           $nonce
     *
     * @return array
     */
    private function getParamsToSign($timestamp, $nonce) {
        $params = array(
            'oauth_consumer_key'     => $this->_consumerKey,
            'oauth_nonce'            => $nonce,
            'oauth_signature_method' => $this->_signatureMethod,
            'oauth_timestamp'        => $timestamp,
            'oauth_version'          => $this->_oauthVersion
        );

        // Filter out oauth_token during temp token step, as in request_token.
        if (strlen($this->_oauthToken) > 0) {
            $params['oauth_token'] = $this->_oauthToken;
        }

        //append any query parameters if the request has them
        if (!is_null($this->_queryParams)) {
            $params = array_merge($params, $this->_queryParams);
        }

        ksort($params);
        return $params;
    }

    /**
     * Returns a Nonce Based on the unique id and URL. This will allow for multiple requests in parallel with the same
     * exact timestamp to use separate nonce's.
     *
     * @param string $url   Request url to generate a nonce for
     *
     * @return string
     */
    private function generateNonce($url) {
        return sha1(uniqid('', true) . $url);
    }

}
