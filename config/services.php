<?php

return [

    'mailgun' => [
        'domain' => env('MAILGUN_DOMAIN'),
        'secret' => env('MAILGUN_SECRET'),
        'endpoint' => env('MAILGUN_ENDPOINT', 'api.mailgun.net'),
        'scheme' => 'https',
    ],

    'mapon' => [
        'key' => env('MAPON_API_KEY'),
        'base_url' => env('MAPON_BASE_URL', 'https://mapon.com/api/v1/'),
        'timeout' => (int) env('MAPON_TIMEOUT', 60),
        'auth_mode' => env('MAPON_AUTH_MODE', 'header'), // header | query
        'auth_header' => env('MAPON_AUTH_HEADER', 'key'),
        'retries' => (int) env('MAPON_RETRIES', 3),
    ],

];
