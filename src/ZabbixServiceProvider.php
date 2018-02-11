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
            return new ZabbixApi(
                $apiUrl = $app['config']['zabbix.host'],
                $user = $app['config']['zabbix.username'],
                $password = $app['config']['zabbix.password'],
                $httpUser = $app['config']['zabbix.http_username'],
                $httpPassword = $app['config']['zabbix.http_password'],
                $authToken = $app['config']['zabbix.authToken'],
                $sslContext = $app['config']['zabbix.sslContext'],
                $checkSsl = $app['config']['zabbix.checkSsl']
            );
        });
    }
}
