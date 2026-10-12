<?php

return [

    'defaults' => [
        'guard' => 'web',
        'passwords' => 'user_accounts',
    ],

    'guards' => [
        'web' => [
            'driver' => 'session',
            'provider' => 'user_accounts',
        ],
    ],

    'providers' => [
        'user_accounts' => [
            'driver' => 'eloquent',
            'model' => App\Domains\IdentityAccess\Models\UserAccount::class,
        ],
    ],

    'passwords' => [
        'user_accounts' => [
            'provider' => 'user_accounts',
            'table' => 'password_reset_tokens',
            'expire' => 60,
            'throttle' => 60,
        ],
    ],

    'password_timeout' => 10800,

];
