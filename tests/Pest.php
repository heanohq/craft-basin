<?php

/*
 * Unit tests cover framework-free code (e.g. the API client) and don't boot Craft.
 *
 * Some tests build plugin models, which are Yii objects. Newer Craft versions
 * autoload the `Yii` class; Craft 5.0 doesn't, so load it here when it's missing.
 */
if (!class_exists('Yii', false) && !class_exists('Yii')) {
    require_once dirname(__DIR__) . '/vendor/yiisoft/yii2/Yii.php';
}
