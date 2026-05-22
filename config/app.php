<?php
return [
    'app_name' => 'AI Writing Coach',
    'debug' => true,
    'database' => [
        'driver' => 'sqlite',
        'database' => __DIR__ . '/../storage/app.sqlite',
    ],
    'security' => [
        'session_cookie' => 'ai_writing_session',
    ],
    'open_source' => [
        // Set empty string '' to disable password gate.
        'access_password' => 'change-this-password',
        // AI provider config for open-source edition.
        'ai_settings' => [
            'base_url' => 'https://api.openai.com/v1',
            'api_key' => '',
            'model' => 'gpt-4.1-mini',
        ],
    ],
];
