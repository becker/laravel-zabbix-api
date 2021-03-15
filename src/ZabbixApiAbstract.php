<?php

namespace Becker\Zabbix;

use Becker\Zabbix\ZabbixException as Exception;

/**
 * @brief   Abstract class for the Zabbix API.
 */

abstract class ZabbixApiAbstract
{

    /**
     * @brief   Anonymous API functions.
     */

    protected static $anonymousFunctions = [
        'apiinfo.version'
    ];

    /**
     * @brief   Boolean if requests/responses should be printed out (JSON).
     */

    protected $printCommunication = false;

    /**
     * @brief   API URL.
     */

    protected $apiUrl;

    /**
     * @brief   Default params.
     */

    protected $defaultParams = [];

    /**
     * @brief   Auth string.
     */

    protected $auth;

    /**
     * @brief   Request ID.
     */

    protected $id = 0;

    /**
     * @brief   Request array.
     */

    protected $request = [];

    /**
     * @brief   JSON encoded request string.
     */

    protected $requestEncoded;

    /**
     * @brief   JSON decoded response string.
     */

    protected $response;

    /**
     * @brief   Response object.
     */

    protected $responseDecoded = null;

    /**
     * @brief   Extra HTTP headers.
     */

    protected $extraHeaders;

    /**
     * @brief   SSL context.
     */

    protected $sslContext = [];

    /**
     * @brief   Checks the API host SSL certificate peer name.
     */

    protected $checkSsl;

    /**
     * @brief   Class constructor.
     *
     * @param   $apiUrl         API url (e.g. http://FQDN/zabbix/api_jsonrpc.php).
     * @param   $user           Username for Zabbix API.
     * @param   $password       Password for Zabbix API.
     * @param   $httpUser       Username for HTTP basic authorization.
     * @param   $httpPassword   Password for HTTP basic authorization.
     * @param   $authToken      Already issued auth token (e.g. extracted from cookies).
     * @param   $sslContext     SSL context for SSL-enabled connections.
     * @param   $checkSsl       Checks the API host SSL certificate peer name.
     */

    public function __construct($apiUrl, $user, $password, $httpUser, $httpPassword, $authToken, $sslContext, $checkSsl)
    {
        $this->apiUrl = $apiUrl;

        $this->sslContext = $sslContext;

        $this->checkSsl = $checkSsl;

        if ($httpUser && $httpPassword) {
            $this->setBasicAuthorization($httpUser, $httpPassword);
        }

        if ($authToken) {
            $this->authToken = $authToken;
        } elseif ($user && $password) {
            $this->userLogin(array('user' => $user, 'password' => $password));
        }
    }

    /**
     * @brief   Sets the username and password for the HTTP basic authorization.
     *
     * @param   $user       HTTP basic authorization username
     * @param   $password   HTTP basic authorization password
     *
     * @retval  ZabbixApiAbstract
     */

    public function setBasicAuthorization($user, $password)
    {
        if ($user && $password) {
            $this->extraHeaders = 'Authorization: Basic ' . base64_encode($user.':'.$password);
        } else {
            $this->extraHeaders = '';
        }

        return $this;
    }

    /**
     * @brief   Returns the default params.
     *
     * @retval  array   Array with default params.
     */

    public function getDefaultParams()
    {
        return $this->defaultParams;
    }

    /**
     * @brief   Sets the default params.
     *
     * @param   $defaultParams  Array with default params.
     *
     * @retval  ZabbixApiAbstract
     *
     * @throws  Exception
     */

    public function setDefaultParams($defaultParams)
    {
        if (is_array($defaultParams)) {
            $this->defaultParams = $defaultParams;
        } else {
            throw new Exception('The argument defaultParams on setDefaultParams() has to be an array.');
        }

        return $this;
    }

    /**
     * @brief   Sets the flag to print communication requests/responses.
     *
     * @param   $print  Boolean if requests/responses should be printed out.
     *
     * @retval  ZabbixApiAbstract
     */
    public function printCommunication($print = true)
    {
        $this->printCommunication = (bool) $print;
        return $this;
    }

    /**
     * @brief   Sends are request to the zabbix API and returns the response
     *          as object.
     *
     * @param   $method     Name of the API method.
     * @param   $params     Additional parameters.
     * @param   $auth       Enable authentication (default TRUE).
     *
     * @retval  stdClass    API JSON response.
     */

    public function request($method, $params=null, $resultArrayKey='', $auth=true)
    {

        // sanity check and conversion for params array
        if (!$params) {
            $params = array();
        } elseif (!is_array($params)) {
            $params = array($params);
        }

        // generate ID
        $this->id = number_format(microtime(true), 4, '', '');

        // build request array
        $this->request = array(
            'jsonrpc' => '2.0',
            'method'  => $method,
            'params'  => $params,
            'id'      => $this->id
        );

        // add auth token if required
        if ($auth) {
            $this->request['auth'] = ($this->authToken ? $this->authToken : null);
        }

        // encode request array
        $this->requestEncoded = json_encode($this->request);

        // debug logging
        if ($this->printCommunication) {
            echo 'API request: '.$this->requestEncoded;
        }

        // initialize context
        $context = array(
            'http' => array(
                'method'  => 'POST',
                'header'  => 'Content-type: application/json-rpc'."\r\n".$this->extraHeaders,
                'content' => $this->requestEncoded
            )
        );
        
        if ($this->isSecure($this->apiUrl)) {
            $ssl = array(
                'ssl' => array(
                    'verify_peer' => $this->checkSsl
                )
            );

            $context = array_merge($context, $ssl);
        }

        if ($this->sslContext) {
            $context['ssl'] = $this->sslContext;
        }

        // create stream context
        $streamContext = stream_context_create($context);

        // get file handler
        $fileHandler = @fopen($this->apiUrl, 'rb', false, $streamContext);
        if (!$fileHandler) {
            throw new Exception('Could not connect to "'.$this->apiUrl.'"');
        }

        // get response
        $this->response = @stream_get_contents($fileHandler);

        // debug logging
        if ($this->printCommunication) {
            echo $this->response."\n";
        }

        // response verification
        if ($this->response === false) {
            throw new Exception('Could not read data from "'.$this->apiUrl.'"');
        }

        // decode response
        $this->responseDecoded = json_decode($this->response);

        // validate response
        if (!is_object($this->responseDecoded) && !is_array($this->responseDecoded)) {
            throw new Exception('Could not decode JSON response.');
        }
        if (property_exists($this->responseDecoded, 'error')) {
            throw new Exception('API error '.$this->responseDecoded->error->code.': '.$this->responseDecoded->error->data);
        }

        // return response
        if ($resultArrayKey && is_array($this->responseDecoded->result)) {
            return $this->convertToAssociatveArray($this->responseDecoded->result, $resultArrayKey);
        } else {
            return $this->responseDecoded->result;
        }
    }

    /**
     * @brief   Check if requested URL is made by HTTPS
     *
     * @retval  boolean
     */
    protected function isSecure($url)
    {
        $url = parse_url($url);

        return $url['scheme'] == 'https';
    }

    /**
     * @brief   Returns the last JSON API request.
     *
     * @retval  string  JSON request.
     */

    public function getRequest()
    {
        return $this->requestEncoded;
    }

    /**
     * @brief   Returns the last JSON API response.
     *
     * @retval  string  JSON response.
     */

    public function getResponse()
    {
        return $this->response;
    }

    /**
     * @brief   Convertes an indexed array to an associative array.
     *
     * @param   $indexedArray           Indexed array with objects.
     * @param   $useObjectProperty      Object property to use as array key.
     *
     * @retval  associative Array
     */

    protected function convertToAssociatveArray($objectArray, $useObjectProperty)
    {
        // sanity check
        if (count($objectArray) == 0 || !property_exists($objectArray[0], $useObjectProperty)) {
            return $objectArray;
        }

        // loop through array and replace keys
        $newObjectArray = array();
        foreach ($objectArray as $key => $object) {
            $newObjectArray[$object->{$useObjectProperty}] = $object;
        }

        // return associative array
        return $newObjectArray;
    }

    /**
     * @brief   Returns a params array for the request.
     *
     * This method will automatically convert all provided types into a correct
     * array. Which means:
     *
     *      - arrays will not be converted (indexed & associatve)
     *      - scalar values will be converted into an one-element array (indexed)
     *      - other values will result in an empty array
     *
     * Afterwards the array will be merged with all default params, while the
     * default params have a lower priority (passed array will overwrite default
     * params). But there is an Exception for merging: If the passed array is an
     * indexed array, the default params will not be merged. This is because
     * there are some API methods, which are expecting a simple JSON array (aka
     * PHP indexed array) instead of an object (aka PHP associative array).
     * Example for this behaviour are delete operations, which are directly
     * expecting an array of IDs '[ 1,2,3 ]' instead of '{ ids: [ 1,2,3 ] }'.
     *
     * @param   $params     Params array.
     *
     * @retval  Array
     */

    protected function getRequestParamsArray($params)
    {
        // if params is a scalar value, turn it into an array
        if (is_scalar($params)) {
            $params = array($params);
        }

        // if params isn't an array, create an empty one (e.g. for booleans, NULL)
        elseif (!is_array($params)) {
            $params = array();
        }

        // if array isn't indexed, merge array with default params
        if (count($params) == 0 || array_keys($params) !== range(0, count($params) - 1)) {
            $params = array_merge($this->getDefaultParams(), $params);
        }

        // return params
        return $params;
    }

    /**
     * @brief   Login into the API.
     *
     * This will also retreive the auth Token, which will be used for any
     * further requests. Please be aware that by default the received auth
     * token will be cached on the filesystem.
     *
     * When a user is successfully logged in for the first time, the token will
     * be cached / stored in the $tokenCacheDir directory. For every future
     * request, the cached auth token will automatically be loaded and the
     * user.login is skipped. If the auth token is invalid/expired, user.login
     * will be executed, and the auth token will be cached again.
     *
     * The $params Array can be used, to pass parameters to the Zabbix API.
     * For more informations about these parameters, check the Zabbix API
     * documentation at https://www.zabbix.com/documentation/.
     *
     * The $arrayKeyProperty can be used to get an associatve instead of an
     * indexed array as response. A valid value for the $arrayKeyProperty is
     * is any property of the returned JSON objects (e.g. name, host,
     * hostid, graphid, screenitemid).
     *
     * @param   $params             Parameters to pass through.
     * @param   $arrayKeyProperty   Object property for key of array.
     * @param   $tokenCacheDir      Path to a directory to store the auth token.
     *
     * @retval  stdClass
     *
     * @throws  Exception
     */

    final public function userLogin($params=array(), $arrayKeyProperty='', $tokenCacheDir='/tmp')
    {
        // reset auth token
        $this->authToken = '';

        // build filename for cached auth token
        if ($tokenCacheDir && array_key_exists('user', $params) && is_dir($tokenCacheDir)) {
            $tokenCacheFile = $tokenCacheDir.'/.zabbixapi-token-'.md5($params['user'].'|'.posix_getuid());
        }

        // try to read cached auth token
        if (isset($tokenCacheFile) && is_file($tokenCacheFile)) {
            try {
                // get auth token and try to execute a user.get (dummy check)
                $this->authToken = file_get_contents($tokenCacheFile);
                $this->userGet();
            } catch (Exception $e) {
                // user.get failed, token invalid so reset it and remove file
                $this->authToken = '';
                unlink($tokenCacheFile);
            }
        }

        // no cached token found so far, so login (again)
        if (!$this->authToken) {
            // login to get the auth token
            $params          = $this->getRequestParamsArray($params);
            $this->authToken = $this->request('user.login', $params, $arrayKeyProperty, false);

            // save cached auth token
            if (isset($tokenCacheFile)) {
                file_put_contents($tokenCacheFile, $this->authToken);
                chmod($tokenCacheFile, 0600);
            }
        }

        return $this->authToken;
    }

    /**
     * @brief   Logout from the API.
     *
     * This will also reset the auth Token.
     *
     * The $params Array can be used, to pass parameters to the Zabbix API.
     * For more informations about these parameters, check the Zabbix API
     * documentation at https://www.zabbix.com/documentation/.
     *
     * The $arrayKeyProperty can be used to get an associatve instead of an
     * indexed array as response. A valid value for the $arrayKeyProperty is
     * is any property of the returned JSON objects (e.g. name, host,
     * hostid, graphid, screenitemid).
     *
     * @param   $params             Parameters to pass through.
     * @param   $arrayKeyProperty   Object property for key of array.
     *
     * @retval  stdClass
     *
     * @throws  Exception
     */

    final public function userLogout($params=array(), $arrayKeyProperty='')
    {
        $params          = $this->getRequestParamsArray($params);
        $response        = $this->request('user.logout', $params, $arrayKeyProperty);
        $this->authToken = '';
        return $response;
    }

    
    /**
     * @brief   Reqeusts the Zabbix API and returns the response of the API
     *          method api.tableName.
     *
     * The $params Array can be used, to pass parameters to the Zabbix API.
     * For more informations about these parameters, check the Zabbix API
     * documentation at https://www.zabbix.com/documentation/.
     *
     * The $arrayKeyProperty can be used to get an associatve instead of an
     * indexed array as response. A valid value for the $arrayKeyProperty is
     * is any property of the returned JSON objects (e.g. name, host,
     * hostid, graphid, screenitemid).
     *
     * @param   $params             Zabbix API parameters.
     * @param   $arrayKeyProperty   Object property for key of array.
     *
     * @retval  stdClass
     *
     * @throws  Exception
     */

    public function apiTableName($params=array(), $arrayKeyProperty='')
    {
        // get params array for request
        $params = $this->getRequestParamsArray($params);

        // check if we've to authenticate
        $auth = in_array('api.tableName', self::$anonymousFunctions) ? false : true;

        // request
        return $this->request('api.tableName', $params, $arrayKeyProperty, $auth);
    }
    
    /**
     * @brief   Reqeusts the Zabbix API and returns the response of the API
     *          method api.pk.
     *
     * The $params Array can be used, to pass parameters to the Zabbix API.
     * For more informations about these parameters, check the Zabbix API
     * documentation at https://www.zabbix.com/documentation/.
     *
     * The $arrayKeyProperty can be used to get an associatve instead of an
     * indexed array as response. A valid value for the $arrayKeyProperty is
     * is any property of the returned JSON objects (e.g. name, host,
     * hostid, graphid, screenitemid).
     *
     * @param   $params             Zabbix API parameters.
     * @param   $arrayKeyProperty   Object property for key of array.
     *
     * @retval  stdClass
     *
     * @throws  Exception
     */

    public function apiPk($params=array(), $arrayKeyProperty='')
    {
        // get params array for request
        $params = $this->getRequestParamsArray($params);

        // check if we've to authenticate
        $auth = in_array('api.pk', self::$anonymousFunctions) ? false : true;

        // request
        return $this->request('api.pk', $params, $arrayKeyProperty, $auth);
    }
    
    /**
     * @brief   Reqeusts the Zabbix API and returns the response of the API
     *          method api.pkOption.
     *
     * The $params Array can be used, to pass parameters to the Zabbix API.
     * For more informations about these parameters, check the Zabbix API
     * documentation at https://www.zabbix.com/documentation/.
     *
     * The $arrayKeyProperty can be used to get an associatve instead of an
     * indexed array as response. A valid value for the $arrayKeyProperty is
     * is any property of the returned JSON objects (e.g. name, host,
     * hostid, graphid, screenitemid).
     *
     * @param   $params             Zabbix API parameters.
     * @param   $arrayKeyProperty   Object property for key of array.
     *
     * @retval  stdClass
     *
     * @throws  Exception
     */

    public function apiPkOption($params=array(), $arrayKeyProperty='')
    {
        // get params array for request
        $params = $this->getRequestParamsArray($params);

        // check if we've to authenticate
        $auth = in_array('api.pkOption', self::$anonymousFunctions) ? false : true;

        // request
        return $this->request('api.pkOption', $params, $arrayKeyProperty, $auth);
    }
    
    /**
     * @brief   Reqeusts the Zabbix API and returns the response of the API
     *          method action.get.
     *
     * The $params Array can be used, to pass parameters to the Zabbix API.
     * For more informations about these parameters, check the Zabbix API
     * documentation at https://www.zabbix.com/documentation/.
     *
     * The $arrayKeyProperty can be used to get an associatve instead of an
     * indexed array as response. A valid value for the $arrayKeyProperty is
     * is any property of the returned JSON objects (e.g. name, host,
     * hostid, graphid, screenitemid).
     *
     * @param   $params             Zabbix API parameters.
     * @param   $arrayKeyProperty   Object property for key of array.
     *
     * @retval  stdClass
     *
     * @throws  Exception
     */

    public function actionGet($params=array(), $arrayKeyProperty='')
    {
        // get params array for request
        $params = $this->getRequestParamsArray($params);

        // check if we've to authenticate
        $auth = in_array('action.get', self::$anonymousFunctions) ? false : true;

        // request
        return $this->request('action.get', $params, $arrayKeyProperty, $auth);
    }
    
    /**
     * @brief   Reqeusts the Zabbix API and returns the response of the API
     *          method action.create.
     *
     * The $params Array can be used, to pass parameters to the Zabbix API.
     * For more informations about these parameters, check the Zabbix API
     * documentation at https://www.zabbix.com/documentation/.
     *
     * The $arrayKeyProperty can be used to get an associatve instead of an
     * indexed array as response. A valid value for the $arrayKeyProperty is
     * is any property of the returned JSON objects (e.g. name, host,
     * hostid, graphid, screenitemid).
     *
     * @param   $params             Zabbix API parameters.
     * @param   $arrayKeyProperty   Object property for key of array.
     *
     * @retval  stdClass
     *
     * @throws  Exception
     */

    public function actionCreate($params=array(), $arrayKeyProperty='')
    {
        // get params array for request
        $params = $this->getRequestParamsArray($params);

        // check if we've to authenticate
        $auth = in_array('action.create', self::$anonymousFunctions) ? false : true;

        // request
        return $this->request('action.create', $params, $arrayKeyProperty, $auth);
    }
    
    /**
     * @brief   Reqeusts the Zabbix API and returns the response of the API
     *          method action.update.
     *
     * The $params Array can be used, to pass parameters to the Zabbix API.
     * For more informations about these parameters, check the Zabbix API
     * documentation at https://www.zabbix.com/documentation/.
     *
     * The $arrayKeyProperty can be used to get an associatve instead of an
     * indexed array as response. A valid value for the $arrayKeyProperty is
     * is any property of the returned JSON objects (e.g. name, host,
     * hostid, graphid, screenitemid).
     *
     * @param   $params             Zabbix API parameters.
     * @param   $arrayKeyProperty   Object property for key of array.
     *
     * @retval  stdClass
     *
     * @throws  Exception
     */

    public function actionUpdate($params=array(), $arrayKeyProperty='')
    {
        // get params array for request
        $params = $this->getRequestParamsArray($params);

        // check if we've to authenticate
        $auth = in_array('action.update', self::$anonymousFunctions) ? false : true;

        // request
        return $this->request('action.update', $params, $arrayKeyProperty, $auth);
    }
    
    /**
     * @brief   Reqeusts the Zabbix API and returns the response of the API
     *          method action.delete.
     *
     * The $params Array can be used, to pass parameters to the Zabbix API.
     * For more informations about these parameters, check the Zabbix API
     * documentation at https://www.zabbix.com/documentation/.
     *
     * The $arrayKeyProperty can be used to get an associatve instead of an
     * indexed array as response. A valid value for the $arrayKeyProperty is
     * is any property of the returned JSON objects (e.g. name, host,
     * hostid, graphid, screenitemid).
     *
     * @param   $params             Zabbix API parameters.
     * @param   $arrayKeyProperty   Object property for key of array.
     *
     * @retval  stdClass
     *
     * @throws  Exception
     */

    public function actionDelete($params=array(), $arrayKeyProperty='')
    {
        // get params array for request
        $params = $this->getRequestParamsArray($params);

        // check if we've to authenticate
        $auth = in_array('action.delete', self::$anonymousFunctions) ? false : true;

        // request
        return $this->request('action.delete', $params, $arrayKeyProperty, $auth);
    }
    
    /**
     * @brief   Reqeusts the Zabbix API and returns the response of the API
     *          method action.validateOperationsIntegrity.
     *
     * The $params Array can be used, to pass parameters to the Zabbix API.
     * For more informations about these parameters, check the Zabbix API
     * documentation at https://www.zabbix.com/documentation/.
     *
     * The $arrayKeyProperty can be used to get an associatve instead of an
     * indexed array as response. A valid value for the $arrayKeyProperty is
     * is any property of the returned JSON objects (e.g. name, host,
     * hostid, graphid, screenitemid).
     *
     * @param   $params             Zabbix API parameters.
     * @param   $arrayKeyProperty   Object property for key of array.
     *
     * @retval  stdClass
     *
     * @throws  Exception
     */

    public function actionValidateOperationsIntegrity($params=array(), $arrayKeyProperty='')
    {
        // get params array for request
        $params = $this->getRequestParamsArray($params);

        // check if we've to authenticate
        $auth = in_array('action.validateOperationsIntegrity', self::$anonymousFunctions) ? false : true;

        // request
        return $this->request('action.validateOperationsIntegrity', $params, $arrayKeyProperty, $auth);
    }
    
    /**
     * @brief   Reqeusts the Zabbix API and returns the response of the API
     *          method action.validateOperationConditions.
     *
     * The $params Array can be used, to pass parameters to the Zabbix API.
     * For more informations about these parameters, check the Zabbix API
     * documentation at https://www.zabbix.com/documentation/.
     *
     * The $arrayKeyProperty can be used to get an associatve instead of an
     * indexed array as response. A valid value for the $arrayKeyProperty is
     * is any property of the returned JSON objects (e.g. name, host,
     * hostid, graphid, screenitemid).
     *
     * @param   $params             Zabbix API parameters.
     * @param   $arrayKeyProperty   Object property for key of array.
     *
     * @retval  stdClass
     *
     * @throws  Exception
     */

    public function actionValidateOperationConditions($params=array(), $arrayKeyProperty='')
    {
        // get params array for request
        $params = $this->getRequestParamsArray($params);

        // check if we've to authenticate
        $auth = in_array('action.validateOperationConditions', self::$anonymousFunctions) ? false : true;

        // request
        return $this->request('action.validateOperationConditions', $params, $arrayKeyProperty, $auth);
    }
    
    /**
     * @brief   Reqeusts the Zabbix API and returns the response of the API
     *          method action.tableName.
     *
     * The $params Array can be used, to pass parameters to the Zabbix API.
     * For more informations about these parameters, check the Zabbix API
     * documentation at https://www.zabbix.com/documentation/.
     *
     * The $arrayKeyProperty can be used to get an associatve instead of an
     * indexed array as response. A valid value for the $arrayKeyProperty is
     * is any property of the returned JSON objects (e.g. name, host,
     * hostid, graphid, screenitemid).
     *
     * @param   $params             Zabbix API parameters.
     * @param   $arrayKeyProperty   Object property for key of array.
     *
     * @retval  stdClass
     *
     * @throws  Exception
     */

    public function actionTableName($params=array(), $arrayKeyProperty='')
    {
        // get params array for request
        $params = $this->getRequestParamsArray($params);

        // check if we've to authenticate
        $auth = in_array('action.tableName', self::$anonymousFunctions) ? false : true;

        // request
        return $this->request('action.tableName', $params, $arrayKeyProperty, $auth);
    }
    
    /**
     * @brief   Reqeusts the Zabbix API and returns the response of the API
     *          method action.pk.
     *
     * The $params Array can be used, to pass parameters to the Zabbix API.
     * For more informations about these parameters, check the Zabbix API
     * documentation at https://www.zabbix.com/documentation/.
     *
     * The $arrayKeyProperty can be used to get an associatve instead of an
     * indexed array as response. A valid value for the $arrayKeyProperty is
     * is any property of the returned JSON objects (e.g. name, host,
     * hostid, graphid, screenitemid).
     *
     * @param   $params             Zabbix API parameters.
     * @param   $arrayKeyProperty   Object property for key of array.
     *
     * @retval  stdClass
     *
     * @throws  Exception
     */

    public function actionPk($params=array(), $arrayKeyProperty='')
    {
        // get params array for request
        $params = $this->getRequestParamsArray($params);

        // check if we've to authenticate
        $auth = in_array('action.pk', self::$anonymousFunctions) ? false : true;

        // request
        return $this->request('action.pk', $params, $arrayKeyProperty, $auth);
    }
    
    /**
     * @brief   Reqeusts the Zabbix API and returns the response of the API
     *          method action.pkOption.
     *
     * The $params Array can be used, to pass parameters to the Zabbix API.
     * For more informations about these parameters, check the Zabbix API
     * documentation at https://www.zabbix.com/documentation/.
     *
     * The $arrayKeyProperty can be used to get an associatve instead of an
     * indexed array as response. A valid value for the $arrayKeyProperty is
     * is any property of the returned JSON objects (e.g. name, host,
     * hostid, graphid, screenitemid).
     *
     * @param   $params             Zabbix API parameters.
     * @param   $arrayKeyProperty   Object property for key of array.
     *
     * @retval  stdClass
     *
     * @throws  Exception
     */

    public function actionPkOption($params=array(), $arrayKeyProperty='')
    {
        // get params array for request
        $params = $this->getRequestParamsArray($params);

        // check if we've to authenticate
        $auth = in_array('action.pkOption', self::$anonymousFunctions) ? false : true;

        // request
        return $this->request('action.pkOption', $params, $arrayKeyProperty, $auth);
    }
    
    /**
     * @brief   Reqeusts the Zabbix API and returns the response of the API
     *          method alert.get.
     *
     * The $params Array can be used, to pass parameters to the Zabbix API.
     * For more informations about these parameters, check the Zabbix API
     * documentation at https://www.zabbix.com/documentation/.
     *
     * The $arrayKeyProperty can be used to get an associatve instead of an
     * indexed array as response. A valid value for the $arrayKeyProperty is
     * is any property of the returned JSON objects (e.g. name, host,
     * hostid, graphid, screenitemid).
     *
     * @param   $params             Zabbix API parameters.
     * @param   $arrayKeyProperty   Object property for key of array.
     *
     * @retval  stdClass
     *
     * @throws  Exception
     */

    public function alertGet($params=array(), $arrayKeyProperty='')
    {
        // get params array for request
        $params = $this->getRequestParamsArray($params);

        // check if we've to authenticate
        $auth = in_array('alert.get', self::$anonymousFunctions) ? false : true;

        // request
        return $this->request('alert.get', $params, $arrayKeyProperty, $auth);
    }
    
    /**
     * @brief   Reqeusts the Zabbix API and returns the response of the API
     *          method alert.tableName.
     *
     * The $params Array can be used, to pass parameters to the Zabbix API.
     * For more informations about these parameters, check the Zabbix API
     * documentation at https://www.zabbix.com/documentation/.
     *
     * The $arrayKeyProperty can be used to get an associatve instead of an
     * indexed array as response. A valid value for the $arrayKeyProperty is
     * is any property of the returned JSON objects (e.g. name, host,
     * hostid, graphid, screenitemid).
     *
     * @param   $params             Zabbix API parameters.
     * @param   $arrayKeyProperty   Object property for key of array.
     *
     * @retval  stdClass
     *
     * @throws  Exception
     */

    public function alertTableName($params=array(), $arrayKeyProperty='')
    {
        // get params array for request
        $params = $this->getRequestParamsArray($params);

        // check if we've to authenticate
        $auth = in_array('alert.tableName', self::$anonymousFunctions) ? false : true;

        // request
        return $this->request('alert.tableName', $params, $arrayKeyProperty, $auth);
    }
    
    /**
     * @brief   Reqeusts the Zabbix API and returns the response of the API
     *          method alert.pk.
     *
     * The $params Array can be used, to pass parameters to the Zabbix API.
     * For more informations about these parameters, check the Zabbix API
     * documentation at https://www.zabbix.com/documentation/.
     *
     * The $arrayKeyProperty can be used to get an associatve instead of an
     * indexed array as response. A valid value for the $arrayKeyProperty is
     * is any property of the returned JSON objects (e.g. name, host,
     * hostid, graphid, screenitemid).
     *
     * @param   $params             Zabbix API parameters.
     * @param   $arrayKeyProperty   Object property for key of array.
     *
     * @retval  stdClass
     *
     * @throws  Exception
     */

    public function alertPk($params=array(), $arrayKeyProperty='')
    {
        // get params array for request
        $params = $this->getRequestParamsArray($params);

        // check if we've to authenticate
        $auth = in_array('alert.pk', self::$anonymousFunctions) ? false : true;

        // request
        return $this->request('alert.pk', $params, $arrayKeyProperty, $auth);
    }
    
    /**
     * @brief   Reqeusts the Zabbix API and returns the response of the API
     *          method alert.pkOption.
     *
     * The $params Array can be used, to pass parameters to the Zabbix API.
     * For more informations about these parameters, check the Zabbix API
     * documentation at https://www.zabbix.com/documentation/.
     *
     * The $arrayKeyProperty can be used to get an associatve instead of an
     * indexed array as response. A valid value for the $arrayKeyProperty is
     * is any property of the returned JSON objects (e.g. name, host,
     * hostid, graphid, screenitemid).
     *
     * @param   $params             Zabbix API parameters.
     * @param   $arrayKeyProperty   Object property for key of array.
     *
     * @retval  stdClass
     *
     * @throws  Exception
     */

    public function alertPkOption($params=array(), $arrayKeyProperty='')
    {
        // get params array for request
        $params = $this->getRequestParamsArray($params);

        // check if we've to authenticate
        $auth = in_array('alert.pkOption', self::$anonymousFunctions) ? false : true;

        // request
        return $this->request('alert.pkOption', $params, $arrayKeyProperty, $auth);
    }
    
    /**
     * @brief   Reqeusts the Zabbix API and returns the response of the API
     *          method apiinfo.version.
     *
     * The $params Array can be used, to pass parameters to the Zabbix API.
     * For more informations about these parameters, check the Zabbix API
     * documentation at https://www.zabbix.com/documentation/.
     *
     * The $arrayKeyProperty can be used to get an associatve instead of an
     * indexed array as response. A valid value for the $arrayKeyProperty is
     * is any property of the returned JSON objects (e.g. name, host,
     * hostid, graphid, screenitemid).
     *
     * @param   $params             Zabbix API parameters.
     * @param   $arrayKeyProperty   Object property for key of array.
     *
     * @retval  stdClass
     *
     * @throws  Exception
     */

    public function apiinfoVersion($params=array(), $arrayKeyProperty='')
    {
        // get params array for request
        $params = $this->getRequestParamsArray($params);

        // check if we've to authenticate
        $auth = in_array('apiinfo.version', self::$anonymousFunctions) ? false : true;

        // request
        return $this->request('apiinfo.version', $params, $arrayKeyProperty, $auth);
    }
    
    /**
     * @brief   Reqeusts the Zabbix API and returns the response of the API
     *          method apiinfo.tableName.
     *
     * The $params Array can be used, to pass parameters to the Zabbix API.
     * For more informations about these parameters, check the Zabbix API
     * documentation at https://www.zabbix.com/documentation/.
     *
     * The $arrayKeyProperty can be used to get an associatve instead of an
     * indexed array as response. A valid value for the $arrayKeyProperty is
     * is any property of the returned JSON objects (e.g. name, host,
     * hostid, graphid, screenitemid).
     *
     * @param   $params             Zabbix API parameters.
     * @param   $arrayKeyProperty   Object property for key of array.
     *
     * @retval  stdClass
     *
     * @throws  Exception
     */

    public function apiinfoTableName($params=array(), $arrayKeyProperty='')
    {
        // get params array for request
        $params = $this->getRequestParamsArray($params);

        // check if we've to authenticate
        $auth = in_array('apiinfo.tableName', self::$anonymousFunctions) ? false : true;

        // request
        return $this->request('apiinfo.tableName', $params, $arrayKeyProperty, $auth);
    }
    
    /**
     * @brief   Reqeusts the Zabbix API and returns the response of the API
     *          method apiinfo.pk.
     *
     * The $params Array can be used, to pass parameters to the Zabbix API.
     * For more informations about these parameters, check the Zabbix API
     * documentation at https://www.zabbix.com/documentation/.
     *
     * The $arrayKeyProperty can be used to get an associatve instead of an
     * indexed array as response. A valid value for the $arrayKeyProperty is
     * is any property of the returned JSON objects (e.g. name, host,
     * hostid, graphid, screenitemid).
     *
     * @param   $params             Zabbix API parameters.
     * @param   $arrayKeyProperty   Object property for key of array.
     *
     * @retval  stdClass
     *
     * @throws  Exception
     */

    public function apiinfoPk($params=array(), $arrayKeyProperty='')
    {
        // get params array for request
        $params = $this->getRequestParamsArray($params);

        // check if we've to authenticate
        $auth = in_array('apiinfo.pk', self::$anonymousFunctions) ? false : true;

        // request
        return $this->request('apiinfo.pk', $params, $arrayKeyProperty, $auth);
    }
    
    /**
     * @brief   Reqeusts the Zabbix API and returns the response of the API
     *          method apiinfo.pkOption.
     *
     * The $params Array can be used, to pass parameters to the Zabbix API.
     * For more informations about these parameters, check the Zabbix API
     * documentation at https://www.zabbix.com/documentation/.
     *
     * The $arrayKeyProperty can be used to get an associatve instead of an
     * indexed array as response. A valid value for the $arrayKeyProperty is
     * is any property of the returned JSON objects (e.g. name, host,
     * hostid, graphid, screenitemid).
     *
     * @param   $params             Zabbix API parameters.
     * @param   $arrayKeyProperty   Object property for key of array.
     *
     * @retval  stdClass
     *
     * @throws  Exception
     */

    public function apiinfoPkOption($params=array(), $arrayKeyProperty='')
    {
        // get params array for request
        $params = $this->getRequestParamsArray($params);

        // check if we've to authenticate
        $auth = in_array('apiinfo.pkOption', self::$anonymousFunctions) ? false : true;

        // request
        return $this->request('apiinfo.pkOption', $params, $arrayKeyProperty, $auth);
    }
    
    /**
     * @brief   Reqeusts the Zabbix API and returns the response of the API
     *          method application.get.
     *
     * The $params Array can be used, to pass parameters to the Zabbix API.
     * For more informations about these parameters, check the Zabbix API
     * documentation at https://www.zabbix.com/documentation/.
     *
     * The $arrayKeyProperty can be used to get an associatve instead of an
     * indexed array as response. A valid value for the $arrayKeyProperty is
     * is any property of the returned JSON objects (e.g. name, host,
     * hostid, graphid, screenitemid).
     *
     * @param   $params             Zabbix API parameters.
     * @param   $arrayKeyProperty   Object property for key of array.
     *
     * @retval  stdClass
     *
     * @throws  Exception
     */

    public function applicationGet($params=array(), $arrayKeyProperty='')
    {
        // get params array for request
        $params = $this->getRequestParamsArray($params);

        // check if we've to authenticate
        $auth = in_array('application.get', self::$anonymousFunctions) ? false : true;

        // request
        return $this->request('application.get', $params, $arrayKeyProperty, $auth);
    }
    
    /**
     * @brief   Reqeusts the Zabbix API and returns the response of the API
     *          method application.checkInput.
     *
     * The $params Array can be used, to pass parameters to the Zabbix API.
     * For more informations about these parameters, check the Zabbix API
     * documentation at https://www.zabbix.com/documentation/.
     *
     * The $arrayKeyProperty can be used to get an associatve instead of an
     * indexed array as response. A valid value for the $arrayKeyProperty is
     * is any property of the returned JSON objects (e.g. name, host,
     * hostid, graphid, screenitemid).
     *
     * @param   $params             Zabbix API parameters.
     * @param   $arrayKeyProperty   Object property for key of array.
     *
     * @retval  stdClass
     *
     * @throws  Exception
     */

    public function applicationCheckInput($params=array(), $arrayKeyProperty='')
    {
        // get params array for request
        $params = $this->getRequestParamsArray($params);

        // check if we've to authenticate
        $auth = in_array('application.checkInput', self::$anonymousFunctions) ? false : true;

        // request
        return $this->request('application.checkInput', $params, $arrayKeyProperty, $auth);
    }
    
    /**
     * @brief   Reqeusts the Zabbix API and returns the response of the API
     *          method application.create.
     *
     * The $params Array can be used, to pass parameters to the Zabbix API.
     * For more informations about these parameters, check the Zabbix API
     * documentation at https://www.zabbix.com/documentation/.
     *
     * The $arrayKeyProperty can be used to get an associatve instead of an
     * indexed array as response. A valid value for the $arrayKeyProperty is
     * is any property of the returned JSON objects (e.g. name, host,
     * hostid, graphid, screenitemid).
     *
     * @param   $params             Zabbix API parameters.
     * @param   $arrayKeyProperty   Object property for key of array.
     *
     * @retval  stdClass
     *
     * @throws  Exception
     */

    public function applicationCreate($params=array(), $arrayKeyProperty='')
    {
        // get params array for request
        $params = $this->getRequestParamsArray($params);

        // check if we've to authenticate
        $auth = in_array('application.create', self::$anonymousFunctions) ? false : true;

        // request
        return $this->request('application.create', $params, $arrayKeyProperty, $auth);
    }
    
    /**
     * @brief   Reqeusts the Zabbix API and returns the response of the API
     *          method application.update.
     *
     * The $params Array can be used, to pass parameters to the Zabbix API.
     * For more informations about these parameters, check the Zabbix API
     * documentation at https://www.zabbix.com/documentation/.
     *
     * The $arrayKeyProperty can be used to get an associatve instead of an
     * indexed array as response. A valid value for the $arrayKeyProperty is
     * is any property of the returned JSON objects (e.g. name, host,
     * hostid, graphid, screenitemid).
     *
     * @param   $params             Zabbix API parameters.
     * @param   $arrayKeyProperty   Object property for key of array.
     *
     * @retval  stdClass
     *
     * @throws  Exception
     */

    public function applicationUpdate($params=array(), $arrayKeyProperty='')
    {
        // get params array for request
        $params = $this->getRequestParamsArray($params);

        // check if we've to authenticate
        $auth = in_array('application.update', self::$anonymousFunctions) ? false : true;

        // request
        return $this->request('application.update', $params, $arrayKeyProperty, $auth);
    }
    
    /**
     * @brief   Reqeusts the Zabbix API and returns the response of the API
     *          method application.delete.
     *
     * The $params Array can be used, to pass parameters to the Zabbix API.
     * For more informations about these parameters, check the Zabbix API
     * documentation at https://www.zabbix.com/documentation/.
     *
     * The $arrayKeyProperty can be used to get an associatve instead of an
     * indexed array as response. A valid value for the $arrayKeyProperty is
     * is any property of the returned JSON objects (e.g. name, host,
     * hostid, graphid, screenitemid).
     *
     * @param   $params             Zabbix API parameters.
     * @param   $arrayKeyProperty   Object property for key of array.
     *
     * @retval  stdClass
     *
     * @throws  Exception
     */

    public function applicationDelete($params=array(), $arrayKeyProperty='')
    {
        // get params array for request
        $params = $this->getRequestParamsArray($params);

        // check if we've to authenticate
        $auth = in_array('application.delete', self::$anonymousFunctions) ? false : true;

        // request
        return $this->request('application.delete', $params, $arrayKeyProperty, $auth);
    }
    
    /**
     * @brief   Reqeusts the Zabbix API and returns the response of the API
     *          method application.massAdd.
     *
     * The $params Array can be used, to pass parameters to the Zabbix API.
     * For more informations about these parameters, check the Zabbix API
     * documentation at https://www.zabbix.com/documentation/.
     *
     * The $arrayKeyProperty can be used to get an associatve instead of an
     * indexed array as response. A valid value for the $arrayKeyProperty is
     * is any property of the returned JSON objects (e.g. name, host,
     * hostid, graphid, screenitemid).
     *
     * @param   $params             Zabbix API parameters.
     * @param   $arrayKeyProperty   Object property for key of array.
     *
     * @retval  stdClass
     *
     * @throws  Exception
     */

    public function applicationMassAdd($params=array(), $arrayKeyProperty='')
    {
        // get params array for request
        $params = $this->getRequestParamsArray($params);

        // check if we've to authenticate
        $auth = in_array('application.massAdd', self::$anonymousFunctions) ? false : true;

        // request
        return $this->request('application.massAdd', $params, $arrayKeyProperty, $auth);
    }
    
    /**
     * @brief   Reqeusts the Zabbix API and returns the response of the API
     *          method application.tableName.
     *
     * The $params Array can be used, to pass parameters to the Zabbix API.
     * For more informations about these parameters, check the Zabbix API
     * documentation at https://www.zabbix.com/documentation/.
     *
     * The $arrayKeyProperty can be used to get an associatve instead of an
     * indexed array as response. A valid value for the $arrayKeyProperty is
     * is any property of the returned JSON objects (e.g. name, host,
     * hostid, graphid, screenitemid).
     *
     * @param   $params             Zabbix API parameters.
     * @param   $arrayKeyProperty   Object property for key of array.
     *
     * @retval  stdClass
     *
     * @throws  Exception
     */

    public function applicationTableName($params=array(), $arrayKeyProperty='')
    {
        // get params array for request
        $params = $this->getRequestParamsArray($params);

        // check if we've to authenticate
        $auth = in_array('application.tableName', self::$anonymousFunctions) ? false : true;

        // request
        return $this->request('application.tableName', $params, $arrayKeyProperty, $auth);
    }
    
    /**
     * @brief   Reqeusts the Zabbix API and returns the response of the API
     *          method application.pk.
     *
     * The $params Array can be used, to pass parameters to the Zabbix API.
     * For more informations about these parameters, check the Zabbix API
     * documentation at https://www.zabbix.com/documentation/.
     *
     * The $arrayKeyProperty can be used to get an associatve instead of an
     * indexed array as response. A valid value for the $arrayKeyProperty is
     * is any property of the returned JSON objects (e.g. name, host,
     * hostid, graphid, screenitemid).
     *
     * @param   $params             Zabbix API parameters.
     * @param   $arrayKeyProperty   Object property for key of array.
     *
     * @retval  stdClass
     *
     * @throws  Exception
     */

    public function applicationPk($params=array(), $arrayKeyProperty='')
    {
        // get params array for request
        $params = $this->getRequestParamsArray($params);

        // check if we've to authenticate
        $auth = in_array('application.pk', self::$anonymousFunctions) ? false : true;

        // request
        return $this->request('application.pk', $params, $arrayKeyProperty, $auth);
    }
    
    /**
     * @brief   Reqeusts the Zabbix API and returns the response of the API
     *          method application.pkOption.
     *
     * The $params Array can be used, to pass parameters to the Zabbix API.
     * For more informations about these parameters, check the Zabbix API
     * documentation at https://www.zabbix.com/documentation/.
     *
     * The $arrayKeyProperty can be used to get an associatve instead of an
     * indexed array as response. A valid value for the $arrayKeyProperty is
     * is any property of the returned JSON objects (e.g. name, host,
     * hostid, graphid, screenitemid).
     *
     * @param   $params             Zabbix API parameters.
     * @param   $arrayKeyProperty   Object property for key of array.
     *
     * @retval  stdClass
     *
     * @throws  Exception
     */

    public function applicationPkOption($params=array(), $arrayKeyProperty='')
    {
        // get params array for request
        $params = $this->getRequestParamsArray($params);

        // check if we've to authenticate
        $auth = in_array('application.pkOption', self::$anonymousFunctions) ? false : true;

        // request
        return $this->request('application.pkOption', $params, $arrayKeyProperty, $auth);
    }
    
    /**
     * @brief   Reqeusts the Zabbix API and returns the response of the API
     *          method configuration.export.
     *
     * The $params Array can be used, to pass parameters to the Zabbix API.
     * For more informations about these parameters, check the Zabbix API
     * documentation at https://www.zabbix.com/documentation/.
     *
     * The $arrayKeyProperty can be used to get an associatve instead of an
     * indexed array as response. A valid value for the $arrayKeyProperty is
     * is any property of the returned JSON objects (e.g. name, host,
     * hostid, graphid, screenitemid).
     *
     * @param   $params             Zabbix API parameters.
     * @param   $arrayKeyProperty   Object property for key of array.
     *
     * @retval  stdClass
     *
     * @throws  Exception
     */

    public function configurationExport($params=array(), $arrayKeyProperty='')
    {
        // get params array for request
        $params = $this->getRequestParamsArray($params);

        // check if we've to authenticate
        $auth = in_array('configuration.export', self::$anonymousFunctions) ? false : true;

        // request
        return $this->request('configuration.export', $params, $arrayKeyProperty, $auth);
    }
    
    /**
     * @brief   Reqeusts the Zabbix API and returns the response of the API
     *          method configuration.import.
     *
     * The $params Array can be used, to pass parameters to the Zabbix API.
     * For more informations about these parameters, check the Zabbix API
     * documentation at https://www.zabbix.com/documentation/.
     *
     * The $arrayKeyProperty can be used to get an associatve instead of an
     * indexed array as response. A valid value for the $arrayKeyProperty is
     * is any property of the returned JSON objects (e.g. name, host,
     * hostid, graphid, screenitemid).
     *
     * @param   $params             Zabbix API parameters.
     * @param   $arrayKeyProperty   Object property for key of array.
     *
     * @retval  stdClass
     *
     * @throws  Exception
     */

    public function configurationImport($params=array(), $arrayKeyProperty='')
    {
        // get params array for request
        $params = $this->getRequestParamsArray($params);

        // check if we've to authenticate
        $auth = in_array('configuration.import', self::$anonymousFunctions) ? false : true;

        // request
        return $this->request('configuration.import', $params, $arrayKeyProperty, $auth);
    }
    
    /**
     * @brief   Reqeusts the Zabbix API and returns the response of the API
     *          method configuration.tableName.
     *
     * The $params Array can be used, to pass parameters to the Zabbix API.
     * For more informations about these parameters, check the Zabbix API
     * documentation at https://www.zabbix.com/documentation/.
     *
     * The $arrayKeyProperty can be used to get an associatve instead of an
     * indexed array as response. A valid value for the $arrayKeyProperty is
     * is any property of the returned JSON objects (e.g. name, host,
     * hostid, graphid, screenitemid).
     *
     * @param   $params             Zabbix API parameters.
     * @param   $arrayKeyProperty   Object property for key of array.
     *
     * @retval  stdClass
     *
     * @throws  Exception
     */

    public function configurationTableName($params=array(), $arrayKeyProperty='')
    {
        // get params array for request
        $params = $this->getRequestParamsArray($params);

        // check if we've to authenticate
        $auth = in_array('configuration.tableName', self::$anonymousFunctions) ? false : true;

        // request
        return $this->request('configuration.tableName', $params, $arrayKeyProperty, $auth);
    }
    
    /**
     * @brief   Reqeusts the Zabbix API and returns the response of the API
     *          method configuration.pk.
     *
     * The $params Array can be used, to pass parameters to the Zabbix API.
     * For more informations about these parameters, check the Zabbix API
     * documentation at https://www.zabbix.com/documentation/.
     *
     * The $arrayKeyProperty can be used to get an associatve instead of an
     * indexed array as response. A valid value for the $arrayKeyProperty is
     * is any property of the returned JSON objects (e.g. name, host,
     * hostid, graphid, screenitemid).
     *
     * @param   $params             Zabbix API parameters.
     * @param   $arrayKeyProperty   Object property for key of array.
     *
     * @retval  stdClass
     *
     * @throws  Exception
     */

    public function configurationPk($params=array(), $arrayKeyProperty='')
    {
        // get params array for request
        $params = $this->getRequestParamsArray($params);

        // check if we've to authenticate
        $auth = in_array('configuration.pk', self::$anonymousFunctions) ? false : true;

        // request
        return $this->request('configuration.pk', $params, $arrayKeyProperty, $auth);
    }
    
    /**
     * @brief   Reqeusts the Zabbix API and returns the response of the API
     *          method configuration.pkOption.
     *
     * The $params Array can be used, to pass parameters to the Zabbix API.
     * For more informations about these parameters, check the Zabbix API
     * documentation at https://www.zabbix.com/documentation/.
     *
     * The $arrayKeyProperty can be used to get an associatve instead of an
     * indexed array as response. A valid value for the $arrayKeyProperty is
     * is any property of the returned JSON objects (e.g. name, host,
     * hostid, graphid, screenitemid).
     *
     * @param   $params             Zabbix API parameters.
     * @param   $arrayKeyProperty   Object property for key of array.
     *
     * @retval  stdClass
     *
     * @throws  Exception
     */

    public function configurationPkOption($params=array(), $arrayKeyProperty='')
    {
        // get params array for request
        $params = $this->getRequestParamsArray($params);

        // check if we've to authenticate
        $auth = in_array('configuration.pkOption', self::$anonymousFunctions) ? false : true;

        // request
        return $this->request('configuration.pkOption', $params, $arrayKeyProperty, $auth);
    }
    
    /**
     * @brief   Reqeusts the Zabbix API and returns the response of the API
     *          method dcheck.get.
     *
     * The $params Array can be used, to pass parameters to the Zabbix API.
     * For more informations about these parameters, check the Zabbix API
     * documentation at https://www.zabbix.com/documentation/.
     *
     * The $arrayKeyProperty can be used to get an associatve instead of an
     * indexed array as response. A valid value for the $arrayKeyProperty is
     * is any property of the returned JSON objects (e.g. name, host,
     * hostid, graphid, screenitemid).
     *
     * @param   $params             Zabbix API parameters.
     * @param   $arrayKeyProperty   Object property for key of array.
     *
     * @retval  stdClass
     *
     * @throws  Exception
     */

    public function dcheckGet($params=array(), $arrayKeyProperty='')
    {
        // get params array for request
        $params = $this->getRequestParamsArray($params);

        // check if we've to authenticate
        $auth = in_array('dcheck.get', self::$anonymousFunctions) ? false : true;

        // request
        return $this->request('dcheck.get', $params, $arrayKeyProperty, $auth);
    }
    
    /**
     * @brief   Reqeusts the Zabbix API and returns the response of the API
     *          method dcheck.isReadable.
     *
     * The $params Array can be used, to pass parameters to the Zabbix API.
     * For more informations about these parameters, check the Zabbix API
     * documentation at https://www.zabbix.com/documentation/.
     *
     * The $arrayKeyProperty can be used to get an associatve instead of an
     * indexed array as response. A valid value for the $arrayKeyProperty is
     * is any property of the returned JSON objects (e.g. name, host,
     * hostid, graphid, screenitemid).
     *
     * @param   $params             Zabbix API parameters.
     * @param   $arrayKeyProperty   Object property for key of array.
     *
     * @retval  stdClass
     *
     * @throws  Exception
     */

    public function dcheckIsReadable($params=array(), $arrayKeyProperty='')
    {
        // get params array for request
        $params = $this->getRequestParamsArray($params);

        // check if we've to authenticate
        $auth = in_array('dcheck.isReadable', self::$anonymousFunctions) ? false : true;

        // request
        return $this->request('dcheck.isReadable', $params, $arrayKeyProperty, $auth);
    }
    
    /**
     * @brief   Reqeusts the Zabbix API and returns the response of the API
     *          method dcheck.isWritable.
     *
     * The $params Array can be used, to pass parameters to the Zabbix API.
     * For more informations about these parameters, check the Zabbix API
     * documentation at https://www.zabbix.com/documentation/.
     *
     * The $arrayKeyProperty can be used to get an associatve instead of an
     * indexed array as response. A valid value for the $arrayKeyProperty is
     * is any property of the returned JSON objects (e.g. name, host,
     * hostid, graphid, screenitemid).
     *
     * @param   $params             Zabbix API parameters.
     * @param   $arrayKeyProperty   Object property for key of array.
     *
     * @retval  stdClass
     *
     * @throws  Exception
     */

    public function dcheckIsWritable($params=array(), $arrayKeyProperty='')
    {
        // get params array for request
        $params = $this->getRequestParamsArray($params);

        // check if we've to authenticate
        $auth = in_array('dcheck.isWritable', self::$anonymousFunctions) ? false : true;

        // request
        return $this->request('dcheck.isWritable', $params, $arrayKeyProperty, $auth);
    }
    
    /**
     * @brief   Reqeusts the Zabbix API and returns the response of the API
     *          method dcheck.tableName.
     *
     * The $params Array can be used, to pass parameters to the Zabbix API.
     * For more informations about these parameters, check the Zabbix API
     * documentation at https://www.zabbix.com/documentation/.
     *
     * The $arrayKeyProperty can be used to get an associatve instead of an
     * indexed array as response. A valid value for the $arrayKeyProperty is
     * is any property of the returned JSON objects (e.g. name, host,
     * hostid, graphid, screenitemid).
     *
     * @param   $params             Zabbix API parameters.
     * @param   $arrayKeyProperty   Object property for key of array.
     *
     * @retval  stdClass
     *
     * @throws  Exception
     */

    public function dcheckTableName($params=array(), $arrayKeyProperty='')
    {
        // get params array for request
        $params = $this->getRequestParamsArray($params);

        // check if we've to authenticate
        $auth = in_array('dcheck.tableName', self::$anonymousFunctions) ? false : true;

        // request
        return $this->request('dcheck.tableName', $params, $arrayKeyProperty, $auth);
    }
    
    /**
     * @brief   Reqeusts the Zabbix API and returns the response of the API
     *          method dcheck.pk.
     *
     * The $params Array can be used, to pass parameters to the Zabbix API.
     * For more informations about these parameters, check the Zabbix API
     * documentation at https://www.zabbix.com/documentation/.
     *
     * The $arrayKeyProperty can be used to get an associatve instead of an
     * indexed array as response. A valid value for the $arrayKeyProperty is
     * is any property of the returned JSON objects (e.g. name, host,
     * hostid, graphid, screenitemid).
     *
     * @param   $params             Zabbix API parameters.
     * @param   $arrayKeyProperty   Object property for key of array.
     *
     * @retval  stdClass
     *
     * @throws  Exception
     */

    public function dcheckPk($params=array(), $arrayKeyProperty='')
    {
        // get params array for request
        $params = $this->getRequestParamsArray($params);

        // check if we've to authenticate
        $auth = in_array('dcheck.pk', self::$anonymousFunctions) ? false : true;

        // request
        return $this->request('dcheck.pk', $params, $arrayKeyProperty, $auth);
    }
    
    /**
     * @brief   Reqeusts the Zabbix API and returns the response of the API
     *          method dcheck.pkOption.
     *
     * The $params Array can be used, to pass parameters to the Zabbix API.
     * For more informations about these parameters, check the Zabbix API
     * documentation at https://www.zabbix.com/documentation/.
     *
     * The $arrayKeyProperty can be used to get an associatve instead of an
     * indexed array as response. A valid value for the $arrayKeyProperty is
     * is any property of the returned JSON objects (e.g. name, host,
     * hostid, graphid, screenitemid).
     *
     * @param   $params             Zabbix API parameters.
     * @param   $arrayKeyProperty   Object property for key of array.
     *
     * @retval  stdClass
     *
     * @throws  Exception
     */

    public function dcheckPkOption($params=array(), $arrayKeyProperty='')
    {
        // get params array for request
        $params = $this->getRequestParamsArray($params);

        // check if we've to authenticate
        $auth = in_array('dcheck.pkOption', self::$anonymousFunctions) ? false : true;

        // request
        return $this->request('dcheck.pkOption', $params, $arrayKeyProperty, $auth);
    }
    
    /**
     * @brief   Reqeusts the Zabbix API and returns the response of the API
     *          method dhost.get.
     *
     * The $params Array can be used, to pass parameters to the Zabbix API.
     * For more informations about these parameters, check the Zabbix API
     * documentation at https://www.zabbix.com/documentation/.
     *
     * The $arrayKeyProperty can be used to get an associatve instead of an
     * indexed array as response. A valid value for the $arrayKeyProperty is
     * is any property of the returned JSON objects (e.g. name, host,
     * hostid, graphid, screenitemid).
     *
     * @param   $params             Zabbix API parameters.
     * @param   $arrayKeyProperty   Object property for key of array.
     *
     * @retval  stdClass
     *
     * @throws  Exception
     */

    public function dhostGet($params=array(), $arrayKeyProperty='')
    {
        // get params array for request
        $params = $this->getRequestParamsArray($params);

        // check if we've to authenticate
        $auth = in_array('dhost.get', self::$anonymousFunctions) ? false : true;

        // request
        return $this->request('dhost.get', $params, $arrayKeyProperty, $auth);
    }
    
    /**
     * @brief   Reqeusts the Zabbix API and returns the response of the API
     *          method dhost.tableName.
     *
     * The $params Array can be used, to pass parameters to the Zabbix API.
     * For more informations about these parameters, check the Zabbix API
     * documentation at https://www.zabbix.com/documentation/.
     *
     * The $arrayKeyProperty can be used to get an associatve instead of an
     * indexed array as response. A valid value for the $arrayKeyProperty is
     * is any property of the returned JSON objects (e.g. name, host,
     * hostid, graphid, screenitemid).
     *
     * @param   $params             Zabbix API parameters.
     * @param   $arrayKeyProperty   Object property for key of array.
     *
     * @retval  stdClass
     *
     * @throws  Exception
     */

    public function dhostTableName($params=array(), $arrayKeyProperty='')
    {
        // get params array for request
        $params = $this->getRequestParamsArray($params);

        // check if we've to authenticate
        $auth = in_array('dhost.tableName', self::$anonymousFunctions) ? false : true;

        // request
        return $this->request('dhost.tableName', $params, $arrayKeyProperty, $auth);
    }
    
    /**
     * @brief   Reqeusts the Zabbix API and returns the response of the API
     *          method dhost.pk.
     *
     * The $params Array can be used, to pass parameters to the Zabbix API.
     * For more informations about these parameters, check the Zabbix API
     * documentation at https://www.zabbix.com/documentation/.
     *
     * The $arrayKeyProperty can be used to get an associatve instead of an
     * indexed array as response. A valid value for the $arrayKeyProperty is
     * is any property of the returned JSON objects (e.g. name, host,
     * hostid, graphid, screenitemid).
     *
     * @param   $params             Zabbix API parameters.
     * @param   $arrayKeyProperty   Object property for key of array.
     *
     * @retval  stdClass
     *
     * @throws  Exception
     */

    public function dhostPk($params=array(), $arrayKeyProperty='')
    {
        // get params array for request
        $params = $this->getRequestParamsArray($params);

        // check if we've to authenticate
        $auth = in_array('dhost.pk', self::$anonymousFunctions) ? false : true;

        // request
        return $this->request('dhost.pk', $params, $arrayKeyProperty, $auth);
    }
    
    /**
     * @brief   Reqeusts the Zabbix API and returns the response of the API
     *          method dhost.pkOption.
     *
     * The $params Array can be used, to pass parameters to the Zabbix API.
     * For more informations about these parameters, check the Zabbix API
     * documentation at https://www.zabbix.com/documentation/.
     *
     * The $arrayKeyProperty can be used to get an associatve instead of an
     * indexed array as response. A valid value for the $arrayKeyProperty is
     * is any property of the returned JSON objects (e.g. name, host,
     * hostid, graphid, screenitemid).
     *
     * @param   $params             Zabbix API parameters.
     * @param   $arrayKeyProperty   Object property for key of array.
     *
     * @retval  stdClass
     *
     * @throws  Exception
     */

    public function dhostPkOption($params=array(), $arrayKeyProperty='')
    {
        // get params array for request
        $params = $this->getRequestParamsArray($params);

        // check if we've to authenticate
        $auth = in_array('dhost.pkOption', self::$anonymousFunctions) ? false : true;

        // request
        return $this->request('dhost.pkOption', $params, $arrayKeyProperty, $auth);
    }
    
    /**
     * @brief   Reqeusts the Zabbix API and returns the response of the API
     *          method discoveryrule.get.
     *
     * The $params Array can be used, to pass parameters to the Zabbix API.
     * For more informations about these parameters, check the Zabbix API
     * documentation at https://www.zabbix.com/documentation/.
     *
     * The $arrayKeyProperty can be used to get an associatve instead of an
     * indexed array as response. A valid value for the $arrayKeyProperty is
     * is any property of the returned JSON objects (e.g. name, host,
     * hostid, graphid, screenitemid).
     *
     * @param   $params             Zabbix API parameters.
     * @param   $arrayKeyProperty   Object property for key of array.
     *
     * @retval  stdClass
     *
     * @throws  Exception
     */

    public function discoveryruleGet($params=array(), $arrayKeyProperty='')
    {
        // get params array for request
        $params = $this->getRequestParamsArray($params);

        // check if we've to authenticate
        $auth = in_array('discoveryrule.get', self::$anonymousFunctions) ? false : true;

        // request
        return $this->request('discoveryrule.get', $params, $arrayKeyProperty, $auth);
    }
    
    /**
     * @brief   Reqeusts the Zabbix API and returns the response of the API
     *          method discoveryrule.create.
     *
     * The $params Array can be used, to pass parameters to the Zabbix API.
     * For more informations about these parameters, check the Zabbix API
     * documentation at https://www.zabbix.com/documentation/.
     *
     * The $arrayKeyProperty can be used to get an associatve instead of an
     * indexed array as response. A valid value for the $arrayKeyProperty is
     * is any property of the returned JSON objects (e.g. name, host,
     * hostid, graphid, screenitemid).
     *
     * @param   $params             Zabbix API parameters.
     * @param   $arrayKeyProperty   Object property for key of array.
     *
     * @retval  stdClass
     *
     * @throws  Exception
     */

    public function discoveryruleCreate($params=array(), $arrayKeyProperty='')
    {
        // get params array for request
        $params = $this->getRequestParamsArray($params);

        // check if we've to authenticate
        $auth = in_array('discoveryrule.create', self::$anonymousFunctions) ? false : true;

        // request
        return $this->request('discoveryrule.create', $params, $arrayKeyProperty, $auth);
    }
    
    /**
     * @brief   Reqeusts the Zabbix API and returns the response of the API
     *          method discoveryrule.update.
     *
     * The $params Array can be used, to pass parameters to the Zabbix API.
     * For more informations about these parameters, check the Zabbix API
     * documentation at https://www.zabbix.com/documentation/.
     *
     * The $arrayKeyProperty can be used to get an associatve instead of an
     * indexed array as response. A valid value for the $arrayKeyProperty is
     * is any property of the returned JSON objects (e.g. name, host,
     * hostid, graphid, screenitemid).
     *
     * @param   $params             Zabbix API parameters.
     * @param   $arrayKeyProperty   Object property for key of array.
     *
     * @retval  stdClass
     *
     * @throws  Exception
     */

    public function discoveryruleUpdate($params=array(), $arrayKeyProperty='')
    {
        // get params array for request
        $params = $this->getRequestParamsArray($params);

        // check if we've to authenticate
        $auth = in_array('discoveryrule.update', self::$anonymousFunctions) ? false : true;

        // request
        return $this->request('discoveryrule.update', $params, $arrayKeyProperty, $auth);
    }
    
    /**
     * @brief   Reqeusts the Zabbix API and returns the response of the API
     *          method discoveryrule.delete.
     *
     * The $params Array can be used, to pass parameters to the Zabbix API.
     * For more informations about these parameters, check the Zabbix API
     * documentation at https://www.zabbix.com/documentation/.
     *
     * The $arrayKeyProperty can be used to get an associatve instead of an
     * indexed array as response. A valid value for the $arrayKeyProperty is
     * is any property of the returned JSON objects (e.g. name, host,
     * hostid, graphid, screenitemid).
     *
     * @param   $params             Zabbix API parameters.
     * @param   $arrayKeyProperty   Object property for key of array.
     *
     * @retval  stdClass
     *
     * @throws  Exception
     */

    public function discoveryruleDelete($params=array(), $arrayKeyProperty='')
    {
        // get params array for request
        $params = $this->getRequestParamsArray($params);

        // check if we've to authenticate
        $auth = in_array('discoveryrule.delete', self::$anonymousFunctions) ? false : true;

        // request
        return $this->request('discoveryrule.delete', $params, $arrayKeyProperty, $auth);
    }
    
    /**
     * @brief   Reqeusts the Zabbix API and returns the response of the API
     *          method discoveryrule.copy.
     *
     * The $params Array can be used, to pass parameters to the Zabbix API.
     * For more informations about these parameters, check the Zabbix API
     * documentation at https://www.zabbix.com/documentation/.
     *
     * The $arrayKeyProperty can be used to get an associatve instead of an
     * indexed array as response. A valid value for the $arrayKeyProperty is
     * is any property of the returned JSON objects (e.g. name, host,
     * hostid, graphid, screenitemid).
     *
     * @param   $params             Zabbix API parameters.
     * @param   $arrayKeyProperty   Object property for key of array.
     *
     * @retval  stdClass
     *
     * @throws  Exception
     */

    public function discoveryruleCopy($params=array(), $arrayKeyProperty='')
    {
        // get params array for request
        $params = $this->getRequestParamsArray($params);

        // check if we've to authenticate
        $auth = in_array('discoveryrule.copy', self::$anonymousFunctions) ? false : true;

        // request
        return $this->request('discoveryrule.copy', $params, $arrayKeyProperty, $auth);
    }
    
    /**
     * @brief   Reqeusts the Zabbix API and returns the response of the API
     *          method discoveryrule.syncTemplates.
     *
     * The $params Array can be used, to pass parameters to the Zabbix API.
     * For more informations about these parameters, check the Zabbix API
     * documentation at https://www.zabbix.com/documentation/.
     *
     * The $arrayKeyProperty can be used to get an associatve instead of an
     * indexed array as response. A valid value for the $arrayKeyProperty is
     * is any property of the returned JSON objects (e.g. name, host,
     * hostid, graphid, screenitemid).
     *
     * @param   $params             Zabbix API parameters.
     * @param   $arrayKeyProperty   Object property for key of array.
     *
     * @retval  stdClass
     *
     * @throws  Exception
     */

    public function discoveryruleSyncTemplates($params=array(), $arrayKeyProperty='')
    {
        // get params array for request
        $params = $this->getRequestParamsArray($params);

        // check if we've to authenticate
        $auth = in_array('discoveryrule.syncTemplates', self::$anonymousFunctions) ? false : true;

        // request
        return $this->request('discoveryrule.syncTemplates', $params, $arrayKeyProperty, $auth);
    }
    
    /**
     * @brief   Reqeusts the Zabbix API and returns the response of the API
     *          method discoveryrule.isReadable.
     *
     * The $params Array can be used, to pass parameters to the Zabbix API.
     * For more informations about these parameters, check the Zabbix API
     * documentation at https://www.zabbix.com/documentation/.
     *
     * The $arrayKeyProperty can be used to get an associatve instead of an
     * indexed array as response. A valid value for the $arrayKeyProperty is
     * is any property of the returned JSON objects (e.g. name, host,
     * hostid, graphid, screenitemid).
     *
     * @param   $params             Zabbix API parameters.
     * @param   $arrayKeyProperty   Object property for key of array.
     *
     * @retval  stdClass
     *
     * @throws  Exception
     */

    public function discoveryruleIsReadable($params=array(), $arrayKeyProperty='')
    {
        // get params array for request
        $params = $this->getRequestParamsArray($params);

        // check if we've to authenticate
        $auth = in_array('discoveryrule.isReadable', self::$anonymousFunctions) ? false : true;

        // request
        return $this->request('discoveryrule.isReadable', $params, $arrayKeyProperty, $auth);
    }
    
    /**
     * @brief   Reqeusts the Zabbix API and returns the response of the API
     *          method discoveryrule.isWritable.
     *
     * The $params Array can be used, to pass parameters to the Zabbix API.
     * For more informations about these parameters, check the Zabbix API
     * documentation at https://www.zabbix.com/documentation/.
     *
     * The $arrayKeyProperty can be used to get an associatve instead of an
     * indexed array as response. A valid value for the $arrayKeyProperty is
     * is any property of the returned JSON objects (e.g. name, host,
     * hostid, graphid, screenitemid).
     *
     * @param   $params             Zabbix API parameters.
     * @param   $arrayKeyProperty   Object property for key of array.
     *
     * @retval  stdClass
     *
     * @throws  Exception
     */

    public function discoveryruleIsWritable($params=array(), $arrayKeyProperty='')
    {
        // get params array for request
        $params = $this->getRequestParamsArray($params);

        // check if we've to authenticate
        $auth = in_array('discoveryrule.isWritable', self::$anonymousFunctions) ? false : true;

        // request
        return $this->request('discoveryrule.isWritable', $params, $arrayKeyProperty, $auth);
    }
    
    /**
     * @brief   Reqeusts the Zabbix API and returns the response of the API
     *          method discoveryrule.findInterfaceForItem.
     *
     * The $params Array can be used, to pass parameters to the Zabbix API.
     * For more informations about these parameters, check the Zabbix API
     * documentation at https://www.zabbix.com/documentation/.
     *
     * The $arrayKeyProperty can be used to get an associatve instead of an
     * indexed array as response. A valid value for the $arrayKeyProperty is
     * is any property of the returned JSON objects (e.g. name, host,
     * hostid, graphid, screenitemid).
     *
     * @param   $params             Zabbix API parameters.
     * @param   $arrayKeyProperty   Object property for key of array.
     *
     * @retval  stdClass
     *
     * @throws  Exception
     */

    public function discoveryruleFindInterfaceForItem($params=array(), $arrayKeyProperty='')
    {
        // get params array for request
        $params = $this->getRequestParamsArray($params);

        // check if we've to authenticate
        $auth = in_array('discoveryrule.findInterfaceForItem', self::$anonymousFunctions) ? false : true;

        // request
        return $this->request('discoveryrule.findInterfaceForItem', $params, $arrayKeyProperty, $auth);
    }
    
    /**
     * @brief   Reqeusts the Zabbix API and returns the response of the API
     *          method discoveryrule.tableName.
     *
     * The $params Array can be used, to pass parameters to the Zabbix API.
     * For more informations about these parameters, check the Zabbix API
     * documentation at https://www.zabbix.com/documentation/.
     *
     * The $arrayKeyProperty can be used to get an associatve instead of an
     * indexed array as response. A valid value for the $arrayKeyProperty is
     * is any property of the returned JSON objects (e.g. name, host,
     * hostid, graphid, screenitemid).
     *
     * @param   $params             Zabbix API parameters.
     * @param   $arrayKeyProperty   Object property for key of array.
     *
     * @retval  stdClass
     *
     * @throws  Exception
     */

    public function discoveryruleTableName($params=array(), $arrayKeyProperty='')
    {
        // get params array for request
        $params = $this->getRequestParamsArray($params);

        // check if we've to authenticate
        $auth = in_array('discoveryrule.tableName', self::$anonymousFunctions) ? false : true;

        // request
        return $this->request('discoveryrule.tableName', $params, $arrayKeyProperty, $auth);
    }
    
    /**
     * @brief   Reqeusts the Zabbix API and returns the response of the API
     *          method discoveryrule.pk.
     *
     * The $params Array can be used, to pass parameters to the Zabbix API.
     * For more informations about these parameters, check the Zabbix API
     * documentation at https://www.zabbix.com/documentation/.
     *
     * The $arrayKeyProperty can be used to get an associatve instead of an
     * indexed array as response. A valid value for the $arrayKeyProperty is
     * is any property of the returned JSON objects (e.g. name, host,
     * hostid, graphid, screenitemid).
     *
     * @param   $params             Zabbix API parameters.
     * @param   $arrayKeyProperty   Object property for key of array.
     *
     * @retval  stdClass
     *
     * @throws  Exception
     */

    public function discoveryrulePk($params=array(), $arrayKeyProperty='')
    {
        // get params array for request
        $params = $this->getRequestParamsArray($params);

        // check if we've to authenticate
        $auth = in_array('discoveryrule.pk', self::$anonymousFunctions) ? false : true;

        // request
        return $this->request('discoveryrule.pk', $params, $arrayKeyProperty, $auth);
    }
    
    /**
     * @brief   Reqeusts the Zabbix API and returns the response of the API
     *          method discoveryrule.pkOption.
     *
     * The $params Array can be used, to pass parameters to the Zabbix API.
     * For more informations about these parameters, check the Zabbix API
     * documentation at https://www.zabbix.com/documentation/.
     *
     * The $arrayKeyProperty can be used to get an associatve instead of an
     * indexed array as response. A valid value for the $arrayKeyProperty is
     * is any property of the returned JSON objects (e.g. name, host,
     * hostid, graphid, screenitemid).
     *
     * @param   $params             Zabbix API parameters.
     * @param   $arrayKeyProperty   Object property for key of array.
     *
     * @retval  stdClass
     *
     * @throws  Exception
     */

    public function discoveryrulePkOption($params=array(), $arrayKeyProperty='')
    {
        // get params array for request
        $params = $this->getRequestParamsArray($params);

        // check if we've to authenticate
        $auth = in_array('discoveryrule.pkOption', self::$anonymousFunctions) ? false : true;

        // request
        return $this->request('discoveryrule.pkOption', $params, $arrayKeyProperty, $auth);
    }
    
    /**
     * @brief   Reqeusts the Zabbix API and returns the response of the API
     *          method drule.get.
     *
     * The $params Array can be used, to pass parameters to the Zabbix API.
     * For more informations about these parameters, check the Zabbix API
     * documentation at https://www.zabbix.com/documentation/.
     *
     * The $arrayKeyProperty can be used to get an associatve instead of an
     * indexed array as response. A valid value for the $arrayKeyProperty is
     * is any property of the returned JSON objects (e.g. name, host,
     * hostid, graphid, screenitemid).
     *
     * @param   $params             Zabbix API parameters.
     * @param   $arrayKeyProperty   Object property for key of array.
     *
     * @retval  stdClass
     *
     * @throws  Exception
     */

    public function druleGet($params=array(), $arrayKeyProperty='')
    {
        // get params array for request
        $params = $this->getRequestParamsArray($params);

        // check if we've to authenticate
        $auth = in_array('drule.get', self::$anonymousFunctions) ? false : true;

        // request
        return $this->request('drule.get', $params, $arrayKeyProperty, $auth);
    }
    
    /**
     * @brief   Reqeusts the Zabbix API and returns the response of the API
     *          method drule.checkInput.
     *
     * The $params Array can be used, to pass parameters to the Zabbix API.
     * For more informations about these parameters, check the Zabbix API
     * documentation at https://www.zabbix.com/documentation/.
     *
     * The $arrayKeyProperty can be used to get an associatve instead of an
     * indexed array as response. A valid value for the $arrayKeyProperty is
     * is any property of the returned JSON objects (e.g. name, host,
     * hostid, graphid, screenitemid).
     *
     * @param   $params             Zabbix API parameters.
     * @param   $arrayKeyProperty   Object property for key of array.
     *
     * @retval  stdClass
     *
     * @throws  Exception
     */

    public function druleCheckInput($params=array(), $arrayKeyProperty='')
    {
        // get params array for request
        $params = $this->getRequestParamsArray($params);

        // check if we've to authenticate
        $auth = in_array('drule.checkInput', self::$anonymousFunctions) ? false : true;

        // request
        return $this->request('drule.checkInput', $params, $arrayKeyProperty, $auth);
    }
    
    /**
     * @brief   Reqeusts the Zabbix API and returns the response of the API
     *          method drule.create.
     *
     * The $params Array can be used, to pass parameters to the Zabbix API.
     * For more informations about these parameters, check the Zabbix API
     * documentation at https://www.zabbix.com/documentation/.
     *
     * The $arrayKeyProperty can be used to get an associatve instead of an
     * indexed array as response. A valid value for the $arrayKeyProperty is
     * is any property of the returned JSON objects (e.g. name, host,
     * hostid, graphid, screenitemid).
     *
     * @param   $params             Zabbix API parameters.
     * @param   $arrayKeyProperty   Object property for key of array.
     *
     * @retval  stdClass
     *
     * @throws  Exception
     */

    public function druleCreate($params=array(), $arrayKeyProperty='')
    {
        // get params array for request
        $params = $this->getRequestParamsArray($params);

        // check if we've to authenticate
        $auth = in_array('drule.create', self::$anonymousFunctions) ? false : true;

        // request
        return $this->request('drule.create', $params, $arrayKeyProperty, $auth);
    }
    
    /**
     * @brief   Reqeusts the Zabbix API and returns the response of the API
     *          method drule.update.
     *
     * The $params Array can be used, to pass parameters to the Zabbix API.
     * For more informations about these parameters, check the Zabbix API
     * documentation at https://www.zabbix.com/documentation/.
     *
     * The $arrayKeyProperty can be used to get an associatve instead of an
     * indexed array as response. A valid value for the $arrayKeyProperty is
     * is any property of the returned JSON objects (e.g. name, host,
     * hostid, graphid, screenitemid).
     *
     * @param   $params             Zabbix API parameters.
     * @param   $arrayKeyProperty   Object property for key of array.
     *
     * @retval  stdClass
     *
     * @throws  Exception
     */

    public function druleUpdate($params=array(), $arrayKeyProperty='')
    {
        // get params array for request
        $params = $this->getRequestParamsArray($params);

        // check if we've to authenticate
        $auth = in_array('drule.update', self::$anonymousFunctions) ? false : true;

        // request
        return $this->request('drule.update', $params, $arrayKeyProperty, $auth);
    }
    
    /**
     * @brief   Reqeusts the Zabbix API and returns the response of the API
     *          method drule.delete.
     *
     * The $params Array can be used, to pass parameters to the Zabbix API.
     * For more informations about these parameters, check the Zabbix API
     * documentation at https://www.zabbix.com/documentation/.
     *
     * The $arrayKeyProperty can be used to get an associatve instead of an
     * indexed array as response. A valid value for the $arrayKeyProperty is
     * is any property of the returned JSON objects (e.g. name, host,
     * hostid, graphid, screenitemid).
     *
     * @param   $params             Zabbix API parameters.
     * @param   $arrayKeyProperty   Object property for key of array.
     *
     * @retval  stdClass
     *
     * @throws  Exception
     */

    public function druleDelete($params=array(), $arrayKeyProperty='')
    {
        // get params array for request
        $params = $this->getRequestParamsArray($params);

        // check if we've to authenticate
        $auth = in_array('drule.delete', self::$anonymousFunctions) ? false : true;

        // request
        return $this->request('drule.delete', $params, $arrayKeyProperty, $auth);
    }
    
    /**
     * @brief   Reqeusts the Zabbix API and returns the response of the API
     *          method drule.isReadable.
     *
     * The $params Array can be used, to pass parameters to the Zabbix API.
     * For more informations about these parameters, check the Zabbix API
     * documentation at https://www.zabbix.com/documentation/.
     *
     * The $arrayKeyProperty can be used to get an associatve instead of an
     * indexed array as response. A valid value for the $arrayKeyProperty is
     * is any property of the returned JSON objects (e.g. name, host,
     * hostid, graphid, screenitemid).
     *
     * @param   $params             Zabbix API parameters.
     * @param   $arrayKeyProperty   Object property for key of array.
     *
     * @retval  stdClass
     *
     * @throws  Exception
     */

    public function druleIsReadable($params=array(), $arrayKeyProperty='')
    {
        // get params array for request
        $params = $this->getRequestParamsArray($params);

        // check if we've to authenticate
        $auth = in_array('drule.isReadable', self::$anonymousFunctions) ? false : true;

        // request
        return $this->request('drule.isReadable', $params, $arrayKeyProperty, $auth);
    }
    
    /**
     * @brief   Reqeusts the Zabbix API and returns the response of the API
     *          method drule.isWritable.
     *
     * The $params Array can be used, to pass parameters to the Zabbix API.
     * For more informations about these parameters, check the Zabbix API
     * documentation at https://www.zabbix.com/documentation/.
     *
     * The $arrayKeyProperty can be used to get an associatve instead of an
     * indexed array as response. A valid value for the $arrayKeyProperty is
     * is any property of the returned JSON objects (e.g. name, host,
     * hostid, graphid, screenitemid).
     *
     * @param   $params             Zabbix API parameters.
     * @param   $arrayKeyProperty   Object property for key of array.
     *
     * @retval  stdClass
     *
     * @throws  Exception
     */

    public function druleIsWritable($params=array(), $arrayKeyProperty='')
    {
        // get params array for request
        $params = $this->getRequestParamsArray($params);

        // check if we've to authenticate
        $auth = in_array('drule.isWritable', self::$anonymousFunctions) ? false : true;

        // request
        return $this->request('drule.isWritable', $params, $arrayKeyProperty, $auth);
    }
    
    /**
     * @brief   Reqeusts the Zabbix API and returns the response of the API
     *          method drule.tableName.
     *
     * The $params Array can be used, to pass parameters to the Zabbix API.
     * For more informations about these parameters, check the Zabbix API
     * documentation at https://www.zabbix.com/documentation/.
     *
     * The $arrayKeyProperty can be used to get an associatve instead of an
     * indexed array as response. A valid value for the $arrayKeyProperty is
     * is any property of the returned JSON objects (e.g. name, host,
     * hostid, graphid, screenitemid).
     *
     * @param   $params             Zabbix API parameters.
     * @param   $arrayKeyProperty   Object property for key of array.
     *
     * @retval  stdClass
     *
     * @throws  Exception
     */

    public function druleTableName($params=array(), $arrayKeyProperty='')
    {
        // get params array for request
        $params = $this->getRequestParamsArray($params);

        // check if we've to authenticate
        $auth = in_array('drule.tableName', self::$anonymousFunctions) ? false : true;

        // request
        return $this->request('drule.tableName', $params, $arrayKeyProperty, $auth);
    }
    
    /**
     * @brief   Reqeusts the Zabbix API and returns the response of the API
     *          method drule.pk.
     *
     * The $params Array can be used, to pass parameters to the Zabbix API.
     * For more informations about these parameters, check the Zabbix API
     * documentation at https://www.zabbix.com/documentation/.
     *
     * The $arrayKeyProperty can be used to get an associatve instead of an
     * indexed array as response. A valid value for the $arrayKeyProperty is
     * is any property of the returned JSON objects (e.g. name, host,
     * hostid, graphid, screenitemid).
     *
     * @param   $params             Zabbix API parameters.
     * @param   $arrayKeyProperty   Object property for key of array.
     *
     * @retval  stdClass
     *
     * @throws  Exception
     */

    public function drulePk($params=array(), $arrayKeyProperty='')
    {
        // get params array for request
        $params = $this->getRequestParamsArray($params);

        // check if we've to authenticate
        $auth = in_array('drule.pk', self::$anonymousFunctions) ? false : true;

        // request
        return $this->request('drule.pk', $params, $arrayKeyProperty, $auth);
    }
    
    /**
     * @brief   Reqeusts the Zabbix API and returns the response of the API
     *          method drule.pkOption.
     *
     * The $params Array can be used, to pass parameters to the Zabbix API.
     * For more informations about these parameters, check the Zabbix API
     * documentation at https://www.zabbix.com/documentation/.
     *
     * The $arrayKeyProperty can be used to get an associatve instead of an
     * indexed array as response. A valid value for the $arrayKeyProperty is
     * is any property of the returned JSON objects (e.g. name, host,
     * hostid, graphid, screenitemid).
     *
     * @param   $params             Zabbix API parameters.
     * @param   $arrayKeyProperty   Object property for key of array.
     *
     * @retval  stdClass
     *
     * @throws  Exception
     */

    public function drulePkOption($params=array(), $arrayKeyProperty='')
    {
        // get params array for request
        $params = $this->getRequestParamsArray($params);

        // check if we've to authenticate
        $auth = in_array('drule.pkOption', self::$anonymousFunctions) ? false : true;

        // request
        return $this->request('drule.pkOption', $params, $arrayKeyProperty, $auth);
    }
    
    /**
     * @brief   Reqeusts the Zabbix API and returns the response of the API
     *          method dservice.get.
     *
     * The $params Array can be used, to pass parameters to the Zabbix API.
     * For more informations about these parameters, check the Zabbix API
     * documentation at https://www.zabbix.com/documentation/.
     *
     * The $arrayKeyProperty can be used to get an associatve instead of an
     * indexed array as response. A valid value for the $arrayKeyProperty is
     * is any property of the returned JSON objects (e.g. name, host,
     * hostid, graphid, screenitemid).
     *
     * @param   $params             Zabbix API parameters.
     * @param   $arrayKeyProperty   Object property for key of array.
     *
     * @retval  stdClass
     *
     * @throws  Exception
     */

    public function dserviceGet($params=array(), $arrayKeyProperty='')
    {
        // get params array for request
        $params = $this->getRequestParamsArray($params);

        // check if we've to authenticate
        $auth = in_array('dservice.get', self::$anonymousFunctions) ? false : true;

        // request
        return $this->request('dservice.get', $params, $arrayKeyProperty, $auth);
    }
    
    /**
     * @brief   Reqeusts the Zabbix API and returns the response of the API
     *          method dservice.tableName.
     *
     * The $params Array can be used, to pass parameters to the Zabbix API.
     * For more informations about these parameters, check the Zabbix API
     * documentation at https://www.zabbix.com/documentation/.
     *
     * The $arrayKeyProperty can be used to get an associatve instead of an
     * indexed array as response. A valid value for the $arrayKeyProperty is
     * is any property of the returned JSON objects (e.g. name, host,
     * hostid, graphid, screenitemid).
     *
     * @param   $params             Zabbix API parameters.
     * @param   $arrayKeyProperty   Object property for key of array.
     *
     * @retval  stdClass
     *
     * @throws  Exception
     */

    public function dserviceTableName($params=array(), $arrayKeyProperty='')
    {
        // get params array for request
        $params = $this->getRequestParamsArray($params);

        // check if we've to authenticate
        $auth = in_array('dservice.tableName', self::$anonymousFunctions) ? false : true;

        // request
        return $this->request('dservice.tableName', $params, $arrayKeyProperty, $auth);
    }
    
    /**
     * @brief   Reqeusts the Zabbix API and returns the response of the API
     *          method dservice.pk.
     *
     * The $params Array can be used, to pass parameters to the Zabbix API.
     * For more informations about these parameters, check the Zabbix API
     * documentation at https://www.zabbix.com/documentation/.
     *
     * The $arrayKeyProperty can be used to get an associatve instead of an
     * indexed array as response. A valid value for the $arrayKeyProperty is
     * is any property of the returned JSON objects (e.g. name, host,
     * hostid, graphid, screenitemid).
     *
     * @param   $params             Zabbix API parameters.
     * @param   $arrayKeyProperty   Object property for key of array.
     *
     * @retval  stdClass
     *
     * @throws  Exception
     */

    public function dservicePk($params=array(), $arrayKeyProperty='')
    {
        // get params array for request
        $params = $this->getRequestParamsArray($params);

        // check if we've to authenticate
        $auth = in_array('dservice.pk', self::$anonymousFunctions) ? false : true;

        // request
        return $this->request('dservice.pk', $params, $arrayKeyProperty, $auth);
    }
    
    /**
     * @brief   Reqeusts the Zabbix API and returns the response of the API
     *          method dservice.pkOption.
     *
     * The $params Array can be used, to pass parameters to the Zabbix API.
     * For more informations about these parameters, check the Zabbix API
     * documentation at https://www.zabbix.com/documentation/.
     *
     * The $arrayKeyProperty can be used to get an associatve instead of an
     * indexed array as response. A valid value for the $arrayKeyProperty is
     * is any property of the returned JSON objects (e.g. name, host,
     * hostid, graphid, screenitemid).
     *
     * @param   $params             Zabbix API parameters.
     * @param   $arrayKeyProperty   Object property for key of array.
     *
     * @retval  stdClass
     *
     * @throws  Exception
     */

    public function dservicePkOption($params=array(), $arrayKeyProperty='')
    {
        // get params array for request
        $params = $this->getRequestParamsArray($params);

        // check if we've to authenticate
        $auth = in_array('dservice.pkOption', self::$anonymousFunctions) ? false : true;

        // request
        return $this->request('dservice.pkOption', $params, $arrayKeyProperty, $auth);
    }
    
    /**
     * @brief   Reqeusts the Zabbix API and returns the response of the API
     *          method event.get.
     *
     * The $params Array can be used, to pass parameters to the Zabbix API.
     * For more informations about these parameters, check the Zabbix API
     * documentation at https://www.zabbix.com/documentation/.
     *
     * The $arrayKeyProperty can be used to get an associatve instead of an
     * indexed array as response. A valid value for the $arrayKeyProperty is
     * is any property of the returned JSON objects (e.g. name, host,
     * hostid, graphid, screenitemid).
     *
     * @param   $params             Zabbix API parameters.
     * @param   $arrayKeyProperty   Object property for key of array.
     *
     * @retval  stdClass
     *
     * @throws  Exception
     */

    public function eventGet($params=array(), $arrayKeyProperty='')
    {
        // get params array for request
        $params = $this->getRequestParamsArray($params);

        // check if we've to authenticate
        $auth = in_array('event.get', self::$anonymousFunctions) ? false : true;

        // request
        return $this->request('event.get', $params, $arrayKeyProperty, $auth);
    }
    
    /**
     * @brief   Reqeusts the Zabbix API and returns the response of the API
     *          method event.acknowledge.
     *
     * The $params Array can be used, to pass parameters to the Zabbix API.
     * For more informations about these parameters, check the Zabbix API
     * documentation at https://www.zabbix.com/documentation/.
     *
     * The $arrayKeyProperty can be used to get an associatve instead of an
     * indexed array as response. A valid value for the $arrayKeyProperty is
     * is any property of the returned JSON objects (e.g. name, host,
     * hostid, graphid, screenitemid).
     *
     * @param   $params             Zabbix API parameters.
     * @param   $arrayKeyProperty   Object property for key of array.
     *
     * @retval  stdClass
     *
     * @throws  Exception
     */

    public function eventAcknowledge($params=array(), $arrayKeyProperty='')
    {
        // get params array for request
        $params = $this->getRequestParamsArray($params);

        // check if we've to authenticate
        $auth = in_array('event.acknowledge', self::$anonymousFunctions) ? false : true;

        // request
        return $this->request('event.acknowledge', $params, $arrayKeyProperty, $auth);
    }
    
    /**
     * @brief   Reqeusts the Zabbix API and returns the response of the API
     *          method event.tableName.
     *
     * The $params Array can be used, to pass parameters to the Zabbix API.
     * For more informations about these parameters, check the Zabbix API
     * documentation at https://www.zabbix.com/documentation/.
     *
     * The $arrayKeyProperty can be used to get an associatve instead of an
     * indexed array as response. A valid value for the $arrayKeyProperty is
     * is any property of the returned JSON objects (e.g. name, host,
     * hostid, graphid, screenitemid).
     *
     * @param   $params             Zabbix API parameters.
     * @param   $arrayKeyProperty   Object property for key of array.
     *
     * @retval  stdClass
     *
     * @throws  Exception
     */

    public function eventTableName($params=array(), $arrayKeyProperty='')
    {
        // get params array for request
        $params = $this->getRequestParamsArray($params);

        // check if we've to authenticate
        $auth = in_array('event.tableName', self::$anonymousFunctions) ? false : true;

        // request
        return $this->request('event.tableName', $params, $arrayKeyProperty, $auth);
    }
    
    /**
     * @brief   Reqeusts the Zabbix API and returns the response of the API
     *          method event.pk.
     *
     * The $params Array can be used, to pass parameters to the Zabbix API.
     * For more informations about these parameters, check the Zabbix API
     * documentation at https://www.zabbix.com/documentation/.
     *
     * The $arrayKeyProperty can be used to get an associatve instead of an
     * indexed array as response. A valid value for the $arrayKeyProperty is
     * is any property of the returned JSON objects (e.g. name, host,
     * hostid, graphid, screenitemid).
     *
     * @param   $params             Zabbix API parameters.
     * @param   $arrayKeyProperty   Object property for key of array.
     *
     * @retval  stdClass
     *
     * @throws  Exception
     */

    public function eventPk($params=array(), $arrayKeyProperty='')
    {
        // get params array for request
        $params = $this->getRequestParamsArray($params);

        // check if we've to authenticate
        $auth = in_array('event.pk', self::$anonymousFunctions) ? false : true;

        // request
        return $this->request('event.pk', $params, $arrayKeyProperty, $auth);
    }
    
    /**
     * @brief   Reqeusts the Zabbix API and returns the response of the API
     *          method event.pkOption.
     *
     * The $params Array can be used, to pass parameters to the Zabbix API.
     * For more informations about these parameters, check the Zabbix API
     * documentation at https://www.zabbix.com/documentation/.
     *
     * The $arrayKeyProperty can be used to get an associatve instead of an
     * indexed array as response. A valid value for the $arrayKeyProperty is
     * is any property of the returned JSON objects (e.g. name, host,
     * hostid, graphid, screenitemid).
     *
     * @param   $params             Zabbix API parameters.
     * @param   $arrayKeyProperty   Object property for key of array.
     *
     * @retval  stdClass
     *
     * @throws  Exception
     */

    public function eventPkOption($params=array(), $arrayKeyProperty='')
    {
        // get params array for request
        $params = $this->getRequestParamsArray($params);

        // check if we've to authenticate
        $auth = in_array('event.pkOption', self::$anonymousFunctions) ? false : true;

        // request
        return $this->request('event.pkOption', $params, $arrayKeyProperty, $auth);
    }
    
    /**
     * @brief   Reqeusts the Zabbix API and returns the response of the API
     *          method graph.get.
     *
     * The $params Array can be used, to pass parameters to the Zabbix API.
     * For more informations about these parameters, check the Zabbix API
     * documentation at https://www.zabbix.com/documentation/.
     *
     * The $arrayKeyProperty can be used to get an associatve instead of an
     * indexed array as response. A valid value for the $arrayKeyProperty is
     * is any property of the returned JSON objects (e.g. name, host,
     * hostid, graphid, screenitemid).
     *
     * @param   $params             Zabbix API parameters.
     * @param   $arrayKeyProperty   Object property for key of array.
     *
     * @retval  stdClass
     *
     * @throws  Exception
     */

    public function graphGet($params=array(), $arrayKeyProperty='')
    {
        // get params array for request
        $params = $this->getRequestParamsArray($params);

        // check if we've to authenticate
        $auth = in_array('graph.get', self::$anonymousFunctions) ? false : true;

        // request
        return $this->request('graph.get', $params, $arrayKeyProperty, $auth);
    }
    
    /**
     * @brief   Reqeusts the Zabbix API and returns the response of the API
     *          method graph.syncTemplates.
     *
     * The $params Array can be used, to pass parameters to the Zabbix API.
     * For more informations about these parameters, check the Zabbix API
     * documentation at https://www.zabbix.com/documentation/.
     *
     * The $arrayKeyProperty can be used to get an associatve instead of an
     * indexed array as response. A valid value for the $arrayKeyProperty is
     * is any property of the returned JSON objects (e.g. name, host,
     * hostid, graphid, screenitemid).
     *
     * @param   $params             Zabbix API parameters.
     * @param   $arrayKeyProperty   Object property for key of array.
     *
     * @retval  stdClass
     *
     * @throws  Exception
     */

    public function graphSyncTemplates($params=array(), $arrayKeyProperty='')
    {
        // get params array for request
        $params = $this->getRequestParamsArray($params);

        // check if we've to authenticate
        $auth = in_array('graph.syncTemplates', self::$anonymousFunctions) ? false : true;

        // request
        return $this->request('graph.syncTemplates', $params, $arrayKeyProperty, $auth);
    }
    
    /**
     * @brief   Reqeusts the Zabbix API and returns the response of the API
     *          method graph.delete.
     *
     * The $params Array can be used, to pass parameters to the Zabbix API.
     * For more informations about these parameters, check the Zabbix API
     * documentation at https://www.zabbix.com/documentation/.
     *
     * The $arrayKeyProperty can be used to get an associatve instead of an
     * indexed array as response. A valid value for the $arrayKeyProperty is
     * is any property of the returned JSON objects (e.g. name, host,
     * hostid, graphid, screenitemid).
     *
     * @param   $params             Zabbix API parameters.
     * @param   $arrayKeyProperty   Object property for key of array.
     *
     * @retval  stdClass
     *
     * @throws  Exception
     */

    public function graphDelete($params=array(), $arrayKeyProperty='')
    {
        // get params array for request
        $params = $this->getRequestParamsArray($params);

        // check if we've to authenticate
        $auth = in_array('graph.delete', self::$anonymousFunctions) ? false : true;

        // request
        return $this->request('graph.delete', $params, $arrayKeyProperty, $auth);
    }
    
    /**
     * @brief   Reqeusts the Zabbix API and returns the response of the API
     *          method graph.update.
     *
     * The $params Array can be used, to pass parameters to the Zabbix API.
     * For more informations about these parameters, check the Zabbix API
     * documentation at https://www.zabbix.com/documentation/.
     *
     * The $arrayKeyProperty can be used to get an associatve instead of an
     * indexed array as response. A valid value for the $arrayKeyProperty is
     * is any property of the returned JSON objects (e.g. name, host,
     * hostid, graphid, screenitemid).
     *
     * @param   $params             Zabbix API parameters.
     * @param   $arrayKeyProperty   Object property for key of array.
     *
     * @retval  stdClass
     *
     * @throws  Exception
     */

    public function graphUpdate($params=array(), $arrayKeyProperty='')
    {
        // get params array for request
        $params = $this->getRequestParamsArray($params);

        // check if we've to authenticate
        $auth = in_array('graph.update', self::$anonymousFunctions) ? false : true;

        // request
        return $this->request('graph.update', $params, $arrayKeyProperty, $auth);
    }
    
    /**
     * @brief   Reqeusts the Zabbix API and returns the response of the API
     *          method graph.create.
     *
     * The $params Array can be used, to pass parameters to the Zabbix API.
     * For more informations about these parameters, check the Zabbix API
     * documentation at https://www.zabbix.com/documentation/.
     *
     * The $arrayKeyProperty can be used to get an associatve instead of an
     * indexed array as response. A valid value for the $arrayKeyProperty is
     * is any property of the returned JSON objects (e.g. name, host,
     * hostid, graphid, screenitemid).
     *
     * @param   $params             Zabbix API parameters.
     * @param   $arrayKeyProperty   Object property for key of array.
     *
     * @retval  stdClass
     *
     * @throws  Exception
     */

    public function graphCreate($params=array(), $arrayKeyProperty='')
    {
        // get params array for request
        $params = $this->getRequestParamsArray($params);

        // check if we've to authenticate
        $auth = in_array('graph.create', self::$anonymousFunctions) ? false : true;

        // request
        return $this->request('graph.create', $params, $arrayKeyProperty, $auth);
    }
    
    /**
     * @brief   Reqeusts the Zabbix API and returns the response of the API
     *          method graph.tableName.
     *
     * The $params Array can be used, to pass parameters to the Zabbix API.
     * For more informations about these parameters, check the Zabbix API
     * documentation at https://www.zabbix.com/documentation/.
     *
     * The $arrayKeyProperty can be used to get an associatve instead of an
     * indexed array as response. A valid value for the $arrayKeyProperty is
     * is any property of the returned JSON objects (e.g. name, host,
     * hostid, graphid, screenitemid).
     *
     * @param   $params             Zabbix API parameters.
     * @param   $arrayKeyProperty   Object property for key of array.
     *
     * @retval  stdClass
     *
     * @throws  Exception
     */

    public function graphTableName($params=array(), $arrayKeyProperty='')
    {
        // get params array for request
        $params = $this->getRequestParamsArray($params);

        // check if we've to authenticate
        $auth = in_array('graph.tableName', self::$anonymousFunctions) ? false : true;

        // request
        return $this->request('graph.tableName', $params, $arrayKeyProperty, $auth);
    }
    
    /**
     * @brief   Reqeusts the Zabbix API and returns the response of the API
     *          method graph.pk.
     *
     * The $params Array can be used, to pass parameters to the Zabbix API.
     * For more informations about these parameters, check the Zabbix API
     * documentation at https://www.zabbix.com/documentation/.
     *
     * The $arrayKeyProperty can be used to get an associatve instead of an
     * indexed array as response. A valid value for the $arrayKeyProperty is
     * is any property of the returned JSON objects (e.g. name, host,
     * hostid, graphid, screenitemid).
     *
     * @param   $params             Zabbix API parameters.
     * @param   $arrayKeyProperty   Object property for key of array.
     *
     * @retval  stdClass
     *
     * @throws  Exception
     */

    public function graphPk($params=array(), $arrayKeyProperty='')
    {
        // get params array for request
        $params = $this->getRequestParamsArray($params);

        // check if we've to authenticate
        $auth = in_array('graph.pk', self::$anonymousFunctions) ? false : true;

        // request
        return $this->request('graph.pk', $params, $arrayKeyProperty, $auth);
    }
    
    /**
     * @brief   Reqeusts the Zabbix API and returns the response of the API
     *          method graph.pkOption.
     *
     * The $params Array can be used, to pass parameters to the Zabbix API.
     * For more informations about these parameters, check the Zabbix API
     * documentation at https://www.zabbix.com/documentation/.
     *
     * The $arrayKeyProperty can be used to get an associatve instead of an
     * indexed array as response. A valid value for the $arrayKeyProperty is
     * is any property of the returned JSON objects (e.g. name, host,
     * hostid, graphid, screenitemid).
     *
     * @param   $params             Zabbix API parameters.
     * @param   $arrayKeyProperty   Object property for key of array.
     *
     * @retval  stdClass
     *
     * @throws  Exception
     */

    public function graphPkOption($params=array(), $arrayKeyProperty='')
    {
        // get params array for request
        $params = $this->getRequestParamsArray($params);

        // check if we've to authenticate
        $auth = in_array('graph.pkOption', self::$anonymousFunctions) ? false : true;

        // request
        return $this->request('graph.pkOption', $params, $arrayKeyProperty, $auth);
    }
    
    /**
     * @brief   Reqeusts the Zabbix API and returns the response of the API
     *          method graphitem.get.
     *
     * The $params Array can be used, to pass parameters to the Zabbix API.
     * For more informations about these parameters, check the Zabbix API
     * documentation at https://www.zabbix.com/documentation/.
     *
     * The $arrayKeyProperty can be used to get an associatve instead of an
     * indexed array as response. A valid value for the $arrayKeyProperty is
     * is any property of the returned JSON objects (e.g. name, host,
     * hostid, graphid, screenitemid).
     *
     * @param   $params             Zabbix API parameters.
     * @param   $arrayKeyProperty   Object property for key of array.
     *
     * @retval  stdClass
     *
     * @throws  Exception
     */

    public function graphitemGet($params=array(), $arrayKeyProperty='')
    {
        // get params array for request
        $params = $this->getRequestParamsArray($params);

        // check if we've to authenticate
        $auth = in_array('graphitem.get', self::$anonymousFunctions) ? false : true;

        // request
        return $this->request('graphitem.get', $params, $arrayKeyProperty, $auth);
    }
    
    /**
     * @brief   Reqeusts the Zabbix API and returns the response of the API
     *          method graphitem.tableName.
     *
     * The $params Array can be used, to pass parameters to the Zabbix API.
     * For more informations about these parameters, check the Zabbix API
     * documentation at https://www.zabbix.com/documentation/.
     *
     * The $arrayKeyProperty can be used to get an associatve instead of an
     * indexed array as response. A valid value for the $arrayKeyProperty is
     * is any property of the returned JSON objects (e.g. name, host,
     * hostid, graphid, screenitemid).
     *
     * @param   $params             Zabbix API parameters.
     * @param   $arrayKeyProperty   Object property for key of array.
     *
     * @retval  stdClass
     *
     * @throws  Exception
     */

    public function graphitemTableName($params=array(), $arrayKeyProperty='')
    {
        // get params array for request
        $params = $this->getRequestParamsArray($params);

        // check if we've to authenticate
        $auth = in_array('graphitem.tableName', self::$anonymousFunctions) ? false : true;

        // request
        return $this->request('graphitem.tableName', $params, $arrayKeyProperty, $auth);
    }
    
    /**
     * @brief   Reqeusts the Zabbix API and returns the response of the API
     *          method graphitem.pk.
     *
     * The $params Array can be used, to pass parameters to the Zabbix API.
     * For more informations about these parameters, check the Zabbix API
     * documentation at https://www.zabbix.com/documentation/.
     *
     * The $arrayKeyProperty can be used to get an associatve instead of an
     * indexed array as response. A valid value for the $arrayKeyProperty is
     * is any property of the returned JSON objects (e.g. name, host,
     * hostid, graphid, screenitemid).
     *
     * @param   $params             Zabbix API parameters.
     * @param   $arrayKeyProperty   Object property for key of array.
     *
     * @retval  stdClass
     *
     * @throws  Exception
     */

    public function graphitemPk($params=array(), $arrayKeyProperty='')
    {
        // get params array for request
        $params = $this->getRequestParamsArray($params);

        // check if we've to authenticate
        $auth = in_array('graphitem.pk', self::$anonymousFunctions) ? false : true;

        // request
        return $this->request('graphitem.pk', $params, $arrayKeyProperty, $auth);
    }
    
    /**
     * @brief   Reqeusts the Zabbix API and returns the response of the API
     *          method graphitem.pkOption.
     *
     * The $params Array can be used, to pass parameters to the Zabbix API.
     * For more informations about these parameters, check the Zabbix API
     * documentation at https://www.zabbix.com/documentation/.
     *
     * The $arrayKeyProperty can be used to get an associatve instead of an
     * indexed array as response. A valid value for the $arrayKeyProperty is
     * is any property of the returned JSON objects (e.g. name, host,
     * hostid, graphid, screenitemid).
     *
     * @param   $params             Zabbix API parameters.
     * @param   $arrayKeyProperty   Object property for key of array.
     *
     * @retval  stdClass
     *
     * @throws  Exception
     */

    public function graphitemPkOption($params=array(), $arrayKeyProperty='')
    {
        // get params array for request
        $params = $this->getRequestParamsArray($params);

        // check if we've to authenticate
        $auth = in_array('graphitem.pkOption', self::$anonymousFunctions) ? false : true;

        // request
        return $this->request('graphitem.pkOption', $params, $arrayKeyProperty, $auth);
    }
    
    /**
     * @brief   Reqeusts the Zabbix API and returns the response of the API
     *          method graphprototype.get.
     *
     * The $params Array can be used, to pass parameters to the Zabbix API.
     * For more informations about these parameters, check the Zabbix API
     * documentation at https://www.zabbix.com/documentation/.
     *
     * The $arrayKeyProperty can be used to get an associatve instead of an
     * indexed array as response. A valid value for the $arrayKeyProperty is
     * is any property of the returned JSON objects (e.g. name, host,
     * hostid, graphid, screenitemid).
     *
     * @param   $params             Zabbix API parameters.
     * @param   $arrayKeyProperty   Object property for key of array.
     *
     * @retval  stdClass
     *
     * @throws  Exception
     */

    public function graphprototypeGet($params=array(), $arrayKeyProperty='')
    {
        // get params array for request
        $params = $this->getRequestParamsArray($params);

        // check if we've to authenticate
        $auth = in_array('graphprototype.get', self::$anonymousFunctions) ? false : true;

        // request
        return $this->request('graphprototype.get', $params, $arrayKeyProperty, $auth);
    }
    
    /**
     * @brief   Reqeusts the Zabbix API and returns the response of the API
     *          method graphprototype.syncTemplates.
     *
     * The $params Array can be used, to pass parameters to the Zabbix API.
     * For more informations about these parameters, check the Zabbix API
     * documentation at https://www.zabbix.com/documentation/.
     *
     * The $arrayKeyProperty can be used to get an associatve instead of an
     * indexed array as response. A valid value for the $arrayKeyProperty is
     * is any property of the returned JSON objects (e.g. name, host,
     * hostid, graphid, screenitemid).
     *
     * @param   $params             Zabbix API parameters.
     * @param   $arrayKeyProperty   Object property for key of array.
     *
     * @retval  stdClass
     *
     * @throws  Exception
     */

    public function graphprototypeSyncTemplates($params=array(), $arrayKeyProperty='')
    {
        // get params array for request
        $params = $this->getRequestParamsArray($params);

        // check if we've to authenticate
        $auth = in_array('graphprototype.syncTemplates', self::$anonymousFunctions) ? false : true;

        // request
        return $this->request('graphprototype.syncTemplates', $params, $arrayKeyProperty, $auth);
    }
    
    /**
     * @brief   Reqeusts the Zabbix API and returns the response of the API
     *          method graphprototype.delete.
     *
     * The $params Array can be used, to pass parameters to the Zabbix API.
     * For more informations about these parameters, check the Zabbix API
     * documentation at https://www.zabbix.com/documentation/.
     *
     * The $arrayKeyProperty can be used to get an associatve instead of an
     * indexed array as response. A valid value for the $arrayKeyProperty is
     * is any property of the returned JSON objects (e.g. name, host,
     * hostid, graphid, screenitemid).
     *
     * @param   $params             Zabbix API parameters.
     * @param   $arrayKeyProperty   Object property for key of array.
     *
     * @retval  stdClass
     *
     * @throws  Exception
     */

    public function graphprototypeDelete($params=array(), $arrayKeyProperty='')
    {
        // get params array for request
        $params = $this->getRequestParamsArray($params);

        // check if we've to authenticate
        $auth = in_array('graphprototype.delete', self::$anonymousFunctions) ? false : true;

        // request
        return $this->request('graphprototype.delete', $params, $arrayKeyProperty, $auth);
    }
    
    /**
     * @brief   Reqeusts the Zabbix API and returns the response of the API
     *          method graphprototype.update.
     *
     * The $params Array can be used, to pass parameters to the Zabbix API.
     * For more informations about these parameters, check the Zabbix API
     * documentation at https://www.zabbix.com/documentation/.
     *
     * The $arrayKeyProperty can be used to get an associatve instead of an
     * indexed array as response. A valid value for the $arrayKeyProperty is
     * is any property of the returned JSON objects (e.g. name, host,
     * hostid, graphid, screenitemid).
     *
     * @param   $params             Zabbix API parameters.
     * @param   $arrayKeyProperty   Object property for key of array.
     *
     * @retval  stdClass
     *
     * @throws  Exception
     */

    public function graphprototypeUpdate($params=array(), $arrayKeyProperty='')
    {
        // get params array for request
        $params = $this->getRequestParamsArray($params);

        // check if we've to authenticate
        $auth = in_array('graphprototype.update', self::$anonymousFunctions) ? false : true;

        // request
        return $this->request('graphprototype.update', $params, $arrayKeyProperty, $auth);
    }
    
    /**
     * @brief   Reqeusts the Zabbix API and returns the response of the API
     *          method graphprototype.create.
     *
     * The $params Array can be used, to pass parameters to the Zabbix API.
     * For more informations about these parameters, check the Zabbix API
     * documentation at https://www.zabbix.com/documentation/.
     *
     * The $arrayKeyProperty can be used to get an associatve instead of an
     * indexed array as response. A valid value for the $arrayKeyProperty is
     * is any property of the returned JSON objects (e.g. name, host,
     * hostid, graphid, screenitemid).
     *
     * @param   $params             Zabbix API parameters.
     * @param   $arrayKeyProperty   Object property for key of array.
     *
     * @retval  stdClass
     *
     * @throws  Exception
     */

    public function graphprototypeCreate($params=array(), $arrayKeyProperty='')
    {
        // get params array for request
        $params = $this->getRequestParamsArray($params);

        // check if we've to authenticate
        $auth = in_array('graphprototype.create', self::$anonymousFunctions) ? false : true;

        // request
        return $this->request('graphprototype.create', $params, $arrayKeyProperty, $auth);
    }
    
    /**
     * @brief   Reqeusts the Zabbix API and returns the response of the API
     *          method graphprototype.tableName.
     *
     * The $params Array can be used, to pass parameters to the Zabbix API.
     * For more informations about these parameters, check the Zabbix API
     * documentation at https://www.zabbix.com/documentation/.
     *
     * The $arrayKeyProperty can be used to get an associatve instead of an
     * indexed array as response. A valid value for the $arrayKeyProperty is
     * is any property of the returned JSON objects (e.g. name, host,
     * hostid, graphid, screenitemid).
     *
     * @param   $params             Zabbix API parameters.
     * @param   $arrayKeyProperty   Object property for key of array.
     *
     * @retval  stdClass
     *
     * @throws  Exception
     */

    public function graphprototypeTableName($params=array(), $arrayKeyProperty='')
    {
        // get params array for request
        $params = $this->getRequestParamsArray($params);

        // check if we've to authenticate
        $auth = in_array('graphprototype.tableName', self::$anonymousFunctions) ? false : true;

        // request
        return $this->request('graphprototype.tableName', $params, $arrayKeyProperty, $auth);
    }
    
    /**
     * @brief   Reqeusts the Zabbix API and returns the response of the API
     *          method graphprototype.pk.
     *
     * The $params Array can be used, to pass parameters to the Zabbix API.
     * For more informations about these parameters, check the Zabbix API
     * documentation at https://www.zabbix.com/documentation/.
     *
     * The $arrayKeyProperty can be used to get an associatve instead of an
     * indexed array as response. A valid value for the $arrayKeyProperty is
     * is any property of the returned JSON objects (e.g. name, host,
     * hostid, graphid, screenitemid).
     *
     * @param   $params             Zabbix API parameters.
     * @param   $arrayKeyProperty   Object property for key of array.
     *
     * @retval  stdClass
     *
     * @throws  Exception
     */

    public function graphprototypePk($params=array(), $arrayKeyProperty='')
    {
        // get params array for request
        $params = $this->getRequestParamsArray($params);

        // check if we've to authenticate
        $auth = in_array('graphprototype.pk', self::$anonymousFunctions) ? false : true;

        // request
        return $this->request('graphprototype.pk', $params, $arrayKeyProperty, $auth);
    }
    
    /**
     * @brief   Reqeusts the Zabbix API and returns the response of the API
     *          method graphprototype.pkOption.
     *
     * The $params Array can be used, to pass parameters to the Zabbix API.
     * For more informations about these parameters, check the Zabbix API
     * documentation at https://www.zabbix.com/documentation/.
     *
     * The $arrayKeyProperty can be used to get an associatve instead of an
     * indexed array as response. A valid value for the $arrayKeyProperty is
     * is any property of the returned JSON objects (e.g. name, host,
     * hostid, graphid, screenitemid).
     *
     * @param   $params             Zabbix API parameters.
     * @param   $arrayKeyProperty   Object property for key of array.
     *
     * @retval  stdClass
     *
     * @throws  Exception
     */

    public function graphprototypePkOption($params=array(), $arrayKeyProperty='')
    {
        // get params array for request
        $params = $this->getRequestParamsArray($params);

        // check if we've to authenticate
        $auth = in_array('graphprototype.pkOption', self::$anonymousFunctions) ? false : true;

        // request
        return $this->request('graphprototype.pkOption', $params, $arrayKeyProperty, $auth);
    }
    
    /**
     * @brief   Reqeusts the Zabbix API and returns the response of the API
     *          method host.get.
     *
     * The $params Array can be used, to pass parameters to the Zabbix API.
     * For more informations about these parameters, check the Zabbix API
     * documentation at https://www.zabbix.com/documentation/.
     *
     * The $arrayKeyProperty can be used to get an associatve instead of an
     * indexed array as response. A valid value for the $arrayKeyProperty is
     * is any property of the returned JSON objects (e.g. name, host,
     * hostid, graphid, screenitemid).
     *
     * @param   $params             Zabbix API parameters.
     * @param   $arrayKeyProperty   Object property for key of array.
     *
     * @retval  stdClass
     *
     * @throws  Exception
     */

    public function hostGet($params=array(), $arrayKeyProperty='')
    {
        // get params array for request
        $params = $this->getRequestParamsArray($params);

        // check if we've to authenticate
        $auth = in_array('host.get', self::$anonymousFunctions) ? false : true;

        // request
        return $this->request('host.get', $params, $arrayKeyProperty, $auth);
    }
    
    /**
     * @brief   Reqeusts the Zabbix API and returns the response of the API
     *          method host.create.
     *
     * The $params Array can be used, to pass parameters to the Zabbix API.
     * For more informations about these parameters, check the Zabbix API
     * documentation at https://www.zabbix.com/documentation/.
     *
     * The $arrayKeyProperty can be used to get an associatve instead of an
     * indexed array as response. A valid value for the $arrayKeyProperty is
     * is any property of the returned JSON objects (e.g. name, host,
     * hostid, graphid, screenitemid).
     *
     * @param   $params             Zabbix API parameters.
     * @param   $arrayKeyProperty   Object property for key of array.
     *
     * @retval  stdClass
     *
     * @throws  Exception
     */

    public function hostCreate($params=array(), $arrayKeyProperty='')
    {
        // get params array for request
        $params = $this->getRequestParamsArray($params);

        // check if we've to authenticate
        $auth = in_array('host.create', self::$anonymousFunctions) ? false : true;

        // request
        return $this->request('host.create', $params, $arrayKeyProperty, $auth);
    }
    
    /**
     * @brief   Reqeusts the Zabbix API and returns the response of the API
     *          method host.update.
     *
     * The $params Array can be used, to pass parameters to the Zabbix API.
     * For more informations about these parameters, check the Zabbix API
     * documentation at https://www.zabbix.com/documentation/.
     *
     * The $arrayKeyProperty can be used to get an associatve instead of an
     * indexed array as response. A valid value for the $arrayKeyProperty is
     * is any property of the returned JSON objects (e.g. name, host,
     * hostid, graphid, screenitemid).
     *
     * @param   $params             Zabbix API parameters.
     * @param   $arrayKeyProperty   Object property for key of array.
     *
     * @retval  stdClass
     *
     * @throws  Exception
     */

    public function hostUpdate($params=array(), $arrayKeyProperty='')
    {
        // get params array for request
        $params = $this->getRequestParamsArray($params);

        // check if we've to authenticate
        $auth = in_array('host.update', self::$anonymousFunctions) ? false : true;

        // request
        return $this->request('host.update', $params, $arrayKeyProperty, $auth);
    }
    
    /**
     * @brief   Reqeusts the Zabbix API and returns the response of the API
     *          method host.massAdd.
     *
     * The $params Array can be used, to pass parameters to the Zabbix API.
     * For more informations about these parameters, check the Zabbix API
     * documentation at https://www.zabbix.com/documentation/.
     *
     * The $arrayKeyProperty can be used to get an associatve instead of an
     * indexed array as response. A valid value for the $arrayKeyProperty is
     * is any property of the returned JSON objects (e.g. name, host,
     * hostid, graphid, screenitemid).
     *
     * @param   $params             Zabbix API parameters.
     * @param   $arrayKeyProperty   Object property for key of array.
     *
     * @retval  stdClass
     *
     * @throws  Exception
     */

    public function hostMassAdd($params=array(), $arrayKeyProperty='')
    {
        // get params array for request
        $params = $this->getRequestParamsArray($params);

        // check if we've to authenticate
        $auth = in_array('host.massAdd', self::$anonymousFunctions) ? false : true;

        // request
        return $this->request('host.massAdd', $params, $arrayKeyProperty, $auth);
    }
    
    /**
     * @brief   Reqeusts the Zabbix API and returns the response of the API
     *          method host.massUpdate.
     *
     * The $params Array can be used, to pass parameters to the Zabbix API.
     * For more informations about these parameters, check the Zabbix API
     * documentation at https://www.zabbix.com/documentation/.
     *
     * The $arrayKeyProperty can be used to get an associatve instead of an
     * indexed array as response. A valid value for the $arrayKeyProperty is
     * is any property of the returned JSON objects (e.g. name, host,
     * hostid, graphid, screenitemid).
     *
     * @param   $params             Zabbix API parameters.
     * @param   $arrayKeyProperty   Object property for key of array.
     *
     * @retval  stdClass
     *
     * @throws  Exception
     */

    public function hostMassUpdate($params=array(), $arrayKeyProperty='')
    {
        // get params array for request
        $params = $this->getRequestParamsArray($params);

        // check if we've to authenticate
        $auth = in_array('host.massUpdate', self::$anonymousFunctions) ? false : true;

        // request
        return $this->request('host.massUpdate', $params, $arrayKeyProperty, $auth);
    }
    
    /**
     * @brief   Reqeusts the Zabbix API and returns the response of the API
     *          method host.massRemove.
     *
     * The $params Array can be used, to pass parameters to the Zabbix API.
     * For more informations about these parameters, check the Zabbix API
     * documentation at https://www.zabbix.com/documentation/.
     *
     * The $arrayKeyProperty can be used to get an associatve instead of an
     * indexed array as response. A valid value for the $arrayKeyProperty is
     * is any property of the returned JSON objects (e.g. name, host,
     * hostid, graphid, screenitemid).
     *
     * @param   $params             Zabbix API parameters.
     * @param   $arrayKeyProperty   Object property for key of array.
     *
     * @retval  stdClass
     *
     * @throws  Exception
     */

    public function hostMassRemove($params=array(), $arrayKeyProperty='')
    {
        // get params array for request
        $params = $this->getRequestParamsArray($params);

        // check if we've to authenticate
        $auth = in_array('host.massRemove', self::$anonymousFunctions) ? false : true;

        // request
        return $this->request('host.massRemove', $params, $arrayKeyProperty, $auth);
    }
    
    /**
     * @brief   Reqeusts the Zabbix API and returns the response of the API
     *          method host.delete.
     *
     * The $params Array can be used, to pass parameters to the Zabbix API.
     * For more informations about these parameters, check the Zabbix API
     * documentation at https://www.zabbix.com/documentation/.
     *
     * The $arrayKeyProperty can be used to get an associatve instead of an
     * indexed array as response. A valid value for the $arrayKeyProperty is
     * is any property of the returned JSON objects (e.g. name, host,
     * hostid, graphid, screenitemid).
     *
     * @param   $params             Zabbix API parameters.
     * @param   $arrayKeyProperty   Object property for key of array.
     *
     * @retval  stdClass
     *
     * @throws  Exception
     */

    public function hostDelete($params=array(), $arrayKeyProperty='')
    {
        // get params array for request
        $params = $this->getRequestParamsArray($params);

        // check if we've to authenticate
        $auth = in_array('host.delete', self::$anonymousFunctions) ? false : true;

        // request
        return $this->request('host.delete', $params, $arrayKeyProperty, $auth);
    }
    
    /**
     * @brief   Reqeusts the Zabbix API and returns the response of the API
     *          method host.isReadable.
     *
     * The $params Array can be used, to pass parameters to the Zabbix API.
     * For more informations about these parameters, check the Zabbix API
     * documentation at https://www.zabbix.com/documentation/.
     *
     * The $arrayKeyProperty can be used to get an associatve instead of an
     * indexed array as response. A valid value for the $arrayKeyProperty is
     * is any property of the returned JSON objects (e.g. name, host,
     * hostid, graphid, screenitemid).
     *
     * @param   $params             Zabbix API parameters.
     * @param   $arrayKeyProperty   Object property for key of array.
     *
     * @retval  stdClass
     *
     * @throws  Exception
     */

    public function hostIsReadable($params=array(), $arrayKeyProperty='')
    {
        // get params array for request
        $params = $this->getRequestParamsArray($params);

        // check if we've to authenticate
        $auth = in_array('host.isReadable', self::$anonymousFunctions) ? false : true;

        // request
        return $this->request('host.isReadable', $params, $arrayKeyProperty, $auth);
    }
    
    /**
     * @brief   Reqeusts the Zabbix API and returns the response of the API
     *          method host.isWritable.
     *
     * The $params Array can be used, to pass parameters to the Zabbix API.
     * For more informations about these parameters, check the Zabbix API
     * documentation at https://www.zabbix.com/documentation/.
     *
     * The $arrayKeyProperty can be used to get an associatve instead of an
     * indexed array as response. A valid value for the $arrayKeyProperty is
     * is any property of the returned JSON objects (e.g. name, host,
     * hostid, graphid, screenitemid).
     *
     * @param   $params             Zabbix API parameters.
     * @param   $arrayKeyProperty   Object property for key of array.
     *
     * @retval  stdClass
     *
     * @throws  Exception
     */

    public function hostIsWritable($params=array(), $arrayKeyProperty='')
    {
        // get params array for request
        $params = $this->getRequestParamsArray($params);

        // check if we've to authenticate
        $auth = in_array('host.isWritable', self::$anonymousFunctions) ? false : true;

        // request
        return $this->request('host.isWritable', $params, $arrayKeyProperty, $auth);
    }
    
    /**
     * @brief   Reqeusts the Zabbix API and returns the response of the API
     *          method host.tableName.
     *
     * The $params Array can be used, to pass parameters to the Zabbix API.
     * For more informations about these parameters, check the Zabbix API
     * documentation at https://www.zabbix.com/documentation/.
     *
     * The $arrayKeyProperty can be used to get an associatve instead of an
     * indexed array as response. A valid value for the $arrayKeyProperty is
     * is any property of the returned JSON objects (e.g. name, host,
     * hostid, graphid, screenitemid).
     *
     * @param   $params             Zabbix API parameters.
     * @param   $arrayKeyProperty   Object property for key of array.
     *
     * @retval  stdClass
     *
     * @throws  Exception
     */

    public function hostTableName($params=array(), $arrayKeyProperty='')
    {
        // get params array for request
        $params = $this->getRequestParamsArray($params);

        // check if we've to authenticate
        $auth = in_array('host.tableName', self::$anonymousFunctions) ? false : true;

        // request
        return $this->request('host.tableName', $params, $arrayKeyProperty, $auth);
    }
    
    /**
     * @brief   Reqeusts the Zabbix API and returns the response of the API
     *          method host.pk.
     *
     * The $params Array can be used, to pass parameters to the Zabbix API.
     * For more informations about these parameters, check the Zabbix API
     * documentation at https://www.zabbix.com/documentation/.
     *
     * The $arrayKeyProperty can be used to get an associatve instead of an
     * indexed array as response. A valid value for the $arrayKeyProperty is
     * is any property of the returned JSON objects (e.g. name, host,
     * hostid, graphid, screenitemid).
     *
     * @param   $params             Zabbix API parameters.
     * @param   $arrayKeyProperty   Object property for key of array.
     *
     * @retval  stdClass
     *
     * @throws  Exception
     */

    public function hostPk($params=array(), $arrayKeyProperty='')
    {
        // get params array for request
        $params = $this->getRequestParamsArray($params);

        // check if we've to authenticate
        $auth = in_array('host.pk', self::$anonymousFunctions) ? false : true;

        // request
        return $this->request('host.pk', $params, $arrayKeyProperty, $auth);
    }
    
    /**
     * @brief   Reqeusts the Zabbix API and returns the response of the API
     *          method host.pkOption.
     *
     * The $params Array can be used, to pass parameters to the Zabbix API.
     * For more informations about these parameters, check the Zabbix API
     * documentation at https://www.zabbix.com/documentation/.
     *
     * The $arrayKeyProperty can be used to get an associatve instead of an
     * indexed array as response. A valid value for the $arrayKeyProperty is
     * is any property of the returned JSON objects (e.g. name, host,
     * hostid, graphid, screenitemid).
     *
     * @param   $params             Zabbix API parameters.
     * @param   $arrayKeyProperty   Object property for key of array.
     *
     * @retval  stdClass
     *
     * @throws  Exception
     */

    public function hostPkOption($params=array(), $arrayKeyProperty='')
    {
        // get params array for request
        $params = $this->getRequestParamsArray($params);

        // check if we've to authenticate
        $auth = in_array('host.pkOption', self::$anonymousFunctions) ? false : true;

        // request
        return $this->request('host.pkOption', $params, $arrayKeyProperty, $auth);
    }
    
    /**
     * @brief   Reqeusts the Zabbix API and returns the response of the API
     *          method hostgroup.get.
     *
     * The $params Array can be used, to pass parameters to the Zabbix API.
     * For more informations about these parameters, check the Zabbix API
     * documentation at https://www.zabbix.com/documentation/.
     *
     * The $arrayKeyProperty can be used to get an associatve instead of an
     * indexed array as response. A valid value for the $arrayKeyProperty is
     * is any property of the returned JSON objects (e.g. name, host,
     * hostid, graphid, screenitemid).
     *
     * @param   $params             Zabbix API parameters.
     * @param   $arrayKeyProperty   Object property for key of array.
     *
     * @retval  stdClass
     *
     * @throws  Exception
     */

    public function hostgroupGet($params=array(), $arrayKeyProperty='')
    {
        // get params array for request
        $params = $this->getRequestParamsArray($params);

        // check if we've to authenticate
        $auth = in_array('hostgroup.get', self::$anonymousFunctions) ? false : true;

        // request
        return $this->request('hostgroup.get', $params, $arrayKeyProperty, $auth);
    }
    
    /**
     * @brief   Reqeusts the Zabbix API and returns the response of the API
     *          method hostgroup.create.
     *
     * The $params Array can be used, to pass parameters to the Zabbix API.
     * For more informations about these parameters, check the Zabbix API
     * documentation at https://www.zabbix.com/documentation/.
     *
     * The $arrayKeyProperty can be used to get an associatve instead of an
     * indexed array as response. A valid value for the $arrayKeyProperty is
     * is any property of the returned JSON objects (e.g. name, host,
     * hostid, graphid, screenitemid).
     *
     * @param   $params             Zabbix API parameters.
     * @param   $arrayKeyProperty   Object property for key of array.
     *
     * @retval  stdClass
     *
     * @throws  Exception
     */

    public function hostgroupCreate($params=array(), $arrayKeyProperty='')
    {
        // get params array for request
        $params = $this->getRequestParamsArray($params);

        // check if we've to authenticate
        $auth = in_array('hostgroup.create', self::$anonymousFunctions) ? false : true;

        // request
        return $this->request('hostgroup.create', $params, $arrayKeyProperty, $auth);
    }
    
    /**
     * @brief   Reqeusts the Zabbix API and returns the response of the API
     *          method hostgroup.update.
     *
     * The $params Array can be used, to pass parameters to the Zabbix API.
     * For more informations about these parameters, check the Zabbix API
     * documentation at https://www.zabbix.com/documentation/.
     *
     * The $arrayKeyProperty can be used to get an associatve instead of an
     * indexed array as response. A valid value for the $arrayKeyProperty is
     * is any property of the returned JSON objects (e.g. name, host,
     * hostid, graphid, screenitemid).
     *
     * @param   $params             Zabbix API parameters.
     * @param   $arrayKeyProperty   Object property for key of array.
     *
     * @retval  stdClass
     *
     * @throws  Exception
     */

    public function hostgroupUpdate($params=array(), $arrayKeyProperty='')
    {
        // get params array for request
        $params = $this->getRequestParamsArray($params);

        // check if we've to authenticate
        $auth = in_array('hostgroup.update', self::$anonymousFunctions) ? false : true;

        // request
        return $this->request('hostgroup.update', $params, $arrayKeyProperty, $auth);
    }
    
    /**
     * @brief   Reqeusts the Zabbix API and returns the response of the API
     *          method hostgroup.delete.
     *
     * The $params Array can be used, to pass parameters to the Zabbix API.
     * For more informations about these parameters, check the Zabbix API
     * documentation at https://www.zabbix.com/documentation/.
     *
     * The $arrayKeyProperty can be used to get an associatve instead of an
     * indexed array as response. A valid value for the $arrayKeyProperty is
     * is any property of the returned JSON objects (e.g. name, host,
     * hostid, graphid, screenitemid).
     *
     * @param   $params             Zabbix API parameters.
     * @param   $arrayKeyProperty   Object property for key of array.
     *
     * @retval  stdClass
     *
     * @throws  Exception
     */

    public function hostgroupDelete($params=array(), $arrayKeyProperty='')
    {
        // get params array for request
        $params = $this->getRequestParamsArray($params);

        // check if we've to authenticate
        $auth = in_array('hostgroup.delete', self::$anonymousFunctions) ? false : true;

        // request
        return $this->request('hostgroup.delete', $params, $arrayKeyProperty, $auth);
    }
    
    /**
     * @brief   Reqeusts the Zabbix API and returns the response of the API
     *          method hostgroup.massAdd.
     *
     * The $params Array can be used, to pass parameters to the Zabbix API.
     * For more informations about these parameters, check the Zabbix API
     * documentation at https://www.zabbix.com/documentation/.
     *
     * The $arrayKeyProperty can be used to get an associatve instead of an
     * indexed array as response. A valid value for the $arrayKeyProperty is
     * is any property of the returned JSON objects (e.g. name, host,
     * hostid, graphid, screenitemid).
     *
     * @param   $params             Zabbix API parameters.
     * @param   $arrayKeyProperty   Object property for key of array.
     *
     * @retval  stdClass
     *
     * @throws  Exception
     */

    public function hostgroupMassAdd($params=array(), $arrayKeyProperty='')
    {
        // get params array for request
        $params = $this->getRequestParamsArray($params);

        // check if we've to authenticate
        $auth = in_array('hostgroup.massAdd', self::$anonymousFunctions) ? false : true;

        // request
        return $this->request('hostgroup.massAdd', $params, $arrayKeyProperty, $auth);
    }
    
    /**
     * @brief   Reqeusts the Zabbix API and returns the response of the API
     *          method hostgroup.massRemove.
     *
     * The $params Array can be used, to pass parameters to the Zabbix API.
     * For more informations about these parameters, check the Zabbix API
     * documentation at https://www.zabbix.com/documentation/.
     *
     * The $arrayKeyProperty can be used to get an associatve instead of an
     * indexed array as response. A valid value for the $arrayKeyProperty is
     * is any property of the returned JSON objects (e.g. name, host,
     * hostid, graphid, screenitemid).
     *
     * @param   $params             Zabbix API parameters.
     * @param   $arrayKeyProperty   Object property for key of array.
     *
     * @retval  stdClass
     *
     * @throws  Exception
     */

    public function hostgroupMassRemove($params=array(), $arrayKeyProperty='')
    {
        // get params array for request
        $params = $this->getRequestParamsArray($params);

        // check if we've to authenticate
        $auth = in_array('hostgroup.massRemove', self::$anonymousFunctions) ? false : true;

        // request
        return $this->request('hostgroup.massRemove', $params, $arrayKeyProperty, $auth);
    }
    
    /**
     * @brief   Reqeusts the Zabbix API and returns the response of the API
     *          method hostgroup.massUpdate.
     *
     * The $params Array can be used, to pass parameters to the Zabbix API.
     * For more informations about these parameters, check the Zabbix API
     * documentation at https://www.zabbix.com/documentation/.
     *
     * The $arrayKeyProperty can be used to get an associatve instead of an
     * indexed array as response. A valid value for the $arrayKeyProperty is
     * is any property of the returned JSON objects (e.g. name, host,
     * hostid, graphid, screenitemid).
     *
     * @param   $params             Zabbix API parameters.
     * @param   $arrayKeyProperty   Object property for key of array.
     *
     * @retval  stdClass
     *
     * @throws  Exception
     */

    public function hostgroupMassUpdate($params=array(), $arrayKeyProperty='')
    {
        // get params array for request
        $params = $this->getRequestParamsArray($params);

        // check if we've to authenticate
        $auth = in_array('hostgroup.massUpdate', self::$anonymousFunctions) ? false : true;

        // request
        return $this->request('hostgroup.massUpdate', $params, $arrayKeyProperty, $auth);
    }
    
    /**
     * @brief   Reqeusts the Zabbix API and returns the response of the API
     *          method hostgroup.isReadable.
     *
     * The $params Array can be used, to pass parameters to the Zabbix API.
     * For more informations about these parameters, check the Zabbix API
     * documentation at https://www.zabbix.com/documentation/.
     *
     * The $arrayKeyProperty can be used to get an associatve instead of an
     * indexed array as response. A valid value for the $arrayKeyProperty is
     * is any property of the returned JSON objects (e.g. name, host,
     * hostid, graphid, screenitemid).
     *
     * @param   $params             Zabbix API parameters.
     * @param   $arrayKeyProperty   Object property for key of array.
     *
     * @retval  stdClass
     *
     * @throws  Exception
     */

    public function hostgroupIsReadable($params=array(), $arrayKeyProperty='')
    {
        // get params array for request
        $params = $this->getRequestParamsArray($params);

        // check if we've to authenticate
        $auth = in_array('hostgroup.isReadable', self::$anonymousFunctions) ? false : true;

        // request
        return $this->request('hostgroup.isReadable', $params, $arrayKeyProperty, $auth);
    }
    
    /**
     * @brief   Reqeusts the Zabbix API and returns the response of the API
     *          method hostgroup.isWritable.
     *
     * The $params Array can be used, to pass parameters to the Zabbix API.
     * For more informations about these parameters, check the Zabbix API
     * documentation at https://www.zabbix.com/documentation/.
     *
     * The $arrayKeyProperty can be used to get an associatve instead of an
     * indexed array as response. A valid value for the $arrayKeyProperty is
     * is any property of the returned JSON objects (e.g. name, host,
     * hostid, graphid, screenitemid).
     *
     * @param   $params             Zabbix API parameters.
     * @param   $arrayKeyProperty   Object property for key of array.
     *
     * @retval  stdClass
     *
     * @throws  Exception
     */

    public function hostgroupIsWritable($params=array(), $arrayKeyProperty='')
    {
        // get params array for request
        $params = $this->getRequestParamsArray($params);

        // check if we've to authenticate
        $auth = in_array('hostgroup.isWritable', self::$anonymousFunctions) ? false : true;

        // request
        return $this->request('hostgroup.isWritable', $params, $arrayKeyProperty, $auth);
    }
    
    /**
     * @brief   Reqeusts the Zabbix API and returns the response of the API
     *          method hostgroup.tableName.
     *
     * The $params Array can be used, to pass parameters to the Zabbix API.
     * For more informations about these parameters, check the Zabbix API
     * documentation at https://www.zabbix.com/documentation/.
     *
     * The $arrayKeyProperty can be used to get an associatve instead of an
     * indexed array as response. A valid value for the $arrayKeyProperty is
     * is any property of the returned JSON objects (e.g. name, host,
     * hostid, graphid, screenitemid).
     *
     * @param   $params             Zabbix API parameters.
     * @param   $arrayKeyProperty   Object property for key of array.
     *
     * @retval  stdClass
     *
     * @throws  Exception
     */

    public function hostgroupTableName($params=array(), $arrayKeyProperty='')
    {
        // get params array for request
        $params = $this->getRequestParamsArray($params);

        // check if we've to authenticate
        $auth = in_array('hostgroup.tableName', self::$anonymousFunctions) ? false : true;

        // request
        return $this->request('hostgroup.tableName', $params, $arrayKeyProperty, $auth);
    }
    
    /**
     * @brief   Reqeusts the Zabbix API and returns the response of the API
     *          method hostgroup.pk.
     *
     * The $params Array can be used, to pass parameters to the Zabbix API.
     * For more informations about these parameters, check the Zabbix API
     * documentation at https://www.zabbix.com/documentation/.
     *
     * The $arrayKeyProperty can be used to get an associatve instead of an
     * indexed array as response. A valid value for the $arrayKeyProperty is
     * is any property of the returned JSON objects (e.g. name, host,
     * hostid, graphid, screenitemid).
     *
     * @param   $params             Zabbix API parameters.
     * @param   $arrayKeyProperty   Object property for key of array.
     *
     * @retval  stdClass
     *
     * @throws  Exception
     */

    public function hostgroupPk($params=array(), $arrayKeyProperty='')
    {
        // get params array for request
        $params = $this->getRequestParamsArray($params);

        // check if we've to authenticate
        $auth = in_array('hostgroup.pk', self::$anonymousFunctions) ? false : true;

        // request
        return $this->request('hostgroup.pk', $params, $arrayKeyProperty, $auth);
    }
    
    /**
     * @brief   Reqeusts the Zabbix API and returns the response of the API
     *          method hostgroup.pkOption.
     *
     * The $params Array can be used, to pass parameters to the Zabbix API.
     * For more informations about these parameters, check the Zabbix API
     * documentation at https://www.zabbix.com/documentation/.
     *
     * The $arrayKeyProperty can be used to get an associatve instead of an
     * indexed array as response. A valid value for the $arrayKeyProperty is
     * is any property of the returned JSON objects (e.g. name, host,
     * hostid, graphid, screenitemid).
     *
     * @param   $params             Zabbix API parameters.
     * @param   $arrayKeyProperty   Object property for key of array.
     *
     * @retval  stdClass
     *
     * @throws  Exception
     */

    public function hostgroupPkOption($params=array(), $arrayKeyProperty='')
    {
        // get params array for request
        $params = $this->getRequestParamsArray($params);

        // check if we've to authenticate
        $auth = in_array('hostgroup.pkOption', self::$anonymousFunctions) ? false : true;

        // request
        return $this->request('hostgroup.pkOption', $params, $arrayKeyProperty, $auth);
    }
    
    /**
     * @brief   Reqeusts the Zabbix API and returns the response of the API
     *          method hostprototype.get.
     *
     * The $params Array can be used, to pass parameters to the Zabbix API.
     * For more informations about these parameters, check the Zabbix API
     * documentation at https://www.zabbix.com/documentation/.
     *
     * The $arrayKeyProperty can be used to get an associatve instead of an
     * indexed array as response. A valid value for the $arrayKeyProperty is
     * is any property of the returned JSON objects (e.g. name, host,
     * hostid, graphid, screenitemid).
     *
     * @param   $params             Zabbix API parameters.
     * @param   $arrayKeyProperty   Object property for key of array.
     *
     * @retval  stdClass
     *
     * @throws  Exception
     */

    public function hostprototypeGet($params=array(), $arrayKeyProperty='')
    {
        // get params array for request
        $params = $this->getRequestParamsArray($params);

        // check if we've to authenticate
        $auth = in_array('hostprototype.get', self::$anonymousFunctions) ? false : true;

        // request
        return $this->request('hostprototype.get', $params, $arrayKeyProperty, $auth);
    }
    
    /**
     * @brief   Reqeusts the Zabbix API and returns the response of the API
     *          method hostprototype.create.
     *
     * The $params Array can be used, to pass parameters to the Zabbix API.
     * For more informations about these parameters, check the Zabbix API
     * documentation at https://www.zabbix.com/documentation/.
     *
     * The $arrayKeyProperty can be used to get an associatve instead of an
     * indexed array as response. A valid value for the $arrayKeyProperty is
     * is any property of the returned JSON objects (e.g. name, host,
     * hostid, graphid, screenitemid).
     *
     * @param   $params             Zabbix API parameters.
     * @param   $arrayKeyProperty   Object property for key of array.
     *
     * @retval  stdClass
     *
     * @throws  Exception
     */

    public function hostprototypeCreate($params=array(), $arrayKeyProperty='')
    {
        // get params array for request
        $params = $this->getRequestParamsArray($params);

        // check if we've to authenticate
        $auth = in_array('hostprototype.create', self::$anonymousFunctions) ? false : true;

        // request
        return $this->request('hostprototype.create', $params, $arrayKeyProperty, $auth);
    }
    
    /**
     * @brief   Reqeusts the Zabbix API and returns the response of the API
     *          method hostprototype.update.
     *
     * The $params Array can be used, to pass parameters to the Zabbix API.
     * For more informations about these parameters, check the Zabbix API
     * documentation at https://www.zabbix.com/documentation/.
     *
     * The $arrayKeyProperty can be used to get an associatve instead of an
     * indexed array as response. A valid value for the $arrayKeyProperty is
     * is any property of the returned JSON objects (e.g. name, host,
     * hostid, graphid, screenitemid).
     *
     * @param   $params             Zabbix API parameters.
     * @param   $arrayKeyProperty   Object property for key of array.
     *
     * @retval  stdClass
     *
     * @throws  Exception
     */

    public function hostprototypeUpdate($params=array(), $arrayKeyProperty='')
    {
        // get params array for request
        $params = $this->getRequestParamsArray($params);

        // check if we've to authenticate
        $auth = in_array('hostprototype.update', self::$anonymousFunctions) ? false : true;

        // request
        return $this->request('hostprototype.update', $params, $arrayKeyProperty, $auth);
    }
    
    /**
     * @brief   Reqeusts the Zabbix API and returns the response of the API
     *          method hostprototype.syncTemplates.
     *
     * The $params Array can be used, to pass parameters to the Zabbix API.
     * For more informations about these parameters, check the Zabbix API
     * documentation at https://www.zabbix.com/documentation/.
     *
     * The $arrayKeyProperty can be used to get an associatve instead of an
     * indexed array as response. A valid value for the $arrayKeyProperty is
     * is any property of the returned JSON objects (e.g. name, host,
     * hostid, graphid, screenitemid).
     *
     * @param   $params             Zabbix API parameters.
     * @param   $arrayKeyProperty   Object property for key of array.
     *
     * @retval  stdClass
     *
     * @throws  Exception
     */

    public function hostprototypeSyncTemplates($params=array(), $arrayKeyProperty='')
    {
        // get params array for request
        $params = $this->getRequestParamsArray($params);

        // check if we've to authenticate
        $auth = in_array('hostprototype.syncTemplates', self::$anonymousFunctions) ? false : true;

        // request
        return $this->request('hostprototype.syncTemplates', $params, $arrayKeyProperty, $auth);
    }
    
    /**
     * @brief   Reqeusts the Zabbix API and returns the response of the API
     *          method hostprototype.delete.
     *
     * The $params Array can be used, to pass parameters to the Zabbix API.
     * For more informations about these parameters, check the Zabbix API
     * documentation at https://www.zabbix.com/documentation/.
     *
     * The $arrayKeyProperty can be used to get an associatve instead of an
     * indexed array as response. A valid value for the $arrayKeyProperty is
     * is any property of the returned JSON objects (e.g. name, host,
     * hostid, graphid, screenitemid).
     *
     * @param   $params             Zabbix API parameters.
     * @param   $arrayKeyProperty   Object property for key of array.
     *
     * @retval  stdClass
     *
     * @throws  Exception
     */

    public function hostprototypeDelete($params=array(), $arrayKeyProperty='')
    {
        // get params array for request
        $params = $this->getRequestParamsArray($params);

        // check if we've to authenticate
        $auth = in_array('hostprototype.delete', self::$anonymousFunctions) ? false : true;

        // request
        return $this->request('hostprototype.delete', $params, $arrayKeyProperty, $auth);
    }
    
    /**
     * @brief   Reqeusts the Zabbix API and returns the response of the API
     *          method hostprototype.isReadable.
     *
     * The $params Array can be used, to pass parameters to the Zabbix API.
     * For more informations about these parameters, check the Zabbix API
     * documentation at https://www.zabbix.com/documentation/.
     *
     * The $arrayKeyProperty can be used to get an associatve instead of an
     * indexed array as response. A valid value for the $arrayKeyProperty is
     * is any property of the returned JSON objects (e.g. name, host,
     * hostid, graphid, screenitemid).
     *
     * @param   $params             Zabbix API parameters.
     * @param   $arrayKeyProperty   Object property for key of array.
     *
     * @retval  stdClass
     *
     * @throws  Exception
     */

    public function hostprototypeIsReadable($params=array(), $arrayKeyProperty='')
    {
        // get params array for request
        $params = $this->getRequestParamsArray($params);

        // check if we've to authenticate
        $auth = in_array('hostprototype.isReadable', self::$anonymousFunctions) ? false : true;

        // request
        return $this->request('hostprototype.isReadable', $params, $arrayKeyProperty, $auth);
    }
    
    /**
     * @brief   Reqeusts the Zabbix API and returns the response of the API
     *          method hostprototype.isWritable.
     *
     * The $params Array can be used, to pass parameters to the Zabbix API.
     * For more informations about these parameters, check the Zabbix API
     * documentation at https://www.zabbix.com/documentation/.
     *
     * The $arrayKeyProperty can be used to get an associatve instead of an
     * indexed array as response. A valid value for the $arrayKeyProperty is
     * is any property of the returned JSON objects (e.g. name, host,
     * hostid, graphid, screenitemid).
     *
     * @param   $params             Zabbix API parameters.
     * @param   $arrayKeyProperty   Object property for key of array.
     *
     * @retval  stdClass
     *
     * @throws  Exception
     */

    public function hostprototypeIsWritable($params=array(), $arrayKeyProperty='')
    {
        // get params array for request
        $params = $this->getRequestParamsArray($params);

        // check if we've to authenticate
        $auth = in_array('hostprototype.isWritable', self::$anonymousFunctions) ? false : true;

        // request
        return $this->request('hostprototype.isWritable', $params, $arrayKeyProperty, $auth);
    }
    
    /**
     * @brief   Reqeusts the Zabbix API and returns the response of the API
     *          method hostprototype.tableName.
     *
     * The $params Array can be used, to pass parameters to the Zabbix API.
     * For more informations about these parameters, check the Zabbix API
     * documentation at https://www.zabbix.com/documentation/.
     *
     * The $arrayKeyProperty can be used to get an associatve instead of an
     * indexed array as response. A valid value for the $arrayKeyProperty is
     * is any property of the returned JSON objects (e.g. name, host,
     * hostid, graphid, screenitemid).
     *
     * @param   $params             Zabbix API parameters.
     * @param   $arrayKeyProperty   Object property for key of array.
     *
     * @retval  stdClass
     *
     * @throws  Exception
     */

    public function hostprototypeTableName($params=array(), $arrayKeyProperty='')
    {
        // get params array for request
        $params = $this->getRequestParamsArray($params);

        // check if we've to authenticate
        $auth = in_array('hostprototype.tableName', self::$anonymousFunctions) ? false : true;

        // request
        return $this->request('hostprototype.tableName', $params, $arrayKeyProperty, $auth);
    }
    
    /**
     * @brief   Reqeusts the Zabbix API and returns the response of the API
     *          method hostprototype.pk.
     *
     * The $params Array can be used, to pass parameters to the Zabbix API.
     * For more informations about these parameters, check the Zabbix API
     * documentation at https://www.zabbix.com/documentation/.
     *
     * The $arrayKeyProperty can be used to get an associatve instead of an
     * indexed array as response. A valid value for the $arrayKeyProperty is
     * is any property of the returned JSON objects (e.g. name, host,
     * hostid, graphid, screenitemid).
     *
     * @param   $params             Zabbix API parameters.
     * @param   $arrayKeyProperty   Object property for key of array.
     *
     * @retval  stdClass
     *
     * @throws  Exception
     */

    public function hostprototypePk($params=array(), $arrayKeyProperty='')
    {
        // get params array for request
        $params = $this->getRequestParamsArray($params);

        // check if we've to authenticate
        $auth = in_array('hostprototype.pk', self::$anonymousFunctions) ? false : true;

        // request
        return $this->request('hostprototype.pk', $params, $arrayKeyProperty, $auth);
    }
    
    /**
     * @brief   Reqeusts the Zabbix API and returns the response of the API
     *          method hostprototype.pkOption.
     *
     * The $params Array can be used, to pass parameters to the Zabbix API.
     * For more informations about these parameters, check the Zabbix API
     * documentation at https://www.zabbix.com/documentation/.
     *
     * The $arrayKeyProperty can be used to get an associatve instead of an
     * indexed array as response. A valid value for the $arrayKeyProperty is
     * is any property of the returned JSON objects (e.g. name, host,
     * hostid, graphid, screenitemid).
     *
     * @param   $params             Zabbix API parameters.
     * @param   $arrayKeyProperty   Object property for key of array.
     *
     * @retval  stdClass
     *
     * @throws  Exception
     */

    public function hostprototypePkOption($params=array(), $arrayKeyProperty='')
    {
        // get params array for request
        $params = $this->getRequestParamsArray($params);

        // check if we've to authenticate
        $auth = in_array('hostprototype.pkOption', self::$anonymousFunctions) ? false : true;

        // request
        return $this->request('hostprototype.pkOption', $params, $arrayKeyProperty, $auth);
    }
    
    /**
     * @brief   Reqeusts the Zabbix API and returns the response of the API
     *          method history.get.
     *
     * The $params Array can be used, to pass parameters to the Zabbix API.
     * For more informations about these parameters, check the Zabbix API
     * documentation at https://www.zabbix.com/documentation/.
     *
     * The $arrayKeyProperty can be used to get an associatve instead of an
     * indexed array as response. A valid value for the $arrayKeyProperty is
     * is any property of the returned JSON objects (e.g. name, host,
     * hostid, graphid, screenitemid).
     *
     * @param   $params             Zabbix API parameters.
     * @param   $arrayKeyProperty   Object property for key of array.
     *
     * @retval  stdClass
     *
     * @throws  Exception
     */

    public function historyGet($params=array(), $arrayKeyProperty='')
    {
        // get params array for request
        $params = $this->getRequestParamsArray($params);

        // check if we've to authenticate
        $auth = in_array('history.get', self::$anonymousFunctions) ? false : true;

        // request
        return $this->request('history.get', $params, $arrayKeyProperty, $auth);
    }
    
    /**
     * @brief   Reqeusts the Zabbix API and returns the response of the API
     *          method history.tableName.
     *
     * The $params Array can be used, to pass parameters to the Zabbix API.
     * For more informations about these parameters, check the Zabbix API
     * documentation at https://www.zabbix.com/documentation/.
     *
     * The $arrayKeyProperty can be used to get an associatve instead of an
     * indexed array as response. A valid value for the $arrayKeyProperty is
     * is any property of the returned JSON objects (e.g. name, host,
     * hostid, graphid, screenitemid).
     *
     * @param   $params             Zabbix API parameters.
     * @param   $arrayKeyProperty   Object property for key of array.
     *
     * @retval  stdClass
     *
     * @throws  Exception
     */

    public function historyTableName($params=array(), $arrayKeyProperty='')
    {
        // get params array for request
        $params = $this->getRequestParamsArray($params);

        // check if we've to authenticate
        $auth = in_array('history.tableName', self::$anonymousFunctions) ? false : true;

        // request
        return $this->request('history.tableName', $params, $arrayKeyProperty, $auth);
    }
    
    /**
     * @brief   Reqeusts the Zabbix API and returns the response of the API
     *          method history.pk.
     *
     * The $params Array can be used, to pass parameters to the Zabbix API.
     * For more informations about these parameters, check the Zabbix API
     * documentation at https://www.zabbix.com/documentation/.
     *
     * The $arrayKeyProperty can be used to get an associatve instead of an
     * indexed array as response. A valid value for the $arrayKeyProperty is
     * is any property of the returned JSON objects (e.g. name, host,
     * hostid, graphid, screenitemid).
     *
     * @param   $params             Zabbix API parameters.
     * @param   $arrayKeyProperty   Object property for key of array.
     *
     * @retval  stdClass
     *
     * @throws  Exception
     */

    public function historyPk($params=array(), $arrayKeyProperty='')
    {
        // get params array for request
        $params = $this->getRequestParamsArray($params);

        // check if we've to authenticate
        $auth = in_array('history.pk', self::$anonymousFunctions) ? false : true;

        // request
        return $this->request('history.pk', $params, $arrayKeyProperty, $auth);
    }
    
    /**
     * @brief   Reqeusts the Zabbix API and returns the response of the API
     *          method history.pkOption.
     *
     * The $params Array can be used, to pass parameters to the Zabbix API.
     * For more informations about these parameters, check the Zabbix API
     * documentation at https://www.zabbix.com/documentation/.
     *
     * The $arrayKeyProperty can be used to get an associatve instead of an
     * indexed array as response. A valid value for the $arrayKeyProperty is
     * is any property of the returned JSON objects (e.g. name, host,
     * hostid, graphid, screenitemid).
     *
     * @param   $params             Zabbix API parameters.
     * @param   $arrayKeyProperty   Object property for key of array.
     *
     * @retval  stdClass
     *
     * @throws  Exception
     */

    public function historyPkOption($params=array(), $arrayKeyProperty='')
    {
        // get params array for request
        $params = $this->getRequestParamsArray($params);

        // check if we've to authenticate
        $auth = in_array('history.pkOption', self::$anonymousFunctions) ? false : true;

        // request
        return $this->request('history.pkOption', $params, $arrayKeyProperty, $auth);
    }
    
    /**
     * @brief   Reqeusts the Zabbix API and returns the response of the API
     *          method hostinterface.get.
     *
     * The $params Array can be used, to pass parameters to the Zabbix API.
     * For more informations about these parameters, check the Zabbix API
     * documentation at https://www.zabbix.com/documentation/.
     *
     * The $arrayKeyProperty can be used to get an associatve instead of an
     * indexed array as response. A valid value for the $arrayKeyProperty is
     * is any property of the returned JSON objects (e.g. name, host,
     * hostid, graphid, screenitemid).
     *
     * @param   $params             Zabbix API parameters.
     * @param   $arrayKeyProperty   Object property for key of array.
     *
     * @retval  stdClass
     *
     * @throws  Exception
     */

    public function hostinterfaceGet($params=array(), $arrayKeyProperty='')
    {
        // get params array for request
        $params = $this->getRequestParamsArray($params);

        // check if we've to authenticate
        $auth = in_array('hostinterface.get', self::$anonymousFunctions) ? false : true;

        // request
        return $this->request('hostinterface.get', $params, $arrayKeyProperty, $auth);
    }
    
    /**
     * @brief   Reqeusts the Zabbix API and returns the response of the API
     *          method hostinterface.checkInput.
     *
     * The $params Array can be used, to pass parameters to the Zabbix API.
     * For more informations about these parameters, check the Zabbix API
     * documentation at https://www.zabbix.com/documentation/.
     *
     * The $arrayKeyProperty can be used to get an associatve instead of an
     * indexed array as response. A valid value for the $arrayKeyProperty is
     * is any property of the returned JSON objects (e.g. name, host,
     * hostid, graphid, screenitemid).
     *
     * @param   $params             Zabbix API parameters.
     * @param   $arrayKeyProperty   Object property for key of array.
     *
     * @retval  stdClass
     *
     * @throws  Exception
     */

    public function hostinterfaceCheckInput($params=array(), $arrayKeyProperty='')
    {
        // get params array for request
        $params = $this->getRequestParamsArray($params);

        // check if we've to authenticate
        $auth = in_array('hostinterface.checkInput', self::$anonymousFunctions) ? false : true;

        // request
        return $this->request('hostinterface.checkInput', $params, $arrayKeyProperty, $auth);
    }
    
    /**
     * @brief   Reqeusts the Zabbix API and returns the response of the API
     *          method hostinterface.create.
     *
     * The $params Array can be used, to pass parameters to the Zabbix API.
     * For more informations about these parameters, check the Zabbix API
     * documentation at https://www.zabbix.com/documentation/.
     *
     * The $arrayKeyProperty can be used to get an associatve instead of an
     * indexed array as response. A valid value for the $arrayKeyProperty is
     * is any property of the returned JSON objects (e.g. name, host,
     * hostid, graphid, screenitemid).
     *
     * @param   $params             Zabbix API parameters.
     * @param   $arrayKeyProperty   Object property for key of array.
     *
     * @retval  stdClass
     *
     * @throws  Exception
     */

    public function hostinterfaceCreate($params=array(), $arrayKeyProperty='')
    {
        // get params array for request
        $params = $this->getRequestParamsArray($params);

        // check if we've to authenticate
        $auth = in_array('hostinterface.create', self::$anonymousFunctions) ? false : true;

        // request
        return $this->request('hostinterface.create', $params, $arrayKeyProperty, $auth);
    }
    
    /**
     * @brief   Reqeusts the Zabbix API and returns the response of the API
     *          method hostinterface.update.
     *
     * The $params Array can be used, to pass parameters to the Zabbix API.
     * For more informations about these parameters, check the Zabbix API
     * documentation at https://www.zabbix.com/documentation/.
     *
     * The $arrayKeyProperty can be used to get an associatve instead of an
     * indexed array as response. A valid value for the $arrayKeyProperty is
     * is any property of the returned JSON objects (e.g. name, host,
     * hostid, graphid, screenitemid).
     *
     * @param   $params             Zabbix API parameters.
     * @param   $arrayKeyProperty   Object property for key of array.
     *
     * @retval  stdClass
     *
     * @throws  Exception
     */

    public function hostinterfaceUpdate($params=array(), $arrayKeyProperty='')
    {
        // get params array for request
        $params = $this->getRequestParamsArray($params);

        // check if we've to authenticate
        $auth = in_array('hostinterface.update', self::$anonymousFunctions) ? false : true;

        // request
        return $this->request('hostinterface.update', $params, $arrayKeyProperty, $auth);
    }
    
    /**
     * @brief   Reqeusts the Zabbix API and returns the response of the API
     *          method hostinterface.delete.
     *
     * The $params Array can be used, to pass parameters to the Zabbix API.
     * For more informations about these parameters, check the Zabbix API
     * documentation at https://www.zabbix.com/documentation/.
     *
     * The $arrayKeyProperty can be used to get an associatve instead of an
     * indexed array as response. A valid value for the $arrayKeyProperty is
     * is any property of the returned JSON objects (e.g. name, host,
     * hostid, graphid, screenitemid).
     *
     * @param   $params             Zabbix API parameters.
     * @param   $arrayKeyProperty   Object property for key of array.
     *
     * @retval  stdClass
     *
     * @throws  Exception
     */

    public function hostinterfaceDelete($params=array(), $arrayKeyProperty='')
    {
        // get params array for request
        $params = $this->getRequestParamsArray($params);

        // check if we've to authenticate
        $auth = in_array('hostinterface.delete', self::$anonymousFunctions) ? false : true;

        // request
        return $this->request('hostinterface.delete', $params, $arrayKeyProperty, $auth);
    }
    
    /**
     * @brief   Reqeusts the Zabbix API and returns the response of the API
     *          method hostinterface.massAdd.
     *
     * The $params Array can be used, to pass parameters to the Zabbix API.
     * For more informations about these parameters, check the Zabbix API
     * documentation at https://www.zabbix.com/documentation/.
     *
     * The $arrayKeyProperty can be used to get an associatve instead of an
     * indexed array as response. A valid value for the $arrayKeyProperty is
     * is any property of the returned JSON objects (e.g. name, host,
     * hostid, graphid, screenitemid).
     *
     * @param   $params             Zabbix API parameters.
     * @param   $arrayKeyProperty   Object property for key of array.
     *
     * @retval  stdClass
     *
     * @throws  Exception
     */

    public function hostinterfaceMassAdd($params=array(), $arrayKeyProperty='')
    {
        // get params array for request
        $params = $this->getRequestParamsArray($params);

        // check if we've to authenticate
        $auth = in_array('hostinterface.massAdd', self::$anonymousFunctions) ? false : true;

        // request
        return $this->request('hostinterface.massAdd', $params, $arrayKeyProperty, $auth);
    }
    
    /**
     * @brief   Reqeusts the Zabbix API and returns the response of the API
     *          method hostinterface.massRemove.
     *
     * The $params Array can be used, to pass parameters to the Zabbix API.
     * For more informations about these parameters, check the Zabbix API
     * documentation at https://www.zabbix.com/documentation/.
     *
     * The $arrayKeyProperty can be used to get an associatve instead of an
     * indexed array as response. A valid value for the $arrayKeyProperty is
     * is any property of the returned JSON objects (e.g. name, host,
     * hostid, graphid, screenitemid).
     *
     * @param   $params             Zabbix API parameters.
     * @param   $arrayKeyProperty   Object property for key of array.
     *
     * @retval  stdClass
     *
     * @throws  Exception
     */

    public function hostinterfaceMassRemove($params=array(), $arrayKeyProperty='')
    {
        // get params array for request
        $params = $this->getRequestParamsArray($params);

        // check if we've to authenticate
        $auth = in_array('hostinterface.massRemove', self::$anonymousFunctions) ? false : true;

        // request
        return $this->request('hostinterface.massRemove', $params, $arrayKeyProperty, $auth);
    }
    
    /**
     * @brief   Reqeusts the Zabbix API and returns the response of the API
     *          method hostinterface.replaceHostInterfaces.
     *
     * The $params Array can be used, to pass parameters to the Zabbix API.
     * For more informations about these parameters, check the Zabbix API
     * documentation at https://www.zabbix.com/documentation/.
     *
     * The $arrayKeyProperty can be used to get an associatve instead of an
     * indexed array as response. A valid value for the $arrayKeyProperty is
     * is any property of the returned JSON objects (e.g. name, host,
     * hostid, graphid, screenitemid).
     *
     * @param   $params             Zabbix API parameters.
     * @param   $arrayKeyProperty   Object property for key of array.
     *
     * @retval  stdClass
     *
     * @throws  Exception
     */

    public function hostinterfaceReplaceHostInterfaces($params=array(), $arrayKeyProperty='')
    {
        // get params array for request
        $params = $this->getRequestParamsArray($params);

        // check if we've to authenticate
        $auth = in_array('hostinterface.replaceHostInterfaces', self::$anonymousFunctions) ? false : true;

        // request
        return $this->request('hostinterface.replaceHostInterfaces', $params, $arrayKeyProperty, $auth);
    }
    
    /**
     * @brief   Reqeusts the Zabbix API and returns the response of the API
     *          method hostinterface.tableName.
     *
     * The $params Array can be used, to pass parameters to the Zabbix API.
     * For more informations about these parameters, check the Zabbix API
     * documentation at https://www.zabbix.com/documentation/.
     *
     * The $arrayKeyProperty can be used to get an associatve instead of an
     * indexed array as response. A valid value for the $arrayKeyProperty is
     * is any property of the returned JSON objects (e.g. name, host,
     * hostid, graphid, screenitemid).
     *
     * @param   $params             Zabbix API parameters.
     * @param   $arrayKeyProperty   Object property for key of array.
     *
     * @retval  stdClass
     *
     * @throws  Exception
     */

    public function hostinterfaceTableName($params=array(), $arrayKeyProperty='')
    {
        // get params array for request
        $params = $this->getRequestParamsArray($params);

        // check if we've to authenticate
        $auth = in_array('hostinterface.tableName', self::$anonymousFunctions) ? false : true;

        // request
        return $this->request('hostinterface.tableName', $params, $arrayKeyProperty, $auth);
    }
    
    /**
     * @brief   Reqeusts the Zabbix API and returns the response of the API
     *          method hostinterface.pk.
     *
     * The $params Array can be used, to pass parameters to the Zabbix API.
     * For more informations about these parameters, check the Zabbix API
     * documentation at https://www.zabbix.com/documentation/.
     *
     * The $arrayKeyProperty can be used to get an associatve instead of an
     * indexed array as response. A valid value for the $arrayKeyProperty is
     * is any property of the returned JSON objects (e.g. name, host,
     * hostid, graphid, screenitemid).
     *
     * @param   $params             Zabbix API parameters.
     * @param   $arrayKeyProperty   Object property for key of array.
     *
     * @retval  stdClass
     *
     * @throws  Exception
     */

    public function hostinterfacePk($params=array(), $arrayKeyProperty='')
    {
        // get params array for request
        $params = $this->getRequestParamsArray($params);

        // check if we've to authenticate
        $auth = in_array('hostinterface.pk', self::$anonymousFunctions) ? false : true;

        // request
        return $this->request('hostinterface.pk', $params, $arrayKeyProperty, $auth);
    }
    
    /**
     * @brief   Reqeusts the Zabbix API and returns the response of the API
     *          method hostinterface.pkOption.
     *
     * The $params Array can be used, to pass parameters to the Zabbix API.
     * For more informations about these parameters, check the Zabbix API
     * documentation at https://www.zabbix.com/documentation/.
     *
     * The $arrayKeyProperty can be used to get an associatve instead of an
     * indexed array as response. A valid value for the $arrayKeyProperty is
     * is any property of the returned JSON objects (e.g. name, host,
     * hostid, graphid, screenitemid).
     *
     * @param   $params             Zabbix API parameters.
     * @param   $arrayKeyProperty   Object property for key of array.
     *
     * @retval  stdClass
     *
     * @throws  Exception
     */

    public function hostinterfacePkOption($params=array(), $arrayKeyProperty='')
    {
        // get params array for request
        $params = $this->getRequestParamsArray($params);

        // check if we've to authenticate
        $auth = in_array('hostinterface.pkOption', self::$anonymousFunctions) ? false : true;

        // request
        return $this->request('hostinterface.pkOption', $params, $arrayKeyProperty, $auth);
    }
    
    /**
     * @brief   Reqeusts the Zabbix API and returns the response of the API
     *          method image.get.
     *
     * The $params Array can be used, to pass parameters to the Zabbix API.
     * For more informations about these parameters, check the Zabbix API
     * documentation at https://www.zabbix.com/documentation/.
     *
     * The $arrayKeyProperty can be used to get an associatve instead of an
     * indexed array as response. A valid value for the $arrayKeyProperty is
     * is any property of the returned JSON objects (e.g. name, host,
     * hostid, graphid, screenitemid).
     *
     * @param   $params             Zabbix API parameters.
     * @param   $arrayKeyProperty   Object property for key of array.
     *
     * @retval  stdClass
     *
     * @throws  Exception
     */

    public function imageGet($params=array(), $arrayKeyProperty='')
    {
        // get params array for request
        $params = $this->getRequestParamsArray($params);

        // check if we've to authenticate
        $auth = in_array('image.get', self::$anonymousFunctions) ? false : true;

        // request
        return $this->request('image.get', $params, $arrayKeyProperty, $auth);
    }
    
    /**
     * @brief   Reqeusts the Zabbix API and returns the response of the API
     *          method image.create.
     *
     * The $params Array can be used, to pass parameters to the Zabbix API.
     * For more informations about these parameters, check the Zabbix API
     * documentation at https://www.zabbix.com/documentation/.
     *
     * The $arrayKeyProperty can be used to get an associatve instead of an
     * indexed array as response. A valid value for the $arrayKeyProperty is
     * is any property of the returned JSON objects (e.g. name, host,
     * hostid, graphid, screenitemid).
     *
     * @param   $params             Zabbix API parameters.
     * @param   $arrayKeyProperty   Object property for key of array.
     *
     * @retval  stdClass
     *
     * @throws  Exception
     */

    public function imageCreate($params=array(), $arrayKeyProperty='')
    {
        // get params array for request
        $params = $this->getRequestParamsArray($params);

        // check if we've to authenticate
        $auth = in_array('image.create', self::$anonymousFunctions) ? false : true;

        // request
        return $this->request('image.create', $params, $arrayKeyProperty, $auth);
    }
    
    /**
     * @brief   Reqeusts the Zabbix API and returns the response of the API
     *          method image.update.
     *
     * The $params Array can be used, to pass parameters to the Zabbix API.
     * For more informations about these parameters, check the Zabbix API
     * documentation at https://www.zabbix.com/documentation/.
     *
     * The $arrayKeyProperty can be used to get an associatve instead of an
     * indexed array as response. A valid value for the $arrayKeyProperty is
     * is any property of the returned JSON objects (e.g. name, host,
     * hostid, graphid, screenitemid).
     *
     * @param   $params             Zabbix API parameters.
     * @param   $arrayKeyProperty   Object property for key of array.
     *
     * @retval  stdClass
     *
     * @throws  Exception
     */

    public function imageUpdate($params=array(), $arrayKeyProperty='')
    {
        // get params array for request
        $params = $this->getRequestParamsArray($params);

        // check if we've to authenticate
        $auth = in_array('image.update', self::$anonymousFunctions) ? false : true;

        // request
        return $this->request('image.update', $params, $arrayKeyProperty, $auth);
    }
    
    /**
     * @brief   Reqeusts the Zabbix API and returns the response of the API
     *          method image.delete.
     *
     * The $params Array can be used, to pass parameters to the Zabbix API.
     * For more informations about these parameters, check the Zabbix API
     * documentation at https://www.zabbix.com/documentation/.
     *
     * The $arrayKeyProperty can be used to get an associatve instead of an
     * indexed array as response. A valid value for the $arrayKeyProperty is
     * is any property of the returned JSON objects (e.g. name, host,
     * hostid, graphid, screenitemid).
     *
     * @param   $params             Zabbix API parameters.
     * @param   $arrayKeyProperty   Object property for key of array.
     *
     * @retval  stdClass
     *
     * @throws  Exception
     */

    public function imageDelete($params=array(), $arrayKeyProperty='')
    {
        // get params array for request
        $params = $this->getRequestParamsArray($params);

        // check if we've to authenticate
        $auth = in_array('image.delete', self::$anonymousFunctions) ? false : true;

        // request
        return $this->request('image.delete', $params, $arrayKeyProperty, $auth);
    }
    
    /**
     * @brief   Reqeusts the Zabbix API and returns the response of the API
     *          method image.tableName.
     *
     * The $params Array can be used, to pass parameters to the Zabbix API.
     * For more informations about these parameters, check the Zabbix API
     * documentation at https://www.zabbix.com/documentation/.
     *
     * The $arrayKeyProperty can be used to get an associatve instead of an
     * indexed array as response. A valid value for the $arrayKeyProperty is
     * is any property of the returned JSON objects (e.g. name, host,
     * hostid, graphid, screenitemid).
     *
     * @param   $params             Zabbix API parameters.
     * @param   $arrayKeyProperty   Object property for key of array.
     *
     * @retval  stdClass
     *
     * @throws  Exception
     */

    public function imageTableName($params=array(), $arrayKeyProperty='')
    {
        // get params array for request
        $params = $this->getRequestParamsArray($params);

        // check if we've to authenticate
        $auth = in_array('image.tableName', self::$anonymousFunctions) ? false : true;

        // request
        return $this->request('image.tableName', $params, $arrayKeyProperty, $auth);
    }
    
    /**
     * @brief   Reqeusts the Zabbix API and returns the response of the API
     *          method image.pk.
     *
     * The $params Array can be used, to pass parameters to the Zabbix API.
     * For more informations about these parameters, check the Zabbix API
     * documentation at https://www.zabbix.com/documentation/.
     *
     * The $arrayKeyProperty can be used to get an associatve instead of an
     * indexed array as response. A valid value for the $arrayKeyProperty is
     * is any property of the returned JSON objects (e.g. name, host,
     * hostid, graphid, screenitemid).
     *
     * @param   $params             Zabbix API parameters.
     * @param   $arrayKeyProperty   Object property for key of array.
     *
     * @retval  stdClass
     *
     * @throws  Exception
     */

    public function imagePk($params=array(), $arrayKeyProperty='')
    {
        // get params array for request
        $params = $this->getRequestParamsArray($params);

        // check if we've to authenticate
        $auth = in_array('image.pk', self::$anonymousFunctions) ? false : true;

        // request
        return $this->request('image.pk', $params, $arrayKeyProperty, $auth);
    }
    
    /**
     * @brief   Reqeusts the Zabbix API and returns the response of the API
     *          method image.pkOption.
     *
     * The $params Array can be used, to pass parameters to the Zabbix API.
     * For more informations about these parameters, check the Zabbix API
     * documentation at https://www.zabbix.com/documentation/.
     *
     * The $arrayKeyProperty can be used to get an associatve instead of an
     * indexed array as response. A valid value for the $arrayKeyProperty is
     * is any property of the returned JSON objects (e.g. name, host,
     * hostid, graphid, screenitemid).
     *
     * @param   $params             Zabbix API parameters.
     * @param   $arrayKeyProperty   Object property for key of array.
     *
     * @retval  stdClass
     *
     * @throws  Exception
     */

    public function imagePkOption($params=array(), $arrayKeyProperty='')
    {
        // get params array for request
        $params = $this->getRequestParamsArray($params);

        // check if we've to authenticate
        $auth = in_array('image.pkOption', self::$anonymousFunctions) ? false : true;

        // request
        return $this->request('image.pkOption', $params, $arrayKeyProperty, $auth);
    }
    
    /**
     * @brief   Reqeusts the Zabbix API and returns the response of the API
     *          method iconmap.get.
     *
     * The $params Array can be used, to pass parameters to the Zabbix API.
     * For more informations about these parameters, check the Zabbix API
     * documentation at https://www.zabbix.com/documentation/.
     *
     * The $arrayKeyProperty can be used to get an associatve instead of an
     * indexed array as response. A valid value for the $arrayKeyProperty is
     * is any property of the returned JSON objects (e.g. name, host,
     * hostid, graphid, screenitemid).
     *
     * @param   $params             Zabbix API parameters.
     * @param   $arrayKeyProperty   Object property for key of array.
     *
     * @retval  stdClass
     *
     * @throws  Exception
     */

    public function iconmapGet($params=array(), $arrayKeyProperty='')
    {
        // get params array for request
        $params = $this->getRequestParamsArray($params);

        // check if we've to authenticate
        $auth = in_array('iconmap.get', self::$anonymousFunctions) ? false : true;

        // request
        return $this->request('iconmap.get', $params, $arrayKeyProperty, $auth);
    }
    
    /**
     * @brief   Reqeusts the Zabbix API and returns the response of the API
     *          method iconmap.create.
     *
     * The $params Array can be used, to pass parameters to the Zabbix API.
     * For more informations about these parameters, check the Zabbix API
     * documentation at https://www.zabbix.com/documentation/.
     *
     * The $arrayKeyProperty can be used to get an associatve instead of an
     * indexed array as response. A valid value for the $arrayKeyProperty is
     * is any property of the returned JSON objects (e.g. name, host,
     * hostid, graphid, screenitemid).
     *
     * @param   $params             Zabbix API parameters.
     * @param   $arrayKeyProperty   Object property for key of array.
     *
     * @retval  stdClass
     *
     * @throws  Exception
     */

    public function iconmapCreate($params=array(), $arrayKeyProperty='')
    {
        // get params array for request
        $params = $this->getRequestParamsArray($params);

        // check if we've to authenticate
        $auth = in_array('iconmap.create', self::$anonymousFunctions) ? false : true;

        // request
        return $this->request('iconmap.create', $params, $arrayKeyProperty, $auth);
    }
    
    /**
     * @brief   Reqeusts the Zabbix API and returns the response of the API
     *          method iconmap.update.
     *
     * The $params Array can be used, to pass parameters to the Zabbix API.
     * For more informations about these parameters, check the Zabbix API
     * documentation at https://www.zabbix.com/documentation/.
     *
     * The $arrayKeyProperty can be used to get an associatve instead of an
     * indexed array as response. A valid value for the $arrayKeyProperty is
     * is any property of the returned JSON objects (e.g. name, host,
     * hostid, graphid, screenitemid).
     *
     * @param   $params             Zabbix API parameters.
     * @param   $arrayKeyProperty   Object property for key of array.
     *
     * @retval  stdClass
     *
     * @throws  Exception
     */

    public function iconmapUpdate($params=array(), $arrayKeyProperty='')
    {
        // get params array for request
        $params = $this->getRequestParamsArray($params);

        // check if we've to authenticate
        $auth = in_array('iconmap.update', self::$anonymousFunctions) ? false : true;

        // request
        return $this->request('iconmap.update', $params, $arrayKeyProperty, $auth);
    }
    
    /**
     * @brief   Reqeusts the Zabbix API and returns the response of the API
     *          method iconmap.delete.
     *
     * The $params Array can be used, to pass parameters to the Zabbix API.
     * For more informations about these parameters, check the Zabbix API
     * documentation at https://www.zabbix.com/documentation/.
     *
     * The $arrayKeyProperty can be used to get an associatve instead of an
     * indexed array as response. A valid value for the $arrayKeyProperty is
     * is any property of the returned JSON objects (e.g. name, host,
     * hostid, graphid, screenitemid).
     *
     * @param   $params             Zabbix API parameters.
     * @param   $arrayKeyProperty   Object property for key of array.
     *
     * @retval  stdClass
     *
     * @throws  Exception
     */

    public function iconmapDelete($params=array(), $arrayKeyProperty='')
    {
        // get params array for request
        $params = $this->getRequestParamsArray($params);

        // check if we've to authenticate
        $auth = in_array('iconmap.delete', self::$anonymousFunctions) ? false : true;

        // request
        return $this->request('iconmap.delete', $params, $arrayKeyProperty, $auth);
    }
    
    /**
     * @brief   Reqeusts the Zabbix API and returns the response of the API
     *          method iconmap.isReadable.
     *
     * The $params Array can be used, to pass parameters to the Zabbix API.
     * For more informations about these parameters, check the Zabbix API
     * documentation at https://www.zabbix.com/documentation/.
     *
     * The $arrayKeyProperty can be used to get an associatve instead of an
     * indexed array as response. A valid value for the $arrayKeyProperty is
     * is any property of the returned JSON objects (e.g. name, host,
     * hostid, graphid, screenitemid).
     *
     * @param   $params             Zabbix API parameters.
     * @param   $arrayKeyProperty   Object property for key of array.
     *
     * @retval  stdClass
     *
     * @throws  Exception
     */

    public function iconmapIsReadable($params=array(), $arrayKeyProperty='')
    {
        // get params array for request
        $params = $this->getRequestParamsArray($params);

        // check if we've to authenticate
        $auth = in_array('iconmap.isReadable', self::$anonymousFunctions) ? false : true;

        // request
        return $this->request('iconmap.isReadable', $params, $arrayKeyProperty, $auth);
    }
    
    /**
     * @brief   Reqeusts the Zabbix API and returns the response of the API
     *          method iconmap.isWritable.
     *
     * The $params Array can be used, to pass parameters to the Zabbix API.
     * For more informations about these parameters, check the Zabbix API
     * documentation at https://www.zabbix.com/documentation/.
     *
     * The $arrayKeyProperty can be used to get an associatve instead of an
     * indexed array as response. A valid value for the $arrayKeyProperty is
     * is any property of the returned JSON objects (e.g. name, host,
     * hostid, graphid, screenitemid).
     *
     * @param   $params             Zabbix API parameters.
     * @param   $arrayKeyProperty   Object property for key of array.
     *
     * @retval  stdClass
     *
     * @throws  Exception
     */

    public function iconmapIsWritable($params=array(), $arrayKeyProperty='')
    {
        // get params array for request
        $params = $this->getRequestParamsArray($params);

        // check if we've to authenticate
        $auth = in_array('iconmap.isWritable', self::$anonymousFunctions) ? false : true;

        // request
        return $this->request('iconmap.isWritable', $params, $arrayKeyProperty, $auth);
    }
    
    /**
     * @brief   Reqeusts the Zabbix API and returns the response of the API
     *          method iconmap.tableName.
     *
     * The $params Array can be used, to pass parameters to the Zabbix API.
     * For more informations about these parameters, check the Zabbix API
     * documentation at https://www.zabbix.com/documentation/.
     *
     * The $arrayKeyProperty can be used to get an associatve instead of an
     * indexed array as response. A valid value for the $arrayKeyProperty is
     * is any property of the returned JSON objects (e.g. name, host,
     * hostid, graphid, screenitemid).
     *
     * @param   $params             Zabbix API parameters.
     * @param   $arrayKeyProperty   Object property for key of array.
     *
     * @retval  stdClass
     *
     * @throws  Exception
     */

    public function iconmapTableName($params=array(), $arrayKeyProperty='')
    {
        // get params array for request
        $params = $this->getRequestParamsArray($params);

        // check if we've to authenticate
        $auth = in_array('iconmap.tableName', self::$anonymousFunctions) ? false : true;

        // request
        return $this->request('iconmap.tableName', $params, $arrayKeyProperty, $auth);
    }
    
    /**
     * @brief   Reqeusts the Zabbix API and returns the response of the API
     *          method iconmap.pk.
     *
     * The $params Array can be used, to pass parameters to the Zabbix API.
     * For more informations about these parameters, check the Zabbix API
     * documentation at https://www.zabbix.com/documentation/.
     *
     * The $arrayKeyProperty can be used to get an associatve instead of an
     * indexed array as response. A valid value for the $arrayKeyProperty is
     * is any property of the returned JSON objects (e.g. name, host,
     * hostid, graphid, screenitemid).
     *
     * @param   $params             Zabbix API parameters.
     * @param   $arrayKeyProperty   Object property for key of array.
     *
     * @retval  stdClass
     *
     * @throws  Exception
     */

    public function iconmapPk($params=array(), $arrayKeyProperty='')
    {
        // get params array for request
        $params = $this->getRequestParamsArray($params);

        // check if we've to authenticate
        $auth = in_array('iconmap.pk', self::$anonymousFunctions) ? false : true;

        // request
        return $this->request('iconmap.pk', $params, $arrayKeyProperty, $auth);
    }
    
    /**
     * @brief   Reqeusts the Zabbix API and returns the response of the API
     *          method iconmap.pkOption.
     *
     * The $params Array can be used, to pass parameters to the Zabbix API.
     * For more informations about these parameters, check the Zabbix API
     * documentation at https://www.zabbix.com/documentation/.
     *
     * The $arrayKeyProperty can be used to get an associatve instead of an
     * indexed array as response. A valid value for the $arrayKeyProperty is
     * is any property of the returned JSON objects (e.g. name, host,
     * hostid, graphid, screenitemid).
     *
     * @param   $params             Zabbix API parameters.
     * @param   $arrayKeyProperty   Object property for key of array.
     *
     * @retval  stdClass
     *
     * @throws  Exception
     */

    public function iconmapPkOption($params=array(), $arrayKeyProperty='')
    {
        // get params array for request
        $params = $this->getRequestParamsArray($params);

        // check if we've to authenticate
        $auth = in_array('iconmap.pkOption', self::$anonymousFunctions) ? false : true;

        // request
        return $this->request('iconmap.pkOption', $params, $arrayKeyProperty, $auth);
    }
    
    /**
     * @brief   Reqeusts the Zabbix API and returns the response of the API
     *          method item.get.
     *
     * The $params Array can be used, to pass parameters to the Zabbix API.
     * For more informations about these parameters, check the Zabbix API
     * documentation at https://www.zabbix.com/documentation/.
     *
     * The $arrayKeyProperty can be used to get an associatve instead of an
     * indexed array as response. A valid value for the $arrayKeyProperty is
     * is any property of the returned JSON objects (e.g. name, host,
     * hostid, graphid, screenitemid).
     *
     * @param   $params             Zabbix API parameters.
     * @param   $arrayKeyProperty   Object property for key of array.
     *
     * @retval  stdClass
     *
     * @throws  Exception
     */

    public function itemGet($params=array(), $arrayKeyProperty='')
    {
        // get params array for request
        $params = $this->getRequestParamsArray($params);

        // check if we've to authenticate
        $auth = in_array('item.get', self::$anonymousFunctions) ? false : true;

        // request
        return $this->request('item.get', $params, $arrayKeyProperty, $auth);
    }
    
    /**
     * @brief   Reqeusts the Zabbix API and returns the response of the API
     *          method item.create.
     *
     * The $params Array can be used, to pass parameters to the Zabbix API.
     * For more informations about these parameters, check the Zabbix API
     * documentation at https://www.zabbix.com/documentation/.
     *
     * The $arrayKeyProperty can be used to get an associatve instead of an
     * indexed array as response. A valid value for the $arrayKeyProperty is
     * is any property of the returned JSON objects (e.g. name, host,
     * hostid, graphid, screenitemid).
     *
     * @param   $params             Zabbix API parameters.
     * @param   $arrayKeyProperty   Object property for key of array.
     *
     * @retval  stdClass
     *
     * @throws  Exception
     */

    public function itemCreate($params=array(), $arrayKeyProperty='')
    {
        // get params array for request
        $params = $this->getRequestParamsArray($params);

        // check if we've to authenticate
        $auth = in_array('item.create', self::$anonymousFunctions) ? false : true;

        // request
        return $this->request('item.create', $params, $arrayKeyProperty, $auth);
    }
    
    /**
     * @brief   Reqeusts the Zabbix API and returns the response of the API
     *          method item.update.
     *
     * The $params Array can be used, to pass parameters to the Zabbix API.
     * For more informations about these parameters, check the Zabbix API
     * documentation at https://www.zabbix.com/documentation/.
     *
     * The $arrayKeyProperty can be used to get an associatve instead of an
     * indexed array as response. A valid value for the $arrayKeyProperty is
     * is any property of the returned JSON objects (e.g. name, host,
     * hostid, graphid, screenitemid).
     *
     * @param   $params             Zabbix API parameters.
     * @param   $arrayKeyProperty   Object property for key of array.
     *
     * @retval  stdClass
     *
     * @throws  Exception
     */

    public function itemUpdate($params=array(), $arrayKeyProperty='')
    {
        // get params array for request
        $params = $this->getRequestParamsArray($params);

        // check if we've to authenticate
        $auth = in_array('item.update', self::$anonymousFunctions) ? false : true;

        // request
        return $this->request('item.update', $params, $arrayKeyProperty, $auth);
    }
    
    /**
     * @brief   Reqeusts the Zabbix API and returns the response of the API
     *          method item.delete.
     *
     * The $params Array can be used, to pass parameters to the Zabbix API.
     * For more informations about these parameters, check the Zabbix API
     * documentation at https://www.zabbix.com/documentation/.
     *
     * The $arrayKeyProperty can be used to get an associatve instead of an
     * indexed array as response. A valid value for the $arrayKeyProperty is
     * is any property of the returned JSON objects (e.g. name, host,
     * hostid, graphid, screenitemid).
     *
     * @param   $params             Zabbix API parameters.
     * @param   $arrayKeyProperty   Object property for key of array.
     *
     * @retval  stdClass
     *
     * @throws  Exception
     */

    public function itemDelete($params=array(), $arrayKeyProperty='')
    {
        // get params array for request
        $params = $this->getRequestParamsArray($params);

        // check if we've to authenticate
        $auth = in_array('item.delete', self::$anonymousFunctions) ? false : true;

        // request
        return $this->request('item.delete', $params, $arrayKeyProperty, $auth);
    }
    
    /**
     * @brief   Reqeusts the Zabbix API and returns the response of the API
     *          method item.syncTemplates.
     *
     * The $params Array can be used, to pass parameters to the Zabbix API.
     * For more informations about these parameters, check the Zabbix API
     * documentation at https://www.zabbix.com/documentation/.
     *
     * The $arrayKeyProperty can be used to get an associatve instead of an
     * indexed array as response. A valid value for the $arrayKeyProperty is
     * is any property of the returned JSON objects (e.g. name, host,
     * hostid, graphid, screenitemid).
     *
     * @param   $params             Zabbix API parameters.
     * @param   $arrayKeyProperty   Object property for key of array.
     *
     * @retval  stdClass
     *
     * @throws  Exception
     */

    public function itemSyncTemplates($params=array(), $arrayKeyProperty='')
    {
        // get params array for request
        $params = $this->getRequestParamsArray($params);

        // check if we've to authenticate
        $auth = in_array('item.syncTemplates', self::$anonymousFunctions) ? false : true;

        // request
        return $this->request('item.syncTemplates', $params, $arrayKeyProperty, $auth);
    }
    
    /**
     * @brief   Reqeusts the Zabbix API and returns the response of the API
     *          method item.validateInventoryLinks.
     *
     * The $params Array can be used, to pass parameters to the Zabbix API.
     * For more informations about these parameters, check the Zabbix API
     * documentation at https://www.zabbix.com/documentation/.
     *
     * The $arrayKeyProperty can be used to get an associatve instead of an
     * indexed array as response. A valid value for the $arrayKeyProperty is
     * is any property of the returned JSON objects (e.g. name, host,
     * hostid, graphid, screenitemid).
     *
     * @param   $params             Zabbix API parameters.
     * @param   $arrayKeyProperty   Object property for key of array.
     *
     * @retval  stdClass
     *
     * @throws  Exception
     */

    public function itemValidateInventoryLinks($params=array(), $arrayKeyProperty='')
    {
        // get params array for request
        $params = $this->getRequestParamsArray($params);

        // check if we've to authenticate
        $auth = in_array('item.validateInventoryLinks', self::$anonymousFunctions) ? false : true;

        // request
        return $this->request('item.validateInventoryLinks', $params, $arrayKeyProperty, $auth);
    }
    
    /**
     * @brief   Reqeusts the Zabbix API and returns the response of the API
     *          method item.addRelatedObjects.
     *
     * The $params Array can be used, to pass parameters to the Zabbix API.
     * For more informations about these parameters, check the Zabbix API
     * documentation at https://www.zabbix.com/documentation/.
     *
     * The $arrayKeyProperty can be used to get an associatve instead of an
     * indexed array as response. A valid value for the $arrayKeyProperty is
     * is any property of the returned JSON objects (e.g. name, host,
     * hostid, graphid, screenitemid).
     *
     * @param   $params             Zabbix API parameters.
     * @param   $arrayKeyProperty   Object property for key of array.
     *
     * @retval  stdClass
     *
     * @throws  Exception
     */

    public function itemAddRelatedObjects($params=array(), $arrayKeyProperty='')
    {
        // get params array for request
        $params = $this->getRequestParamsArray($params);

        // check if we've to authenticate
        $auth = in_array('item.addRelatedObjects', self::$anonymousFunctions) ? false : true;

        // request
        return $this->request('item.addRelatedObjects', $params, $arrayKeyProperty, $auth);
    }
    
    /**
     * @brief   Reqeusts the Zabbix API and returns the response of the API
     *          method item.findInterfaceForItem.
     *
     * The $params Array can be used, to pass parameters to the Zabbix API.
     * For more informations about these parameters, check the Zabbix API
     * documentation at https://www.zabbix.com/documentation/.
     *
     * The $arrayKeyProperty can be used to get an associatve instead of an
     * indexed array as response. A valid value for the $arrayKeyProperty is
     * is any property of the returned JSON objects (e.g. name, host,
     * hostid, graphid, screenitemid).
     *
     * @param   $params             Zabbix API parameters.
     * @param   $arrayKeyProperty   Object property for key of array.
     *
     * @retval  stdClass
     *
     * @throws  Exception
     */

    public function itemFindInterfaceForItem($params=array(), $arrayKeyProperty='')
    {
        // get params array for request
        $params = $this->getRequestParamsArray($params);

        // check if we've to authenticate
        $auth = in_array('item.findInterfaceForItem', self::$anonymousFunctions) ? false : true;

        // request
        return $this->request('item.findInterfaceForItem', $params, $arrayKeyProperty, $auth);
    }
    
    /**
     * @brief   Reqeusts the Zabbix API and returns the response of the API
     *          method item.isReadable.
     *
     * The $params Array can be used, to pass parameters to the Zabbix API.
     * For more informations about these parameters, check the Zabbix API
     * documentation at https://www.zabbix.com/documentation/.
     *
     * The $arrayKeyProperty can be used to get an associatve instead of an
     * indexed array as response. A valid value for the $arrayKeyProperty is
     * is any property of the returned JSON objects (e.g. name, host,
     * hostid, graphid, screenitemid).
     *
     * @param   $params             Zabbix API parameters.
     * @param   $arrayKeyProperty   Object property for key of array.
     *
     * @retval  stdClass
     *
     * @throws  Exception
     */

    public function itemIsReadable($params=array(), $arrayKeyProperty='')
    {
        // get params array for request
        $params = $this->getRequestParamsArray($params);

        // check if we've to authenticate
        $auth = in_array('item.isReadable', self::$anonymousFunctions) ? false : true;

        // request
        return $this->request('item.isReadable', $params, $arrayKeyProperty, $auth);
    }
    
    /**
     * @brief   Reqeusts the Zabbix API and returns the response of the API
     *          method item.isWritable.
     *
     * The $params Array can be used, to pass parameters to the Zabbix API.
     * For more informations about these parameters, check the Zabbix API
     * documentation at https://www.zabbix.com/documentation/.
     *
     * The $arrayKeyProperty can be used to get an associatve instead of an
     * indexed array as response. A valid value for the $arrayKeyProperty is
     * is any property of the returned JSON objects (e.g. name, host,
     * hostid, graphid, screenitemid).
     *
     * @param   $params             Zabbix API parameters.
     * @param   $arrayKeyProperty   Object property for key of array.
     *
     * @retval  stdClass
     *
     * @throws  Exception
     */

    public function itemIsWritable($params=array(), $arrayKeyProperty='')
    {
        // get params array for request
        $params = $this->getRequestParamsArray($params);

        // check if we've to authenticate
        $auth = in_array('item.isWritable', self::$anonymousFunctions) ? false : true;

        // request
        return $this->request('item.isWritable', $params, $arrayKeyProperty, $auth);
    }
    
    /**
     * @brief   Reqeusts the Zabbix API and returns the response of the API
     *          method item.tableName.
     *
     * The $params Array can be used, to pass parameters to the Zabbix API.
     * For more informations about these parameters, check the Zabbix API
     * documentation at https://www.zabbix.com/documentation/.
     *
     * The $arrayKeyProperty can be used to get an associatve instead of an
     * indexed array as response. A valid value for the $arrayKeyProperty is
     * is any property of the returned JSON objects (e.g. name, host,
     * hostid, graphid, screenitemid).
     *
     * @param   $params             Zabbix API parameters.
     * @param   $arrayKeyProperty   Object property for key of array.
     *
     * @retval  stdClass
     *
     * @throws  Exception
     */

    public function itemTableName($params=array(), $arrayKeyProperty='')
    {
        // get params array for request
        $params = $this->getRequestParamsArray($params);

        // check if we've to authenticate
        $auth = in_array('item.tableName', self::$anonymousFunctions) ? false : true;

        // request
        return $this->request('item.tableName', $params, $arrayKeyProperty, $auth);
    }
    
    /**
     * @brief   Reqeusts the Zabbix API and returns the response of the API
     *          method item.pk.
     *
     * The $params Array can be used, to pass parameters to the Zabbix API.
     * For more informations about these parameters, check the Zabbix API
     * documentation at https://www.zabbix.com/documentation/.
     *
     * The $arrayKeyProperty can be used to get an associatve instead of an
     * indexed array as response. A valid value for the $arrayKeyProperty is
     * is any property of the returned JSON objects (e.g. name, host,
     * hostid, graphid, screenitemid).
     *
     * @param   $params             Zabbix API parameters.
     * @param   $arrayKeyProperty   Object property for key of array.
     *
     * @retval  stdClass
     *
     * @throws  Exception
     */

    public function itemPk($params=array(), $arrayKeyProperty='')
    {
        // get params array for request
        $params = $this->getRequestParamsArray($params);

        // check if we've to authenticate
        $auth = in_array('item.pk', self::$anonymousFunctions) ? false : true;

        // request
        return $this->request('item.pk', $params, $arrayKeyProperty, $auth);
    }
    
    /**
     * @brief   Reqeusts the Zabbix API and returns the response of the API
     *          method item.pkOption.
     *
     * The $params Array can be used, to pass parameters to the Zabbix API.
     * For more informations about these parameters, check the Zabbix API
     * documentation at https://www.zabbix.com/documentation/.
     *
     * The $arrayKeyProperty can be used to get an associatve instead of an
     * indexed array as response. A valid value for the $arrayKeyProperty is
     * is any property of the returned JSON objects (e.g. name, host,
     * hostid, graphid, screenitemid).
     *
     * @param   $params             Zabbix API parameters.
     * @param   $arrayKeyProperty   Object property for key of array.
     *
     * @retval  stdClass
     *
     * @throws  Exception
     */

    public function itemPkOption($params=array(), $arrayKeyProperty='')
    {
        // get params array for request
        $params = $this->getRequestParamsArray($params);

        // check if we've to authenticate
        $auth = in_array('item.pkOption', self::$anonymousFunctions) ? false : true;

        // request
        return $this->request('item.pkOption', $params, $arrayKeyProperty, $auth);
    }
    
    /**
     * @brief   Reqeusts the Zabbix API and returns the response of the API
     *          method itemprototype.get.
     *
     * The $params Array can be used, to pass parameters to the Zabbix API.
     * For more informations about these parameters, check the Zabbix API
     * documentation at https://www.zabbix.com/documentation/.
     *
     * The $arrayKeyProperty can be used to get an associatve instead of an
     * indexed array as response. A valid value for the $arrayKeyProperty is
     * is any property of the returned JSON objects (e.g. name, host,
     * hostid, graphid, screenitemid).
     *
     * @param   $params             Zabbix API parameters.
     * @param   $arrayKeyProperty   Object property for key of array.
     *
     * @retval  stdClass
     *
     * @throws  Exception
     */

    public function itemprototypeGet($params=array(), $arrayKeyProperty='')
    {
        // get params array for request
        $params = $this->getRequestParamsArray($params);

        // check if we've to authenticate
        $auth = in_array('itemprototype.get', self::$anonymousFunctions) ? false : true;

        // request
        return $this->request('itemprototype.get', $params, $arrayKeyProperty, $auth);
    }
    
    /**
     * @brief   Reqeusts the Zabbix API and returns the response of the API
     *          method itemprototype.create.
     *
     * The $params Array can be used, to pass parameters to the Zabbix API.
     * For more informations about these parameters, check the Zabbix API
     * documentation at https://www.zabbix.com/documentation/.
     *
     * The $arrayKeyProperty can be used to get an associatve instead of an
     * indexed array as response. A valid value for the $arrayKeyProperty is
     * is any property of the returned JSON objects (e.g. name, host,
     * hostid, graphid, screenitemid).
     *
     * @param   $params             Zabbix API parameters.
     * @param   $arrayKeyProperty   Object property for key of array.
     *
     * @retval  stdClass
     *
     * @throws  Exception
     */

    public function itemprototypeCreate($params=array(), $arrayKeyProperty='')
    {
        // get params array for request
        $params = $this->getRequestParamsArray($params);

        // check if we've to authenticate
        $auth = in_array('itemprototype.create', self::$anonymousFunctions) ? false : true;

        // request
        return $this->request('itemprototype.create', $params, $arrayKeyProperty, $auth);
    }
    
    /**
     * @brief   Reqeusts the Zabbix API and returns the response of the API
     *          method itemprototype.update.
     *
     * The $params Array can be used, to pass parameters to the Zabbix API.
     * For more informations about these parameters, check the Zabbix API
     * documentation at https://www.zabbix.com/documentation/.
     *
     * The $arrayKeyProperty can be used to get an associatve instead of an
     * indexed array as response. A valid value for the $arrayKeyProperty is
     * is any property of the returned JSON objects (e.g. name, host,
     * hostid, graphid, screenitemid).
     *
     * @param   $params             Zabbix API parameters.
     * @param   $arrayKeyProperty   Object property for key of array.
     *
     * @retval  stdClass
     *
     * @throws  Exception
     */

    public function itemprototypeUpdate($params=array(), $arrayKeyProperty='')
    {
        // get params array for request
        $params = $this->getRequestParamsArray($params);

        // check if we've to authenticate
        $auth = in_array('itemprototype.update', self::$anonymousFunctions) ? false : true;

        // request
        return $this->request('itemprototype.update', $params, $arrayKeyProperty, $auth);
    }
    
    /**
     * @brief   Reqeusts the Zabbix API and returns the response of the API
     *          method itemprototype.delete.
     *
     * The $params Array can be used, to pass parameters to the Zabbix API.
     * For more informations about these parameters, check the Zabbix API
     * documentation at https://www.zabbix.com/documentation/.
     *
     * The $arrayKeyProperty can be used to get an associatve instead of an
     * indexed array as response. A valid value for the $arrayKeyProperty is
     * is any property of the returned JSON objects (e.g. name, host,
     * hostid, graphid, screenitemid).
     *
     * @param   $params             Zabbix API parameters.
     * @param   $arrayKeyProperty   Object property for key of array.
     *
     * @retval  stdClass
     *
     * @throws  Exception
     */

    public function itemprototypeDelete($params=array(), $arrayKeyProperty='')
    {
        // get params array for request
        $params = $this->getRequestParamsArray($params);

        // check if we've to authenticate
        $auth = in_array('itemprototype.delete', self::$anonymousFunctions) ? false : true;

        // request
        return $this->request('itemprototype.delete', $params, $arrayKeyProperty, $auth);
    }
    
    /**
     * @brief   Reqeusts the Zabbix API and returns the response of the API
     *          method itemprototype.syncTemplates.
     *
     * The $params Array can be used, to pass parameters to the Zabbix API.
     * For more informations about these parameters, check the Zabbix API
     * documentation at https://www.zabbix.com/documentation/.
     *
     * The $arrayKeyProperty can be used to get an associatve instead of an
     * indexed array as response. A valid value for the $arrayKeyProperty is
     * is any property of the returned JSON objects (e.g. name, host,
     * hostid, graphid, screenitemid).
     *
     * @param   $params             Zabbix API parameters.
     * @param   $arrayKeyProperty   Object property for key of array.
     *
     * @retval  stdClass
     *
     * @throws  Exception
     */

    public function itemprototypeSyncTemplates($params=array(), $arrayKeyProperty='')
    {
        // get params array for request
        $params = $this->getRequestParamsArray($params);

        // check if we've to authenticate
        $auth = in_array('itemprototype.syncTemplates', self::$anonymousFunctions) ? false : true;

        // request
        return $this->request('itemprototype.syncTemplates', $params, $arrayKeyProperty, $auth);
    }
    
    /**
     * @brief   Reqeusts the Zabbix API and returns the response of the API
     *          method itemprototype.addRelatedObjects.
     *
     * The $params Array can be used, to pass parameters to the Zabbix API.
     * For more informations about these parameters, check the Zabbix API
     * documentation at https://www.zabbix.com/documentation/.
     *
     * The $arrayKeyProperty can be used to get an associatve instead of an
     * indexed array as response. A valid value for the $arrayKeyProperty is
     * is any property of the returned JSON objects (e.g. name, host,
     * hostid, graphid, screenitemid).
     *
     * @param   $params             Zabbix API parameters.
     * @param   $arrayKeyProperty   Object property for key of array.
     *
     * @retval  stdClass
     *
     * @throws  Exception
     */

    public function itemprototypeAddRelatedObjects($params=array(), $arrayKeyProperty='')
    {
        // get params array for request
        $params = $this->getRequestParamsArray($params);

        // check if we've to authenticate
        $auth = in_array('itemprototype.addRelatedObjects', self::$anonymousFunctions) ? false : true;

        // request
        return $this->request('itemprototype.addRelatedObjects', $params, $arrayKeyProperty, $auth);
    }
    
    /**
     * @brief   Reqeusts the Zabbix API and returns the response of the API
     *          method itemprototype.findInterfaceForItem.
     *
     * The $params Array can be used, to pass parameters to the Zabbix API.
     * For more informations about these parameters, check the Zabbix API
     * documentation at https://www.zabbix.com/documentation/.
     *
     * The $arrayKeyProperty can be used to get an associatve instead of an
     * indexed array as response. A valid value for the $arrayKeyProperty is
     * is any property of the returned JSON objects (e.g. name, host,
     * hostid, graphid, screenitemid).
     *
     * @param   $params             Zabbix API parameters.
     * @param   $arrayKeyProperty   Object property for key of array.
     *
     * @retval  stdClass
     *
     * @throws  Exception
     */

    public function itemprototypeFindInterfaceForItem($params=array(), $arrayKeyProperty='')
    {
        // get params array for request
        $params = $this->getRequestParamsArray($params);

        // check if we've to authenticate
        $auth = in_array('itemprototype.findInterfaceForItem', self::$anonymousFunctions) ? false : true;

        // request
        return $this->request('itemprototype.findInterfaceForItem', $params, $arrayKeyProperty, $auth);
    }
    
    /**
     * @brief   Reqeusts the Zabbix API and returns the response of the API
     *          method itemprototype.isReadable.
     *
     * The $params Array can be used, to pass parameters to the Zabbix API.
     * For more informations about these parameters, check the Zabbix API
     * documentation at https://www.zabbix.com/documentation/.
     *
     * The $arrayKeyProperty can be used to get an associatve instead of an
     * indexed array as response. A valid value for the $arrayKeyProperty is
     * is any property of the returned JSON objects (e.g. name, host,
     * hostid, graphid, screenitemid).
     *
     * @param   $params             Zabbix API parameters.
     * @param   $arrayKeyProperty   Object property for key of array.
     *
     * @retval  stdClass
     *
     * @throws  Exception
     */

    public function itemprototypeIsReadable($params=array(), $arrayKeyProperty='')
    {
        // get params array for request
        $params = $this->getRequestParamsArray($params);

        // check if we've to authenticate
        $auth = in_array('itemprototype.isReadable', self::$anonymousFunctions) ? false : true;

        // request
        return $this->request('itemprototype.isReadable', $params, $arrayKeyProperty, $auth);
    }
    
    /**
     * @brief   Reqeusts the Zabbix API and returns the response of the API
     *          method itemprototype.isWritable.
     *
     * The $params Array can be used, to pass parameters to the Zabbix API.
     * For more informations about these parameters, check the Zabbix API
     * documentation at https://www.zabbix.com/documentation/.
     *
     * The $arrayKeyProperty can be used to get an associatve instead of an
     * indexed array as response. A valid value for the $arrayKeyProperty is
     * is any property of the returned JSON objects (e.g. name, host,
     * hostid, graphid, screenitemid).
     *
     * @param   $params             Zabbix API parameters.
     * @param   $arrayKeyProperty   Object property for key of array.
     *
     * @retval  stdClass
     *
     * @throws  Exception
     */

    public function itemprototypeIsWritable($params=array(), $arrayKeyProperty='')
    {
        // get params array for request
        $params = $this->getRequestParamsArray($params);

        // check if we've to authenticate
        $auth = in_array('itemprototype.isWritable', self::$anonymousFunctions) ? false : true;

        // request
        return $this->request('itemprototype.isWritable', $params, $arrayKeyProperty, $auth);
    }
    
    /**
     * @brief   Reqeusts the Zabbix API and returns the response of the API
     *          method itemprototype.tableName.
     *
     * The $params Array can be used, to pass parameters to the Zabbix API.
     * For more informations about these parameters, check the Zabbix API
     * documentation at https://www.zabbix.com/documentation/.
     *
     * The $arrayKeyProperty can be used to get an associatve instead of an
     * indexed array as response. A valid value for the $arrayKeyProperty is
     * is any property of the returned JSON objects (e.g. name, host,
     * hostid, graphid, screenitemid).
     *
     * @param   $params             Zabbix API parameters.
     * @param   $arrayKeyProperty   Object property for key of array.
     *
     * @retval  stdClass
     *
     * @throws  Exception
     */

    public function itemprototypeTableName($params=array(), $arrayKeyProperty='')
    {
        // get params array for request
        $params = $this->getRequestParamsArray($params);

        // check if we've to authenticate
        $auth = in_array('itemprototype.tableName', self::$anonymousFunctions) ? false : true;

        // request
        return $this->request('itemprototype.tableName', $params, $arrayKeyProperty, $auth);
    }
    
    /**
     * @brief   Reqeusts the Zabbix API and returns the response of the API
     *          method itemprototype.pk.
     *
     * The $params Array can be used, to pass parameters to the Zabbix API.
     * For more informations about these parameters, check the Zabbix API
     * documentation at https://www.zabbix.com/documentation/.
     *
     * The $arrayKeyProperty can be used to get an associatve instead of an
     * indexed array as response. A valid value for the $arrayKeyProperty is
     * is any property of the returned JSON objects (e.g. name, host,
     * hostid, graphid, screenitemid).
     *
     * @param   $params             Zabbix API parameters.
     * @param   $arrayKeyProperty   Object property for key of array.
     *
     * @retval  stdClass
     *
     * @throws  Exception
     */

    public function itemprototypePk($params=array(), $arrayKeyProperty='')
    {
        // get params array for request
        $params = $this->getRequestParamsArray($params);

        // check if we've to authenticate
        $auth = in_array('itemprototype.pk', self::$anonymousFunctions) ? false : true;

        // request
        return $this->request('itemprototype.pk', $params, $arrayKeyProperty, $auth);
    }
    
    /**
     * @brief   Reqeusts the Zabbix API and returns the response of the API
     *          method itemprototype.pkOption.
     *
     * The $params Array can be used, to pass parameters to the Zabbix API.
     * For more informations about these parameters, check the Zabbix API
     * documentation at https://www.zabbix.com/documentation/.
     *
     * The $arrayKeyProperty can be used to get an associatve instead of an
     * indexed array as response. A valid value for the $arrayKeyProperty is
     * is any property of the returned JSON objects (e.g. name, host,
     * hostid, graphid, screenitemid).
     *
     * @param   $params             Zabbix API parameters.
     * @param   $arrayKeyProperty   Object property for key of array.
     *
     * @retval  stdClass
     *
     * @throws  Exception
     */

    public function itemprototypePkOption($params=array(), $arrayKeyProperty='')
    {
        // get params array for request
        $params = $this->getRequestParamsArray($params);

        // check if we've to authenticate
        $auth = in_array('itemprototype.pkOption', self::$anonymousFunctions) ? false : true;

        // request
        return $this->request('itemprototype.pkOption', $params, $arrayKeyProperty, $auth);
    }
    
    /**
     * @brief   Reqeusts the Zabbix API and returns the response of the API
     *          method maintenance.get.
     *
     * The $params Array can be used, to pass parameters to the Zabbix API.
     * For more informations about these parameters, check the Zabbix API
     * documentation at https://www.zabbix.com/documentation/.
     *
     * The $arrayKeyProperty can be used to get an associatve instead of an
     * indexed array as response. A valid value for the $arrayKeyProperty is
     * is any property of the returned JSON objects (e.g. name, host,
     * hostid, graphid, screenitemid).
     *
     * @param   $params             Zabbix API parameters.
     * @param   $arrayKeyProperty   Object property for key of array.
     *
     * @retval  stdClass
     *
     * @throws  Exception
     */

    public function maintenanceGet($params=array(), $arrayKeyProperty='')
    {
        // get params array for request
        $params = $this->getRequestParamsArray($params);

        // check if we've to authenticate
        $auth = in_array('maintenance.get', self::$anonymousFunctions) ? false : true;

        // request
        return $this->request('maintenance.get', $params, $arrayKeyProperty, $auth);
    }
    
    /**
     * @brief   Reqeusts the Zabbix API and returns the response of the API
     *          method maintenance.create.
     *
     * The $params Array can be used, to pass parameters to the Zabbix API.
     * For more informations about these parameters, check the Zabbix API
     * documentation at https://www.zabbix.com/documentation/.
     *
     * The $arrayKeyProperty can be used to get an associatve instead of an
     * indexed array as response. A valid value for the $arrayKeyProperty is
     * is any property of the returned JSON objects (e.g. name, host,
     * hostid, graphid, screenitemid).
     *
     * @param   $params             Zabbix API parameters.
     * @param   $arrayKeyProperty   Object property for key of array.
     *
     * @retval  stdClass
     *
     * @throws  Exception
     */

    public function maintenanceCreate($params=array(), $arrayKeyProperty='')
    {
        // get params array for request
        $params = $this->getRequestParamsArray($params);

        // check if we've to authenticate
        $auth = in_array('maintenance.create', self::$anonymousFunctions) ? false : true;

        // request
        return $this->request('maintenance.create', $params, $arrayKeyProperty, $auth);
    }
    
    /**
     * @brief   Reqeusts the Zabbix API and returns the response of the API
     *          method maintenance.update.
     *
     * The $params Array can be used, to pass parameters to the Zabbix API.
     * For more informations about these parameters, check the Zabbix API
     * documentation at https://www.zabbix.com/documentation/.
     *
     * The $arrayKeyProperty can be used to get an associatve instead of an
     * indexed array as response. A valid value for the $arrayKeyProperty is
     * is any property of the returned JSON objects (e.g. name, host,
     * hostid, graphid, screenitemid).
     *
     * @param   $params             Zabbix API parameters.
     * @param   $arrayKeyProperty   Object property for key of array.
     *
     * @retval  stdClass
     *
     * @throws  Exception
     */

    public function maintenanceUpdate($params=array(), $arrayKeyProperty='')
    {
        // get params array for request
        $params = $this->getRequestParamsArray($params);

        // check if we've to authenticate
        $auth = in_array('maintenance.update', self::$anonymousFunctions) ? false : true;

        // request
        return $this->request('maintenance.update', $params, $arrayKeyProperty, $auth);
    }
    
    /**
     * @brief   Reqeusts the Zabbix API and returns the response of the API
     *          method maintenance.delete.
     *
     * The $params Array can be used, to pass parameters to the Zabbix API.
     * For more informations about these parameters, check the Zabbix API
     * documentation at https://www.zabbix.com/documentation/.
     *
     * The $arrayKeyProperty can be used to get an associatve instead of an
     * indexed array as response. A valid value for the $arrayKeyProperty is
     * is any property of the returned JSON objects (e.g. name, host,
     * hostid, graphid, screenitemid).
     *
     * @param   $params             Zabbix API parameters.
     * @param   $arrayKeyProperty   Object property for key of array.
     *
     * @retval  stdClass
     *
     * @throws  Exception
     */

    public function maintenanceDelete($params=array(), $arrayKeyProperty='')
    {
        // get params array for request
        $params = $this->getRequestParamsArray($params);

        // check if we've to authenticate
        $auth = in_array('maintenance.delete', self::$anonymousFunctions) ? false : true;

        // request
        return $this->request('maintenance.delete', $params, $arrayKeyProperty, $auth);
    }
    
    /**
     * @brief   Reqeusts the Zabbix API and returns the response of the API
     *          method maintenance.tableName.
     *
     * The $params Array can be used, to pass parameters to the Zabbix API.
     * For more informations about these parameters, check the Zabbix API
     * documentation at https://www.zabbix.com/documentation/.
     *
     * The $arrayKeyProperty can be used to get an associatve instead of an
     * indexed array as response. A valid value for the $arrayKeyProperty is
     * is any property of the returned JSON objects (e.g. name, host,
     * hostid, graphid, screenitemid).
     *
     * @param   $params             Zabbix API parameters.
     * @param   $arrayKeyProperty   Object property for key of array.
     *
     * @retval  stdClass
     *
     * @throws  Exception
     */

    public function maintenanceTableName($params=array(), $arrayKeyProperty='')
    {
        // get params array for request
        $params = $this->getRequestParamsArray($params);

        // check if we've to authenticate
        $auth = in_array('maintenance.tableName', self::$anonymousFunctions) ? false : true;

        // request
        return $this->request('maintenance.tableName', $params, $arrayKeyProperty, $auth);
    }
    
    /**
     * @brief   Reqeusts the Zabbix API and returns the response of the API
     *          method maintenance.pk.
     *
     * The $params Array can be used, to pass parameters to the Zabbix API.
     * For more informations about these parameters, check the Zabbix API
     * documentation at https://www.zabbix.com/documentation/.
     *
     * The $arrayKeyProperty can be used to get an associatve instead of an
     * indexed array as response. A valid value for the $arrayKeyProperty is
     * is any property of the returned JSON objects (e.g. name, host,
     * hostid, graphid, screenitemid).
     *
     * @param   $params             Zabbix API parameters.
     * @param   $arrayKeyProperty   Object property for key of array.
     *
     * @retval  stdClass
     *
     * @throws  Exception
     */

    public function maintenancePk($params=array(), $arrayKeyProperty='')
    {
        // get params array for request
        $params = $this->getRequestParamsArray($params);

        // check if we've to authenticate
        $auth = in_array('maintenance.pk', self::$anonymousFunctions) ? false : true;

        // request
        return $this->request('maintenance.pk', $params, $arrayKeyProperty, $auth);
    }
    
    /**
     * @brief   Reqeusts the Zabbix API and returns the response of the API
     *          method maintenance.pkOption.
     *
     * The $params Array can be used, to pass parameters to the Zabbix API.
     * For more informations about these parameters, check the Zabbix API
     * documentation at https://www.zabbix.com/documentation/.
     *
     * The $arrayKeyProperty can be used to get an associatve instead of an
     * indexed array as response. A valid value for the $arrayKeyProperty is
     * is any property of the returned JSON objects (e.g. name, host,
     * hostid, graphid, screenitemid).
     *
     * @param   $params             Zabbix API parameters.
     * @param   $arrayKeyProperty   Object property for key of array.
     *
     * @retval  stdClass
     *
     * @throws  Exception
     */

    public function maintenancePkOption($params=array(), $arrayKeyProperty='')
    {
        // get params array for request
        $params = $this->getRequestParamsArray($params);

        // check if we've to authenticate
        $auth = in_array('maintenance.pkOption', self::$anonymousFunctions) ? false : true;

        // request
        return $this->request('maintenance.pkOption', $params, $arrayKeyProperty, $auth);
    }
    
    /**
     * @brief   Reqeusts the Zabbix API and returns the response of the API
     *          method map.get.
     *
     * The $params Array can be used, to pass parameters to the Zabbix API.
     * For more informations about these parameters, check the Zabbix API
     * documentation at https://www.zabbix.com/documentation/.
     *
     * The $arrayKeyProperty can be used to get an associatve instead of an
     * indexed array as response. A valid value for the $arrayKeyProperty is
     * is any property of the returned JSON objects (e.g. name, host,
     * hostid, graphid, screenitemid).
     *
     * @param   $params             Zabbix API parameters.
     * @param   $arrayKeyProperty   Object property for key of array.
     *
     * @retval  stdClass
     *
     * @throws  Exception
     */

    public function mapGet($params=array(), $arrayKeyProperty='')
    {
        // get params array for request
        $params = $this->getRequestParamsArray($params);

        // check if we've to authenticate
        $auth = in_array('map.get', self::$anonymousFunctions) ? false : true;

        // request
        return $this->request('map.get', $params, $arrayKeyProperty, $auth);
    }
    
    /**
     * @brief   Reqeusts the Zabbix API and returns the response of the API
     *          method map.create.
     *
     * The $params Array can be used, to pass parameters to the Zabbix API.
     * For more informations about these parameters, check the Zabbix API
     * documentation at https://www.zabbix.com/documentation/.
     *
     * The $arrayKeyProperty can be used to get an associatve instead of an
     * indexed array as response. A valid value for the $arrayKeyProperty is
     * is any property of the returned JSON objects (e.g. name, host,
     * hostid, graphid, screenitemid).
     *
     * @param   $params             Zabbix API parameters.
     * @param   $arrayKeyProperty   Object property for key of array.
     *
     * @retval  stdClass
     *
     * @throws  Exception
     */

    public function mapCreate($params=array(), $arrayKeyProperty='')
    {
        // get params array for request
        $params = $this->getRequestParamsArray($params);

        // check if we've to authenticate
        $auth = in_array('map.create', self::$anonymousFunctions) ? false : true;

        // request
        return $this->request('map.create', $params, $arrayKeyProperty, $auth);
    }
    
    /**
     * @brief   Reqeusts the Zabbix API and returns the response of the API
     *          method map.update.
     *
     * The $params Array can be used, to pass parameters to the Zabbix API.
     * For more informations about these parameters, check the Zabbix API
     * documentation at https://www.zabbix.com/documentation/.
     *
     * The $arrayKeyProperty can be used to get an associatve instead of an
     * indexed array as response. A valid value for the $arrayKeyProperty is
     * is any property of the returned JSON objects (e.g. name, host,
     * hostid, graphid, screenitemid).
     *
     * @param   $params             Zabbix API parameters.
     * @param   $arrayKeyProperty   Object property for key of array.
     *
     * @retval  stdClass
     *
     * @throws  Exception
     */

    public function mapUpdate($params=array(), $arrayKeyProperty='')
    {
        // get params array for request
        $params = $this->getRequestParamsArray($params);

        // check if we've to authenticate
        $auth = in_array('map.update', self::$anonymousFunctions) ? false : true;

        // request
        return $this->request('map.update', $params, $arrayKeyProperty, $auth);
    }
    
    /**
     * @brief   Reqeusts the Zabbix API and returns the response of the API
     *          method map.delete.
     *
     * The $params Array can be used, to pass parameters to the Zabbix API.
     * For more informations about these parameters, check the Zabbix API
     * documentation at https://www.zabbix.com/documentation/.
     *
     * The $arrayKeyProperty can be used to get an associatve instead of an
     * indexed array as response. A valid value for the $arrayKeyProperty is
     * is any property of the returned JSON objects (e.g. name, host,
     * hostid, graphid, screenitemid).
     *
     * @param   $params             Zabbix API parameters.
     * @param   $arrayKeyProperty   Object property for key of array.
     *
     * @retval  stdClass
     *
     * @throws  Exception
     */

    public function mapDelete($params=array(), $arrayKeyProperty='')
    {
        // get params array for request
        $params = $this->getRequestParamsArray($params);

        // check if we've to authenticate
        $auth = in_array('map.delete', self::$anonymousFunctions) ? false : true;

        // request
        return $this->request('map.delete', $params, $arrayKeyProperty, $auth);
    }
    
    /**
     * @brief   Reqeusts the Zabbix API and returns the response of the API
     *          method map.isReadable.
     *
     * The $params Array can be used, to pass parameters to the Zabbix API.
     * For more informations about these parameters, check the Zabbix API
     * documentation at https://www.zabbix.com/documentation/.
     *
     * The $arrayKeyProperty can be used to get an associatve instead of an
     * indexed array as response. A valid value for the $arrayKeyProperty is
     * is any property of the returned JSON objects (e.g. name, host,
     * hostid, graphid, screenitemid).
     *
     * @param   $params             Zabbix API parameters.
     * @param   $arrayKeyProperty   Object property for key of array.
     *
     * @retval  stdClass
     *
     * @throws  Exception
     */

    public function mapIsReadable($params=array(), $arrayKeyProperty='')
    {
        // get params array for request
        $params = $this->getRequestParamsArray($params);

        // check if we've to authenticate
        $auth = in_array('map.isReadable', self::$anonymousFunctions) ? false : true;

        // request
        return $this->request('map.isReadable', $params, $arrayKeyProperty, $auth);
    }
    
    /**
     * @brief   Reqeusts the Zabbix API and returns the response of the API
     *          method map.isWritable.
     *
     * The $params Array can be used, to pass parameters to the Zabbix API.
     * For more informations about these parameters, check the Zabbix API
     * documentation at https://www.zabbix.com/documentation/.
     *
     * The $arrayKeyProperty can be used to get an associatve instead of an
     * indexed array as response. A valid value for the $arrayKeyProperty is
     * is any property of the returned JSON objects (e.g. name, host,
     * hostid, graphid, screenitemid).
     *
     * @param   $params             Zabbix API parameters.
     * @param   $arrayKeyProperty   Object property for key of array.
     *
     * @retval  stdClass
     *
     * @throws  Exception
     */

    public function mapIsWritable($params=array(), $arrayKeyProperty='')
    {
        // get params array for request
        $params = $this->getRequestParamsArray($params);

        // check if we've to authenticate
        $auth = in_array('map.isWritable', self::$anonymousFunctions) ? false : true;

        // request
        return $this->request('map.isWritable', $params, $arrayKeyProperty, $auth);
    }
    
    /**
     * @brief   Reqeusts the Zabbix API and returns the response of the API
     *          method map.checkCircleSelementsLink.
     *
     * The $params Array can be used, to pass parameters to the Zabbix API.
     * For more informations about these parameters, check the Zabbix API
     * documentation at https://www.zabbix.com/documentation/.
     *
     * The $arrayKeyProperty can be used to get an associatve instead of an
     * indexed array as response. A valid value for the $arrayKeyProperty is
     * is any property of the returned JSON objects (e.g. name, host,
     * hostid, graphid, screenitemid).
     *
     * @param   $params             Zabbix API parameters.
     * @param   $arrayKeyProperty   Object property for key of array.
     *
     * @retval  stdClass
     *
     * @throws  Exception
     */

    public function mapCheckCircleSelementsLink($params=array(), $arrayKeyProperty='')
    {
        // get params array for request
        $params = $this->getRequestParamsArray($params);

        // check if we've to authenticate
        $auth = in_array('map.checkCircleSelementsLink', self::$anonymousFunctions) ? false : true;

        // request
        return $this->request('map.checkCircleSelementsLink', $params, $arrayKeyProperty, $auth);
    }
    
    /**
     * @brief   Reqeusts the Zabbix API and returns the response of the API
     *          method map.tableName.
     *
     * The $params Array can be used, to pass parameters to the Zabbix API.
     * For more informations about these parameters, check the Zabbix API
     * documentation at https://www.zabbix.com/documentation/.
     *
     * The $arrayKeyProperty can be used to get an associatve instead of an
     * indexed array as response. A valid value for the $arrayKeyProperty is
     * is any property of the returned JSON objects (e.g. name, host,
     * hostid, graphid, screenitemid).
     *
     * @param   $params             Zabbix API parameters.
     * @param   $arrayKeyProperty   Object property for key of array.
     *
     * @retval  stdClass
     *
     * @throws  Exception
     */

    public function mapTableName($params=array(), $arrayKeyProperty='')
    {
        // get params array for request
        $params = $this->getRequestParamsArray($params);

        // check if we've to authenticate
        $auth = in_array('map.tableName', self::$anonymousFunctions) ? false : true;

        // request
        return $this->request('map.tableName', $params, $arrayKeyProperty, $auth);
    }
    
    /**
     * @brief   Reqeusts the Zabbix API and returns the response of the API
     *          method map.pk.
     *
     * The $params Array can be used, to pass parameters to the Zabbix API.
     * For more informations about these parameters, check the Zabbix API
     * documentation at https://www.zabbix.com/documentation/.
     *
     * The $arrayKeyProperty can be used to get an associatve instead of an
     * indexed array as response. A valid value for the $arrayKeyProperty is
     * is any property of the returned JSON objects (e.g. name, host,
     * hostid, graphid, screenitemid).
     *
     * @param   $params             Zabbix API parameters.
     * @param   $arrayKeyProperty   Object property for key of array.
     *
     * @retval  stdClass
     *
     * @throws  Exception
     */

    public function mapPk($params=array(), $arrayKeyProperty='')
    {
        // get params array for request
        $params = $this->getRequestParamsArray($params);

        // check if we've to authenticate
        $auth = in_array('map.pk', self::$anonymousFunctions) ? false : true;

        // request
        return $this->request('map.pk', $params, $arrayKeyProperty, $auth);
    }
    
    /**
     * @brief   Reqeusts the Zabbix API and returns the response of the API
     *          method map.pkOption.
     *
     * The $params Array can be used, to pass parameters to the Zabbix API.
     * For more informations about these parameters, check the Zabbix API
     * documentation at https://www.zabbix.com/documentation/.
     *
     * The $arrayKeyProperty can be used to get an associatve instead of an
     * indexed array as response. A valid value for the $arrayKeyProperty is
     * is any property of the returned JSON objects (e.g. name, host,
     * hostid, graphid, screenitemid).
     *
     * @param   $params             Zabbix API parameters.
     * @param   $arrayKeyProperty   Object property for key of array.
     *
     * @retval  stdClass
     *
     * @throws  Exception
     */

    public function mapPkOption($params=array(), $arrayKeyProperty='')
    {
        // get params array for request
        $params = $this->getRequestParamsArray($params);

        // check if we've to authenticate
        $auth = in_array('map.pkOption', self::$anonymousFunctions) ? false : true;

        // request
        return $this->request('map.pkOption', $params, $arrayKeyProperty, $auth);
    }
    
    /**
     * @brief   Reqeusts the Zabbix API and returns the response of the API
     *          method mediatype.get.
     *
     * The $params Array can be used, to pass parameters to the Zabbix API.
     * For more informations about these parameters, check the Zabbix API
     * documentation at https://www.zabbix.com/documentation/.
     *
     * The $arrayKeyProperty can be used to get an associatve instead of an
     * indexed array as response. A valid value for the $arrayKeyProperty is
     * is any property of the returned JSON objects (e.g. name, host,
     * hostid, graphid, screenitemid).
     *
     * @param   $params             Zabbix API parameters.
     * @param   $arrayKeyProperty   Object property for key of array.
     *
     * @retval  stdClass
     *
     * @throws  Exception
     */

    public function mediatypeGet($params=array(), $arrayKeyProperty='')
    {
        // get params array for request
        $params = $this->getRequestParamsArray($params);

        // check if we've to authenticate
        $auth = in_array('mediatype.get', self::$anonymousFunctions) ? false : true;

        // request
        return $this->request('mediatype.get', $params, $arrayKeyProperty, $auth);
    }
    
    /**
     * @brief   Reqeusts the Zabbix API and returns the response of the API
     *          method mediatype.create.
     *
     * The $params Array can be used, to pass parameters to the Zabbix API.
     * For more informations about these parameters, check the Zabbix API
     * documentation at https://www.zabbix.com/documentation/.
     *
     * The $arrayKeyProperty can be used to get an associatve instead of an
     * indexed array as response. A valid value for the $arrayKeyProperty is
     * is any property of the returned JSON objects (e.g. name, host,
     * hostid, graphid, screenitemid).
     *
     * @param   $params             Zabbix API parameters.
     * @param   $arrayKeyProperty   Object property for key of array.
     *
     * @retval  stdClass
     *
     * @throws  Exception
     */

    public function mediatypeCreate($params=array(), $arrayKeyProperty='')
    {
        // get params array for request
        $params = $this->getRequestParamsArray($params);

        // check if we've to authenticate
        $auth = in_array('mediatype.create', self::$anonymousFunctions) ? false : true;

        // request
        return $this->request('mediatype.create', $params, $arrayKeyProperty, $auth);
    }
    
    /**
     * @brief   Reqeusts the Zabbix API and returns the response of the API
     *          method mediatype.update.
     *
     * The $params Array can be used, to pass parameters to the Zabbix API.
     * For more informations about these parameters, check the Zabbix API
     * documentation at https://www.zabbix.com/documentation/.
     *
     * The $arrayKeyProperty can be used to get an associatve instead of an
     * indexed array as response. A valid value for the $arrayKeyProperty is
     * is any property of the returned JSON objects (e.g. name, host,
     * hostid, graphid, screenitemid).
     *
     * @param   $params             Zabbix API parameters.
     * @param   $arrayKeyProperty   Object property for key of array.
     *
     * @retval  stdClass
     *
     * @throws  Exception
     */

    public function mediatypeUpdate($params=array(), $arrayKeyProperty='')
    {
        // get params array for request
        $params = $this->getRequestParamsArray($params);

        // check if we've to authenticate
        $auth = in_array('mediatype.update', self::$anonymousFunctions) ? false : true;

        // request
        return $this->request('mediatype.update', $params, $arrayKeyProperty, $auth);
    }
    
    /**
     * @brief   Reqeusts the Zabbix API and returns the response of the API
     *          method mediatype.delete.
     *
     * The $params Array can be used, to pass parameters to the Zabbix API.
     * For more informations about these parameters, check the Zabbix API
     * documentation at https://www.zabbix.com/documentation/.
     *
     * The $arrayKeyProperty can be used to get an associatve instead of an
     * indexed array as response. A valid value for the $arrayKeyProperty is
     * is any property of the returned JSON objects (e.g. name, host,
     * hostid, graphid, screenitemid).
     *
     * @param   $params             Zabbix API parameters.
     * @param   $arrayKeyProperty   Object property for key of array.
     *
     * @retval  stdClass
     *
     * @throws  Exception
     */

    public function mediatypeDelete($params=array(), $arrayKeyProperty='')
    {
        // get params array for request
        $params = $this->getRequestParamsArray($params);

        // check if we've to authenticate
        $auth = in_array('mediatype.delete', self::$anonymousFunctions) ? false : true;

        // request
        return $this->request('mediatype.delete', $params, $arrayKeyProperty, $auth);
    }
    
    /**
     * @brief   Reqeusts the Zabbix API and returns the response of the API
     *          method mediatype.tableName.
     *
     * The $params Array can be used, to pass parameters to the Zabbix API.
     * For more informations about these parameters, check the Zabbix API
     * documentation at https://www.zabbix.com/documentation/.
     *
     * The $arrayKeyProperty can be used to get an associatve instead of an
     * indexed array as response. A valid value for the $arrayKeyProperty is
     * is any property of the returned JSON objects (e.g. name, host,
     * hostid, graphid, screenitemid).
     *
     * @param   $params             Zabbix API parameters.
     * @param   $arrayKeyProperty   Object property for key of array.
     *
     * @retval  stdClass
     *
     * @throws  Exception
     */

    public function mediatypeTableName($params=array(), $arrayKeyProperty='')
    {
        // get params array for request
        $params = $this->getRequestParamsArray($params);

        // check if we've to authenticate
        $auth = in_array('mediatype.tableName', self::$anonymousFunctions) ? false : true;

        // request
        return $this->request('mediatype.tableName', $params, $arrayKeyProperty, $auth);
    }
    
    /**
     * @brief   Reqeusts the Zabbix API and returns the response of the API
     *          method mediatype.pk.
     *
     * The $params Array can be used, to pass parameters to the Zabbix API.
     * For more informations about these parameters, check the Zabbix API
     * documentation at https://www.zabbix.com/documentation/.
     *
     * The $arrayKeyProperty can be used to get an associatve instead of an
     * indexed array as response. A valid value for the $arrayKeyProperty is
     * is any property of the returned JSON objects (e.g. name, host,
     * hostid, graphid, screenitemid).
     *
     * @param   $params             Zabbix API parameters.
     * @param   $arrayKeyProperty   Object property for key of array.
     *
     * @retval  stdClass
     *
     * @throws  Exception
     */

    public function mediatypePk($params=array(), $arrayKeyProperty='')
    {
        // get params array for request
        $params = $this->getRequestParamsArray($params);

        // check if we've to authenticate
        $auth = in_array('mediatype.pk', self::$anonymousFunctions) ? false : true;

        // request
        return $this->request('mediatype.pk', $params, $arrayKeyProperty, $auth);
    }
    
    /**
     * @brief   Reqeusts the Zabbix API and returns the response of the API
     *          method mediatype.pkOption.
     *
     * The $params Array can be used, to pass parameters to the Zabbix API.
     * For more informations about these parameters, check the Zabbix API
     * documentation at https://www.zabbix.com/documentation/.
     *
     * The $arrayKeyProperty can be used to get an associatve instead of an
     * indexed array as response. A valid value for the $arrayKeyProperty is
     * is any property of the returned JSON objects (e.g. name, host,
     * hostid, graphid, screenitemid).
     *
     * @param   $params             Zabbix API parameters.
     * @param   $arrayKeyProperty   Object property for key of array.
     *
     * @retval  stdClass
     *
     * @throws  Exception
     */

    public function mediatypePkOption($params=array(), $arrayKeyProperty='')
    {
        // get params array for request
        $params = $this->getRequestParamsArray($params);

        // check if we've to authenticate
        $auth = in_array('mediatype.pkOption', self::$anonymousFunctions) ? false : true;

        // request
        return $this->request('mediatype.pkOption', $params, $arrayKeyProperty, $auth);
    }
    
    /**
     * @brief   Reqeusts the Zabbix API and returns the response of the API
     *          method proxy.get.
     *
     * The $params Array can be used, to pass parameters to the Zabbix API.
     * For more informations about these parameters, check the Zabbix API
     * documentation at https://www.zabbix.com/documentation/.
     *
     * The $arrayKeyProperty can be used to get an associatve instead of an
     * indexed array as response. A valid value for the $arrayKeyProperty is
     * is any property of the returned JSON objects (e.g. name, host,
     * hostid, graphid, screenitemid).
     *
     * @param   $params             Zabbix API parameters.
     * @param   $arrayKeyProperty   Object property for key of array.
     *
     * @retval  stdClass
     *
     * @throws  Exception
     */

    public function proxyGet($params=array(), $arrayKeyProperty='')
    {
        // get params array for request
        $params = $this->getRequestParamsArray($params);

        // check if we've to authenticate
        $auth = in_array('proxy.get', self::$anonymousFunctions) ? false : true;

        // request
        return $this->request('proxy.get', $params, $arrayKeyProperty, $auth);
    }
    
    /**
     * @brief   Reqeusts the Zabbix API and returns the response of the API
     *          method proxy.create.
     *
     * The $params Array can be used, to pass parameters to the Zabbix API.
     * For more informations about these parameters, check the Zabbix API
     * documentation at https://www.zabbix.com/documentation/.
     *
     * The $arrayKeyProperty can be used to get an associatve instead of an
     * indexed array as response. A valid value for the $arrayKeyProperty is
     * is any property of the returned JSON objects (e.g. name, host,
     * hostid, graphid, screenitemid).
     *
     * @param   $params             Zabbix API parameters.
     * @param   $arrayKeyProperty   Object property for key of array.
     *
     * @retval  stdClass
     *
     * @throws  Exception
     */

    public function proxyCreate($params=array(), $arrayKeyProperty='')
    {
        // get params array for request
        $params = $this->getRequestParamsArray($params);

        // check if we've to authenticate
        $auth = in_array('proxy.create', self::$anonymousFunctions) ? false : true;

        // request
        return $this->request('proxy.create', $params, $arrayKeyProperty, $auth);
    }
    
    /**
     * @brief   Reqeusts the Zabbix API and returns the response of the API
     *          method proxy.update.
     *
     * The $params Array can be used, to pass parameters to the Zabbix API.
     * For more informations about these parameters, check the Zabbix API
     * documentation at https://www.zabbix.com/documentation/.
     *
     * The $arrayKeyProperty can be used to get an associatve instead of an
     * indexed array as response. A valid value for the $arrayKeyProperty is
     * is any property of the returned JSON objects (e.g. name, host,
     * hostid, graphid, screenitemid).
     *
     * @param   $params             Zabbix API parameters.
     * @param   $arrayKeyProperty   Object property for key of array.
     *
     * @retval  stdClass
     *
     * @throws  Exception
     */

    public function proxyUpdate($params=array(), $arrayKeyProperty='')
    {
        // get params array for request
        $params = $this->getRequestParamsArray($params);

        // check if we've to authenticate
        $auth = in_array('proxy.update', self::$anonymousFunctions) ? false : true;

        // request
        return $this->request('proxy.update', $params, $arrayKeyProperty, $auth);
    }
    
    /**
     * @brief   Reqeusts the Zabbix API and returns the response of the API
     *          method proxy.delete.
     *
     * The $params Array can be used, to pass parameters to the Zabbix API.
     * For more informations about these parameters, check the Zabbix API
     * documentation at https://www.zabbix.com/documentation/.
     *
     * The $arrayKeyProperty can be used to get an associatve instead of an
     * indexed array as response. A valid value for the $arrayKeyProperty is
     * is any property of the returned JSON objects (e.g. name, host,
     * hostid, graphid, screenitemid).
     *
     * @param   $params             Zabbix API parameters.
     * @param   $arrayKeyProperty   Object property for key of array.
     *
     * @retval  stdClass
     *
     * @throws  Exception
     */

    public function proxyDelete($params=array(), $arrayKeyProperty='')
    {
        // get params array for request
        $params = $this->getRequestParamsArray($params);

        // check if we've to authenticate
        $auth = in_array('proxy.delete', self::$anonymousFunctions) ? false : true;

        // request
        return $this->request('proxy.delete', $params, $arrayKeyProperty, $auth);
    }
    
    /**
     * @brief   Reqeusts the Zabbix API and returns the response of the API
     *          method proxy.isReadable.
     *
     * The $params Array can be used, to pass parameters to the Zabbix API.
     * For more informations about these parameters, check the Zabbix API
     * documentation at https://www.zabbix.com/documentation/.
     *
     * The $arrayKeyProperty can be used to get an associatve instead of an
     * indexed array as response. A valid value for the $arrayKeyProperty is
     * is any property of the returned JSON objects (e.g. name, host,
     * hostid, graphid, screenitemid).
     *
     * @param   $params             Zabbix API parameters.
     * @param   $arrayKeyProperty   Object property for key of array.
     *
     * @retval  stdClass
     *
     * @throws  Exception
     */

    public function proxyIsReadable($params=array(), $arrayKeyProperty='')
    {
        // get params array for request
        $params = $this->getRequestParamsArray($params);

        // check if we've to authenticate
        $auth = in_array('proxy.isReadable', self::$anonymousFunctions) ? false : true;

        // request
        return $this->request('proxy.isReadable', $params, $arrayKeyProperty, $auth);
    }
    
    /**
     * @brief   Reqeusts the Zabbix API and returns the response of the API
     *          method proxy.isWritable.
     *
     * The $params Array can be used, to pass parameters to the Zabbix API.
     * For more informations about these parameters, check the Zabbix API
     * documentation at https://www.zabbix.com/documentation/.
     *
     * The $arrayKeyProperty can be used to get an associatve instead of an
     * indexed array as response. A valid value for the $arrayKeyProperty is
     * is any property of the returned JSON objects (e.g. name, host,
     * hostid, graphid, screenitemid).
     *
     * @param   $params             Zabbix API parameters.
     * @param   $arrayKeyProperty   Object property for key of array.
     *
     * @retval  stdClass
     *
     * @throws  Exception
     */

    public function proxyIsWritable($params=array(), $arrayKeyProperty='')
    {
        // get params array for request
        $params = $this->getRequestParamsArray($params);

        // check if we've to authenticate
        $auth = in_array('proxy.isWritable', self::$anonymousFunctions) ? false : true;

        // request
        return $this->request('proxy.isWritable', $params, $arrayKeyProperty, $auth);
    }
    
    /**
     * @brief   Reqeusts the Zabbix API and returns the response of the API
     *          method proxy.tableName.
     *
     * The $params Array can be used, to pass parameters to the Zabbix API.
     * For more informations about these parameters, check the Zabbix API
     * documentation at https://www.zabbix.com/documentation/.
     *
     * The $arrayKeyProperty can be used to get an associatve instead of an
     * indexed array as response. A valid value for the $arrayKeyProperty is
     * is any property of the returned JSON objects (e.g. name, host,
     * hostid, graphid, screenitemid).
     *
     * @param   $params             Zabbix API parameters.
     * @param   $arrayKeyProperty   Object property for key of array.
     *
     * @retval  stdClass
     *
     * @throws  Exception
     */

    public function proxyTableName($params=array(), $arrayKeyProperty='')
    {
        // get params array for request
        $params = $this->getRequestParamsArray($params);

        // check if we've to authenticate
        $auth = in_array('proxy.tableName', self::$anonymousFunctions) ? false : true;

        // request
        return $this->request('proxy.tableName', $params, $arrayKeyProperty, $auth);
    }
    
    /**
     * @brief   Reqeusts the Zabbix API and returns the response of the API
     *          method proxy.pk.
     *
     * The $params Array can be used, to pass parameters to the Zabbix API.
     * For more informations about these parameters, check the Zabbix API
     * documentation at https://www.zabbix.com/documentation/.
     *
     * The $arrayKeyProperty can be used to get an associatve instead of an
     * indexed array as response. A valid value for the $arrayKeyProperty is
     * is any property of the returned JSON objects (e.g. name, host,
     * hostid, graphid, screenitemid).
     *
     * @param   $params             Zabbix API parameters.
     * @param   $arrayKeyProperty   Object property for key of array.
     *
     * @retval  stdClass
     *
     * @throws  Exception
     */

    public function proxyPk($params=array(), $arrayKeyProperty='')
    {
        // get params array for request
        $params = $this->getRequestParamsArray($params);

        // check if we've to authenticate
        $auth = in_array('proxy.pk', self::$anonymousFunctions) ? false : true;

        // request
        return $this->request('proxy.pk', $params, $arrayKeyProperty, $auth);
    }
    
    /**
     * @brief   Reqeusts the Zabbix API and returns the response of the API
     *          method proxy.pkOption.
     *
     * The $params Array can be used, to pass parameters to the Zabbix API.
     * For more informations about these parameters, check the Zabbix API
     * documentation at https://www.zabbix.com/documentation/.
     *
     * The $arrayKeyProperty can be used to get an associatve instead of an
     * indexed array as response. A valid value for the $arrayKeyProperty is
     * is any property of the returned JSON objects (e.g. name, host,
     * hostid, graphid, screenitemid).
     *
     * @param   $params             Zabbix API parameters.
     * @param   $arrayKeyProperty   Object property for key of array.
     *
     * @retval  stdClass
     *
     * @throws  Exception
     */

    public function proxyPkOption($params=array(), $arrayKeyProperty='')
    {
        // get params array for request
        $params = $this->getRequestParamsArray($params);

        // check if we've to authenticate
        $auth = in_array('proxy.pkOption', self::$anonymousFunctions) ? false : true;

        // request
        return $this->request('proxy.pkOption', $params, $arrayKeyProperty, $auth);
    }
    
    /**
     * @brief   Reqeusts the Zabbix API and returns the response of the API
     *          method service.get.
     *
     * The $params Array can be used, to pass parameters to the Zabbix API.
     * For more informations about these parameters, check the Zabbix API
     * documentation at https://www.zabbix.com/documentation/.
     *
     * The $arrayKeyProperty can be used to get an associatve instead of an
     * indexed array as response. A valid value for the $arrayKeyProperty is
     * is any property of the returned JSON objects (e.g. name, host,
     * hostid, graphid, screenitemid).
     *
     * @param   $params             Zabbix API parameters.
     * @param   $arrayKeyProperty   Object property for key of array.
     *
     * @retval  stdClass
     *
     * @throws  Exception
     */

    public function serviceGet($params=array(), $arrayKeyProperty='')
    {
        // get params array for request
        $params = $this->getRequestParamsArray($params);

        // check if we've to authenticate
        $auth = in_array('service.get', self::$anonymousFunctions) ? false : true;

        // request
        return $this->request('service.get', $params, $arrayKeyProperty, $auth);
    }
    
    /**
     * @brief   Reqeusts the Zabbix API and returns the response of the API
     *          method service.create.
     *
     * The $params Array can be used, to pass parameters to the Zabbix API.
     * For more informations about these parameters, check the Zabbix API
     * documentation at https://www.zabbix.com/documentation/.
     *
     * The $arrayKeyProperty can be used to get an associatve instead of an
     * indexed array as response. A valid value for the $arrayKeyProperty is
     * is any property of the returned JSON objects (e.g. name, host,
     * hostid, graphid, screenitemid).
     *
     * @param   $params             Zabbix API parameters.
     * @param   $arrayKeyProperty   Object property for key of array.
     *
     * @retval  stdClass
     *
     * @throws  Exception
     */

    public function serviceCreate($params=array(), $arrayKeyProperty='')
    {
        // get params array for request
        $params = $this->getRequestParamsArray($params);

        // check if we've to authenticate
        $auth = in_array('service.create', self::$anonymousFunctions) ? false : true;

        // request
        return $this->request('service.create', $params, $arrayKeyProperty, $auth);
    }
    
    /**
     * @brief   Reqeusts the Zabbix API and returns the response of the API
     *          method service.validateUpdate.
     *
     * The $params Array can be used, to pass parameters to the Zabbix API.
     * For more informations about these parameters, check the Zabbix API
     * documentation at https://www.zabbix.com/documentation/.
     *
     * The $arrayKeyProperty can be used to get an associatve instead of an
     * indexed array as response. A valid value for the $arrayKeyProperty is
     * is any property of the returned JSON objects (e.g. name, host,
     * hostid, graphid, screenitemid).
     *
     * @param   $params             Zabbix API parameters.
     * @param   $arrayKeyProperty   Object property for key of array.
     *
     * @retval  stdClass
     *
     * @throws  Exception
     */

    public function serviceValidateUpdate($params=array(), $arrayKeyProperty='')
    {
        // get params array for request
        $params = $this->getRequestParamsArray($params);

        // check if we've to authenticate
        $auth = in_array('service.validateUpdate', self::$anonymousFunctions) ? false : true;

        // request
        return $this->request('service.validateUpdate', $params, $arrayKeyProperty, $auth);
    }
    
    /**
     * @brief   Reqeusts the Zabbix API and returns the response of the API
     *          method service.update.
     *
     * The $params Array can be used, to pass parameters to the Zabbix API.
     * For more informations about these parameters, check the Zabbix API
     * documentation at https://www.zabbix.com/documentation/.
     *
     * The $arrayKeyProperty can be used to get an associatve instead of an
     * indexed array as response. A valid value for the $arrayKeyProperty is
     * is any property of the returned JSON objects (e.g. name, host,
     * hostid, graphid, screenitemid).
     *
     * @param   $params             Zabbix API parameters.
     * @param   $arrayKeyProperty   Object property for key of array.
     *
     * @retval  stdClass
     *
     * @throws  Exception
     */

    public function serviceUpdate($params=array(), $arrayKeyProperty='')
    {
        // get params array for request
        $params = $this->getRequestParamsArray($params);

        // check if we've to authenticate
        $auth = in_array('service.update', self::$anonymousFunctions) ? false : true;

        // request
        return $this->request('service.update', $params, $arrayKeyProperty, $auth);
    }
    
    /**
     * @brief   Reqeusts the Zabbix API and returns the response of the API
     *          method service.validateDelete.
     *
     * The $params Array can be used, to pass parameters to the Zabbix API.
     * For more informations about these parameters, check the Zabbix API
     * documentation at https://www.zabbix.com/documentation/.
     *
     * The $arrayKeyProperty can be used to get an associatve instead of an
     * indexed array as response. A valid value for the $arrayKeyProperty is
     * is any property of the returned JSON objects (e.g. name, host,
     * hostid, graphid, screenitemid).
     *
     * @param   $params             Zabbix API parameters.
     * @param   $arrayKeyProperty   Object property for key of array.
     *
     * @retval  stdClass
     *
     * @throws  Exception
     */

    public function serviceValidateDelete($params=array(), $arrayKeyProperty='')
    {
        // get params array for request
        $params = $this->getRequestParamsArray($params);

        // check if we've to authenticate
        $auth = in_array('service.validateDelete', self::$anonymousFunctions) ? false : true;

        // request
        return $this->request('service.validateDelete', $params, $arrayKeyProperty, $auth);
    }
    
    /**
     * @brief   Reqeusts the Zabbix API and returns the response of the API
     *          method service.delete.
     *
     * The $params Array can be used, to pass parameters to the Zabbix API.
     * For more informations about these parameters, check the Zabbix API
     * documentation at https://www.zabbix.com/documentation/.
     *
     * The $arrayKeyProperty can be used to get an associatve instead of an
     * indexed array as response. A valid value for the $arrayKeyProperty is
     * is any property of the returned JSON objects (e.g. name, host,
     * hostid, graphid, screenitemid).
     *
     * @param   $params             Zabbix API parameters.
     * @param   $arrayKeyProperty   Object property for key of array.
     *
     * @retval  stdClass
     *
     * @throws  Exception
     */

    public function serviceDelete($params=array(), $arrayKeyProperty='')
    {
        // get params array for request
        $params = $this->getRequestParamsArray($params);

        // check if we've to authenticate
        $auth = in_array('service.delete', self::$anonymousFunctions) ? false : true;

        // request
        return $this->request('service.delete', $params, $arrayKeyProperty, $auth);
    }
    
    /**
     * @brief   Reqeusts the Zabbix API and returns the response of the API
     *          method service.addDependencies.
     *
     * The $params Array can be used, to pass parameters to the Zabbix API.
     * For more informations about these parameters, check the Zabbix API
     * documentation at https://www.zabbix.com/documentation/.
     *
     * The $arrayKeyProperty can be used to get an associatve instead of an
     * indexed array as response. A valid value for the $arrayKeyProperty is
     * is any property of the returned JSON objects (e.g. name, host,
     * hostid, graphid, screenitemid).
     *
     * @param   $params             Zabbix API parameters.
     * @param   $arrayKeyProperty   Object property for key of array.
     *
     * @retval  stdClass
     *
     * @throws  Exception
     */

    public function serviceAddDependencies($params=array(), $arrayKeyProperty='')
    {
        // get params array for request
        $params = $this->getRequestParamsArray($params);

        // check if we've to authenticate
        $auth = in_array('service.addDependencies', self::$anonymousFunctions) ? false : true;

        // request
        return $this->request('service.addDependencies', $params, $arrayKeyProperty, $auth);
    }
    
    /**
     * @brief   Reqeusts the Zabbix API and returns the response of the API
     *          method service.deleteDependencies.
     *
     * The $params Array can be used, to pass parameters to the Zabbix API.
     * For more informations about these parameters, check the Zabbix API
     * documentation at https://www.zabbix.com/documentation/.
     *
     * The $arrayKeyProperty can be used to get an associatve instead of an
     * indexed array as response. A valid value for the $arrayKeyProperty is
     * is any property of the returned JSON objects (e.g. name, host,
     * hostid, graphid, screenitemid).
     *
     * @param   $params             Zabbix API parameters.
     * @param   $arrayKeyProperty   Object property for key of array.
     *
     * @retval  stdClass
     *
     * @throws  Exception
     */

    public function serviceDeleteDependencies($params=array(), $arrayKeyProperty='')
    {
        // get params array for request
        $params = $this->getRequestParamsArray($params);

        // check if we've to authenticate
        $auth = in_array('service.deleteDependencies', self::$anonymousFunctions) ? false : true;

        // request
        return $this->request('service.deleteDependencies', $params, $arrayKeyProperty, $auth);
    }
    
    /**
     * @brief   Reqeusts the Zabbix API and returns the response of the API
     *          method service.validateAddTimes.
     *
     * The $params Array can be used, to pass parameters to the Zabbix API.
     * For more informations about these parameters, check the Zabbix API
     * documentation at https://www.zabbix.com/documentation/.
     *
     * The $arrayKeyProperty can be used to get an associatve instead of an
     * indexed array as response. A valid value for the $arrayKeyProperty is
     * is any property of the returned JSON objects (e.g. name, host,
     * hostid, graphid, screenitemid).
     *
     * @param   $params             Zabbix API parameters.
     * @param   $arrayKeyProperty   Object property for key of array.
     *
     * @retval  stdClass
     *
     * @throws  Exception
     */

    public function serviceValidateAddTimes($params=array(), $arrayKeyProperty='')
    {
        // get params array for request
        $params = $this->getRequestParamsArray($params);

        // check if we've to authenticate
        $auth = in_array('service.validateAddTimes', self::$anonymousFunctions) ? false : true;

        // request
        return $this->request('service.validateAddTimes', $params, $arrayKeyProperty, $auth);
    }
    
    /**
     * @brief   Reqeusts the Zabbix API and returns the response of the API
     *          method service.addTimes.
     *
     * The $params Array can be used, to pass parameters to the Zabbix API.
     * For more informations about these parameters, check the Zabbix API
     * documentation at https://www.zabbix.com/documentation/.
     *
     * The $arrayKeyProperty can be used to get an associatve instead of an
     * indexed array as response. A valid value for the $arrayKeyProperty is
     * is any property of the returned JSON objects (e.g. name, host,
     * hostid, graphid, screenitemid).
     *
     * @param   $params             Zabbix API parameters.
     * @param   $arrayKeyProperty   Object property for key of array.
     *
     * @retval  stdClass
     *
     * @throws  Exception
     */

    public function serviceAddTimes($params=array(), $arrayKeyProperty='')
    {
        // get params array for request
        $params = $this->getRequestParamsArray($params);

        // check if we've to authenticate
        $auth = in_array('service.addTimes', self::$anonymousFunctions) ? false : true;

        // request
        return $this->request('service.addTimes', $params, $arrayKeyProperty, $auth);
    }
    
    /**
     * @brief   Reqeusts the Zabbix API and returns the response of the API
     *          method service.getSla.
     *
     * The $params Array can be used, to pass parameters to the Zabbix API.
     * For more informations about these parameters, check the Zabbix API
     * documentation at https://www.zabbix.com/documentation/.
     *
     * The $arrayKeyProperty can be used to get an associatve instead of an
     * indexed array as response. A valid value for the $arrayKeyProperty is
     * is any property of the returned JSON objects (e.g. name, host,
     * hostid, graphid, screenitemid).
     *
     * @param   $params             Zabbix API parameters.
     * @param   $arrayKeyProperty   Object property for key of array.
     *
     * @retval  stdClass
     *
     * @throws  Exception
     */

    public function serviceGetSla($params=array(), $arrayKeyProperty='')
    {
        // get params array for request
        $params = $this->getRequestParamsArray($params);

        // check if we've to authenticate
        $auth = in_array('service.getSla', self::$anonymousFunctions) ? false : true;

        // request
        return $this->request('service.getSla', $params, $arrayKeyProperty, $auth);
    }
    
    /**
     * @brief   Reqeusts the Zabbix API and returns the response of the API
     *          method service.deleteTimes.
     *
     * The $params Array can be used, to pass parameters to the Zabbix API.
     * For more informations about these parameters, check the Zabbix API
     * documentation at https://www.zabbix.com/documentation/.
     *
     * The $arrayKeyProperty can be used to get an associatve instead of an
     * indexed array as response. A valid value for the $arrayKeyProperty is
     * is any property of the returned JSON objects (e.g. name, host,
     * hostid, graphid, screenitemid).
     *
     * @param   $params             Zabbix API parameters.
     * @param   $arrayKeyProperty   Object property for key of array.
     *
     * @retval  stdClass
     *
     * @throws  Exception
     */

    public function serviceDeleteTimes($params=array(), $arrayKeyProperty='')
    {
        // get params array for request
        $params = $this->getRequestParamsArray($params);

        // check if we've to authenticate
        $auth = in_array('service.deleteTimes', self::$anonymousFunctions) ? false : true;

        // request
        return $this->request('service.deleteTimes', $params, $arrayKeyProperty, $auth);
    }
    
    /**
     * @brief   Reqeusts the Zabbix API and returns the response of the API
     *          method service.isReadable.
     *
     * The $params Array can be used, to pass parameters to the Zabbix API.
     * For more informations about these parameters, check the Zabbix API
     * documentation at https://www.zabbix.com/documentation/.
     *
     * The $arrayKeyProperty can be used to get an associatve instead of an
     * indexed array as response. A valid value for the $arrayKeyProperty is
     * is any property of the returned JSON objects (e.g. name, host,
     * hostid, graphid, screenitemid).
     *
     * @param   $params             Zabbix API parameters.
     * @param   $arrayKeyProperty   Object property for key of array.
     *
     * @retval  stdClass
     *
     * @throws  Exception
     */

    public function serviceIsReadable($params=array(), $arrayKeyProperty='')
    {
        // get params array for request
        $params = $this->getRequestParamsArray($params);

        // check if we've to authenticate
        $auth = in_array('service.isReadable', self::$anonymousFunctions) ? false : true;

        // request
        return $this->request('service.isReadable', $params, $arrayKeyProperty, $auth);
    }
    
    /**
     * @brief   Reqeusts the Zabbix API and returns the response of the API
     *          method service.isWritable.
     *
     * The $params Array can be used, to pass parameters to the Zabbix API.
     * For more informations about these parameters, check the Zabbix API
     * documentation at https://www.zabbix.com/documentation/.
     *
     * The $arrayKeyProperty can be used to get an associatve instead of an
     * indexed array as response. A valid value for the $arrayKeyProperty is
     * is any property of the returned JSON objects (e.g. name, host,
     * hostid, graphid, screenitemid).
     *
     * @param   $params             Zabbix API parameters.
     * @param   $arrayKeyProperty   Object property for key of array.
     *
     * @retval  stdClass
     *
     * @throws  Exception
     */

    public function serviceIsWritable($params=array(), $arrayKeyProperty='')
    {
        // get params array for request
        $params = $this->getRequestParamsArray($params);

        // check if we've to authenticate
        $auth = in_array('service.isWritable', self::$anonymousFunctions) ? false : true;

        // request
        return $this->request('service.isWritable', $params, $arrayKeyProperty, $auth);
    }
    
    /**
     * @brief   Reqeusts the Zabbix API and returns the response of the API
     *          method service.tableName.
     *
     * The $params Array can be used, to pass parameters to the Zabbix API.
     * For more informations about these parameters, check the Zabbix API
     * documentation at https://www.zabbix.com/documentation/.
     *
     * The $arrayKeyProperty can be used to get an associatve instead of an
     * indexed array as response. A valid value for the $arrayKeyProperty is
     * is any property of the returned JSON objects (e.g. name, host,
     * hostid, graphid, screenitemid).
     *
     * @param   $params             Zabbix API parameters.
     * @param   $arrayKeyProperty   Object property for key of array.
     *
     * @retval  stdClass
     *
     * @throws  Exception
     */

    public function serviceTableName($params=array(), $arrayKeyProperty='')
    {
        // get params array for request
        $params = $this->getRequestParamsArray($params);

        // check if we've to authenticate
        $auth = in_array('service.tableName', self::$anonymousFunctions) ? false : true;

        // request
        return $this->request('service.tableName', $params, $arrayKeyProperty, $auth);
    }
    
    /**
     * @brief   Reqeusts the Zabbix API and returns the response of the API
     *          method service.pk.
     *
     * The $params Array can be used, to pass parameters to the Zabbix API.
     * For more informations about these parameters, check the Zabbix API
     * documentation at https://www.zabbix.com/documentation/.
     *
     * The $arrayKeyProperty can be used to get an associatve instead of an
     * indexed array as response. A valid value for the $arrayKeyProperty is
     * is any property of the returned JSON objects (e.g. name, host,
     * hostid, graphid, screenitemid).
     *
     * @param   $params             Zabbix API parameters.
     * @param   $arrayKeyProperty   Object property for key of array.
     *
     * @retval  stdClass
     *
     * @throws  Exception
     */

    public function servicePk($params=array(), $arrayKeyProperty='')
    {
        // get params array for request
        $params = $this->getRequestParamsArray($params);

        // check if we've to authenticate
        $auth = in_array('service.pk', self::$anonymousFunctions) ? false : true;

        // request
        return $this->request('service.pk', $params, $arrayKeyProperty, $auth);
    }
    
    /**
     * @brief   Reqeusts the Zabbix API and returns the response of the API
     *          method service.pkOption.
     *
     * The $params Array can be used, to pass parameters to the Zabbix API.
     * For more informations about these parameters, check the Zabbix API
     * documentation at https://www.zabbix.com/documentation/.
     *
     * The $arrayKeyProperty can be used to get an associatve instead of an
     * indexed array as response. A valid value for the $arrayKeyProperty is
     * is any property of the returned JSON objects (e.g. name, host,
     * hostid, graphid, screenitemid).
     *
     * @param   $params             Zabbix API parameters.
     * @param   $arrayKeyProperty   Object property for key of array.
     *
     * @retval  stdClass
     *
     * @throws  Exception
     */

    public function servicePkOption($params=array(), $arrayKeyProperty='')
    {
        // get params array for request
        $params = $this->getRequestParamsArray($params);

        // check if we've to authenticate
        $auth = in_array('service.pkOption', self::$anonymousFunctions) ? false : true;

        // request
        return $this->request('service.pkOption', $params, $arrayKeyProperty, $auth);
    }
    
    /**
     * @brief   Reqeusts the Zabbix API and returns the response of the API
     *          method screen.get.
     *
     * The $params Array can be used, to pass parameters to the Zabbix API.
     * For more informations about these parameters, check the Zabbix API
     * documentation at https://www.zabbix.com/documentation/.
     *
     * The $arrayKeyProperty can be used to get an associatve instead of an
     * indexed array as response. A valid value for the $arrayKeyProperty is
     * is any property of the returned JSON objects (e.g. name, host,
     * hostid, graphid, screenitemid).
     *
     * @param   $params             Zabbix API parameters.
     * @param   $arrayKeyProperty   Object property for key of array.
     *
     * @retval  stdClass
     *
     * @throws  Exception
     */

    public function screenGet($params=array(), $arrayKeyProperty='')
    {
        // get params array for request
        $params = $this->getRequestParamsArray($params);

        // check if we've to authenticate
        $auth = in_array('screen.get', self::$anonymousFunctions) ? false : true;

        // request
        return $this->request('screen.get', $params, $arrayKeyProperty, $auth);
    }
    
    /**
     * @brief   Reqeusts the Zabbix API and returns the response of the API
     *          method screen.create.
     *
     * The $params Array can be used, to pass parameters to the Zabbix API.
     * For more informations about these parameters, check the Zabbix API
     * documentation at https://www.zabbix.com/documentation/.
     *
     * The $arrayKeyProperty can be used to get an associatve instead of an
     * indexed array as response. A valid value for the $arrayKeyProperty is
     * is any property of the returned JSON objects (e.g. name, host,
     * hostid, graphid, screenitemid).
     *
     * @param   $params             Zabbix API parameters.
     * @param   $arrayKeyProperty   Object property for key of array.
     *
     * @retval  stdClass
     *
     * @throws  Exception
     */

    public function screenCreate($params=array(), $arrayKeyProperty='')
    {
        // get params array for request
        $params = $this->getRequestParamsArray($params);

        // check if we've to authenticate
        $auth = in_array('screen.create', self::$anonymousFunctions) ? false : true;

        // request
        return $this->request('screen.create', $params, $arrayKeyProperty, $auth);
    }
    
    /**
     * @brief   Reqeusts the Zabbix API and returns the response of the API
     *          method screen.update.
     *
     * The $params Array can be used, to pass parameters to the Zabbix API.
     * For more informations about these parameters, check the Zabbix API
     * documentation at https://www.zabbix.com/documentation/.
     *
     * The $arrayKeyProperty can be used to get an associatve instead of an
     * indexed array as response. A valid value for the $arrayKeyProperty is
     * is any property of the returned JSON objects (e.g. name, host,
     * hostid, graphid, screenitemid).
     *
     * @param   $params             Zabbix API parameters.
     * @param   $arrayKeyProperty   Object property for key of array.
     *
     * @retval  stdClass
     *
     * @throws  Exception
     */

    public function screenUpdate($params=array(), $arrayKeyProperty='')
    {
        // get params array for request
        $params = $this->getRequestParamsArray($params);

        // check if we've to authenticate
        $auth = in_array('screen.update', self::$anonymousFunctions) ? false : true;

        // request
        return $this->request('screen.update', $params, $arrayKeyProperty, $auth);
    }
    
    /**
     * @brief   Reqeusts the Zabbix API and returns the response of the API
     *          method screen.delete.
     *
     * The $params Array can be used, to pass parameters to the Zabbix API.
     * For more informations about these parameters, check the Zabbix API
     * documentation at https://www.zabbix.com/documentation/.
     *
     * The $arrayKeyProperty can be used to get an associatve instead of an
     * indexed array as response. A valid value for the $arrayKeyProperty is
     * is any property of the returned JSON objects (e.g. name, host,
     * hostid, graphid, screenitemid).
     *
     * @param   $params             Zabbix API parameters.
     * @param   $arrayKeyProperty   Object property for key of array.
     *
     * @retval  stdClass
     *
     * @throws  Exception
     */

    public function screenDelete($params=array(), $arrayKeyProperty='')
    {
        // get params array for request
        $params = $this->getRequestParamsArray($params);

        // check if we've to authenticate
        $auth = in_array('screen.delete', self::$anonymousFunctions) ? false : true;

        // request
        return $this->request('screen.delete', $params, $arrayKeyProperty, $auth);
    }
    
    /**
     * @brief   Reqeusts the Zabbix API and returns the response of the API
     *          method screen.tableName.
     *
     * The $params Array can be used, to pass parameters to the Zabbix API.
     * For more informations about these parameters, check the Zabbix API
     * documentation at https://www.zabbix.com/documentation/.
     *
     * The $arrayKeyProperty can be used to get an associatve instead of an
     * indexed array as response. A valid value for the $arrayKeyProperty is
     * is any property of the returned JSON objects (e.g. name, host,
     * hostid, graphid, screenitemid).
     *
     * @param   $params             Zabbix API parameters.
     * @param   $arrayKeyProperty   Object property for key of array.
     *
     * @retval  stdClass
     *
     * @throws  Exception
     */

    public function screenTableName($params=array(), $arrayKeyProperty='')
    {
        // get params array for request
        $params = $this->getRequestParamsArray($params);

        // check if we've to authenticate
        $auth = in_array('screen.tableName', self::$anonymousFunctions) ? false : true;

        // request
        return $this->request('screen.tableName', $params, $arrayKeyProperty, $auth);
    }
    
    /**
     * @brief   Reqeusts the Zabbix API and returns the response of the API
     *          method screen.pk.
     *
     * The $params Array can be used, to pass parameters to the Zabbix API.
     * For more informations about these parameters, check the Zabbix API
     * documentation at https://www.zabbix.com/documentation/.
     *
     * The $arrayKeyProperty can be used to get an associatve instead of an
     * indexed array as response. A valid value for the $arrayKeyProperty is
     * is any property of the returned JSON objects (e.g. name, host,
     * hostid, graphid, screenitemid).
     *
     * @param   $params             Zabbix API parameters.
     * @param   $arrayKeyProperty   Object property for key of array.
     *
     * @retval  stdClass
     *
     * @throws  Exception
     */

    public function screenPk($params=array(), $arrayKeyProperty='')
    {
        // get params array for request
        $params = $this->getRequestParamsArray($params);

        // check if we've to authenticate
        $auth = in_array('screen.pk', self::$anonymousFunctions) ? false : true;

        // request
        return $this->request('screen.pk', $params, $arrayKeyProperty, $auth);
    }
    
    /**
     * @brief   Reqeusts the Zabbix API and returns the response of the API
     *          method screen.pkOption.
     *
     * The $params Array can be used, to pass parameters to the Zabbix API.
     * For more informations about these parameters, check the Zabbix API
     * documentation at https://www.zabbix.com/documentation/.
     *
     * The $arrayKeyProperty can be used to get an associatve instead of an
     * indexed array as response. A valid value for the $arrayKeyProperty is
     * is any property of the returned JSON objects (e.g. name, host,
     * hostid, graphid, screenitemid).
     *
     * @param   $params             Zabbix API parameters.
     * @param   $arrayKeyProperty   Object property for key of array.
     *
     * @retval  stdClass
     *
     * @throws  Exception
     */

    public function screenPkOption($params=array(), $arrayKeyProperty='')
    {
        // get params array for request
        $params = $this->getRequestParamsArray($params);

        // check if we've to authenticate
        $auth = in_array('screen.pkOption', self::$anonymousFunctions) ? false : true;

        // request
        return $this->request('screen.pkOption', $params, $arrayKeyProperty, $auth);
    }
    
    /**
     * @brief   Reqeusts the Zabbix API and returns the response of the API
     *          method screenitem.get.
     *
     * The $params Array can be used, to pass parameters to the Zabbix API.
     * For more informations about these parameters, check the Zabbix API
     * documentation at https://www.zabbix.com/documentation/.
     *
     * The $arrayKeyProperty can be used to get an associatve instead of an
     * indexed array as response. A valid value for the $arrayKeyProperty is
     * is any property of the returned JSON objects (e.g. name, host,
     * hostid, graphid, screenitemid).
     *
     * @param   $params             Zabbix API parameters.
     * @param   $arrayKeyProperty   Object property for key of array.
     *
     * @retval  stdClass
     *
     * @throws  Exception
     */

    public function screenitemGet($params=array(), $arrayKeyProperty='')
    {
        // get params array for request
        $params = $this->getRequestParamsArray($params);

        // check if we've to authenticate
        $auth = in_array('screenitem.get', self::$anonymousFunctions) ? false : true;

        // request
        return $this->request('screenitem.get', $params, $arrayKeyProperty, $auth);
    }
    
    /**
     * @brief   Reqeusts the Zabbix API and returns the response of the API
     *          method screenitem.create.
     *
     * The $params Array can be used, to pass parameters to the Zabbix API.
     * For more informations about these parameters, check the Zabbix API
     * documentation at https://www.zabbix.com/documentation/.
     *
     * The $arrayKeyProperty can be used to get an associatve instead of an
     * indexed array as response. A valid value for the $arrayKeyProperty is
     * is any property of the returned JSON objects (e.g. name, host,
     * hostid, graphid, screenitemid).
     *
     * @param   $params             Zabbix API parameters.
     * @param   $arrayKeyProperty   Object property for key of array.
     *
     * @retval  stdClass
     *
     * @throws  Exception
     */

    public function screenitemCreate($params=array(), $arrayKeyProperty='')
    {
        // get params array for request
        $params = $this->getRequestParamsArray($params);

        // check if we've to authenticate
        $auth = in_array('screenitem.create', self::$anonymousFunctions) ? false : true;

        // request
        return $this->request('screenitem.create', $params, $arrayKeyProperty, $auth);
    }
    
    /**
     * @brief   Reqeusts the Zabbix API and returns the response of the API
     *          method screenitem.update.
     *
     * The $params Array can be used, to pass parameters to the Zabbix API.
     * For more informations about these parameters, check the Zabbix API
     * documentation at https://www.zabbix.com/documentation/.
     *
     * The $arrayKeyProperty can be used to get an associatve instead of an
     * indexed array as response. A valid value for the $arrayKeyProperty is
     * is any property of the returned JSON objects (e.g. name, host,
     * hostid, graphid, screenitemid).
     *
     * @param   $params             Zabbix API parameters.
     * @param   $arrayKeyProperty   Object property for key of array.
     *
     * @retval  stdClass
     *
     * @throws  Exception
     */

    public function screenitemUpdate($params=array(), $arrayKeyProperty='')
    {
        // get params array for request
        $params = $this->getRequestParamsArray($params);

        // check if we've to authenticate
        $auth = in_array('screenitem.update', self::$anonymousFunctions) ? false : true;

        // request
        return $this->request('screenitem.update', $params, $arrayKeyProperty, $auth);
    }
    
    /**
     * @brief   Reqeusts the Zabbix API and returns the response of the API
     *          method screenitem.updateByPosition.
     *
     * The $params Array can be used, to pass parameters to the Zabbix API.
     * For more informations about these parameters, check the Zabbix API
     * documentation at https://www.zabbix.com/documentation/.
     *
     * The $arrayKeyProperty can be used to get an associatve instead of an
     * indexed array as response. A valid value for the $arrayKeyProperty is
     * is any property of the returned JSON objects (e.g. name, host,
     * hostid, graphid, screenitemid).
     *
     * @param   $params             Zabbix API parameters.
     * @param   $arrayKeyProperty   Object property for key of array.
     *
     * @retval  stdClass
     *
     * @throws  Exception
     */

    public function screenitemUpdateByPosition($params=array(), $arrayKeyProperty='')
    {
        // get params array for request
        $params = $this->getRequestParamsArray($params);

        // check if we've to authenticate
        $auth = in_array('screenitem.updateByPosition', self::$anonymousFunctions) ? false : true;

        // request
        return $this->request('screenitem.updateByPosition', $params, $arrayKeyProperty, $auth);
    }
    
    /**
     * @brief   Reqeusts the Zabbix API and returns the response of the API
     *          method screenitem.delete.
     *
     * The $params Array can be used, to pass parameters to the Zabbix API.
     * For more informations about these parameters, check the Zabbix API
     * documentation at https://www.zabbix.com/documentation/.
     *
     * The $arrayKeyProperty can be used to get an associatve instead of an
     * indexed array as response. A valid value for the $arrayKeyProperty is
     * is any property of the returned JSON objects (e.g. name, host,
     * hostid, graphid, screenitemid).
     *
     * @param   $params             Zabbix API parameters.
     * @param   $arrayKeyProperty   Object property for key of array.
     *
     * @retval  stdClass
     *
     * @throws  Exception
     */

    public function screenitemDelete($params=array(), $arrayKeyProperty='')
    {
        // get params array for request
        $params = $this->getRequestParamsArray($params);

        // check if we've to authenticate
        $auth = in_array('screenitem.delete', self::$anonymousFunctions) ? false : true;

        // request
        return $this->request('screenitem.delete', $params, $arrayKeyProperty, $auth);
    }
    
    /**
     * @brief   Reqeusts the Zabbix API and returns the response of the API
     *          method screenitem.isReadable.
     *
     * The $params Array can be used, to pass parameters to the Zabbix API.
     * For more informations about these parameters, check the Zabbix API
     * documentation at https://www.zabbix.com/documentation/.
     *
     * The $arrayKeyProperty can be used to get an associatve instead of an
     * indexed array as response. A valid value for the $arrayKeyProperty is
     * is any property of the returned JSON objects (e.g. name, host,
     * hostid, graphid, screenitemid).
     *
     * @param   $params             Zabbix API parameters.
     * @param   $arrayKeyProperty   Object property for key of array.
     *
     * @retval  stdClass
     *
     * @throws  Exception
     */

    public function screenitemIsReadable($params=array(), $arrayKeyProperty='')
    {
        // get params array for request
        $params = $this->getRequestParamsArray($params);

        // check if we've to authenticate
        $auth = in_array('screenitem.isReadable', self::$anonymousFunctions) ? false : true;

        // request
        return $this->request('screenitem.isReadable', $params, $arrayKeyProperty, $auth);
    }
    
    /**
     * @brief   Reqeusts the Zabbix API and returns the response of the API
     *          method screenitem.isWritable.
     *
     * The $params Array can be used, to pass parameters to the Zabbix API.
     * For more informations about these parameters, check the Zabbix API
     * documentation at https://www.zabbix.com/documentation/.
     *
     * The $arrayKeyProperty can be used to get an associatve instead of an
     * indexed array as response. A valid value for the $arrayKeyProperty is
     * is any property of the returned JSON objects (e.g. name, host,
     * hostid, graphid, screenitemid).
     *
     * @param   $params             Zabbix API parameters.
     * @param   $arrayKeyProperty   Object property for key of array.
     *
     * @retval  stdClass
     *
     * @throws  Exception
     */

    public function screenitemIsWritable($params=array(), $arrayKeyProperty='')
    {
        // get params array for request
        $params = $this->getRequestParamsArray($params);

        // check if we've to authenticate
        $auth = in_array('screenitem.isWritable', self::$anonymousFunctions) ? false : true;

        // request
        return $this->request('screenitem.isWritable', $params, $arrayKeyProperty, $auth);
    }
    
    /**
     * @brief   Reqeusts the Zabbix API and returns the response of the API
     *          method screenitem.tableName.
     *
     * The $params Array can be used, to pass parameters to the Zabbix API.
     * For more informations about these parameters, check the Zabbix API
     * documentation at https://www.zabbix.com/documentation/.
     *
     * The $arrayKeyProperty can be used to get an associatve instead of an
     * indexed array as response. A valid value for the $arrayKeyProperty is
     * is any property of the returned JSON objects (e.g. name, host,
     * hostid, graphid, screenitemid).
     *
     * @param   $params             Zabbix API parameters.
     * @param   $arrayKeyProperty   Object property for key of array.
     *
     * @retval  stdClass
     *
     * @throws  Exception
     */

    public function screenitemTableName($params=array(), $arrayKeyProperty='')
    {
        // get params array for request
        $params = $this->getRequestParamsArray($params);

        // check if we've to authenticate
        $auth = in_array('screenitem.tableName', self::$anonymousFunctions) ? false : true;

        // request
        return $this->request('screenitem.tableName', $params, $arrayKeyProperty, $auth);
    }
    
    /**
     * @brief   Reqeusts the Zabbix API and returns the response of the API
     *          method screenitem.pk.
     *
     * The $params Array can be used, to pass parameters to the Zabbix API.
     * For more informations about these parameters, check the Zabbix API
     * documentation at https://www.zabbix.com/documentation/.
     *
     * The $arrayKeyProperty can be used to get an associatve instead of an
     * indexed array as response. A valid value for the $arrayKeyProperty is
     * is any property of the returned JSON objects (e.g. name, host,
     * hostid, graphid, screenitemid).
     *
     * @param   $params             Zabbix API parameters.
     * @param   $arrayKeyProperty   Object property for key of array.
     *
     * @retval  stdClass
     *
     * @throws  Exception
     */

    public function screenitemPk($params=array(), $arrayKeyProperty='')
    {
        // get params array for request
        $params = $this->getRequestParamsArray($params);

        // check if we've to authenticate
        $auth = in_array('screenitem.pk', self::$anonymousFunctions) ? false : true;

        // request
        return $this->request('screenitem.pk', $params, $arrayKeyProperty, $auth);
    }
    
    /**
     * @brief   Reqeusts the Zabbix API and returns the response of the API
     *          method screenitem.pkOption.
     *
     * The $params Array can be used, to pass parameters to the Zabbix API.
     * For more informations about these parameters, check the Zabbix API
     * documentation at https://www.zabbix.com/documentation/.
     *
     * The $arrayKeyProperty can be used to get an associatve instead of an
     * indexed array as response. A valid value for the $arrayKeyProperty is
     * is any property of the returned JSON objects (e.g. name, host,
     * hostid, graphid, screenitemid).
     *
     * @param   $params             Zabbix API parameters.
     * @param   $arrayKeyProperty   Object property for key of array.
     *
     * @retval  stdClass
     *
     * @throws  Exception
     */

    public function screenitemPkOption($params=array(), $arrayKeyProperty='')
    {
        // get params array for request
        $params = $this->getRequestParamsArray($params);

        // check if we've to authenticate
        $auth = in_array('screenitem.pkOption', self::$anonymousFunctions) ? false : true;

        // request
        return $this->request('screenitem.pkOption', $params, $arrayKeyProperty, $auth);
    }
    
    /**
     * @brief   Reqeusts the Zabbix API and returns the response of the API
     *          method script.get.
     *
     * The $params Array can be used, to pass parameters to the Zabbix API.
     * For more informations about these parameters, check the Zabbix API
     * documentation at https://www.zabbix.com/documentation/.
     *
     * The $arrayKeyProperty can be used to get an associatve instead of an
     * indexed array as response. A valid value for the $arrayKeyProperty is
     * is any property of the returned JSON objects (e.g. name, host,
     * hostid, graphid, screenitemid).
     *
     * @param   $params             Zabbix API parameters.
     * @param   $arrayKeyProperty   Object property for key of array.
     *
     * @retval  stdClass
     *
     * @throws  Exception
     */

    public function scriptGet($params=array(), $arrayKeyProperty='')
    {
        // get params array for request
        $params = $this->getRequestParamsArray($params);

        // check if we've to authenticate
        $auth = in_array('script.get', self::$anonymousFunctions) ? false : true;

        // request
        return $this->request('script.get', $params, $arrayKeyProperty, $auth);
    }
    
    /**
     * @brief   Reqeusts the Zabbix API and returns the response of the API
     *          method script.create.
     *
     * The $params Array can be used, to pass parameters to the Zabbix API.
     * For more informations about these parameters, check the Zabbix API
     * documentation at https://www.zabbix.com/documentation/.
     *
     * The $arrayKeyProperty can be used to get an associatve instead of an
     * indexed array as response. A valid value for the $arrayKeyProperty is
     * is any property of the returned JSON objects (e.g. name, host,
     * hostid, graphid, screenitemid).
     *
     * @param   $params             Zabbix API parameters.
     * @param   $arrayKeyProperty   Object property for key of array.
     *
     * @retval  stdClass
     *
     * @throws  Exception
     */

    public function scriptCreate($params=array(), $arrayKeyProperty='')
    {
        // get params array for request
        $params = $this->getRequestParamsArray($params);

        // check if we've to authenticate
        $auth = in_array('script.create', self::$anonymousFunctions) ? false : true;

        // request
        return $this->request('script.create', $params, $arrayKeyProperty, $auth);
    }
    
    /**
     * @brief   Reqeusts the Zabbix API and returns the response of the API
     *          method script.update.
     *
     * The $params Array can be used, to pass parameters to the Zabbix API.
     * For more informations about these parameters, check the Zabbix API
     * documentation at https://www.zabbix.com/documentation/.
     *
     * The $arrayKeyProperty can be used to get an associatve instead of an
     * indexed array as response. A valid value for the $arrayKeyProperty is
     * is any property of the returned JSON objects (e.g. name, host,
     * hostid, graphid, screenitemid).
     *
     * @param   $params             Zabbix API parameters.
     * @param   $arrayKeyProperty   Object property for key of array.
     *
     * @retval  stdClass
     *
     * @throws  Exception
     */

    public function scriptUpdate($params=array(), $arrayKeyProperty='')
    {
        // get params array for request
        $params = $this->getRequestParamsArray($params);

        // check if we've to authenticate
        $auth = in_array('script.update', self::$anonymousFunctions) ? false : true;

        // request
        return $this->request('script.update', $params, $arrayKeyProperty, $auth);
    }
    
    /**
     * @brief   Reqeusts the Zabbix API and returns the response of the API
     *          method script.delete.
     *
     * The $params Array can be used, to pass parameters to the Zabbix API.
     * For more informations about these parameters, check the Zabbix API
     * documentation at https://www.zabbix.com/documentation/.
     *
     * The $arrayKeyProperty can be used to get an associatve instead of an
     * indexed array as response. A valid value for the $arrayKeyProperty is
     * is any property of the returned JSON objects (e.g. name, host,
     * hostid, graphid, screenitemid).
     *
     * @param   $params             Zabbix API parameters.
     * @param   $arrayKeyProperty   Object property for key of array.
     *
     * @retval  stdClass
     *
     * @throws  Exception
     */

    public function scriptDelete($params=array(), $arrayKeyProperty='')
    {
        // get params array for request
        $params = $this->getRequestParamsArray($params);

        // check if we've to authenticate
        $auth = in_array('script.delete', self::$anonymousFunctions) ? false : true;

        // request
        return $this->request('script.delete', $params, $arrayKeyProperty, $auth);
    }
    
    /**
     * @brief   Reqeusts the Zabbix API and returns the response of the API
     *          method script.execute.
     *
     * The $params Array can be used, to pass parameters to the Zabbix API.
     * For more informations about these parameters, check the Zabbix API
     * documentation at https://www.zabbix.com/documentation/.
     *
     * The $arrayKeyProperty can be used to get an associatve instead of an
     * indexed array as response. A valid value for the $arrayKeyProperty is
     * is any property of the returned JSON objects (e.g. name, host,
     * hostid, graphid, screenitemid).
     *
     * @param   $params             Zabbix API parameters.
     * @param   $arrayKeyProperty   Object property for key of array.
     *
     * @retval  stdClass
     *
     * @throws  Exception
     */

    public function scriptExecute($params=array(), $arrayKeyProperty='')
    {
        // get params array for request
        $params = $this->getRequestParamsArray($params);

        // check if we've to authenticate
        $auth = in_array('script.execute', self::$anonymousFunctions) ? false : true;

        // request
        return $this->request('script.execute', $params, $arrayKeyProperty, $auth);
    }
    
    /**
     * @brief   Reqeusts the Zabbix API and returns the response of the API
     *          method script.getScriptsByHosts.
     *
     * The $params Array can be used, to pass parameters to the Zabbix API.
     * For more informations about these parameters, check the Zabbix API
     * documentation at https://www.zabbix.com/documentation/.
     *
     * The $arrayKeyProperty can be used to get an associatve instead of an
     * indexed array as response. A valid value for the $arrayKeyProperty is
     * is any property of the returned JSON objects (e.g. name, host,
     * hostid, graphid, screenitemid).
     *
     * @param   $params             Zabbix API parameters.
     * @param   $arrayKeyProperty   Object property for key of array.
     *
     * @retval  stdClass
     *
     * @throws  Exception
     */

    public function scriptGetScriptsByHosts($params=array(), $arrayKeyProperty='')
    {
        // get params array for request
        $params = $this->getRequestParamsArray($params);

        // check if we've to authenticate
        $auth = in_array('script.getScriptsByHosts', self::$anonymousFunctions) ? false : true;

        // request
        return $this->request('script.getScriptsByHosts', $params, $arrayKeyProperty, $auth);
    }
    
    /**
     * @brief   Reqeusts the Zabbix API and returns the response of the API
     *          method script.tableName.
     *
     * The $params Array can be used, to pass parameters to the Zabbix API.
     * For more informations about these parameters, check the Zabbix API
     * documentation at https://www.zabbix.com/documentation/.
     *
     * The $arrayKeyProperty can be used to get an associatve instead of an
     * indexed array as response. A valid value for the $arrayKeyProperty is
     * is any property of the returned JSON objects (e.g. name, host,
     * hostid, graphid, screenitemid).
     *
     * @param   $params             Zabbix API parameters.
     * @param   $arrayKeyProperty   Object property for key of array.
     *
     * @retval  stdClass
     *
     * @throws  Exception
     */

    public function scriptTableName($params=array(), $arrayKeyProperty='')
    {
        // get params array for request
        $params = $this->getRequestParamsArray($params);

        // check if we've to authenticate
        $auth = in_array('script.tableName', self::$anonymousFunctions) ? false : true;

        // request
        return $this->request('script.tableName', $params, $arrayKeyProperty, $auth);
    }
    
    /**
     * @brief   Reqeusts the Zabbix API and returns the response of the API
     *          method script.pk.
     *
     * The $params Array can be used, to pass parameters to the Zabbix API.
     * For more informations about these parameters, check the Zabbix API
     * documentation at https://www.zabbix.com/documentation/.
     *
     * The $arrayKeyProperty can be used to get an associatve instead of an
     * indexed array as response. A valid value for the $arrayKeyProperty is
     * is any property of the returned JSON objects (e.g. name, host,
     * hostid, graphid, screenitemid).
     *
     * @param   $params             Zabbix API parameters.
     * @param   $arrayKeyProperty   Object property for key of array.
     *
     * @retval  stdClass
     *
     * @throws  Exception
     */

    public function scriptPk($params=array(), $arrayKeyProperty='')
    {
        // get params array for request
        $params = $this->getRequestParamsArray($params);

        // check if we've to authenticate
        $auth = in_array('script.pk', self::$anonymousFunctions) ? false : true;

        // request
        return $this->request('script.pk', $params, $arrayKeyProperty, $auth);
    }
    
    /**
     * @brief   Reqeusts the Zabbix API and returns the response of the API
     *          method script.pkOption.
     *
     * The $params Array can be used, to pass parameters to the Zabbix API.
     * For more informations about these parameters, check the Zabbix API
     * documentation at https://www.zabbix.com/documentation/.
     *
     * The $arrayKeyProperty can be used to get an associatve instead of an
     * indexed array as response. A valid value for the $arrayKeyProperty is
     * is any property of the returned JSON objects (e.g. name, host,
     * hostid, graphid, screenitemid).
     *
     * @param   $params             Zabbix API parameters.
     * @param   $arrayKeyProperty   Object property for key of array.
     *
     * @retval  stdClass
     *
     * @throws  Exception
     */

    public function scriptPkOption($params=array(), $arrayKeyProperty='')
    {
        // get params array for request
        $params = $this->getRequestParamsArray($params);

        // check if we've to authenticate
        $auth = in_array('script.pkOption', self::$anonymousFunctions) ? false : true;

        // request
        return $this->request('script.pkOption', $params, $arrayKeyProperty, $auth);
    }
    
    /**
     * @brief   Reqeusts the Zabbix API and returns the response of the API
     *          method template.pkOption.
     *
     * The $params Array can be used, to pass parameters to the Zabbix API.
     * For more informations about these parameters, check the Zabbix API
     * documentation at https://www.zabbix.com/documentation/.
     *
     * The $arrayKeyProperty can be used to get an associatve instead of an
     * indexed array as response. A valid value for the $arrayKeyProperty is
     * is any property of the returned JSON objects (e.g. name, host,
     * hostid, graphid, screenitemid).
     *
     * @param   $params             Zabbix API parameters.
     * @param   $arrayKeyProperty   Object property for key of array.
     *
     * @retval  stdClass
     *
     * @throws  Exception
     */

    public function templatePkOption($params=array(), $arrayKeyProperty='')
    {
        // get params array for request
        $params = $this->getRequestParamsArray($params);

        // check if we've to authenticate
        $auth = in_array('template.pkOption', self::$anonymousFunctions) ? false : true;

        // request
        return $this->request('template.pkOption', $params, $arrayKeyProperty, $auth);
    }
    
    /**
     * @brief   Reqeusts the Zabbix API and returns the response of the API
     *          method template.get.
     *
     * The $params Array can be used, to pass parameters to the Zabbix API.
     * For more informations about these parameters, check the Zabbix API
     * documentation at https://www.zabbix.com/documentation/.
     *
     * The $arrayKeyProperty can be used to get an associatve instead of an
     * indexed array as response. A valid value for the $arrayKeyProperty is
     * is any property of the returned JSON objects (e.g. name, host,
     * hostid, graphid, screenitemid).
     *
     * @param   $params             Zabbix API parameters.
     * @param   $arrayKeyProperty   Object property for key of array.
     *
     * @retval  stdClass
     *
     * @throws  Exception
     */

    public function templateGet($params=array(), $arrayKeyProperty='')
    {
        // get params array for request
        $params = $this->getRequestParamsArray($params);

        // check if we've to authenticate
        $auth = in_array('template.get', self::$anonymousFunctions) ? false : true;

        // request
        return $this->request('template.get', $params, $arrayKeyProperty, $auth);
    }
    
    /**
     * @brief   Reqeusts the Zabbix API and returns the response of the API
     *          method template.create.
     *
     * The $params Array can be used, to pass parameters to the Zabbix API.
     * For more informations about these parameters, check the Zabbix API
     * documentation at https://www.zabbix.com/documentation/.
     *
     * The $arrayKeyProperty can be used to get an associatve instead of an
     * indexed array as response. A valid value for the $arrayKeyProperty is
     * is any property of the returned JSON objects (e.g. name, host,
     * hostid, graphid, screenitemid).
     *
     * @param   $params             Zabbix API parameters.
     * @param   $arrayKeyProperty   Object property for key of array.
     *
     * @retval  stdClass
     *
     * @throws  Exception
     */

    public function templateCreate($params=array(), $arrayKeyProperty='')
    {
        // get params array for request
        $params = $this->getRequestParamsArray($params);

        // check if we've to authenticate
        $auth = in_array('template.create', self::$anonymousFunctions) ? false : true;

        // request
        return $this->request('template.create', $params, $arrayKeyProperty, $auth);
    }
    
    /**
     * @brief   Reqeusts the Zabbix API and returns the response of the API
     *          method template.update.
     *
     * The $params Array can be used, to pass parameters to the Zabbix API.
     * For more informations about these parameters, check the Zabbix API
     * documentation at https://www.zabbix.com/documentation/.
     *
     * The $arrayKeyProperty can be used to get an associatve instead of an
     * indexed array as response. A valid value for the $arrayKeyProperty is
     * is any property of the returned JSON objects (e.g. name, host,
     * hostid, graphid, screenitemid).
     *
     * @param   $params             Zabbix API parameters.
     * @param   $arrayKeyProperty   Object property for key of array.
     *
     * @retval  stdClass
     *
     * @throws  Exception
     */

    public function templateUpdate($params=array(), $arrayKeyProperty='')
    {
        // get params array for request
        $params = $this->getRequestParamsArray($params);

        // check if we've to authenticate
        $auth = in_array('template.update', self::$anonymousFunctions) ? false : true;

        // request
        return $this->request('template.update', $params, $arrayKeyProperty, $auth);
    }
    
    /**
     * @brief   Reqeusts the Zabbix API and returns the response of the API
     *          method template.delete.
     *
     * The $params Array can be used, to pass parameters to the Zabbix API.
     * For more informations about these parameters, check the Zabbix API
     * documentation at https://www.zabbix.com/documentation/.
     *
     * The $arrayKeyProperty can be used to get an associatve instead of an
     * indexed array as response. A valid value for the $arrayKeyProperty is
     * is any property of the returned JSON objects (e.g. name, host,
     * hostid, graphid, screenitemid).
     *
     * @param   $params             Zabbix API parameters.
     * @param   $arrayKeyProperty   Object property for key of array.
     *
     * @retval  stdClass
     *
     * @throws  Exception
     */

    public function templateDelete($params=array(), $arrayKeyProperty='')
    {
        // get params array for request
        $params = $this->getRequestParamsArray($params);

        // check if we've to authenticate
        $auth = in_array('template.delete', self::$anonymousFunctions) ? false : true;

        // request
        return $this->request('template.delete', $params, $arrayKeyProperty, $auth);
    }
    
    /**
     * @brief   Reqeusts the Zabbix API and returns the response of the API
     *          method template.massAdd.
     *
     * The $params Array can be used, to pass parameters to the Zabbix API.
     * For more informations about these parameters, check the Zabbix API
     * documentation at https://www.zabbix.com/documentation/.
     *
     * The $arrayKeyProperty can be used to get an associatve instead of an
     * indexed array as response. A valid value for the $arrayKeyProperty is
     * is any property of the returned JSON objects (e.g. name, host,
     * hostid, graphid, screenitemid).
     *
     * @param   $params             Zabbix API parameters.
     * @param   $arrayKeyProperty   Object property for key of array.
     *
     * @retval  stdClass
     *
     * @throws  Exception
     */

    public function templateMassAdd($params=array(), $arrayKeyProperty='')
    {
        // get params array for request
        $params = $this->getRequestParamsArray($params);

        // check if we've to authenticate
        $auth = in_array('template.massAdd', self::$anonymousFunctions) ? false : true;

        // request
        return $this->request('template.massAdd', $params, $arrayKeyProperty, $auth);
    }
    
    /**
     * @brief   Reqeusts the Zabbix API and returns the response of the API
     *          method template.massUpdate.
     *
     * The $params Array can be used, to pass parameters to the Zabbix API.
     * For more informations about these parameters, check the Zabbix API
     * documentation at https://www.zabbix.com/documentation/.
     *
     * The $arrayKeyProperty can be used to get an associatve instead of an
     * indexed array as response. A valid value for the $arrayKeyProperty is
     * is any property of the returned JSON objects (e.g. name, host,
     * hostid, graphid, screenitemid).
     *
     * @param   $params             Zabbix API parameters.
     * @param   $arrayKeyProperty   Object property for key of array.
     *
     * @retval  stdClass
     *
     * @throws  Exception
     */

    public function templateMassUpdate($params=array(), $arrayKeyProperty='')
    {
        // get params array for request
        $params = $this->getRequestParamsArray($params);

        // check if we've to authenticate
        $auth = in_array('template.massUpdate', self::$anonymousFunctions) ? false : true;

        // request
        return $this->request('template.massUpdate', $params, $arrayKeyProperty, $auth);
    }
    
    /**
     * @brief   Reqeusts the Zabbix API and returns the response of the API
     *          method template.massRemove.
     *
     * The $params Array can be used, to pass parameters to the Zabbix API.
     * For more informations about these parameters, check the Zabbix API
     * documentation at https://www.zabbix.com/documentation/.
     *
     * The $arrayKeyProperty can be used to get an associatve instead of an
     * indexed array as response. A valid value for the $arrayKeyProperty is
     * is any property of the returned JSON objects (e.g. name, host,
     * hostid, graphid, screenitemid).
     *
     * @param   $params             Zabbix API parameters.
     * @param   $arrayKeyProperty   Object property for key of array.
     *
     * @retval  stdClass
     *
     * @throws  Exception
     */

    public function templateMassRemove($params=array(), $arrayKeyProperty='')
    {
        // get params array for request
        $params = $this->getRequestParamsArray($params);

        // check if we've to authenticate
        $auth = in_array('template.massRemove', self::$anonymousFunctions) ? false : true;

        // request
        return $this->request('template.massRemove', $params, $arrayKeyProperty, $auth);
    }
    
    /**
     * @brief   Reqeusts the Zabbix API and returns the response of the API
     *          method template.isReadable.
     *
     * The $params Array can be used, to pass parameters to the Zabbix API.
     * For more informations about these parameters, check the Zabbix API
     * documentation at https://www.zabbix.com/documentation/.
     *
     * The $arrayKeyProperty can be used to get an associatve instead of an
     * indexed array as response. A valid value for the $arrayKeyProperty is
     * is any property of the returned JSON objects (e.g. name, host,
     * hostid, graphid, screenitemid).
     *
     * @param   $params             Zabbix API parameters.
     * @param   $arrayKeyProperty   Object property for key of array.
     *
     * @retval  stdClass
     *
     * @throws  Exception
     */

    public function templateIsReadable($params=array(), $arrayKeyProperty='')
    {
        // get params array for request
        $params = $this->getRequestParamsArray($params);

        // check if we've to authenticate
        $auth = in_array('template.isReadable', self::$anonymousFunctions) ? false : true;

        // request
        return $this->request('template.isReadable', $params, $arrayKeyProperty, $auth);
    }
    
    /**
     * @brief   Reqeusts the Zabbix API and returns the response of the API
     *          method template.isWritable.
     *
     * The $params Array can be used, to pass parameters to the Zabbix API.
     * For more informations about these parameters, check the Zabbix API
     * documentation at https://www.zabbix.com/documentation/.
     *
     * The $arrayKeyProperty can be used to get an associatve instead of an
     * indexed array as response. A valid value for the $arrayKeyProperty is
     * is any property of the returned JSON objects (e.g. name, host,
     * hostid, graphid, screenitemid).
     *
     * @param   $params             Zabbix API parameters.
     * @param   $arrayKeyProperty   Object property for key of array.
     *
     * @retval  stdClass
     *
     * @throws  Exception
     */

    public function templateIsWritable($params=array(), $arrayKeyProperty='')
    {
        // get params array for request
        $params = $this->getRequestParamsArray($params);

        // check if we've to authenticate
        $auth = in_array('template.isWritable', self::$anonymousFunctions) ? false : true;

        // request
        return $this->request('template.isWritable', $params, $arrayKeyProperty, $auth);
    }
    
    /**
     * @brief   Reqeusts the Zabbix API and returns the response of the API
     *          method template.tableName.
     *
     * The $params Array can be used, to pass parameters to the Zabbix API.
     * For more informations about these parameters, check the Zabbix API
     * documentation at https://www.zabbix.com/documentation/.
     *
     * The $arrayKeyProperty can be used to get an associatve instead of an
     * indexed array as response. A valid value for the $arrayKeyProperty is
     * is any property of the returned JSON objects (e.g. name, host,
     * hostid, graphid, screenitemid).
     *
     * @param   $params             Zabbix API parameters.
     * @param   $arrayKeyProperty   Object property for key of array.
     *
     * @retval  stdClass
     *
     * @throws  Exception
     */

    public function templateTableName($params=array(), $arrayKeyProperty='')
    {
        // get params array for request
        $params = $this->getRequestParamsArray($params);

        // check if we've to authenticate
        $auth = in_array('template.tableName', self::$anonymousFunctions) ? false : true;

        // request
        return $this->request('template.tableName', $params, $arrayKeyProperty, $auth);
    }
    
    /**
     * @brief   Reqeusts the Zabbix API and returns the response of the API
     *          method template.pk.
     *
     * The $params Array can be used, to pass parameters to the Zabbix API.
     * For more informations about these parameters, check the Zabbix API
     * documentation at https://www.zabbix.com/documentation/.
     *
     * The $arrayKeyProperty can be used to get an associatve instead of an
     * indexed array as response. A valid value for the $arrayKeyProperty is
     * is any property of the returned JSON objects (e.g. name, host,
     * hostid, graphid, screenitemid).
     *
     * @param   $params             Zabbix API parameters.
     * @param   $arrayKeyProperty   Object property for key of array.
     *
     * @retval  stdClass
     *
     * @throws  Exception
     */

    public function templatePk($params=array(), $arrayKeyProperty='')
    {
        // get params array for request
        $params = $this->getRequestParamsArray($params);

        // check if we've to authenticate
        $auth = in_array('template.pk', self::$anonymousFunctions) ? false : true;

        // request
        return $this->request('template.pk', $params, $arrayKeyProperty, $auth);
    }
    
    /**
     * @brief   Reqeusts the Zabbix API and returns the response of the API
     *          method templatescreen.get.
     *
     * The $params Array can be used, to pass parameters to the Zabbix API.
     * For more informations about these parameters, check the Zabbix API
     * documentation at https://www.zabbix.com/documentation/.
     *
     * The $arrayKeyProperty can be used to get an associatve instead of an
     * indexed array as response. A valid value for the $arrayKeyProperty is
     * is any property of the returned JSON objects (e.g. name, host,
     * hostid, graphid, screenitemid).
     *
     * @param   $params             Zabbix API parameters.
     * @param   $arrayKeyProperty   Object property for key of array.
     *
     * @retval  stdClass
     *
     * @throws  Exception
     */

    public function templatescreenGet($params=array(), $arrayKeyProperty='')
    {
        // get params array for request
        $params = $this->getRequestParamsArray($params);

        // check if we've to authenticate
        $auth = in_array('templatescreen.get', self::$anonymousFunctions) ? false : true;

        // request
        return $this->request('templatescreen.get', $params, $arrayKeyProperty, $auth);
    }
    
    /**
     * @brief   Reqeusts the Zabbix API and returns the response of the API
     *          method templatescreen.copy.
     *
     * The $params Array can be used, to pass parameters to the Zabbix API.
     * For more informations about these parameters, check the Zabbix API
     * documentation at https://www.zabbix.com/documentation/.
     *
     * The $arrayKeyProperty can be used to get an associatve instead of an
     * indexed array as response. A valid value for the $arrayKeyProperty is
     * is any property of the returned JSON objects (e.g. name, host,
     * hostid, graphid, screenitemid).
     *
     * @param   $params             Zabbix API parameters.
     * @param   $arrayKeyProperty   Object property for key of array.
     *
     * @retval  stdClass
     *
     * @throws  Exception
     */

    public function templatescreenCopy($params=array(), $arrayKeyProperty='')
    {
        // get params array for request
        $params = $this->getRequestParamsArray($params);

        // check if we've to authenticate
        $auth = in_array('templatescreen.copy', self::$anonymousFunctions) ? false : true;

        // request
        return $this->request('templatescreen.copy', $params, $arrayKeyProperty, $auth);
    }
    
    /**
     * @brief   Reqeusts the Zabbix API and returns the response of the API
     *          method templatescreen.update.
     *
     * The $params Array can be used, to pass parameters to the Zabbix API.
     * For more informations about these parameters, check the Zabbix API
     * documentation at https://www.zabbix.com/documentation/.
     *
     * The $arrayKeyProperty can be used to get an associatve instead of an
     * indexed array as response. A valid value for the $arrayKeyProperty is
     * is any property of the returned JSON objects (e.g. name, host,
     * hostid, graphid, screenitemid).
     *
     * @param   $params             Zabbix API parameters.
     * @param   $arrayKeyProperty   Object property for key of array.
     *
     * @retval  stdClass
     *
     * @throws  Exception
     */

    public function templatescreenUpdate($params=array(), $arrayKeyProperty='')
    {
        // get params array for request
        $params = $this->getRequestParamsArray($params);

        // check if we've to authenticate
        $auth = in_array('templatescreen.update', self::$anonymousFunctions) ? false : true;

        // request
        return $this->request('templatescreen.update', $params, $arrayKeyProperty, $auth);
    }
    
    /**
     * @brief   Reqeusts the Zabbix API and returns the response of the API
     *          method templatescreen.create.
     *
     * The $params Array can be used, to pass parameters to the Zabbix API.
     * For more informations about these parameters, check the Zabbix API
     * documentation at https://www.zabbix.com/documentation/.
     *
     * The $arrayKeyProperty can be used to get an associatve instead of an
     * indexed array as response. A valid value for the $arrayKeyProperty is
     * is any property of the returned JSON objects (e.g. name, host,
     * hostid, graphid, screenitemid).
     *
     * @param   $params             Zabbix API parameters.
     * @param   $arrayKeyProperty   Object property for key of array.
     *
     * @retval  stdClass
     *
     * @throws  Exception
     */

    public function templatescreenCreate($params=array(), $arrayKeyProperty='')
    {
        // get params array for request
        $params = $this->getRequestParamsArray($params);

        // check if we've to authenticate
        $auth = in_array('templatescreen.create', self::$anonymousFunctions) ? false : true;

        // request
        return $this->request('templatescreen.create', $params, $arrayKeyProperty, $auth);
    }
    
    /**
     * @brief   Reqeusts the Zabbix API and returns the response of the API
     *          method templatescreen.delete.
     *
     * The $params Array can be used, to pass parameters to the Zabbix API.
     * For more informations about these parameters, check the Zabbix API
     * documentation at https://www.zabbix.com/documentation/.
     *
     * The $arrayKeyProperty can be used to get an associatve instead of an
     * indexed array as response. A valid value for the $arrayKeyProperty is
     * is any property of the returned JSON objects (e.g. name, host,
     * hostid, graphid, screenitemid).
     *
     * @param   $params             Zabbix API parameters.
     * @param   $arrayKeyProperty   Object property for key of array.
     *
     * @retval  stdClass
     *
     * @throws  Exception
     */

    public function templatescreenDelete($params=array(), $arrayKeyProperty='')
    {
        // get params array for request
        $params = $this->getRequestParamsArray($params);

        // check if we've to authenticate
        $auth = in_array('templatescreen.delete', self::$anonymousFunctions) ? false : true;

        // request
        return $this->request('templatescreen.delete', $params, $arrayKeyProperty, $auth);
    }
    
    /**
     * @brief   Reqeusts the Zabbix API and returns the response of the API
     *          method templatescreen.tableName.
     *
     * The $params Array can be used, to pass parameters to the Zabbix API.
     * For more informations about these parameters, check the Zabbix API
     * documentation at https://www.zabbix.com/documentation/.
     *
     * The $arrayKeyProperty can be used to get an associatve instead of an
     * indexed array as response. A valid value for the $arrayKeyProperty is
     * is any property of the returned JSON objects (e.g. name, host,
     * hostid, graphid, screenitemid).
     *
     * @param   $params             Zabbix API parameters.
     * @param   $arrayKeyProperty   Object property for key of array.
     *
     * @retval  stdClass
     *
     * @throws  Exception
     */

    public function templatescreenTableName($params=array(), $arrayKeyProperty='')
    {
        // get params array for request
        $params = $this->getRequestParamsArray($params);

        // check if we've to authenticate
        $auth = in_array('templatescreen.tableName', self::$anonymousFunctions) ? false : true;

        // request
        return $this->request('templatescreen.tableName', $params, $arrayKeyProperty, $auth);
    }
    
    /**
     * @brief   Reqeusts the Zabbix API and returns the response of the API
     *          method templatescreen.pk.
     *
     * The $params Array can be used, to pass parameters to the Zabbix API.
     * For more informations about these parameters, check the Zabbix API
     * documentation at https://www.zabbix.com/documentation/.
     *
     * The $arrayKeyProperty can be used to get an associatve instead of an
     * indexed array as response. A valid value for the $arrayKeyProperty is
     * is any property of the returned JSON objects (e.g. name, host,
     * hostid, graphid, screenitemid).
     *
     * @param   $params             Zabbix API parameters.
     * @param   $arrayKeyProperty   Object property for key of array.
     *
     * @retval  stdClass
     *
     * @throws  Exception
     */

    public function templatescreenPk($params=array(), $arrayKeyProperty='')
    {
        // get params array for request
        $params = $this->getRequestParamsArray($params);

        // check if we've to authenticate
        $auth = in_array('templatescreen.pk', self::$anonymousFunctions) ? false : true;

        // request
        return $this->request('templatescreen.pk', $params, $arrayKeyProperty, $auth);
    }
    
    /**
     * @brief   Reqeusts the Zabbix API and returns the response of the API
     *          method templatescreen.pkOption.
     *
     * The $params Array can be used, to pass parameters to the Zabbix API.
     * For more informations about these parameters, check the Zabbix API
     * documentation at https://www.zabbix.com/documentation/.
     *
     * The $arrayKeyProperty can be used to get an associatve instead of an
     * indexed array as response. A valid value for the $arrayKeyProperty is
     * is any property of the returned JSON objects (e.g. name, host,
     * hostid, graphid, screenitemid).
     *
     * @param   $params             Zabbix API parameters.
     * @param   $arrayKeyProperty   Object property for key of array.
     *
     * @retval  stdClass
     *
     * @throws  Exception
     */

    public function templatescreenPkOption($params=array(), $arrayKeyProperty='')
    {
        // get params array for request
        $params = $this->getRequestParamsArray($params);

        // check if we've to authenticate
        $auth = in_array('templatescreen.pkOption', self::$anonymousFunctions) ? false : true;

        // request
        return $this->request('templatescreen.pkOption', $params, $arrayKeyProperty, $auth);
    }
    
    /**
     * @brief   Reqeusts the Zabbix API and returns the response of the API
     *          method templatescreenitem.get.
     *
     * The $params Array can be used, to pass parameters to the Zabbix API.
     * For more informations about these parameters, check the Zabbix API
     * documentation at https://www.zabbix.com/documentation/.
     *
     * The $arrayKeyProperty can be used to get an associatve instead of an
     * indexed array as response. A valid value for the $arrayKeyProperty is
     * is any property of the returned JSON objects (e.g. name, host,
     * hostid, graphid, screenitemid).
     *
     * @param   $params             Zabbix API parameters.
     * @param   $arrayKeyProperty   Object property for key of array.
     *
     * @retval  stdClass
     *
     * @throws  Exception
     */

    public function templatescreenitemGet($params=array(), $arrayKeyProperty='')
    {
        // get params array for request
        $params = $this->getRequestParamsArray($params);

        // check if we've to authenticate
        $auth = in_array('templatescreenitem.get', self::$anonymousFunctions) ? false : true;

        // request
        return $this->request('templatescreenitem.get', $params, $arrayKeyProperty, $auth);
    }
    
    /**
     * @brief   Reqeusts the Zabbix API and returns the response of the API
     *          method templatescreenitem.tableName.
     *
     * The $params Array can be used, to pass parameters to the Zabbix API.
     * For more informations about these parameters, check the Zabbix API
     * documentation at https://www.zabbix.com/documentation/.
     *
     * The $arrayKeyProperty can be used to get an associatve instead of an
     * indexed array as response. A valid value for the $arrayKeyProperty is
     * is any property of the returned JSON objects (e.g. name, host,
     * hostid, graphid, screenitemid).
     *
     * @param   $params             Zabbix API parameters.
     * @param   $arrayKeyProperty   Object property for key of array.
     *
     * @retval  stdClass
     *
     * @throws  Exception
     */

    public function templatescreenitemTableName($params=array(), $arrayKeyProperty='')
    {
        // get params array for request
        $params = $this->getRequestParamsArray($params);

        // check if we've to authenticate
        $auth = in_array('templatescreenitem.tableName', self::$anonymousFunctions) ? false : true;

        // request
        return $this->request('templatescreenitem.tableName', $params, $arrayKeyProperty, $auth);
    }
    
    /**
     * @brief   Reqeusts the Zabbix API and returns the response of the API
     *          method templatescreenitem.pk.
     *
     * The $params Array can be used, to pass parameters to the Zabbix API.
     * For more informations about these parameters, check the Zabbix API
     * documentation at https://www.zabbix.com/documentation/.
     *
     * The $arrayKeyProperty can be used to get an associatve instead of an
     * indexed array as response. A valid value for the $arrayKeyProperty is
     * is any property of the returned JSON objects (e.g. name, host,
     * hostid, graphid, screenitemid).
     *
     * @param   $params             Zabbix API parameters.
     * @param   $arrayKeyProperty   Object property for key of array.
     *
     * @retval  stdClass
     *
     * @throws  Exception
     */

    public function templatescreenitemPk($params=array(), $arrayKeyProperty='')
    {
        // get params array for request
        $params = $this->getRequestParamsArray($params);

        // check if we've to authenticate
        $auth = in_array('templatescreenitem.pk', self::$anonymousFunctions) ? false : true;

        // request
        return $this->request('templatescreenitem.pk', $params, $arrayKeyProperty, $auth);
    }
    
    /**
     * @brief   Reqeusts the Zabbix API and returns the response of the API
     *          method templatescreenitem.pkOption.
     *
     * The $params Array can be used, to pass parameters to the Zabbix API.
     * For more informations about these parameters, check the Zabbix API
     * documentation at https://www.zabbix.com/documentation/.
     *
     * The $arrayKeyProperty can be used to get an associatve instead of an
     * indexed array as response. A valid value for the $arrayKeyProperty is
     * is any property of the returned JSON objects (e.g. name, host,
     * hostid, graphid, screenitemid).
     *
     * @param   $params             Zabbix API parameters.
     * @param   $arrayKeyProperty   Object property for key of array.
     *
     * @retval  stdClass
     *
     * @throws  Exception
     */

    public function templatescreenitemPkOption($params=array(), $arrayKeyProperty='')
    {
        // get params array for request
        $params = $this->getRequestParamsArray($params);

        // check if we've to authenticate
        $auth = in_array('templatescreenitem.pkOption', self::$anonymousFunctions) ? false : true;

        // request
        return $this->request('templatescreenitem.pkOption', $params, $arrayKeyProperty, $auth);
    }
    
    /**
     * @brief   Reqeusts the Zabbix API and returns the response of the API
     *          method trend.get.
     *
     * The $params Array can be used, to pass parameters to the Zabbix API.
     * For more informations about these parameters, check the Zabbix API
     * documentation at https://www.zabbix.com/documentation/.
     *
     * The $arrayKeyProperty can be used to get an associatve instead of an
     * indexed array as response. A valid value for the $arrayKeyProperty is
     * is any property of the returned JSON objects (e.g. name, host,
     * hostid, graphid, screenitemid).
     *
     * @param   $params             Zabbix API parameters.
     * @param   $arrayKeyProperty   Object property for key of array.
     *
     * @retval  stdClass
     *
     * @throws  Exception
     */

    public function trendGet($params=array(), $arrayKeyProperty='')
    {
        // get params array for request
        $params = $this->getRequestParamsArray($params);

        // check if we've to authenticate
        $auth = in_array('trend.get', self::$anonymousFunctions) ? false : true;

        // request
        return $this->request('trend.get', $params, $arrayKeyProperty, $auth);
    }
    
    /**
     * @brief   Reqeusts the Zabbix API and returns the response of the API
     *          method trend.tableName.
     *
     * The $params Array can be used, to pass parameters to the Zabbix API.
     * For more informations about these parameters, check the Zabbix API
     * documentation at https://www.zabbix.com/documentation/.
     *
     * The $arrayKeyProperty can be used to get an associatve instead of an
     * indexed array as response. A valid value for the $arrayKeyProperty is
     * is any property of the returned JSON objects (e.g. name, host,
     * hostid, graphid, screenitemid).
     *
     * @param   $params             Zabbix API parameters.
     * @param   $arrayKeyProperty   Object property for key of array.
     *
     * @retval  stdClass
     *
     * @throws  Exception
     */

    public function trendTableName($params=array(), $arrayKeyProperty='')
    {
        // get params array for request
        $params = $this->getRequestParamsArray($params);

        // check if we've to authenticate
        $auth = in_array('trend.tableName', self::$anonymousFunctions) ? false : true;

        // request
        return $this->request('trend.tableName', $params, $arrayKeyProperty, $auth);
    }
    
    /**
     * @brief   Reqeusts the Zabbix API and returns the response of the API
     *          method trend.pk.
     *
     * The $params Array can be used, to pass parameters to the Zabbix API.
     * For more informations about these parameters, check the Zabbix API
     * documentation at https://www.zabbix.com/documentation/.
     *
     * The $arrayKeyProperty can be used to get an associatve instead of an
     * indexed array as response. A valid value for the $arrayKeyProperty is
     * is any property of the returned JSON objects (e.g. name, host,
     * hostid, graphid, screenitemid).
     *
     * @param   $params             Zabbix API parameters.
     * @param   $arrayKeyProperty   Object property for key of array.
     *
     * @retval  stdClass
     *
     * @throws  Exception
     */

    public function trendPk($params=array(), $arrayKeyProperty='')
    {
        // get params array for request
        $params = $this->getRequestParamsArray($params);

        // check if we've to authenticate
        $auth = in_array('trend.pk', self::$anonymousFunctions) ? false : true;

        // request
        return $this->request('trend.pk', $params, $arrayKeyProperty, $auth);
    }
    
    /**
     * @brief   Reqeusts the Zabbix API and returns the response of the API
     *          method trend.pkOption.
     *
     * The $params Array can be used, to pass parameters to the Zabbix API.
     * For more informations about these parameters, check the Zabbix API
     * documentation at https://www.zabbix.com/documentation/.
     *
     * The $arrayKeyProperty can be used to get an associatve instead of an
     * indexed array as response. A valid value for the $arrayKeyProperty is
     * is any property of the returned JSON objects (e.g. name, host,
     * hostid, graphid, screenitemid).
     *
     * @param   $params             Zabbix API parameters.
     * @param   $arrayKeyProperty   Object property for key of array.
     *
     * @retval  stdClass
     *
     * @throws  Exception
     */

    public function trendPkOption($params=array(), $arrayKeyProperty='')
    {
        // get params array for request
        $params = $this->getRequestParamsArray($params);

        // check if we've to authenticate
        $auth = in_array('trend.pkOption', self::$anonymousFunctions) ? false : true;

        // request
        return $this->request('trend.pkOption', $params, $arrayKeyProperty, $auth);
    }
    
    /**
     * @brief   Reqeusts the Zabbix API and returns the response of the API
     *          method trigger.get.
     *
     * The $params Array can be used, to pass parameters to the Zabbix API.
     * For more informations about these parameters, check the Zabbix API
     * documentation at https://www.zabbix.com/documentation/.
     *
     * The $arrayKeyProperty can be used to get an associatve instead of an
     * indexed array as response. A valid value for the $arrayKeyProperty is
     * is any property of the returned JSON objects (e.g. name, host,
     * hostid, graphid, screenitemid).
     *
     * @param   $params             Zabbix API parameters.
     * @param   $arrayKeyProperty   Object property for key of array.
     *
     * @retval  stdClass
     *
     * @throws  Exception
     */

    public function triggerGet($params=array(), $arrayKeyProperty='')
    {
        // get params array for request
        $params = $this->getRequestParamsArray($params);

        // check if we've to authenticate
        $auth = in_array('trigger.get', self::$anonymousFunctions) ? false : true;

        // request
        return $this->request('trigger.get', $params, $arrayKeyProperty, $auth);
    }
    
    /**
     * @brief   Reqeusts the Zabbix API and returns the response of the API
     *          method trigger.checkInput.
     *
     * The $params Array can be used, to pass parameters to the Zabbix API.
     * For more informations about these parameters, check the Zabbix API
     * documentation at https://www.zabbix.com/documentation/.
     *
     * The $arrayKeyProperty can be used to get an associatve instead of an
     * indexed array as response. A valid value for the $arrayKeyProperty is
     * is any property of the returned JSON objects (e.g. name, host,
     * hostid, graphid, screenitemid).
     *
     * @param   $params             Zabbix API parameters.
     * @param   $arrayKeyProperty   Object property for key of array.
     *
     * @retval  stdClass
     *
     * @throws  Exception
     */

    public function triggerCheckInput($params=array(), $arrayKeyProperty='')
    {
        // get params array for request
        $params = $this->getRequestParamsArray($params);

        // check if we've to authenticate
        $auth = in_array('trigger.checkInput', self::$anonymousFunctions) ? false : true;

        // request
        return $this->request('trigger.checkInput', $params, $arrayKeyProperty, $auth);
    }
    
    /**
     * @brief   Reqeusts the Zabbix API and returns the response of the API
     *          method trigger.create.
     *
     * The $params Array can be used, to pass parameters to the Zabbix API.
     * For more informations about these parameters, check the Zabbix API
     * documentation at https://www.zabbix.com/documentation/.
     *
     * The $arrayKeyProperty can be used to get an associatve instead of an
     * indexed array as response. A valid value for the $arrayKeyProperty is
     * is any property of the returned JSON objects (e.g. name, host,
     * hostid, graphid, screenitemid).
     *
     * @param   $params             Zabbix API parameters.
     * @param   $arrayKeyProperty   Object property for key of array.
     *
     * @retval  stdClass
     *
     * @throws  Exception
     */

    public function triggerCreate($params=array(), $arrayKeyProperty='')
    {
        // get params array for request
        $params = $this->getRequestParamsArray($params);

        // check if we've to authenticate
        $auth = in_array('trigger.create', self::$anonymousFunctions) ? false : true;

        // request
        return $this->request('trigger.create', $params, $arrayKeyProperty, $auth);
    }
    
    /**
     * @brief   Reqeusts the Zabbix API and returns the response of the API
     *          method trigger.update.
     *
     * The $params Array can be used, to pass parameters to the Zabbix API.
     * For more informations about these parameters, check the Zabbix API
     * documentation at https://www.zabbix.com/documentation/.
     *
     * The $arrayKeyProperty can be used to get an associatve instead of an
     * indexed array as response. A valid value for the $arrayKeyProperty is
     * is any property of the returned JSON objects (e.g. name, host,
     * hostid, graphid, screenitemid).
     *
     * @param   $params             Zabbix API parameters.
     * @param   $arrayKeyProperty   Object property for key of array.
     *
     * @retval  stdClass
     *
     * @throws  Exception
     */

    public function triggerUpdate($params=array(), $arrayKeyProperty='')
    {
        // get params array for request
        $params = $this->getRequestParamsArray($params);

        // check if we've to authenticate
        $auth = in_array('trigger.update', self::$anonymousFunctions) ? false : true;

        // request
        return $this->request('trigger.update', $params, $arrayKeyProperty, $auth);
    }
    
    /**
     * @brief   Reqeusts the Zabbix API and returns the response of the API
     *          method trigger.delete.
     *
     * The $params Array can be used, to pass parameters to the Zabbix API.
     * For more informations about these parameters, check the Zabbix API
     * documentation at https://www.zabbix.com/documentation/.
     *
     * The $arrayKeyProperty can be used to get an associatve instead of an
     * indexed array as response. A valid value for the $arrayKeyProperty is
     * is any property of the returned JSON objects (e.g. name, host,
     * hostid, graphid, screenitemid).
     *
     * @param   $params             Zabbix API parameters.
     * @param   $arrayKeyProperty   Object property for key of array.
     *
     * @retval  stdClass
     *
     * @throws  Exception
     */

    public function triggerDelete($params=array(), $arrayKeyProperty='')
    {
        // get params array for request
        $params = $this->getRequestParamsArray($params);

        // check if we've to authenticate
        $auth = in_array('trigger.delete', self::$anonymousFunctions) ? false : true;

        // request
        return $this->request('trigger.delete', $params, $arrayKeyProperty, $auth);
    }
    
    /**
     * @brief   Reqeusts the Zabbix API and returns the response of the API
     *          method trigger.addDependencies.
     *
     * The $params Array can be used, to pass parameters to the Zabbix API.
     * For more informations about these parameters, check the Zabbix API
     * documentation at https://www.zabbix.com/documentation/.
     *
     * The $arrayKeyProperty can be used to get an associatve instead of an
     * indexed array as response. A valid value for the $arrayKeyProperty is
     * is any property of the returned JSON objects (e.g. name, host,
     * hostid, graphid, screenitemid).
     *
     * @param   $params             Zabbix API parameters.
     * @param   $arrayKeyProperty   Object property for key of array.
     *
     * @retval  stdClass
     *
     * @throws  Exception
     */

    public function triggerAddDependencies($params=array(), $arrayKeyProperty='')
    {
        // get params array for request
        $params = $this->getRequestParamsArray($params);

        // check if we've to authenticate
        $auth = in_array('trigger.addDependencies', self::$anonymousFunctions) ? false : true;

        // request
        return $this->request('trigger.addDependencies', $params, $arrayKeyProperty, $auth);
    }
    
    /**
     * @brief   Reqeusts the Zabbix API and returns the response of the API
     *          method trigger.deleteDependencies.
     *
     * The $params Array can be used, to pass parameters to the Zabbix API.
     * For more informations about these parameters, check the Zabbix API
     * documentation at https://www.zabbix.com/documentation/.
     *
     * The $arrayKeyProperty can be used to get an associatve instead of an
     * indexed array as response. A valid value for the $arrayKeyProperty is
     * is any property of the returned JSON objects (e.g. name, host,
     * hostid, graphid, screenitemid).
     *
     * @param   $params             Zabbix API parameters.
     * @param   $arrayKeyProperty   Object property for key of array.
     *
     * @retval  stdClass
     *
     * @throws  Exception
     */

    public function triggerDeleteDependencies($params=array(), $arrayKeyProperty='')
    {
        // get params array for request
        $params = $this->getRequestParamsArray($params);

        // check if we've to authenticate
        $auth = in_array('trigger.deleteDependencies', self::$anonymousFunctions) ? false : true;

        // request
        return $this->request('trigger.deleteDependencies', $params, $arrayKeyProperty, $auth);
    }
    
    /**
     * @brief   Reqeusts the Zabbix API and returns the response of the API
     *          method trigger.syncTemplates.
     *
     * The $params Array can be used, to pass parameters to the Zabbix API.
     * For more informations about these parameters, check the Zabbix API
     * documentation at https://www.zabbix.com/documentation/.
     *
     * The $arrayKeyProperty can be used to get an associatve instead of an
     * indexed array as response. A valid value for the $arrayKeyProperty is
     * is any property of the returned JSON objects (e.g. name, host,
     * hostid, graphid, screenitemid).
     *
     * @param   $params             Zabbix API parameters.
     * @param   $arrayKeyProperty   Object property for key of array.
     *
     * @retval  stdClass
     *
     * @throws  Exception
     */

    public function triggerSyncTemplates($params=array(), $arrayKeyProperty='')
    {
        // get params array for request
        $params = $this->getRequestParamsArray($params);

        // check if we've to authenticate
        $auth = in_array('trigger.syncTemplates', self::$anonymousFunctions) ? false : true;

        // request
        return $this->request('trigger.syncTemplates', $params, $arrayKeyProperty, $auth);
    }
    
    /**
     * @brief   Reqeusts the Zabbix API and returns the response of the API
     *          method trigger.syncTemplateDependencies.
     *
     * The $params Array can be used, to pass parameters to the Zabbix API.
     * For more informations about these parameters, check the Zabbix API
     * documentation at https://www.zabbix.com/documentation/.
     *
     * The $arrayKeyProperty can be used to get an associatve instead of an
     * indexed array as response. A valid value for the $arrayKeyProperty is
     * is any property of the returned JSON objects (e.g. name, host,
     * hostid, graphid, screenitemid).
     *
     * @param   $params             Zabbix API parameters.
     * @param   $arrayKeyProperty   Object property for key of array.
     *
     * @retval  stdClass
     *
     * @throws  Exception
     */

    public function triggerSyncTemplateDependencies($params=array(), $arrayKeyProperty='')
    {
        // get params array for request
        $params = $this->getRequestParamsArray($params);

        // check if we've to authenticate
        $auth = in_array('trigger.syncTemplateDependencies', self::$anonymousFunctions) ? false : true;

        // request
        return $this->request('trigger.syncTemplateDependencies', $params, $arrayKeyProperty, $auth);
    }
    
    /**
     * @brief   Reqeusts the Zabbix API and returns the response of the API
     *          method trigger.isReadable.
     *
     * The $params Array can be used, to pass parameters to the Zabbix API.
     * For more informations about these parameters, check the Zabbix API
     * documentation at https://www.zabbix.com/documentation/.
     *
     * The $arrayKeyProperty can be used to get an associatve instead of an
     * indexed array as response. A valid value for the $arrayKeyProperty is
     * is any property of the returned JSON objects (e.g. name, host,
     * hostid, graphid, screenitemid).
     *
     * @param   $params             Zabbix API parameters.
     * @param   $arrayKeyProperty   Object property for key of array.
     *
     * @retval  stdClass
     *
     * @throws  Exception
     */

    public function triggerIsReadable($params=array(), $arrayKeyProperty='')
    {
        // get params array for request
        $params = $this->getRequestParamsArray($params);

        // check if we've to authenticate
        $auth = in_array('trigger.isReadable', self::$anonymousFunctions) ? false : true;

        // request
        return $this->request('trigger.isReadable', $params, $arrayKeyProperty, $auth);
    }
    
    /**
     * @brief   Reqeusts the Zabbix API and returns the response of the API
     *          method trigger.isWritable.
     *
     * The $params Array can be used, to pass parameters to the Zabbix API.
     * For more informations about these parameters, check the Zabbix API
     * documentation at https://www.zabbix.com/documentation/.
     *
     * The $arrayKeyProperty can be used to get an associatve instead of an
     * indexed array as response. A valid value for the $arrayKeyProperty is
     * is any property of the returned JSON objects (e.g. name, host,
     * hostid, graphid, screenitemid).
     *
     * @param   $params             Zabbix API parameters.
     * @param   $arrayKeyProperty   Object property for key of array.
     *
     * @retval  stdClass
     *
     * @throws  Exception
     */

    public function triggerIsWritable($params=array(), $arrayKeyProperty='')
    {
        // get params array for request
        $params = $this->getRequestParamsArray($params);

        // check if we've to authenticate
        $auth = in_array('trigger.isWritable', self::$anonymousFunctions) ? false : true;

        // request
        return $this->request('trigger.isWritable', $params, $arrayKeyProperty, $auth);
    }
    
    /**
     * @brief   Reqeusts the Zabbix API and returns the response of the API
     *          method trigger.tableName.
     *
     * The $params Array can be used, to pass parameters to the Zabbix API.
     * For more informations about these parameters, check the Zabbix API
     * documentation at https://www.zabbix.com/documentation/.
     *
     * The $arrayKeyProperty can be used to get an associatve instead of an
     * indexed array as response. A valid value for the $arrayKeyProperty is
     * is any property of the returned JSON objects (e.g. name, host,
     * hostid, graphid, screenitemid).
     *
     * @param   $params             Zabbix API parameters.
     * @param   $arrayKeyProperty   Object property for key of array.
     *
     * @retval  stdClass
     *
     * @throws  Exception
     */

    public function triggerTableName($params=array(), $arrayKeyProperty='')
    {
        // get params array for request
        $params = $this->getRequestParamsArray($params);

        // check if we've to authenticate
        $auth = in_array('trigger.tableName', self::$anonymousFunctions) ? false : true;

        // request
        return $this->request('trigger.tableName', $params, $arrayKeyProperty, $auth);
    }
    
    /**
     * @brief   Reqeusts the Zabbix API and returns the response of the API
     *          method trigger.pk.
     *
     * The $params Array can be used, to pass parameters to the Zabbix API.
     * For more informations about these parameters, check the Zabbix API
     * documentation at https://www.zabbix.com/documentation/.
     *
     * The $arrayKeyProperty can be used to get an associatve instead of an
     * indexed array as response. A valid value for the $arrayKeyProperty is
     * is any property of the returned JSON objects (e.g. name, host,
     * hostid, graphid, screenitemid).
     *
     * @param   $params             Zabbix API parameters.
     * @param   $arrayKeyProperty   Object property for key of array.
     *
     * @retval  stdClass
     *
     * @throws  Exception
     */

    public function triggerPk($params=array(), $arrayKeyProperty='')
    {
        // get params array for request
        $params = $this->getRequestParamsArray($params);

        // check if we've to authenticate
        $auth = in_array('trigger.pk', self::$anonymousFunctions) ? false : true;

        // request
        return $this->request('trigger.pk', $params, $arrayKeyProperty, $auth);
    }
    
    /**
     * @brief   Reqeusts the Zabbix API and returns the response of the API
     *          method trigger.pkOption.
     *
     * The $params Array can be used, to pass parameters to the Zabbix API.
     * For more informations about these parameters, check the Zabbix API
     * documentation at https://www.zabbix.com/documentation/.
     *
     * The $arrayKeyProperty can be used to get an associatve instead of an
     * indexed array as response. A valid value for the $arrayKeyProperty is
     * is any property of the returned JSON objects (e.g. name, host,
     * hostid, graphid, screenitemid).
     *
     * @param   $params             Zabbix API parameters.
     * @param   $arrayKeyProperty   Object property for key of array.
     *
     * @retval  stdClass
     *
     * @throws  Exception
     */

    public function triggerPkOption($params=array(), $arrayKeyProperty='')
    {
        // get params array for request
        $params = $this->getRequestParamsArray($params);

        // check if we've to authenticate
        $auth = in_array('trigger.pkOption', self::$anonymousFunctions) ? false : true;

        // request
        return $this->request('trigger.pkOption', $params, $arrayKeyProperty, $auth);
    }
    
    /**
     * @brief   Reqeusts the Zabbix API and returns the response of the API
     *          method triggerprototype.get.
     *
     * The $params Array can be used, to pass parameters to the Zabbix API.
     * For more informations about these parameters, check the Zabbix API
     * documentation at https://www.zabbix.com/documentation/.
     *
     * The $arrayKeyProperty can be used to get an associatve instead of an
     * indexed array as response. A valid value for the $arrayKeyProperty is
     * is any property of the returned JSON objects (e.g. name, host,
     * hostid, graphid, screenitemid).
     *
     * @param   $params             Zabbix API parameters.
     * @param   $arrayKeyProperty   Object property for key of array.
     *
     * @retval  stdClass
     *
     * @throws  Exception
     */

    public function triggerprototypeGet($params=array(), $arrayKeyProperty='')
    {
        // get params array for request
        $params = $this->getRequestParamsArray($params);

        // check if we've to authenticate
        $auth = in_array('triggerprototype.get', self::$anonymousFunctions) ? false : true;

        // request
        return $this->request('triggerprototype.get', $params, $arrayKeyProperty, $auth);
    }
    
    /**
     * @brief   Reqeusts the Zabbix API and returns the response of the API
     *          method triggerprototype.create.
     *
     * The $params Array can be used, to pass parameters to the Zabbix API.
     * For more informations about these parameters, check the Zabbix API
     * documentation at https://www.zabbix.com/documentation/.
     *
     * The $arrayKeyProperty can be used to get an associatve instead of an
     * indexed array as response. A valid value for the $arrayKeyProperty is
     * is any property of the returned JSON objects (e.g. name, host,
     * hostid, graphid, screenitemid).
     *
     * @param   $params             Zabbix API parameters.
     * @param   $arrayKeyProperty   Object property for key of array.
     *
     * @retval  stdClass
     *
     * @throws  Exception
     */

    public function triggerprototypeCreate($params=array(), $arrayKeyProperty='')
    {
        // get params array for request
        $params = $this->getRequestParamsArray($params);

        // check if we've to authenticate
        $auth = in_array('triggerprototype.create', self::$anonymousFunctions) ? false : true;

        // request
        return $this->request('triggerprototype.create', $params, $arrayKeyProperty, $auth);
    }
    
    /**
     * @brief   Reqeusts the Zabbix API and returns the response of the API
     *          method triggerprototype.update.
     *
     * The $params Array can be used, to pass parameters to the Zabbix API.
     * For more informations about these parameters, check the Zabbix API
     * documentation at https://www.zabbix.com/documentation/.
     *
     * The $arrayKeyProperty can be used to get an associatve instead of an
     * indexed array as response. A valid value for the $arrayKeyProperty is
     * is any property of the returned JSON objects (e.g. name, host,
     * hostid, graphid, screenitemid).
     *
     * @param   $params             Zabbix API parameters.
     * @param   $arrayKeyProperty   Object property for key of array.
     *
     * @retval  stdClass
     *
     * @throws  Exception
     */

    public function triggerprototypeUpdate($params=array(), $arrayKeyProperty='')
    {
        // get params array for request
        $params = $this->getRequestParamsArray($params);

        // check if we've to authenticate
        $auth = in_array('triggerprototype.update', self::$anonymousFunctions) ? false : true;

        // request
        return $this->request('triggerprototype.update', $params, $arrayKeyProperty, $auth);
    }
    
    /**
     * @brief   Reqeusts the Zabbix API and returns the response of the API
     *          method triggerprototype.delete.
     *
     * The $params Array can be used, to pass parameters to the Zabbix API.
     * For more informations about these parameters, check the Zabbix API
     * documentation at https://www.zabbix.com/documentation/.
     *
     * The $arrayKeyProperty can be used to get an associatve instead of an
     * indexed array as response. A valid value for the $arrayKeyProperty is
     * is any property of the returned JSON objects (e.g. name, host,
     * hostid, graphid, screenitemid).
     *
     * @param   $params             Zabbix API parameters.
     * @param   $arrayKeyProperty   Object property for key of array.
     *
     * @retval  stdClass
     *
     * @throws  Exception
     */

    public function triggerprototypeDelete($params=array(), $arrayKeyProperty='')
    {
        // get params array for request
        $params = $this->getRequestParamsArray($params);

        // check if we've to authenticate
        $auth = in_array('triggerprototype.delete', self::$anonymousFunctions) ? false : true;

        // request
        return $this->request('triggerprototype.delete', $params, $arrayKeyProperty, $auth);
    }
    
    /**
     * @brief   Reqeusts the Zabbix API and returns the response of the API
     *          method triggerprototype.addDependencies.
     *
     * The $params Array can be used, to pass parameters to the Zabbix API.
     * For more informations about these parameters, check the Zabbix API
     * documentation at https://www.zabbix.com/documentation/.
     *
     * The $arrayKeyProperty can be used to get an associatve instead of an
     * indexed array as response. A valid value for the $arrayKeyProperty is
     * is any property of the returned JSON objects (e.g. name, host,
     * hostid, graphid, screenitemid).
     *
     * @param   $params             Zabbix API parameters.
     * @param   $arrayKeyProperty   Object property for key of array.
     *
     * @retval  stdClass
     *
     * @throws  Exception
     */

    public function triggerprototypeAddDependencies($params=array(), $arrayKeyProperty='')
    {
        // get params array for request
        $params = $this->getRequestParamsArray($params);

        // check if we've to authenticate
        $auth = in_array('triggerprototype.addDependencies', self::$anonymousFunctions) ? false : true;

        // request
        return $this->request('triggerprototype.addDependencies', $params, $arrayKeyProperty, $auth);
    }
    
    /**
     * @brief   Reqeusts the Zabbix API and returns the response of the API
     *          method triggerprototype.syncTemplateDependencies.
     *
     * The $params Array can be used, to pass parameters to the Zabbix API.
     * For more informations about these parameters, check the Zabbix API
     * documentation at https://www.zabbix.com/documentation/.
     *
     * The $arrayKeyProperty can be used to get an associatve instead of an
     * indexed array as response. A valid value for the $arrayKeyProperty is
     * is any property of the returned JSON objects (e.g. name, host,
     * hostid, graphid, screenitemid).
     *
     * @param   $params             Zabbix API parameters.
     * @param   $arrayKeyProperty   Object property for key of array.
     *
     * @retval  stdClass
     *
     * @throws  Exception
     */

    public function triggerprototypeSyncTemplateDependencies($params=array(), $arrayKeyProperty='')
    {
        // get params array for request
        $params = $this->getRequestParamsArray($params);

        // check if we've to authenticate
        $auth = in_array('triggerprototype.syncTemplateDependencies', self::$anonymousFunctions) ? false : true;

        // request
        return $this->request('triggerprototype.syncTemplateDependencies', $params, $arrayKeyProperty, $auth);
    }
    
    /**
     * @brief   Reqeusts the Zabbix API and returns the response of the API
     *          method triggerprototype.syncTemplates.
     *
     * The $params Array can be used, to pass parameters to the Zabbix API.
     * For more informations about these parameters, check the Zabbix API
     * documentation at https://www.zabbix.com/documentation/.
     *
     * The $arrayKeyProperty can be used to get an associatve instead of an
     * indexed array as response. A valid value for the $arrayKeyProperty is
     * is any property of the returned JSON objects (e.g. name, host,
     * hostid, graphid, screenitemid).
     *
     * @param   $params             Zabbix API parameters.
     * @param   $arrayKeyProperty   Object property for key of array.
     *
     * @retval  stdClass
     *
     * @throws  Exception
     */

    public function triggerprototypeSyncTemplates($params=array(), $arrayKeyProperty='')
    {
        // get params array for request
        $params = $this->getRequestParamsArray($params);

        // check if we've to authenticate
        $auth = in_array('triggerprototype.syncTemplates', self::$anonymousFunctions) ? false : true;

        // request
        return $this->request('triggerprototype.syncTemplates', $params, $arrayKeyProperty, $auth);
    }
    
    /**
     * @brief   Reqeusts the Zabbix API and returns the response of the API
     *          method triggerprototype.tableName.
     *
     * The $params Array can be used, to pass parameters to the Zabbix API.
     * For more informations about these parameters, check the Zabbix API
     * documentation at https://www.zabbix.com/documentation/.
     *
     * The $arrayKeyProperty can be used to get an associatve instead of an
     * indexed array as response. A valid value for the $arrayKeyProperty is
     * is any property of the returned JSON objects (e.g. name, host,
     * hostid, graphid, screenitemid).
     *
     * @param   $params             Zabbix API parameters.
     * @param   $arrayKeyProperty   Object property for key of array.
     *
     * @retval  stdClass
     *
     * @throws  Exception
     */

    public function triggerprototypeTableName($params=array(), $arrayKeyProperty='')
    {
        // get params array for request
        $params = $this->getRequestParamsArray($params);

        // check if we've to authenticate
        $auth = in_array('triggerprototype.tableName', self::$anonymousFunctions) ? false : true;

        // request
        return $this->request('triggerprototype.tableName', $params, $arrayKeyProperty, $auth);
    }
    
    /**
     * @brief   Reqeusts the Zabbix API and returns the response of the API
     *          method triggerprototype.pk.
     *
     * The $params Array can be used, to pass parameters to the Zabbix API.
     * For more informations about these parameters, check the Zabbix API
     * documentation at https://www.zabbix.com/documentation/.
     *
     * The $arrayKeyProperty can be used to get an associatve instead of an
     * indexed array as response. A valid value for the $arrayKeyProperty is
     * is any property of the returned JSON objects (e.g. name, host,
     * hostid, graphid, screenitemid).
     *
     * @param   $params             Zabbix API parameters.
     * @param   $arrayKeyProperty   Object property for key of array.
     *
     * @retval  stdClass
     *
     * @throws  Exception
     */

    public function triggerprototypePk($params=array(), $arrayKeyProperty='')
    {
        // get params array for request
        $params = $this->getRequestParamsArray($params);

        // check if we've to authenticate
        $auth = in_array('triggerprototype.pk', self::$anonymousFunctions) ? false : true;

        // request
        return $this->request('triggerprototype.pk', $params, $arrayKeyProperty, $auth);
    }
    
    /**
     * @brief   Reqeusts the Zabbix API and returns the response of the API
     *          method triggerprototype.pkOption.
     *
     * The $params Array can be used, to pass parameters to the Zabbix API.
     * For more informations about these parameters, check the Zabbix API
     * documentation at https://www.zabbix.com/documentation/.
     *
     * The $arrayKeyProperty can be used to get an associatve instead of an
     * indexed array as response. A valid value for the $arrayKeyProperty is
     * is any property of the returned JSON objects (e.g. name, host,
     * hostid, graphid, screenitemid).
     *
     * @param   $params             Zabbix API parameters.
     * @param   $arrayKeyProperty   Object property for key of array.
     *
     * @retval  stdClass
     *
     * @throws  Exception
     */

    public function triggerprototypePkOption($params=array(), $arrayKeyProperty='')
    {
        // get params array for request
        $params = $this->getRequestParamsArray($params);

        // check if we've to authenticate
        $auth = in_array('triggerprototype.pkOption', self::$anonymousFunctions) ? false : true;

        // request
        return $this->request('triggerprototype.pkOption', $params, $arrayKeyProperty, $auth);
    }
    
    /**
     * @brief   Reqeusts the Zabbix API and returns the response of the API
     *          method user.get.
     *
     * The $params Array can be used, to pass parameters to the Zabbix API.
     * For more informations about these parameters, check the Zabbix API
     * documentation at https://www.zabbix.com/documentation/.
     *
     * The $arrayKeyProperty can be used to get an associatve instead of an
     * indexed array as response. A valid value for the $arrayKeyProperty is
     * is any property of the returned JSON objects (e.g. name, host,
     * hostid, graphid, screenitemid).
     *
     * @param   $params             Zabbix API parameters.
     * @param   $arrayKeyProperty   Object property for key of array.
     *
     * @retval  stdClass
     *
     * @throws  Exception
     */

    public function userGet($params=array(), $arrayKeyProperty='')
    {
        // get params array for request
        $params = $this->getRequestParamsArray($params);

        // check if we've to authenticate
        $auth = in_array('user.get', self::$anonymousFunctions) ? false : true;

        // request
        return $this->request('user.get', $params, $arrayKeyProperty, $auth);
    }
    
    /**
     * @brief   Reqeusts the Zabbix API and returns the response of the API
     *          method user.create.
     *
     * The $params Array can be used, to pass parameters to the Zabbix API.
     * For more informations about these parameters, check the Zabbix API
     * documentation at https://www.zabbix.com/documentation/.
     *
     * The $arrayKeyProperty can be used to get an associatve instead of an
     * indexed array as response. A valid value for the $arrayKeyProperty is
     * is any property of the returned JSON objects (e.g. name, host,
     * hostid, graphid, screenitemid).
     *
     * @param   $params             Zabbix API parameters.
     * @param   $arrayKeyProperty   Object property for key of array.
     *
     * @retval  stdClass
     *
     * @throws  Exception
     */

    public function userCreate($params=array(), $arrayKeyProperty='')
    {
        // get params array for request
        $params = $this->getRequestParamsArray($params);

        // check if we've to authenticate
        $auth = in_array('user.create', self::$anonymousFunctions) ? false : true;

        // request
        return $this->request('user.create', $params, $arrayKeyProperty, $auth);
    }
    
    /**
     * @brief   Reqeusts the Zabbix API and returns the response of the API
     *          method user.update.
     *
     * The $params Array can be used, to pass parameters to the Zabbix API.
     * For more informations about these parameters, check the Zabbix API
     * documentation at https://www.zabbix.com/documentation/.
     *
     * The $arrayKeyProperty can be used to get an associatve instead of an
     * indexed array as response. A valid value for the $arrayKeyProperty is
     * is any property of the returned JSON objects (e.g. name, host,
     * hostid, graphid, screenitemid).
     *
     * @param   $params             Zabbix API parameters.
     * @param   $arrayKeyProperty   Object property for key of array.
     *
     * @retval  stdClass
     *
     * @throws  Exception
     */

    public function userUpdate($params=array(), $arrayKeyProperty='')
    {
        // get params array for request
        $params = $this->getRequestParamsArray($params);

        // check if we've to authenticate
        $auth = in_array('user.update', self::$anonymousFunctions) ? false : true;

        // request
        return $this->request('user.update', $params, $arrayKeyProperty, $auth);
    }
    
    /**
     * @brief   Reqeusts the Zabbix API and returns the response of the API
     *          method user.updateProfile.
     *
     * The $params Array can be used, to pass parameters to the Zabbix API.
     * For more informations about these parameters, check the Zabbix API
     * documentation at https://www.zabbix.com/documentation/.
     *
     * The $arrayKeyProperty can be used to get an associatve instead of an
     * indexed array as response. A valid value for the $arrayKeyProperty is
     * is any property of the returned JSON objects (e.g. name, host,
     * hostid, graphid, screenitemid).
     *
     * @param   $params             Zabbix API parameters.
     * @param   $arrayKeyProperty   Object property for key of array.
     *
     * @retval  stdClass
     *
     * @throws  Exception
     */

    public function userUpdateProfile($params=array(), $arrayKeyProperty='')
    {
        // get params array for request
        $params = $this->getRequestParamsArray($params);

        // check if we've to authenticate
        $auth = in_array('user.updateProfile', self::$anonymousFunctions) ? false : true;

        // request
        return $this->request('user.updateProfile', $params, $arrayKeyProperty, $auth);
    }
    
    /**
     * @brief   Reqeusts the Zabbix API and returns the response of the API
     *          method user.delete.
     *
     * The $params Array can be used, to pass parameters to the Zabbix API.
     * For more informations about these parameters, check the Zabbix API
     * documentation at https://www.zabbix.com/documentation/.
     *
     * The $arrayKeyProperty can be used to get an associatve instead of an
     * indexed array as response. A valid value for the $arrayKeyProperty is
     * is any property of the returned JSON objects (e.g. name, host,
     * hostid, graphid, screenitemid).
     *
     * @param   $params             Zabbix API parameters.
     * @param   $arrayKeyProperty   Object property for key of array.
     *
     * @retval  stdClass
     *
     * @throws  Exception
     */

    public function userDelete($params=array(), $arrayKeyProperty='')
    {
        // get params array for request
        $params = $this->getRequestParamsArray($params);

        // check if we've to authenticate
        $auth = in_array('user.delete', self::$anonymousFunctions) ? false : true;

        // request
        return $this->request('user.delete', $params, $arrayKeyProperty, $auth);
    }
    
    /**
     * @brief   Reqeusts the Zabbix API and returns the response of the API
     *          method user.addMedia.
     *
     * The $params Array can be used, to pass parameters to the Zabbix API.
     * For more informations about these parameters, check the Zabbix API
     * documentation at https://www.zabbix.com/documentation/.
     *
     * The $arrayKeyProperty can be used to get an associatve instead of an
     * indexed array as response. A valid value for the $arrayKeyProperty is
     * is any property of the returned JSON objects (e.g. name, host,
     * hostid, graphid, screenitemid).
     *
     * @param   $params             Zabbix API parameters.
     * @param   $arrayKeyProperty   Object property for key of array.
     *
     * @retval  stdClass
     *
     * @throws  Exception
     */

    public function userAddMedia($params=array(), $arrayKeyProperty='')
    {
        // get params array for request
        $params = $this->getRequestParamsArray($params);

        // check if we've to authenticate
        $auth = in_array('user.addMedia', self::$anonymousFunctions) ? false : true;

        // request
        return $this->request('user.addMedia', $params, $arrayKeyProperty, $auth);
    }
    
    /**
     * @brief   Reqeusts the Zabbix API and returns the response of the API
     *          method user.updateMedia.
     *
     * The $params Array can be used, to pass parameters to the Zabbix API.
     * For more informations about these parameters, check the Zabbix API
     * documentation at https://www.zabbix.com/documentation/.
     *
     * The $arrayKeyProperty can be used to get an associatve instead of an
     * indexed array as response. A valid value for the $arrayKeyProperty is
     * is any property of the returned JSON objects (e.g. name, host,
     * hostid, graphid, screenitemid).
     *
     * @param   $params             Zabbix API parameters.
     * @param   $arrayKeyProperty   Object property for key of array.
     *
     * @retval  stdClass
     *
     * @throws  Exception
     */

    public function userUpdateMedia($params=array(), $arrayKeyProperty='')
    {
        // get params array for request
        $params = $this->getRequestParamsArray($params);

        // check if we've to authenticate
        $auth = in_array('user.updateMedia', self::$anonymousFunctions) ? false : true;

        // request
        return $this->request('user.updateMedia', $params, $arrayKeyProperty, $auth);
    }
    
    /**
     * @brief   Reqeusts the Zabbix API and returns the response of the API
     *          method user.deleteMedia.
     *
     * The $params Array can be used, to pass parameters to the Zabbix API.
     * For more informations about these parameters, check the Zabbix API
     * documentation at https://www.zabbix.com/documentation/.
     *
     * The $arrayKeyProperty can be used to get an associatve instead of an
     * indexed array as response. A valid value for the $arrayKeyProperty is
     * is any property of the returned JSON objects (e.g. name, host,
     * hostid, graphid, screenitemid).
     *
     * @param   $params             Zabbix API parameters.
     * @param   $arrayKeyProperty   Object property for key of array.
     *
     * @retval  stdClass
     *
     * @throws  Exception
     */

    public function userDeleteMedia($params=array(), $arrayKeyProperty='')
    {
        // get params array for request
        $params = $this->getRequestParamsArray($params);

        // check if we've to authenticate
        $auth = in_array('user.deleteMedia', self::$anonymousFunctions) ? false : true;

        // request
        return $this->request('user.deleteMedia', $params, $arrayKeyProperty, $auth);
    }
    
    /**
     * @brief   Reqeusts the Zabbix API and returns the response of the API
     *          method user.deleteMediaReal.
     *
     * The $params Array can be used, to pass parameters to the Zabbix API.
     * For more informations about these parameters, check the Zabbix API
     * documentation at https://www.zabbix.com/documentation/.
     *
     * The $arrayKeyProperty can be used to get an associatve instead of an
     * indexed array as response. A valid value for the $arrayKeyProperty is
     * is any property of the returned JSON objects (e.g. name, host,
     * hostid, graphid, screenitemid).
     *
     * @param   $params             Zabbix API parameters.
     * @param   $arrayKeyProperty   Object property for key of array.
     *
     * @retval  stdClass
     *
     * @throws  Exception
     */

    public function userDeleteMediaReal($params=array(), $arrayKeyProperty='')
    {
        // get params array for request
        $params = $this->getRequestParamsArray($params);

        // check if we've to authenticate
        $auth = in_array('user.deleteMediaReal', self::$anonymousFunctions) ? false : true;

        // request
        return $this->request('user.deleteMediaReal', $params, $arrayKeyProperty, $auth);
    }
    
    /**
     * @brief   Reqeusts the Zabbix API and returns the response of the API
     *          method user.checkAuthentication.
     *
     * The $params Array can be used, to pass parameters to the Zabbix API.
     * For more informations about these parameters, check the Zabbix API
     * documentation at https://www.zabbix.com/documentation/.
     *
     * The $arrayKeyProperty can be used to get an associatve instead of an
     * indexed array as response. A valid value for the $arrayKeyProperty is
     * is any property of the returned JSON objects (e.g. name, host,
     * hostid, graphid, screenitemid).
     *
     * @param   $params             Zabbix API parameters.
     * @param   $arrayKeyProperty   Object property for key of array.
     *
     * @retval  stdClass
     *
     * @throws  Exception
     */

    public function userCheckAuthentication($params=array(), $arrayKeyProperty='')
    {
        // get params array for request
        $params = $this->getRequestParamsArray($params);

        // check if we've to authenticate
        $auth = in_array('user.checkAuthentication', self::$anonymousFunctions) ? false : true;

        // request
        return $this->request('user.checkAuthentication', $params, $arrayKeyProperty, $auth);
    }
    
    /**
     * @brief   Reqeusts the Zabbix API and returns the response of the API
     *          method user.isReadable.
     *
     * The $params Array can be used, to pass parameters to the Zabbix API.
     * For more informations about these parameters, check the Zabbix API
     * documentation at https://www.zabbix.com/documentation/.
     *
     * The $arrayKeyProperty can be used to get an associatve instead of an
     * indexed array as response. A valid value for the $arrayKeyProperty is
     * is any property of the returned JSON objects (e.g. name, host,
     * hostid, graphid, screenitemid).
     *
     * @param   $params             Zabbix API parameters.
     * @param   $arrayKeyProperty   Object property for key of array.
     *
     * @retval  stdClass
     *
     * @throws  Exception
     */

    public function userIsReadable($params=array(), $arrayKeyProperty='')
    {
        // get params array for request
        $params = $this->getRequestParamsArray($params);

        // check if we've to authenticate
        $auth = in_array('user.isReadable', self::$anonymousFunctions) ? false : true;

        // request
        return $this->request('user.isReadable', $params, $arrayKeyProperty, $auth);
    }
    
    /**
     * @brief   Reqeusts the Zabbix API and returns the response of the API
     *          method user.isWritable.
     *
     * The $params Array can be used, to pass parameters to the Zabbix API.
     * For more informations about these parameters, check the Zabbix API
     * documentation at https://www.zabbix.com/documentation/.
     *
     * The $arrayKeyProperty can be used to get an associatve instead of an
     * indexed array as response. A valid value for the $arrayKeyProperty is
     * is any property of the returned JSON objects (e.g. name, host,
     * hostid, graphid, screenitemid).
     *
     * @param   $params             Zabbix API parameters.
     * @param   $arrayKeyProperty   Object property for key of array.
     *
     * @retval  stdClass
     *
     * @throws  Exception
     */

    public function userIsWritable($params=array(), $arrayKeyProperty='')
    {
        // get params array for request
        $params = $this->getRequestParamsArray($params);

        // check if we've to authenticate
        $auth = in_array('user.isWritable', self::$anonymousFunctions) ? false : true;

        // request
        return $this->request('user.isWritable', $params, $arrayKeyProperty, $auth);
    }
    
    /**
     * @brief   Reqeusts the Zabbix API and returns the response of the API
     *          method user.tableName.
     *
     * The $params Array can be used, to pass parameters to the Zabbix API.
     * For more informations about these parameters, check the Zabbix API
     * documentation at https://www.zabbix.com/documentation/.
     *
     * The $arrayKeyProperty can be used to get an associatve instead of an
     * indexed array as response. A valid value for the $arrayKeyProperty is
     * is any property of the returned JSON objects (e.g. name, host,
     * hostid, graphid, screenitemid).
     *
     * @param   $params             Zabbix API parameters.
     * @param   $arrayKeyProperty   Object property for key of array.
     *
     * @retval  stdClass
     *
     * @throws  Exception
     */

    public function userTableName($params=array(), $arrayKeyProperty='')
    {
        // get params array for request
        $params = $this->getRequestParamsArray($params);

        // check if we've to authenticate
        $auth = in_array('user.tableName', self::$anonymousFunctions) ? false : true;

        // request
        return $this->request('user.tableName', $params, $arrayKeyProperty, $auth);
    }
    
    /**
     * @brief   Reqeusts the Zabbix API and returns the response of the API
     *          method user.pk.
     *
     * The $params Array can be used, to pass parameters to the Zabbix API.
     * For more informations about these parameters, check the Zabbix API
     * documentation at https://www.zabbix.com/documentation/.
     *
     * The $arrayKeyProperty can be used to get an associatve instead of an
     * indexed array as response. A valid value for the $arrayKeyProperty is
     * is any property of the returned JSON objects (e.g. name, host,
     * hostid, graphid, screenitemid).
     *
     * @param   $params             Zabbix API parameters.
     * @param   $arrayKeyProperty   Object property for key of array.
     *
     * @retval  stdClass
     *
     * @throws  Exception
     */

    public function userPk($params=array(), $arrayKeyProperty='')
    {
        // get params array for request
        $params = $this->getRequestParamsArray($params);

        // check if we've to authenticate
        $auth = in_array('user.pk', self::$anonymousFunctions) ? false : true;

        // request
        return $this->request('user.pk', $params, $arrayKeyProperty, $auth);
    }
    
    /**
     * @brief   Reqeusts the Zabbix API and returns the response of the API
     *          method user.pkOption.
     *
     * The $params Array can be used, to pass parameters to the Zabbix API.
     * For more informations about these parameters, check the Zabbix API
     * documentation at https://www.zabbix.com/documentation/.
     *
     * The $arrayKeyProperty can be used to get an associatve instead of an
     * indexed array as response. A valid value for the $arrayKeyProperty is
     * is any property of the returned JSON objects (e.g. name, host,
     * hostid, graphid, screenitemid).
     *
     * @param   $params             Zabbix API parameters.
     * @param   $arrayKeyProperty   Object property for key of array.
     *
     * @retval  stdClass
     *
     * @throws  Exception
     */

    public function userPkOption($params=array(), $arrayKeyProperty='')
    {
        // get params array for request
        $params = $this->getRequestParamsArray($params);

        // check if we've to authenticate
        $auth = in_array('user.pkOption', self::$anonymousFunctions) ? false : true;

        // request
        return $this->request('user.pkOption', $params, $arrayKeyProperty, $auth);
    }
    
    /**
     * @brief   Reqeusts the Zabbix API and returns the response of the API
     *          method usergroup.get.
     *
     * The $params Array can be used, to pass parameters to the Zabbix API.
     * For more informations about these parameters, check the Zabbix API
     * documentation at https://www.zabbix.com/documentation/.
     *
     * The $arrayKeyProperty can be used to get an associatve instead of an
     * indexed array as response. A valid value for the $arrayKeyProperty is
     * is any property of the returned JSON objects (e.g. name, host,
     * hostid, graphid, screenitemid).
     *
     * @param   $params             Zabbix API parameters.
     * @param   $arrayKeyProperty   Object property for key of array.
     *
     * @retval  stdClass
     *
     * @throws  Exception
     */

    public function usergroupGet($params=array(), $arrayKeyProperty='')
    {
        // get params array for request
        $params = $this->getRequestParamsArray($params);

        // check if we've to authenticate
        $auth = in_array('usergroup.get', self::$anonymousFunctions) ? false : true;

        // request
        return $this->request('usergroup.get', $params, $arrayKeyProperty, $auth);
    }
    
    /**
     * @brief   Reqeusts the Zabbix API and returns the response of the API
     *          method usergroup.create.
     *
     * The $params Array can be used, to pass parameters to the Zabbix API.
     * For more informations about these parameters, check the Zabbix API
     * documentation at https://www.zabbix.com/documentation/.
     *
     * The $arrayKeyProperty can be used to get an associatve instead of an
     * indexed array as response. A valid value for the $arrayKeyProperty is
     * is any property of the returned JSON objects (e.g. name, host,
     * hostid, graphid, screenitemid).
     *
     * @param   $params             Zabbix API parameters.
     * @param   $arrayKeyProperty   Object property for key of array.
     *
     * @retval  stdClass
     *
     * @throws  Exception
     */

    public function usergroupCreate($params=array(), $arrayKeyProperty='')
    {
        // get params array for request
        $params = $this->getRequestParamsArray($params);

        // check if we've to authenticate
        $auth = in_array('usergroup.create', self::$anonymousFunctions) ? false : true;

        // request
        return $this->request('usergroup.create', $params, $arrayKeyProperty, $auth);
    }
    
    /**
     * @brief   Reqeusts the Zabbix API and returns the response of the API
     *          method usergroup.update.
     *
     * The $params Array can be used, to pass parameters to the Zabbix API.
     * For more informations about these parameters, check the Zabbix API
     * documentation at https://www.zabbix.com/documentation/.
     *
     * The $arrayKeyProperty can be used to get an associatve instead of an
     * indexed array as response. A valid value for the $arrayKeyProperty is
     * is any property of the returned JSON objects (e.g. name, host,
     * hostid, graphid, screenitemid).
     *
     * @param   $params             Zabbix API parameters.
     * @param   $arrayKeyProperty   Object property for key of array.
     *
     * @retval  stdClass
     *
     * @throws  Exception
     */

    public function usergroupUpdate($params=array(), $arrayKeyProperty='')
    {
        // get params array for request
        $params = $this->getRequestParamsArray($params);

        // check if we've to authenticate
        $auth = in_array('usergroup.update', self::$anonymousFunctions) ? false : true;

        // request
        return $this->request('usergroup.update', $params, $arrayKeyProperty, $auth);
    }
    
    /**
     * @brief   Reqeusts the Zabbix API and returns the response of the API
     *          method usergroup.massAdd.
     *
     * The $params Array can be used, to pass parameters to the Zabbix API.
     * For more informations about these parameters, check the Zabbix API
     * documentation at https://www.zabbix.com/documentation/.
     *
     * The $arrayKeyProperty can be used to get an associatve instead of an
     * indexed array as response. A valid value for the $arrayKeyProperty is
     * is any property of the returned JSON objects (e.g. name, host,
     * hostid, graphid, screenitemid).
     *
     * @param   $params             Zabbix API parameters.
     * @param   $arrayKeyProperty   Object property for key of array.
     *
     * @retval  stdClass
     *
     * @throws  Exception
     */

    public function usergroupMassAdd($params=array(), $arrayKeyProperty='')
    {
        // get params array for request
        $params = $this->getRequestParamsArray($params);

        // check if we've to authenticate
        $auth = in_array('usergroup.massAdd', self::$anonymousFunctions) ? false : true;

        // request
        return $this->request('usergroup.massAdd', $params, $arrayKeyProperty, $auth);
    }
    
    /**
     * @brief   Reqeusts the Zabbix API and returns the response of the API
     *          method usergroup.massUpdate.
     *
     * The $params Array can be used, to pass parameters to the Zabbix API.
     * For more informations about these parameters, check the Zabbix API
     * documentation at https://www.zabbix.com/documentation/.
     *
     * The $arrayKeyProperty can be used to get an associatve instead of an
     * indexed array as response. A valid value for the $arrayKeyProperty is
     * is any property of the returned JSON objects (e.g. name, host,
     * hostid, graphid, screenitemid).
     *
     * @param   $params             Zabbix API parameters.
     * @param   $arrayKeyProperty   Object property for key of array.
     *
     * @retval  stdClass
     *
     * @throws  Exception
     */

    public function usergroupMassUpdate($params=array(), $arrayKeyProperty='')
    {
        // get params array for request
        $params = $this->getRequestParamsArray($params);

        // check if we've to authenticate
        $auth = in_array('usergroup.massUpdate', self::$anonymousFunctions) ? false : true;

        // request
        return $this->request('usergroup.massUpdate', $params, $arrayKeyProperty, $auth);
    }
    
    /**
     * @brief   Reqeusts the Zabbix API and returns the response of the API
     *          method usergroup.delete.
     *
     * The $params Array can be used, to pass parameters to the Zabbix API.
     * For more informations about these parameters, check the Zabbix API
     * documentation at https://www.zabbix.com/documentation/.
     *
     * The $arrayKeyProperty can be used to get an associatve instead of an
     * indexed array as response. A valid value for the $arrayKeyProperty is
     * is any property of the returned JSON objects (e.g. name, host,
     * hostid, graphid, screenitemid).
     *
     * @param   $params             Zabbix API parameters.
     * @param   $arrayKeyProperty   Object property for key of array.
     *
     * @retval  stdClass
     *
     * @throws  Exception
     */

    public function usergroupDelete($params=array(), $arrayKeyProperty='')
    {
        // get params array for request
        $params = $this->getRequestParamsArray($params);

        // check if we've to authenticate
        $auth = in_array('usergroup.delete', self::$anonymousFunctions) ? false : true;

        // request
        return $this->request('usergroup.delete', $params, $arrayKeyProperty, $auth);
    }
    
    /**
     * @brief   Reqeusts the Zabbix API and returns the response of the API
     *          method usergroup.isReadable.
     *
     * The $params Array can be used, to pass parameters to the Zabbix API.
     * For more informations about these parameters, check the Zabbix API
     * documentation at https://www.zabbix.com/documentation/.
     *
     * The $arrayKeyProperty can be used to get an associatve instead of an
     * indexed array as response. A valid value for the $arrayKeyProperty is
     * is any property of the returned JSON objects (e.g. name, host,
     * hostid, graphid, screenitemid).
     *
     * @param   $params             Zabbix API parameters.
     * @param   $arrayKeyProperty   Object property for key of array.
     *
     * @retval  stdClass
     *
     * @throws  Exception
     */

    public function usergroupIsReadable($params=array(), $arrayKeyProperty='')
    {
        // get params array for request
        $params = $this->getRequestParamsArray($params);

        // check if we've to authenticate
        $auth = in_array('usergroup.isReadable', self::$anonymousFunctions) ? false : true;

        // request
        return $this->request('usergroup.isReadable', $params, $arrayKeyProperty, $auth);
    }
    
    /**
     * @brief   Reqeusts the Zabbix API and returns the response of the API
     *          method usergroup.isWritable.
     *
     * The $params Array can be used, to pass parameters to the Zabbix API.
     * For more informations about these parameters, check the Zabbix API
     * documentation at https://www.zabbix.com/documentation/.
     *
     * The $arrayKeyProperty can be used to get an associatve instead of an
     * indexed array as response. A valid value for the $arrayKeyProperty is
     * is any property of the returned JSON objects (e.g. name, host,
     * hostid, graphid, screenitemid).
     *
     * @param   $params             Zabbix API parameters.
     * @param   $arrayKeyProperty   Object property for key of array.
     *
     * @retval  stdClass
     *
     * @throws  Exception
     */

    public function usergroupIsWritable($params=array(), $arrayKeyProperty='')
    {
        // get params array for request
        $params = $this->getRequestParamsArray($params);

        // check if we've to authenticate
        $auth = in_array('usergroup.isWritable', self::$anonymousFunctions) ? false : true;

        // request
        return $this->request('usergroup.isWritable', $params, $arrayKeyProperty, $auth);
    }
    
    /**
     * @brief   Reqeusts the Zabbix API and returns the response of the API
     *          method usergroup.tableName.
     *
     * The $params Array can be used, to pass parameters to the Zabbix API.
     * For more informations about these parameters, check the Zabbix API
     * documentation at https://www.zabbix.com/documentation/.
     *
     * The $arrayKeyProperty can be used to get an associatve instead of an
     * indexed array as response. A valid value for the $arrayKeyProperty is
     * is any property of the returned JSON objects (e.g. name, host,
     * hostid, graphid, screenitemid).
     *
     * @param   $params             Zabbix API parameters.
     * @param   $arrayKeyProperty   Object property for key of array.
     *
     * @retval  stdClass
     *
     * @throws  Exception
     */

    public function usergroupTableName($params=array(), $arrayKeyProperty='')
    {
        // get params array for request
        $params = $this->getRequestParamsArray($params);

        // check if we've to authenticate
        $auth = in_array('usergroup.tableName', self::$anonymousFunctions) ? false : true;

        // request
        return $this->request('usergroup.tableName', $params, $arrayKeyProperty, $auth);
    }
    
    /**
     * @brief   Reqeusts the Zabbix API and returns the response of the API
     *          method usergroup.pk.
     *
     * The $params Array can be used, to pass parameters to the Zabbix API.
     * For more informations about these parameters, check the Zabbix API
     * documentation at https://www.zabbix.com/documentation/.
     *
     * The $arrayKeyProperty can be used to get an associatve instead of an
     * indexed array as response. A valid value for the $arrayKeyProperty is
     * is any property of the returned JSON objects (e.g. name, host,
     * hostid, graphid, screenitemid).
     *
     * @param   $params             Zabbix API parameters.
     * @param   $arrayKeyProperty   Object property for key of array.
     *
     * @retval  stdClass
     *
     * @throws  Exception
     */

    public function usergroupPk($params=array(), $arrayKeyProperty='')
    {
        // get params array for request
        $params = $this->getRequestParamsArray($params);

        // check if we've to authenticate
        $auth = in_array('usergroup.pk', self::$anonymousFunctions) ? false : true;

        // request
        return $this->request('usergroup.pk', $params, $arrayKeyProperty, $auth);
    }
    
    /**
     * @brief   Reqeusts the Zabbix API and returns the response of the API
     *          method usergroup.pkOption.
     *
     * The $params Array can be used, to pass parameters to the Zabbix API.
     * For more informations about these parameters, check the Zabbix API
     * documentation at https://www.zabbix.com/documentation/.
     *
     * The $arrayKeyProperty can be used to get an associatve instead of an
     * indexed array as response. A valid value for the $arrayKeyProperty is
     * is any property of the returned JSON objects (e.g. name, host,
     * hostid, graphid, screenitemid).
     *
     * @param   $params             Zabbix API parameters.
     * @param   $arrayKeyProperty   Object property for key of array.
     *
     * @retval  stdClass
     *
     * @throws  Exception
     */

    public function usergroupPkOption($params=array(), $arrayKeyProperty='')
    {
        // get params array for request
        $params = $this->getRequestParamsArray($params);

        // check if we've to authenticate
        $auth = in_array('usergroup.pkOption', self::$anonymousFunctions) ? false : true;

        // request
        return $this->request('usergroup.pkOption', $params, $arrayKeyProperty, $auth);
    }
    
    /**
     * @brief   Reqeusts the Zabbix API and returns the response of the API
     *          method usermacro.get.
     *
     * The $params Array can be used, to pass parameters to the Zabbix API.
     * For more informations about these parameters, check the Zabbix API
     * documentation at https://www.zabbix.com/documentation/.
     *
     * The $arrayKeyProperty can be used to get an associatve instead of an
     * indexed array as response. A valid value for the $arrayKeyProperty is
     * is any property of the returned JSON objects (e.g. name, host,
     * hostid, graphid, screenitemid).
     *
     * @param   $params             Zabbix API parameters.
     * @param   $arrayKeyProperty   Object property for key of array.
     *
     * @retval  stdClass
     *
     * @throws  Exception
     */

    public function usermacroGet($params=array(), $arrayKeyProperty='')
    {
        // get params array for request
        $params = $this->getRequestParamsArray($params);

        // check if we've to authenticate
        $auth = in_array('usermacro.get', self::$anonymousFunctions) ? false : true;

        // request
        return $this->request('usermacro.get', $params, $arrayKeyProperty, $auth);
    }
    
    /**
     * @brief   Reqeusts the Zabbix API and returns the response of the API
     *          method usermacro.createGlobal.
     *
     * The $params Array can be used, to pass parameters to the Zabbix API.
     * For more informations about these parameters, check the Zabbix API
     * documentation at https://www.zabbix.com/documentation/.
     *
     * The $arrayKeyProperty can be used to get an associatve instead of an
     * indexed array as response. A valid value for the $arrayKeyProperty is
     * is any property of the returned JSON objects (e.g. name, host,
     * hostid, graphid, screenitemid).
     *
     * @param   $params             Zabbix API parameters.
     * @param   $arrayKeyProperty   Object property for key of array.
     *
     * @retval  stdClass
     *
     * @throws  Exception
     */

    public function usermacroCreateGlobal($params=array(), $arrayKeyProperty='')
    {
        // get params array for request
        $params = $this->getRequestParamsArray($params);

        // check if we've to authenticate
        $auth = in_array('usermacro.createGlobal', self::$anonymousFunctions) ? false : true;

        // request
        return $this->request('usermacro.createGlobal', $params, $arrayKeyProperty, $auth);
    }
    
    /**
     * @brief   Reqeusts the Zabbix API and returns the response of the API
     *          method usermacro.updateGlobal.
     *
     * The $params Array can be used, to pass parameters to the Zabbix API.
     * For more informations about these parameters, check the Zabbix API
     * documentation at https://www.zabbix.com/documentation/.
     *
     * The $arrayKeyProperty can be used to get an associatve instead of an
     * indexed array as response. A valid value for the $arrayKeyProperty is
     * is any property of the returned JSON objects (e.g. name, host,
     * hostid, graphid, screenitemid).
     *
     * @param   $params             Zabbix API parameters.
     * @param   $arrayKeyProperty   Object property for key of array.
     *
     * @retval  stdClass
     *
     * @throws  Exception
     */

    public function usermacroUpdateGlobal($params=array(), $arrayKeyProperty='')
    {
        // get params array for request
        $params = $this->getRequestParamsArray($params);

        // check if we've to authenticate
        $auth = in_array('usermacro.updateGlobal', self::$anonymousFunctions) ? false : true;

        // request
        return $this->request('usermacro.updateGlobal', $params, $arrayKeyProperty, $auth);
    }
    
    /**
     * @brief   Reqeusts the Zabbix API and returns the response of the API
     *          method usermacro.deleteGlobal.
     *
     * The $params Array can be used, to pass parameters to the Zabbix API.
     * For more informations about these parameters, check the Zabbix API
     * documentation at https://www.zabbix.com/documentation/.
     *
     * The $arrayKeyProperty can be used to get an associatve instead of an
     * indexed array as response. A valid value for the $arrayKeyProperty is
     * is any property of the returned JSON objects (e.g. name, host,
     * hostid, graphid, screenitemid).
     *
     * @param   $params             Zabbix API parameters.
     * @param   $arrayKeyProperty   Object property for key of array.
     *
     * @retval  stdClass
     *
     * @throws  Exception
     */

    public function usermacroDeleteGlobal($params=array(), $arrayKeyProperty='')
    {
        // get params array for request
        $params = $this->getRequestParamsArray($params);

        // check if we've to authenticate
        $auth = in_array('usermacro.deleteGlobal', self::$anonymousFunctions) ? false : true;

        // request
        return $this->request('usermacro.deleteGlobal', $params, $arrayKeyProperty, $auth);
    }
    
    /**
     * @brief   Reqeusts the Zabbix API and returns the response of the API
     *          method usermacro.create.
     *
     * The $params Array can be used, to pass parameters to the Zabbix API.
     * For more informations about these parameters, check the Zabbix API
     * documentation at https://www.zabbix.com/documentation/.
     *
     * The $arrayKeyProperty can be used to get an associatve instead of an
     * indexed array as response. A valid value for the $arrayKeyProperty is
     * is any property of the returned JSON objects (e.g. name, host,
     * hostid, graphid, screenitemid).
     *
     * @param   $params             Zabbix API parameters.
     * @param   $arrayKeyProperty   Object property for key of array.
     *
     * @retval  stdClass
     *
     * @throws  Exception
     */

    public function usermacroCreate($params=array(), $arrayKeyProperty='')
    {
        // get params array for request
        $params = $this->getRequestParamsArray($params);

        // check if we've to authenticate
        $auth = in_array('usermacro.create', self::$anonymousFunctions) ? false : true;

        // request
        return $this->request('usermacro.create', $params, $arrayKeyProperty, $auth);
    }
    
    /**
     * @brief   Reqeusts the Zabbix API and returns the response of the API
     *          method usermacro.update.
     *
     * The $params Array can be used, to pass parameters to the Zabbix API.
     * For more informations about these parameters, check the Zabbix API
     * documentation at https://www.zabbix.com/documentation/.
     *
     * The $arrayKeyProperty can be used to get an associatve instead of an
     * indexed array as response. A valid value for the $arrayKeyProperty is
     * is any property of the returned JSON objects (e.g. name, host,
     * hostid, graphid, screenitemid).
     *
     * @param   $params             Zabbix API parameters.
     * @param   $arrayKeyProperty   Object property for key of array.
     *
     * @retval  stdClass
     *
     * @throws  Exception
     */

    public function usermacroUpdate($params=array(), $arrayKeyProperty='')
    {
        // get params array for request
        $params = $this->getRequestParamsArray($params);

        // check if we've to authenticate
        $auth = in_array('usermacro.update', self::$anonymousFunctions) ? false : true;

        // request
        return $this->request('usermacro.update', $params, $arrayKeyProperty, $auth);
    }
    
    /**
     * @brief   Reqeusts the Zabbix API and returns the response of the API
     *          method usermacro.delete.
     *
     * The $params Array can be used, to pass parameters to the Zabbix API.
     * For more informations about these parameters, check the Zabbix API
     * documentation at https://www.zabbix.com/documentation/.
     *
     * The $arrayKeyProperty can be used to get an associatve instead of an
     * indexed array as response. A valid value for the $arrayKeyProperty is
     * is any property of the returned JSON objects (e.g. name, host,
     * hostid, graphid, screenitemid).
     *
     * @param   $params             Zabbix API parameters.
     * @param   $arrayKeyProperty   Object property for key of array.
     *
     * @retval  stdClass
     *
     * @throws  Exception
     */

    public function usermacroDelete($params=array(), $arrayKeyProperty='')
    {
        // get params array for request
        $params = $this->getRequestParamsArray($params);

        // check if we've to authenticate
        $auth = in_array('usermacro.delete', self::$anonymousFunctions) ? false : true;

        // request
        return $this->request('usermacro.delete', $params, $arrayKeyProperty, $auth);
    }
    
    /**
     * @brief   Reqeusts the Zabbix API and returns the response of the API
     *          method usermacro.replaceMacros.
     *
     * The $params Array can be used, to pass parameters to the Zabbix API.
     * For more informations about these parameters, check the Zabbix API
     * documentation at https://www.zabbix.com/documentation/.
     *
     * The $arrayKeyProperty can be used to get an associatve instead of an
     * indexed array as response. A valid value for the $arrayKeyProperty is
     * is any property of the returned JSON objects (e.g. name, host,
     * hostid, graphid, screenitemid).
     *
     * @param   $params             Zabbix API parameters.
     * @param   $arrayKeyProperty   Object property for key of array.
     *
     * @retval  stdClass
     *
     * @throws  Exception
     */

    public function usermacroReplaceMacros($params=array(), $arrayKeyProperty='')
    {
        // get params array for request
        $params = $this->getRequestParamsArray($params);

        // check if we've to authenticate
        $auth = in_array('usermacro.replaceMacros', self::$anonymousFunctions) ? false : true;

        // request
        return $this->request('usermacro.replaceMacros', $params, $arrayKeyProperty, $auth);
    }
    
    /**
     * @brief   Reqeusts the Zabbix API and returns the response of the API
     *          method usermacro.tableName.
     *
     * The $params Array can be used, to pass parameters to the Zabbix API.
     * For more informations about these parameters, check the Zabbix API
     * documentation at https://www.zabbix.com/documentation/.
     *
     * The $arrayKeyProperty can be used to get an associatve instead of an
     * indexed array as response. A valid value for the $arrayKeyProperty is
     * is any property of the returned JSON objects (e.g. name, host,
     * hostid, graphid, screenitemid).
     *
     * @param   $params             Zabbix API parameters.
     * @param   $arrayKeyProperty   Object property for key of array.
     *
     * @retval  stdClass
     *
     * @throws  Exception
     */

    public function usermacroTableName($params=array(), $arrayKeyProperty='')
    {
        // get params array for request
        $params = $this->getRequestParamsArray($params);

        // check if we've to authenticate
        $auth = in_array('usermacro.tableName', self::$anonymousFunctions) ? false : true;

        // request
        return $this->request('usermacro.tableName', $params, $arrayKeyProperty, $auth);
    }
    
    /**
     * @brief   Reqeusts the Zabbix API and returns the response of the API
     *          method usermacro.pk.
     *
     * The $params Array can be used, to pass parameters to the Zabbix API.
     * For more informations about these parameters, check the Zabbix API
     * documentation at https://www.zabbix.com/documentation/.
     *
     * The $arrayKeyProperty can be used to get an associatve instead of an
     * indexed array as response. A valid value for the $arrayKeyProperty is
     * is any property of the returned JSON objects (e.g. name, host,
     * hostid, graphid, screenitemid).
     *
     * @param   $params             Zabbix API parameters.
     * @param   $arrayKeyProperty   Object property for key of array.
     *
     * @retval  stdClass
     *
     * @throws  Exception
     */

    public function usermacroPk($params=array(), $arrayKeyProperty='')
    {
        // get params array for request
        $params = $this->getRequestParamsArray($params);

        // check if we've to authenticate
        $auth = in_array('usermacro.pk', self::$anonymousFunctions) ? false : true;

        // request
        return $this->request('usermacro.pk', $params, $arrayKeyProperty, $auth);
    }
    
    /**
     * @brief   Reqeusts the Zabbix API and returns the response of the API
     *          method usermacro.pkOption.
     *
     * The $params Array can be used, to pass parameters to the Zabbix API.
     * For more informations about these parameters, check the Zabbix API
     * documentation at https://www.zabbix.com/documentation/.
     *
     * The $arrayKeyProperty can be used to get an associatve instead of an
     * indexed array as response. A valid value for the $arrayKeyProperty is
     * is any property of the returned JSON objects (e.g. name, host,
     * hostid, graphid, screenitemid).
     *
     * @param   $params             Zabbix API parameters.
     * @param   $arrayKeyProperty   Object property for key of array.
     *
     * @retval  stdClass
     *
     * @throws  Exception
     */

    public function usermacroPkOption($params=array(), $arrayKeyProperty='')
    {
        // get params array for request
        $params = $this->getRequestParamsArray($params);

        // check if we've to authenticate
        $auth = in_array('usermacro.pkOption', self::$anonymousFunctions) ? false : true;

        // request
        return $this->request('usermacro.pkOption', $params, $arrayKeyProperty, $auth);
    }
    
    /**
     * @brief   Reqeusts the Zabbix API and returns the response of the API
     *          method usermedia.get.
     *
     * The $params Array can be used, to pass parameters to the Zabbix API.
     * For more informations about these parameters, check the Zabbix API
     * documentation at https://www.zabbix.com/documentation/.
     *
     * The $arrayKeyProperty can be used to get an associatve instead of an
     * indexed array as response. A valid value for the $arrayKeyProperty is
     * is any property of the returned JSON objects (e.g. name, host,
     * hostid, graphid, screenitemid).
     *
     * @param   $params             Zabbix API parameters.
     * @param   $arrayKeyProperty   Object property for key of array.
     *
     * @retval  stdClass
     *
     * @throws  Exception
     */

    public function usermediaGet($params=array(), $arrayKeyProperty='')
    {
        // get params array for request
        $params = $this->getRequestParamsArray($params);

        // check if we've to authenticate
        $auth = in_array('usermedia.get', self::$anonymousFunctions) ? false : true;

        // request
        return $this->request('usermedia.get', $params, $arrayKeyProperty, $auth);
    }
    
    /**
     * @brief   Reqeusts the Zabbix API and returns the response of the API
     *          method usermedia.tableName.
     *
     * The $params Array can be used, to pass parameters to the Zabbix API.
     * For more informations about these parameters, check the Zabbix API
     * documentation at https://www.zabbix.com/documentation/.
     *
     * The $arrayKeyProperty can be used to get an associatve instead of an
     * indexed array as response. A valid value for the $arrayKeyProperty is
     * is any property of the returned JSON objects (e.g. name, host,
     * hostid, graphid, screenitemid).
     *
     * @param   $params             Zabbix API parameters.
     * @param   $arrayKeyProperty   Object property for key of array.
     *
     * @retval  stdClass
     *
     * @throws  Exception
     */

    public function usermediaTableName($params=array(), $arrayKeyProperty='')
    {
        // get params array for request
        $params = $this->getRequestParamsArray($params);

        // check if we've to authenticate
        $auth = in_array('usermedia.tableName', self::$anonymousFunctions) ? false : true;

        // request
        return $this->request('usermedia.tableName', $params, $arrayKeyProperty, $auth);
    }
    
    /**
     * @brief   Reqeusts the Zabbix API and returns the response of the API
     *          method usermedia.pk.
     *
     * The $params Array can be used, to pass parameters to the Zabbix API.
     * For more informations about these parameters, check the Zabbix API
     * documentation at https://www.zabbix.com/documentation/.
     *
     * The $arrayKeyProperty can be used to get an associatve instead of an
     * indexed array as response. A valid value for the $arrayKeyProperty is
     * is any property of the returned JSON objects (e.g. name, host,
     * hostid, graphid, screenitemid).
     *
     * @param   $params             Zabbix API parameters.
     * @param   $arrayKeyProperty   Object property for key of array.
     *
     * @retval  stdClass
     *
     * @throws  Exception
     */

    public function usermediaPk($params=array(), $arrayKeyProperty='')
    {
        // get params array for request
        $params = $this->getRequestParamsArray($params);

        // check if we've to authenticate
        $auth = in_array('usermedia.pk', self::$anonymousFunctions) ? false : true;

        // request
        return $this->request('usermedia.pk', $params, $arrayKeyProperty, $auth);
    }
    
    /**
     * @brief   Reqeusts the Zabbix API and returns the response of the API
     *          method usermedia.pkOption.
     *
     * The $params Array can be used, to pass parameters to the Zabbix API.
     * For more informations about these parameters, check the Zabbix API
     * documentation at https://www.zabbix.com/documentation/.
     *
     * The $arrayKeyProperty can be used to get an associatve instead of an
     * indexed array as response. A valid value for the $arrayKeyProperty is
     * is any property of the returned JSON objects (e.g. name, host,
     * hostid, graphid, screenitemid).
     *
     * @param   $params             Zabbix API parameters.
     * @param   $arrayKeyProperty   Object property for key of array.
     *
     * @retval  stdClass
     *
     * @throws  Exception
     */

    public function usermediaPkOption($params=array(), $arrayKeyProperty='')
    {
        // get params array for request
        $params = $this->getRequestParamsArray($params);

        // check if we've to authenticate
        $auth = in_array('usermedia.pkOption', self::$anonymousFunctions) ? false : true;

        // request
        return $this->request('usermedia.pkOption', $params, $arrayKeyProperty, $auth);
    }
    
    /**
     * @brief   Reqeusts the Zabbix API and returns the response of the API
     *          method valuemap.get.
     *
     * The $params Array can be used, to pass parameters to the Zabbix API.
     * For more informations about these parameters, check the Zabbix API
     * documentation at https://www.zabbix.com/documentation/.
     *
     * The $arrayKeyProperty can be used to get an associatve instead of an
     * indexed array as response. A valid value for the $arrayKeyProperty is
     * is any property of the returned JSON objects (e.g. name, host,
     * hostid, graphid, screenitemid).
     *
     * @param   $params             Zabbix API parameters.
     * @param   $arrayKeyProperty   Object property for key of array.
     *
     * @retval  stdClass
     *
     * @throws  Exception
     */

    public function valuemapGet($params=array(), $arrayKeyProperty='')
    {
        // get params array for request
        $params = $this->getRequestParamsArray($params);

        // check if we've to authenticate
        $auth = in_array('valuemap.get', self::$anonymousFunctions) ? false : true;

        // request
        return $this->request('valuemap.get', $params, $arrayKeyProperty, $auth);
    }
    
    /**
     * @brief   Reqeusts the Zabbix API and returns the response of the API
     *          method valuemap.create.
     *
     * The $params Array can be used, to pass parameters to the Zabbix API.
     * For more informations about these parameters, check the Zabbix API
     * documentation at https://www.zabbix.com/documentation/.
     *
     * The $arrayKeyProperty can be used to get an associatve instead of an
     * indexed array as response. A valid value for the $arrayKeyProperty is
     * is any property of the returned JSON objects (e.g. name, host,
     * hostid, graphid, screenitemid).
     *
     * @param   $params             Zabbix API parameters.
     * @param   $arrayKeyProperty   Object property for key of array.
     *
     * @retval  stdClass
     *
     * @throws  Exception
     */

    public function valuemapCreate($params=array(), $arrayKeyProperty='')
    {
        // get params array for request
        $params = $this->getRequestParamsArray($params);

        // check if we've to authenticate
        $auth = in_array('valuemap.create', self::$anonymousFunctions) ? false : true;

        // request
        return $this->request('valuemap.create', $params, $arrayKeyProperty, $auth);
    }
    
    /**
     * @brief   Reqeusts the Zabbix API and returns the response of the API
     *          method valuemap.update.
     *
     * The $params Array can be used, to pass parameters to the Zabbix API.
     * For more informations about these parameters, check the Zabbix API
     * documentation at https://www.zabbix.com/documentation/.
     *
     * The $arrayKeyProperty can be used to get an associatve instead of an
     * indexed array as response. A valid value for the $arrayKeyProperty is
     * is any property of the returned JSON objects (e.g. name, host,
     * hostid, graphid, screenitemid).
     *
     * @param   $params             Zabbix API parameters.
     * @param   $arrayKeyProperty   Object property for key of array.
     *
     * @retval  stdClass
     *
     * @throws  Exception
     */

    public function valuemapUpdate($params=array(), $arrayKeyProperty='')
    {
        // get params array for request
        $params = $this->getRequestParamsArray($params);

        // check if we've to authenticate
        $auth = in_array('valuemap.update', self::$anonymousFunctions) ? false : true;

        // request
        return $this->request('valuemap.update', $params, $arrayKeyProperty, $auth);
    }
    
    /**
     * @brief   Reqeusts the Zabbix API and returns the response of the API
     *          method valuemap.delete.
     *
     * The $params Array can be used, to pass parameters to the Zabbix API.
     * For more informations about these parameters, check the Zabbix API
     * documentation at https://www.zabbix.com/documentation/.
     *
     * The $arrayKeyProperty can be used to get an associatve instead of an
     * indexed array as response. A valid value for the $arrayKeyProperty is
     * is any property of the returned JSON objects (e.g. name, host,
     * hostid, graphid, screenitemid).
     *
     * @param   $params             Zabbix API parameters.
     * @param   $arrayKeyProperty   Object property for key of array.
     *
     * @retval  stdClass
     *
     * @throws  Exception
     */

    public function valuemapDelete($params=array(), $arrayKeyProperty='')
    {
        // get params array for request
        $params = $this->getRequestParamsArray($params);

        // check if we've to authenticate
        $auth = in_array('valuemap.delete', self::$anonymousFunctions) ? false : true;

        // request
        return $this->request('valuemap.delete', $params, $arrayKeyProperty, $auth);
    }
    
    /**
     * @brief   Reqeusts the Zabbix API and returns the response of the API
     *          method valuemap.tableName.
     *
     * The $params Array can be used, to pass parameters to the Zabbix API.
     * For more informations about these parameters, check the Zabbix API
     * documentation at https://www.zabbix.com/documentation/.
     *
     * The $arrayKeyProperty can be used to get an associatve instead of an
     * indexed array as response. A valid value for the $arrayKeyProperty is
     * is any property of the returned JSON objects (e.g. name, host,
     * hostid, graphid, screenitemid).
     *
     * @param   $params             Zabbix API parameters.
     * @param   $arrayKeyProperty   Object property for key of array.
     *
     * @retval  stdClass
     *
     * @throws  Exception
     */

    public function valuemapTableName($params=array(), $arrayKeyProperty='')
    {
        // get params array for request
        $params = $this->getRequestParamsArray($params);

        // check if we've to authenticate
        $auth = in_array('valuemap.tableName', self::$anonymousFunctions) ? false : true;

        // request
        return $this->request('valuemap.tableName', $params, $arrayKeyProperty, $auth);
    }
    
    /**
     * @brief   Reqeusts the Zabbix API and returns the response of the API
     *          method valuemap.pk.
     *
     * The $params Array can be used, to pass parameters to the Zabbix API.
     * For more informations about these parameters, check the Zabbix API
     * documentation at https://www.zabbix.com/documentation/.
     *
     * The $arrayKeyProperty can be used to get an associatve instead of an
     * indexed array as response. A valid value for the $arrayKeyProperty is
     * is any property of the returned JSON objects (e.g. name, host,
     * hostid, graphid, screenitemid).
     *
     * @param   $params             Zabbix API parameters.
     * @param   $arrayKeyProperty   Object property for key of array.
     *
     * @retval  stdClass
     *
     * @throws  Exception
     */

    public function valuemapPk($params=array(), $arrayKeyProperty='')
    {
        // get params array for request
        $params = $this->getRequestParamsArray($params);

        // check if we've to authenticate
        $auth = in_array('valuemap.pk', self::$anonymousFunctions) ? false : true;

        // request
        return $this->request('valuemap.pk', $params, $arrayKeyProperty, $auth);
    }
    
    /**
     * @brief   Reqeusts the Zabbix API and returns the response of the API
     *          method valuemap.pkOption.
     *
     * The $params Array can be used, to pass parameters to the Zabbix API.
     * For more informations about these parameters, check the Zabbix API
     * documentation at https://www.zabbix.com/documentation/.
     *
     * The $arrayKeyProperty can be used to get an associatve instead of an
     * indexed array as response. A valid value for the $arrayKeyProperty is
     * is any property of the returned JSON objects (e.g. name, host,
     * hostid, graphid, screenitemid).
     *
     * @param   $params             Zabbix API parameters.
     * @param   $arrayKeyProperty   Object property for key of array.
     *
     * @retval  stdClass
     *
     * @throws  Exception
     */

    public function valuemapPkOption($params=array(), $arrayKeyProperty='')
    {
        // get params array for request
        $params = $this->getRequestParamsArray($params);

        // check if we've to authenticate
        $auth = in_array('valuemap.pkOption', self::$anonymousFunctions) ? false : true;

        // request
        return $this->request('valuemap.pkOption', $params, $arrayKeyProperty, $auth);
    }
    
    /**
     * @brief   Reqeusts the Zabbix API and returns the response of the API
     *          method httptest.get.
     *
     * The $params Array can be used, to pass parameters to the Zabbix API.
     * For more informations about these parameters, check the Zabbix API
     * documentation at https://www.zabbix.com/documentation/.
     *
     * The $arrayKeyProperty can be used to get an associatve instead of an
     * indexed array as response. A valid value for the $arrayKeyProperty is
     * is any property of the returned JSON objects (e.g. name, host,
     * hostid, graphid, screenitemid).
     *
     * @param   $params             Zabbix API parameters.
     * @param   $arrayKeyProperty   Object property for key of array.
     *
     * @retval  stdClass
     *
     * @throws  Exception
     */

    public function httptestGet($params=array(), $arrayKeyProperty='')
    {
        // get params array for request
        $params = $this->getRequestParamsArray($params);

        // check if we've to authenticate
        $auth = in_array('httptest.get', self::$anonymousFunctions) ? false : true;

        // request
        return $this->request('httptest.get', $params, $arrayKeyProperty, $auth);
    }
    
    /**
     * @brief   Reqeusts the Zabbix API and returns the response of the API
     *          method httptest.create.
     *
     * The $params Array can be used, to pass parameters to the Zabbix API.
     * For more informations about these parameters, check the Zabbix API
     * documentation at https://www.zabbix.com/documentation/.
     *
     * The $arrayKeyProperty can be used to get an associatve instead of an
     * indexed array as response. A valid value for the $arrayKeyProperty is
     * is any property of the returned JSON objects (e.g. name, host,
     * hostid, graphid, screenitemid).
     *
     * @param   $params             Zabbix API parameters.
     * @param   $arrayKeyProperty   Object property for key of array.
     *
     * @retval  stdClass
     *
     * @throws  Exception
     */

    public function httptestCreate($params=array(), $arrayKeyProperty='')
    {
        // get params array for request
        $params = $this->getRequestParamsArray($params);

        // check if we've to authenticate
        $auth = in_array('httptest.create', self::$anonymousFunctions) ? false : true;

        // request
        return $this->request('httptest.create', $params, $arrayKeyProperty, $auth);
    }
    
    /**
     * @brief   Reqeusts the Zabbix API and returns the response of the API
     *          method httptest.update.
     *
     * The $params Array can be used, to pass parameters to the Zabbix API.
     * For more informations about these parameters, check the Zabbix API
     * documentation at https://www.zabbix.com/documentation/.
     *
     * The $arrayKeyProperty can be used to get an associatve instead of an
     * indexed array as response. A valid value for the $arrayKeyProperty is
     * is any property of the returned JSON objects (e.g. name, host,
     * hostid, graphid, screenitemid).
     *
     * @param   $params             Zabbix API parameters.
     * @param   $arrayKeyProperty   Object property for key of array.
     *
     * @retval  stdClass
     *
     * @throws  Exception
     */

    public function httptestUpdate($params=array(), $arrayKeyProperty='')
    {
        // get params array for request
        $params = $this->getRequestParamsArray($params);

        // check if we've to authenticate
        $auth = in_array('httptest.update', self::$anonymousFunctions) ? false : true;

        // request
        return $this->request('httptest.update', $params, $arrayKeyProperty, $auth);
    }
    
    /**
     * @brief   Reqeusts the Zabbix API and returns the response of the API
     *          method httptest.delete.
     *
     * The $params Array can be used, to pass parameters to the Zabbix API.
     * For more informations about these parameters, check the Zabbix API
     * documentation at https://www.zabbix.com/documentation/.
     *
     * The $arrayKeyProperty can be used to get an associatve instead of an
     * indexed array as response. A valid value for the $arrayKeyProperty is
     * is any property of the returned JSON objects (e.g. name, host,
     * hostid, graphid, screenitemid).
     *
     * @param   $params             Zabbix API parameters.
     * @param   $arrayKeyProperty   Object property for key of array.
     *
     * @retval  stdClass
     *
     * @throws  Exception
     */

    public function httptestDelete($params=array(), $arrayKeyProperty='')
    {
        // get params array for request
        $params = $this->getRequestParamsArray($params);

        // check if we've to authenticate
        $auth = in_array('httptest.delete', self::$anonymousFunctions) ? false : true;

        // request
        return $this->request('httptest.delete', $params, $arrayKeyProperty, $auth);
    }
    
    /**
     * @brief   Reqeusts the Zabbix API and returns the response of the API
     *          method httptest.isReadable.
     *
     * The $params Array can be used, to pass parameters to the Zabbix API.
     * For more informations about these parameters, check the Zabbix API
     * documentation at https://www.zabbix.com/documentation/.
     *
     * The $arrayKeyProperty can be used to get an associatve instead of an
     * indexed array as response. A valid value for the $arrayKeyProperty is
     * is any property of the returned JSON objects (e.g. name, host,
     * hostid, graphid, screenitemid).
     *
     * @param   $params             Zabbix API parameters.
     * @param   $arrayKeyProperty   Object property for key of array.
     *
     * @retval  stdClass
     *
     * @throws  Exception
     */

    public function httptestIsReadable($params=array(), $arrayKeyProperty='')
    {
        // get params array for request
        $params = $this->getRequestParamsArray($params);

        // check if we've to authenticate
        $auth = in_array('httptest.isReadable', self::$anonymousFunctions) ? false : true;

        // request
        return $this->request('httptest.isReadable', $params, $arrayKeyProperty, $auth);
    }
    
    /**
     * @brief   Reqeusts the Zabbix API and returns the response of the API
     *          method httptest.isWritable.
     *
     * The $params Array can be used, to pass parameters to the Zabbix API.
     * For more informations about these parameters, check the Zabbix API
     * documentation at https://www.zabbix.com/documentation/.
     *
     * The $arrayKeyProperty can be used to get an associatve instead of an
     * indexed array as response. A valid value for the $arrayKeyProperty is
     * is any property of the returned JSON objects (e.g. name, host,
     * hostid, graphid, screenitemid).
     *
     * @param   $params             Zabbix API parameters.
     * @param   $arrayKeyProperty   Object property for key of array.
     *
     * @retval  stdClass
     *
     * @throws  Exception
     */

    public function httptestIsWritable($params=array(), $arrayKeyProperty='')
    {
        // get params array for request
        $params = $this->getRequestParamsArray($params);

        // check if we've to authenticate
        $auth = in_array('httptest.isWritable', self::$anonymousFunctions) ? false : true;

        // request
        return $this->request('httptest.isWritable', $params, $arrayKeyProperty, $auth);
    }
    
    /**
     * @brief   Reqeusts the Zabbix API and returns the response of the API
     *          method httptest.tableName.
     *
     * The $params Array can be used, to pass parameters to the Zabbix API.
     * For more informations about these parameters, check the Zabbix API
     * documentation at https://www.zabbix.com/documentation/.
     *
     * The $arrayKeyProperty can be used to get an associatve instead of an
     * indexed array as response. A valid value for the $arrayKeyProperty is
     * is any property of the returned JSON objects (e.g. name, host,
     * hostid, graphid, screenitemid).
     *
     * @param   $params             Zabbix API parameters.
     * @param   $arrayKeyProperty   Object property for key of array.
     *
     * @retval  stdClass
     *
     * @throws  Exception
     */

    public function httptestTableName($params=array(), $arrayKeyProperty='')
    {
        // get params array for request
        $params = $this->getRequestParamsArray($params);

        // check if we've to authenticate
        $auth = in_array('httptest.tableName', self::$anonymousFunctions) ? false : true;

        // request
        return $this->request('httptest.tableName', $params, $arrayKeyProperty, $auth);
    }
    
    /**
     * @brief   Reqeusts the Zabbix API and returns the response of the API
     *          method httptest.pk.
     *
     * The $params Array can be used, to pass parameters to the Zabbix API.
     * For more informations about these parameters, check the Zabbix API
     * documentation at https://www.zabbix.com/documentation/.
     *
     * The $arrayKeyProperty can be used to get an associatve instead of an
     * indexed array as response. A valid value for the $arrayKeyProperty is
     * is any property of the returned JSON objects (e.g. name, host,
     * hostid, graphid, screenitemid).
     *
     * @param   $params             Zabbix API parameters.
     * @param   $arrayKeyProperty   Object property for key of array.
     *
     * @retval  stdClass
     *
     * @throws  Exception
     */

    public function httptestPk($params=array(), $arrayKeyProperty='')
    {
        // get params array for request
        $params = $this->getRequestParamsArray($params);

        // check if we've to authenticate
        $auth = in_array('httptest.pk', self::$anonymousFunctions) ? false : true;

        // request
        return $this->request('httptest.pk', $params, $arrayKeyProperty, $auth);
    }
    
    /**
     * @brief   Reqeusts the Zabbix API and returns the response of the API
     *          method httptest.pkOption.
     *
     * The $params Array can be used, to pass parameters to the Zabbix API.
     * For more informations about these parameters, check the Zabbix API
     * documentation at https://www.zabbix.com/documentation/.
     *
     * The $arrayKeyProperty can be used to get an associatve instead of an
     * indexed array as response. A valid value for the $arrayKeyProperty is
     * is any property of the returned JSON objects (e.g. name, host,
     * hostid, graphid, screenitemid).
     *
     * @param   $params             Zabbix API parameters.
     * @param   $arrayKeyProperty   Object property for key of array.
     *
     * @retval  stdClass
     *
     * @throws  Exception
     */

    public function httptestPkOption($params=array(), $arrayKeyProperty='')
    {
        // get params array for request
        $params = $this->getRequestParamsArray($params);

        // check if we've to authenticate
        $auth = in_array('httptest.pkOption', self::$anonymousFunctions) ? false : true;

        // request
        return $this->request('httptest.pkOption', $params, $arrayKeyProperty, $auth);
    }
}
