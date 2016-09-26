<?php

namespace Becker\Zabbix;

use Illuminate\Support\ServiceProvider;

class ZabbixServiceProvider extends ServiceProvider
{

    /**
     * Bootstrap any application services.
     *
     * @return void
     */
    public function boot()
    {
        $this->publishes([
            __DIR__.'/config/zabbix.php' => config_path('zabbix.php'),
        ], 'zabbix');
    }

    /**
     * Register any application services.
     *
     * @return void
     */
    public function register()
    {
	$this->app->singleton('zabbix', function ($app) {
            return new ZabbixApi($app['config']['zabbix.host'],$app['config']['zabbix.username'],$app['config']['zabbix.password']);
        });
    }
}
