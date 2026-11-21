<?php

return [
    'devMode' => true,
    'securityKey' => 'integration-tests-only',
    'omitScriptNameInUrls' => true,
    'runQueueAutomatically' => false,
    'aliases' => [
        '@templates' => dirname(__DIR__) . '/templates',
        '@webroot' => dirname(__DIR__) . '/web',
        '@web' => 'http://localhost:8080',
    ],
];
