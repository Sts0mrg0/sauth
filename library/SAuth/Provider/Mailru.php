<?php

/**  SAuth_Provider_Abstract */
require_once 'SAuth/Provider/Abstract.php';

/**  SAuth_Provider_Interface */
require_once 'SAuth/Provider/Interface.php';

/**  Zend_Http_Client */
require_once 'Zend/Http/Client.php';


/**
 * Authorisation with mail.ru
 * http://api.mail.ru/docs/guides/oauth/sites/
 * http://api.mail.ru/sites/my/
 * http://api.mail.ru/docs/guides/restapi/
 */
class SAuth_Provider_Mailru extends SAuth_Provider_Abstract implements SAuth_Provider_Interface {
    
    /**
     * @var array Configuration array
     */
    protected $_config = array(
        'consumerId' => '',
        'privateKey' => '',
        'consumerSecret' => '',
        'callbackUrl' => '',
        'userAuthorizationUrl' => 'https://connect.mail.ru/oauth/authorize',
        'accessTokenUrl' => 'https://connect.mail.ru/oauth/token',
        'requestDatarUrl' => 'http://www.appsmail.ru/platform/api',
        'responseType' => 'code',
    );
    
    /**
     * @var string Session key
     */
    protected $_sessionKey = 'SAUTH_MAILRU';
    
    /**
     * Authorized user by facebook OAuth 2.0
     * @param array $config
     * @return true
     */
    public function auth(array $config = array()) {
        
        $config = $this->setConfig($config);
        
        $authorizationUrl = $config['userAuthorizationUrl'];
        $accessTokenUrl = $config['accessTokenUrl'];
        $clientId = $config['consumerId'];
        $clientSecret = $config['consumerSecret'];
        $privateKey = $config['privateKey'];
        $redirectUrl = $config['callbackUrl'];
        $responseType = $config['responseType'];
        
        if (empty($authorizationUrl) || empty($clientId) || empty($clientSecret) || empty($redirectUrl) 
            || empty($accessTokenUrl) || empty($privateKey)) {
                
            require_once 'SAuth/Exception.php';    
            throw new SAuth_Exception('Mail.ru auth configuration not specifed.');
        }

        if (isset($_GET['code']) && !empty($_GET['code'])) {
            	
            $authorizationCode = trim($_GET['code']);
            $accessConfig = array(
                'client_id' => $clientId,
                'redirect_uri' => $redirectUrl,
                'client_secret' => $clientSecret,
                'code' => $authorizationCode,
                'grant_type' => 'authorization_code',
            );
            
            $client = new Zend_Http_Client();
            $client->setUri($accessTokenUrl);
            $client->setParameterPost($accessConfig);
            $response = $client->request(Zend_Http_Client::POST);
            if ($response->isError()) {
                //mail.ru return 400 http code on error
                switch  ($response->getStatus()) {
                    case '400':
                        $parsedErrors = $this->parseResponseJson($response->getBody());
                        $this->_setError($parsedErrors['error']);
                        break;
                    default:
                        $this->_setError('Mail.ru Oauth service unavailable');
                        break;
                }
                
                return false;
            } elseif ($response->isSuccessful()) {
                
                $parsedResponse = $this->parseResponseJson($response->getBody());
                $this->_setTokenAccess($parsedResponse['access_token']);
                $this->setUserParameters($parsedResponse);
                if ($userParameters = $this->requestUserParams()) {
                    $this->setUserParameters($userParameters);
                }
                return $this->isAuthorized();
            }
        } elseif (!isset($_GET['error'])) {
            
            $authorizationConfig = array(
                'client_id' => $clientId, 
                'redirect_uri' => $redirectUrl,
                'response_type' => $responseType,
            );
            // TODO: maybe http_build_url ?
            $url = $authorizationUrl . '?';
            $url .= http_build_query($authorizationConfig, null, '&');
            header('Location: ' . $url);
            exit(1);
        } else {
            $this->_setError($_GET['error']);
            return false;
        }
    }
    
    /**
     * Getting authentication identification
     * @return false|int User ID
     */
    public function getAuthId() {
        
        $id = (int) $this->getUserParameters('uid');
        return $id > 0 ? $id : false;
    }
    
    /**
     * Request user params on mail.ru using REST API
     * http://api.mail.ru/docs/reference/rest/users-getinfo/
     * FIXME: Working only after auth process, because don't consider expire time
     * @return array User params
     */
    public function requestUserParams() {
        
        if (!$this->isAuthorized()) {
            return false;
        }
        
        $restUrl = $this->getConfig('requestDatarUrl');
        $accessToken = $this->_getTokenAccess();
        $config = $this->getConfig();
        
        if ($accessToken && !empty($restUrl)) {
            $client = new Zend_Http_Client();
            $client->setUri($restUrl);
            $requestParametrs = array(
                'app_id' => $config['consumerId'],
                'method' => 'users.getInfo',
                'secure' => 1,
                'session_key' => $accessToken,
            );
            $sig = $this->getSign($requestParametrs);
            $requestParametrs['sig'] = $sig;
            
            $client->setParameterPost($requestParametrs);
            $response = $client->request(Zend_Http_Client::POST);
            if ($response->isError()) {
                $parsedErrors = (array) $this->parseResponseJson($response->getBody());
                $this->_setError($parsedErrors['error']['error_msg']);
                return false;
            } elseif ($response->isSuccessful()) {
                $parsedResponse = (array) $this->parseResponseJson($response->getBody());
                return isset($parsedResponse[0]) ? $parsedResponse[0] : false;
            }
        }
        return false;
    }
    
    /**
     * Return mail.ru sign
     * @param array $requestParams Request parameters
     * @return string Signature
     */
    public function getSign(array $requestParams) {
        
        $config = $this->getConfig();
        $uid = $this->_getTokenAccess();
        $consumerSecret = $config['consumerSecret'];
        ksort($requestParams);
        $params = '';
        foreach ($requestParams as $key => $value) {
            $params .= $key . '=' . $value;
        }
        return md5($params . $consumerSecret);
    }    
}