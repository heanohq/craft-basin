<?php

return [
    'devMode' => true,
    'securityKey' => 'integration-tests-only',
    'omitScriptNameInUrls' => true,
    'runQueueAutomatically' => false,
    // Root-relative, so published resources (e.g. the honeypot stylesheet)
    // come from whichever server the page does: the browser tests serve
    // Craft from their own port, not @web's.
    'resourceBaseUrl' => '/cpresources',
    'aliases' => [
        '@templates' => dirname(__DIR__) . '/templates',
        '@webroot' => dirname(__DIR__) . '/web',
        '@web' => 'http://localhost:8080',
    ],
];
