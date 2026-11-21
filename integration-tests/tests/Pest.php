<?php

use heano\basin\Plugin;
use heano\basin\tests\craft\FakeBasin;
use markhuot\craftpest\test\RefreshesDatabase;
use markhuot\craftpest\test\TestCase;

/*
 * Integration tests run inside a real Craft install (craft-pest boots and
 * installs it, then InstallsBasin installs the plugin). Each test runs in a database
 * transaction that's rolled back afterwards, and talks to a fresh FakeBasin
 * instead of Basin.
 */

uses(TestCase::class, RefreshesDatabase::class)
    ->beforeEach(function() {
        // Plugin caches (projects, forms, counts) live outside the database.
        Craft::$app->getCache()->flush();

        // Fresh services, as on a new request: some keep state in memory
        // (e.g. the forms list). The API client talks to a fresh FakeBasin.
        $this->basin = new FakeBasin();
        $plugin = Plugin::getInstance();
        foreach (Plugin::config()['components'] as $id => $class) {
            $plugin->set($id, $id === 'api' ? ['class' => $class, 'httpClient' => $this->basin] : $class);
        }
    })
    ->afterEach(function() {
        expect($this->basin->unhandled)->toBe([], 'The plugin made requests FakeBasin doesn’t handle');
    })
    ->in(__DIR__);

/**
 * Renders a Twig string as a site template would, but with strict variables,
 * so a missing variable or method fails the test instead of rendering nothing.
 */
function twig(string $source, array $variables = []): string
{
    $view = Craft::$app->getView();
    $view->setTemplateMode('site');
    $twig = $view->getTwig();
    $twig->enableStrictVariables();
    try {
        return $view->renderString($source, $variables);
    } finally {
        $twig->disableStrictVariables();
    }
}
