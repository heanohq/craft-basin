<?php

use craft\web\Application;
use heano\basin\tests\craft\FakeBasin;
use putyourlightson\blitz\Blitz;
use putyourlightson\blitz\models\SiteUriModel;
use putyourlightson\blitz\services\GenerateCacheService;
use yii\base\Event;

/*
 * Pages Blitz caches get the same spam protection as any other page,
 * including the static files a server can send without running PHP
 * (forms/spam-protection.md, "Static caching"). Blitz saves its file before
 * the plugin's usual hook runs, so the plugin protects the page in Blitz's
 * beforeSaveCache event (Plugin::registerSpamProtection()). These tests catch
 * Blitz changing that event, or anything else that leaves cached pages
 * unprotected.
 */

beforeEach(function() {
    $settings = Blitz::$plugin->settings;
    $this->blitzSettings = $settings->toArray();
    $settings->cachingEnabled = true;
    $settings->includedUriPatterns = [['siteId' => '', 'uriPattern' => 'basin-test/.*']];
    // The test pages take the form's ID as a query string.
    $settings->queryStringCaching = 1;
});

afterEach(function() {
    Blitz::$plugin->clearCache->clearAll();
    Blitz::$plugin->settings->setAttributes($this->blitzSettings, false);
});

/**
 * Requests a page as a visitor would, with Blitz caching it. Blitz decides
 * whether to cache a request in its handler for Craft's application init
 * event, which craft-pest's requests don't fire, so run Blitz's own handler
 * (not a copy of it) once the request is set up.
 */
function visitCached(string $uri): string
{
    $runBlitz = function() {
        foreach (blitzInitHandlers() as $handler) {
            $handler(new Event());
        }
    };
    Event::on(Application::class, Application::EVENT_BEFORE_REQUEST, $runBlitz);
    try {
        return test()->get($uri)->assertOk()->content;
    } finally {
        Event::off(Application::class, Application::EVENT_BEFORE_REQUEST, $runBlitz);
    }
}

/** @return list<Closure> Blitz's handlers for Craft's application init event */
function blitzInitHandlers(): array
{
    $events = (new ReflectionProperty(Event::class, '_events'))->getValue();
    $handlers = [];
    foreach ($events[Application::EVENT_INIT][Application::class] ?? [] as [$handler]) {
        if ($handler instanceof Closure && (new ReflectionFunction($handler))->getClosureThis() instanceof Blitz) {
            $handlers[] = $handler;
        }
    }
    expect($handlers)->not->toBeEmpty('Blitz no longer decides what to cache on application init');
    return $handlers;
}

/** The page Blitz saved for a URI, as a server would send it from the static file. */
function blitzSaved(string $uri): string
{
    return (string)Blitz::$plugin->cacheStorage->get(new SiteUriModel(['siteId' => 1, 'uri' => ltrim($uri, '/')]));
}

/** A page without Blitz's comments, which differ between the saved and sent copies. */
function withoutBlitzComments(string $html): string
{
    return trim(preg_replace('/<!--\s*(?:Cached|Served) by Blitz.*?-->/s', '', $html));
}

it('still has the event the plugin protects pages in', function() {
    // The plugin names these as strings, so Blitz isn't a dependency, and a
    // renamed class or event would otherwise fail silently.
    expect(class_exists(GenerateCacheService::class))->toBeTrue()
        ->and(GenerateCacheService::EVENT_BEFORE_SAVE_CACHE)->toBe('beforeSaveCache');
});

it('saves the page with its spam protection, for servers that send the file directly', function() {
    $form = $this->basin->addForm('CAPTCHA form', FakeBasin::turnstile() + FakeBasin::honeypot());
    $uri = "/basin-test/form?uuid={$form['uuid']}";

    $sent = visitCached($uri);
    $saved = blitzSaved($uri);

    expect($saved)->not->toBe('', 'Blitz didn’t cache the page')
        ->and(substr_count($saved, '<div data-basin-extra'))->toBe(1)
        ->and(substr_count($saved, 'data-basin-styles'))->toBe(1)
        ->and(substr_count($saved, 'data-basin-protect-script'))->toBe(1)
        // What Blitz sends this time is what it saved, protected once.
        ->and(withoutBlitzComments($sent))->toBe(withoutBlitzComments($saved));
});

it('saves a honeypot-only page with its honeypot and stylesheet', function() {
    $form = $this->basin->addForm('Honeypot form', FakeBasin::honeypot('_trap'));
    $uri = "/basin-test/form?uuid={$form['uuid']}";

    visitCached($uri);
    $saved = blitzSaved($uri);

    expect($saved)->toContain('name="_trap"')
        ->toMatch('/<link [^>]*data-basin-styles>/')
        ->not->toContain('data-basin-protect-script');
});
