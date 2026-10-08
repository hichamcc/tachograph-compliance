<?php

use Laravel\Fortify\Features;

return [

    'lowercase_usernames' => true,

    'home' => '/app',

    'limiters' => [
        'login' => 'login',
        'two-factor' => 'two-factor',
    ],

    'features' => [
        // Public sign-up disabled: users are created by an admin (see docs/DEPLOYMENT.md).
        // Features::registration(),
        Features::resetPasswords(),
        Features::emailVerification(),
        Features::twoFactorAuthentication([
            'confirm' => true,
            'confirmPassword' => true,
            // 'window' => 0
        ]),
    ],

];
