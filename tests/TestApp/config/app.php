<?php
declare(strict_types=1);

/**
 * Test application config for Crustum/AiTui.
 */
return [
    'debug' => true,
    'App' => [
        'namespace' => 'TestApp',
        'encoding' => 'UTF-8',
        'defaultLocale' => 'en_US',
        'defaultTimezone' => 'UTC',
        'base' => false,
        'dir' => 'src',
        'webroot' => 'webroot',
        'wwwRoot' => WWW_ROOT,
        'fullBaseUrl' => 'http://localhost',
        'paths' => [
            'plugins' => [ROOT . DS],
            'templates' => [APP . 'templates' . DS],
            'locales' => [RESOURCES . 'locales' . DS],
        ],
    ],
    'Security' => [
        'salt' => 'ai-tui-test-security-salt-change-me',
    ],
    'Session' => [
        'defaults' => 'php',
    ],
];
