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
    'host' => env('ZABBIX_HOST', 'localhost') . '/api_jsonrpc.php',
    
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
    | If specified, it will be the HTTP Basic authorization username
    |
    */
    'http_username' => env('ZABBIX_HTTP_USERNAME'),

    /*
    |--------------------------------------------------------------------------
    | Zabbix HTTP Password 
    |--------------------------------------------------------------------------
    |
    | If username was specified, this will be the HTTP Basic authorization 
    | password
    |
    */
    'http_password' => env('ZABBIX_HTTP_PASSWORD')
];
