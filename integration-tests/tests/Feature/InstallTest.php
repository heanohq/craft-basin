<?php

use craft\db\Table;
use heano\basin\Plugin;
use heano\basin\services\FormIssues;
use heano\basin\services\FormSettings;

it('is installed and up to date', function() {
    $plugins = Craft::$app->getPlugins();

    expect($plugins->isPluginInstalled('basin'))->toBeTrue()
        ->and($plugins->isPluginEnabled('basin'))->toBeTrue()
        ->and(Plugin::getInstance()->getMigrator()->getNewMigrations())->toBe([])
        ->and($plugins->getStoredPluginInfo('basin')['schemaVersion'])->toBe(Plugin::getInstance()->schemaVersion);
});

it('creates its tables', function() {
    $db = Craft::$app->getDb();

    expect($db->tableExists(FormIssues::TABLE))->toBeTrue()
        ->and($db->tableExists(FormSettings::TABLE))->toBeTrue();
});
