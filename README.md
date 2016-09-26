# Laravel Zabbix API

This package provides a Zabbix API library for Laravel Framework. It uses the PhpZabbixApi library confirm/PhpZabbixApi.

### Installation

Enter in your Laravel application folder and require the package:

```php
composer require becker/laravel-zabbix-api
```

### Register the Service Provider

Open up the ``config/app.php``and register the new Service Provider:

```php
//config/app.php

/*
 * Package Service Providers...
 */

Becker\Zabbix\ZabbixServiceProvider::class,

//...
```

### Publish the files

```php
php artisan vendor:publish
```
This will create the ``config/zabbix.php`` file.



### Define your Zabbix Server configurations

At your ``.env`` file, define the new Zabbix parameters:

```php
//.env

ZABBIX_HOST=http://your.zabbix.url
ZABBIX_USERNAME=username
ZABBIX_PASSWORD=password
```

### Use it in your Controller

```php
//app/Http/Controllers/TestController.php

<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

use App\Http\Requests;

class TestController extends Controller
{
    
    /**
     * Create a new Zabbix API instance.
     *
     * @return void
     */
	public function __construct()
	{
		$this->zabbix = app('zabbix');
	}

    /**
	 * Get all the Zabbix host groups
     *
	 * @return array
	 */
    public function index()
    {
    	return $this->zabbix->hostgroupGet(['output' => 'extend']);
    }
}
```
