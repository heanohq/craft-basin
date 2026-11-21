<?php

namespace heano\basin\tests\craft;

use Craft;
use Pest\Contracts\Plugins\HandlesArguments;

/**
 * Installs the plugin once craft-pest has booted and installed Craft, before
 * any test starts its transaction (MySQL commits on CREATE TABLE, so
 * installing inside a test's transaction would break the rollback). Also
 * switches Craft to Pro and registers the plugin's CP routes.
 */
class InstallsBasin implements HandlesArguments
{
    public function handleArguments(array $arguments): array
    {
        if (class_exists(Craft::class, false) && Craft::$app?->getIsInstalled()) {
            // Tests roll back and rewrite data freely: refuse anything but a
            // database made for them.
            $database = Craft::$app->getDb()->createCommand('SELECT DATABASE()')->queryScalar();
            if (!str_ends_with((string)$database, '_tests')) {
                throw new \RuntimeException("Refusing to run integration tests against the database \"$database\". Use one whose name ends in _tests.");
            }

            // A database installed by an older Craft or plugin version (e.g.
            // after a lowest-dependencies run) gets updated, as a site would.
            $updates = Craft::$app->getUpdates();
            if ($handles = $updates->getPendingMigrationHandles(true)) {
                $updates->runMigrations($handles);
            }

            // Pro, for more than one user (craft-pest's actingAsAdmin() adds
            // one, and permission tests need non-admins).
            $projectConfig = Craft::$app->getProjectConfig();
            if ($projectConfig->get('system.edition') !== 'pro') {
                $projectConfig->set('system.edition', 'pro');
                $projectConfig->saveModifiedConfigData();
            }
            Craft::$app->setEdition(\craft\enums\CmsEdition::Pro);

            $plugins = Craft::$app->getPlugins();
            if (!$plugins->isPluginInstalled('basin')) {
                $plugins->installPlugin('basin');
                // Craft saves project config at the end of a request, which
                // never comes here, so save it now or the next run won't know
                // the plugin is installed.
                Craft::$app->getProjectConfig()->saveModifiedConfigData();

                // Load the plugin again, as the next request would, so it
                // starts with its settings from config/basin.php.
                $loaded = new \ReflectionProperty($plugins, '_pluginsLoaded');
                $loaded->setValue($plugins, false);
                $plugins->loadPlugins();
                // Twig's `craft` variable was built before the plugin existed.
                Craft::$app->set('view', \craft\helpers\App::viewConfig());
            }

            // Each test response keeps its own assertions (see TestableResponse).
            Craft::$container->set(\markhuot\craftpest\web\TestableResponse::class, TestableResponse::class);

            // Craft boots here as a site request, so the plugin skipped its CP
            // routes (it registers them only on CP requests). Register them
            // now; craft-pest rebuilds the URL manager for each test request,
            // which then picks them up.
            $plugin = $plugins->getPlugin('basin');
            (new \ReflectionMethod($plugin, 'registerCpRoutes'))->invoke($plugin);
        }
        return $arguments;
    }
}
