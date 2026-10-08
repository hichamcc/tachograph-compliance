<?php

namespace App\Services\Mapon;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;

class MaponServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(MaponClient::class, function (Application $app) {
            $config = $app['config']->get('services.mapon');

            return new MaponClient(
                apiKey: (string) ($config['key'] ?? ''),
                baseUrl: (string) ($config['base_url'] ?? 'https://mapon.com/api/v1/'),
                timeout: (int) ($config['timeout'] ?? 60),
                authMode: (string) ($config['auth_mode'] ?? 'header'),
                authHeader: (string) ($config['auth_header'] ?? 'key'),
                retries: (int) ($config['retries'] ?? 3),
            );
        });
    }
}
