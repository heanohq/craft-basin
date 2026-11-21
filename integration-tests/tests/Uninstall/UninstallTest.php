<?php

use heano\basin\Plugin;
use heano\basin\services\FormIssues;
use heano\basin\services\FormSettings;
use heano\basin\tests\craft\FakeBasin;

beforeEach(fn() => FakeBasin::$current = new FakeBasin());
afterEach(fn() => FakeBasin::$current = null);

/*
 * Uninstalling and reinstalling the plugin. Runs outside a transaction, and
 * leaves the plugin installed again for the other tests.
 *
 *     vendor/bin/pest --testsuite=Uninstall
 */

/** Installs the plugin again, as InstallsBasin does. */
function reinstall(): void
{
    $plugins = Craft::$app->getPlugins();
    if (!$plugins->isPluginInstalled('basin')) {
        $plugins->installPlugin('basin');
    }
    Craft::$app->getProjectConfig()->saveModifiedConfigData();
}

function basinTables(): array
{
    $db = Craft::$app->getDb();
    $db->getSchema()->refresh();
    return array_values(array_filter(
        [FormIssues::TABLE, FormSettings::TABLE],
        fn($table) => $db->tableExists($table),
    ));
}

it('removes its tables, settings and migrations when uninstalled, and installs again', function() {
    $db = Craft::$app->getDb();
    // Something in each table, as on a real site.
    Plugin::getInstance()->getFormIssues()->record('f99999999999', FormIssues::REASON_MISSING, FormIssues::SOURCE_TWIG);
    Plugin::getInstance()->getFormSettings()->save('f00000000001', ['hostedFormId' => 'studio1']);

    try {
        expect(Craft::$app->getPlugins()->uninstallPlugin('basin'))->toBeTrue();
        Craft::$app->getProjectConfig()->saveModifiedConfigData();

        expect(basinTables())->toBe([])
            ->and(Craft::$app->getProjectConfig()->get('plugins.basin'))->toBeNull()
            ->and((int)$db->createCommand("SELECT COUNT(*) FROM {{%migrations}} WHERE track = 'plugin:basin'")->queryScalar())->toBe(0);
    } finally {
        reinstall();
    }

    expect(Craft::$app->getPlugins()->isPluginInstalled('basin'))->toBeTrue()
        ->and(basinTables())->toBe([FormIssues::TABLE, FormSettings::TABLE])
        // Nothing carried over.
        ->and((int)(new craft\db\Query())->from(FormIssues::TABLE)->count())->toBe(0)
        ->and((int)(new craft\db\Query())->from(FormSettings::TABLE)->count())->toBe(0);
});
