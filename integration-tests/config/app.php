<?php

return [
    'components' => [
        // In memory, so tests can flush it between runs without touching files.
        'cache' => [
            'class' => \yii\caching\ArrayCache::class,
            'defaultDuration' => 0,
        ],
        // Run queue jobs as soon as they're pushed.
        'queue' => [
            'class' => \yii\queue\sync\Queue::class,
            'handle' => true,
        ],
    ],
];
