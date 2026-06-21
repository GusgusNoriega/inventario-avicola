<?php

$origins = array_values(array_filter(array_map(
    'trim',
    explode(',', (string) env(
        'FRONTEND_URLS',
        'http://localhost,http://127.0.0.1,http://sistema-pollos.test'
    ))
)));

return [
    'paths' => ['api/*'],
    'allowed_methods' => ['*'],
    'allowed_origins' => $origins,
    'allowed_origins_patterns' => [],
    'allowed_headers' => ['Accept', 'Authorization', 'Content-Type', 'X-Requested-With'],
    'exposed_headers' => [],
    'max_age' => 3600,
    'supports_credentials' => false,
];
