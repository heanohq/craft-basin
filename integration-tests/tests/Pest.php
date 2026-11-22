<?php

use heano\basin\fields\BasinFormField;
use heano\basin\Plugin;
use heano\basin\tests\craft\FakeBasin;
use markhuot\craftpest\factories\Field;
use markhuot\craftpest\factories\Section;
use markhuot\craftpest\test\RefreshesDatabase;
use markhuot\craftpest\test\TestCase;

/*
 * Integration tests run inside a real Craft install (craft-pest boots and
 * installs it, then InstallsBasin installs the plugin). Each test runs in a database
 * transaction that's rolled back afterwards, and every HTTP request goes to
 * a fresh FakeBasin instead of the network.
 */

uses(TestCase::class, RefreshesDatabase::class)
    ->beforeEach(function() {
        // A fresh FakeBasin, which every HTTP request goes to (config/guzzle.php).
        $this->basin = FakeBasin::$current = new FakeBasin();

        // Settings changed by a test are put back afterwards.
        $this->originalSettings = Plugin::getInstance()->getSettings()->toArray();
        newRequest();
    })
    ->afterEach(function() {
        Plugin::getInstance()->getSettings()->setAttributes($this->originalSettings, false);
        FakeBasin::$current = null;
        expect($this->basin->unhandled)->toBe([], 'Requests FakeBasin doesn’t handle were made');
    })
    ->in('Feature', 'Browser', 'Providers', 'Docs', 'Blitz');

// Uninstalling drops tables, which ends the transaction RefreshesDatabase
// relies on, so those tests run on their own (phpunit.xml, Uninstall suite).
uses(TestCase::class)->in('Uninstall');

/**
 * Starts afresh, as a new request would: empties the cache (where the plugin
 * keeps Basin's answers) and gives the plugin fresh services, some of which
 * keep state in memory (e.g. the forms list). Use it after changing
 * FakeBasin's data, so the plugin sees the change.
 */
function newRequest(): void
{
    Craft::$app->getCache()->flush();
    $plugin = Plugin::getInstance();
    foreach (Plugin::config()['components'] as $id => $class) {
        $plugin->set($id, $class);
    }
}

/** Changes plugin settings for the current test only. */
function settings(array $values): void
{
    Plugin::getInstance()->getSettings()->setAttributes($values, false);
}

/** Runs queued jobs, as Craft's queue runner would. */
function runQueue(): void
{
    Craft::$app->getQueue()->run();
}

/**
 * POSTs to a Control Panel action as the CP's JavaScript does (JSON), so
 * success and failure come back as JSON with a status code.
 */
function cpAction(string $action, array $body = []): \markhuot\craftpest\web\TestableResponse
{
    return test()->postJson("/admin/actions/$action", $body);
}

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

/** A section whose entries have a Basin Form field with the handle `contactForm`. */
function sectionWithFormField(array $fieldSettings = []): array
{
    $field = Field::factory()->type(BasinFormField::class)
        ->set(['name' => 'Contact form', 'handle' => 'contactForm'] + $fieldSettings)
        ->create();
    $section = Section::factory()->fields($field)->create();
    return [$section, $field];
}
