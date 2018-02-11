<?php

return [

	/*
    |--------------------------------------------------------------------------
    | Zabbix Host 
    |--------------------------------------------------------------------------
    |
    | This specifies the Zabbix Server host that Laravel will connect.
    |
 */
    'host' => env('ZABBIX_HOST', 'localhost') . '/' . env('ZABBIX_API_FILE', 'api_jsonrpc.php'),
    
    /*
    |--------------------------------------------------------------------------
    | Zabbix Username 
    |--------------------------------------------------------------------------
    |
    | This specifies the Zabbix Server username that Laravel will use to
    | authenticate.
    |
     */
    'username' => env('ZABBIX_USERNAME', 'admin'),

    /*
    |--------------------------------------------------------------------------
    | Zabbix Password 
    |--------------------------------------------------------------------------
    |
    | This specifies the password of the username.
    |
     */
    'password' => env('ZABBIX_PASSWORD', 'zabbix'),

    /*
    |--------------------------------------------------------------------------
    | Zabbix HTTP Username 
    |--------------------------------------------------------------------------
    |
    | If specified, it will be the HTTP Basic authorization username.
    |
     */
    'http_username' => env('ZABBIX_HTTP_USERNAME'),

    /*
    |--------------------------------------------------------------------------
    | Zabbix HTTP Password 
    |--------------------------------------------------------------------------
    |
    | If username was specified, this will be the HTTP Basic authorization 
    | password.
    |
     */
    'http_password' => env('ZABBIX_HTTP_PASSWORD'),

    /*
    |--------------------------------------------------------------------------
    | Zabbix Auth Token
    |--------------------------------------------------------------------------
    |
    |  If you want to use an already issued auth token instead of username
    |  and password.
    |
     */
    'authToken' => env('ZABBIX_AUTH_TOKEN', ''),

    /*
    |--------------------------------------------------------------------------
    | Zabbix Auth Token
    |--------------------------------------------------------------------------
    |
    |  SSL context for SSL - enabled connections.
    |
     */
    'sslContext' => env('ZABBIX_SSL_CONTEXT', ''),

    /*
    |--------------------------------------------------------------------------
    | Check SSL Certificate
    |--------------------------------------------------------------------------
    |
    | Checks the API host SSL certificate peer name.
    |
     */
    'checkSsl' => env('ZABBIX_CHECK_SSL', true),

];