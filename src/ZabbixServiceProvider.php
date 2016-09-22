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
        ]);
    }

    /**
     * Register any application services.
     *
     * @return void
     */
    public function register()
    {
        $this->app['zabbix'] = $this->app->share(function($app) {
            return new ZabbixApi(config('zabbix.host'),config('zabbix.username'),config('zabbix.password'));
        });
    }
}
