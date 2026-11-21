<?php

return [
    'components' => [
        // In memory, so tests can flush it between runs without touching files.
        'cache' => [
            'class' => \yii\caching\ArrayCache::class,
            'defaultDuration' => 0,
        ],
    ],
];
