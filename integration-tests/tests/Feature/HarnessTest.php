<?php

use PHPUnit\Framework\AssertionFailedError;

/*
 * Checks the test tools themselves: a response assertion must check the
 * response it's called on, and fail when it should, even after another
 * request in the same test. Other tests rely on this.
 */

it('fails assertions that should fail', function() {
    $response = $this->actingAsAdmin()->get('/admin/basin/forms');

    expect(fn() => $response->assertSee('Text that is not on the page'))->toThrow(AssertionFailedError::class)
        ->and(fn() => $response->assertDontSee('Alpha form 1'))->toThrow(AssertionFailedError::class)
        ->and(fn() => $response->assertStatus(404))->toThrow(AssertionFailedError::class);
});

it('checks the response it’s called on after another request', function() {
    $forms = $this->actingAsAdmin()->get('/admin/basin/forms');
    $this->get('/admin/basin/errors')->assertOk();

    $forms->assertSee('https://usebasin.com/f/f00000000001');
    expect(fn() => $forms->assertDontSee('https://usebasin.com/f/f00000000001'))->toThrow(AssertionFailedError::class);
});

it('sends nothing anywhere but FakeBasin', function() {
    Craft::createGuzzleClient()->get('https://example.com/', ['http_errors' => false]);

    expect($this->basin->unhandled)->toBe(['GET https://example.com/']);
    $this->basin->unhandled = [];
});

it('renders a second Control Panel screen in the same test', function() {
    $this->actingAsAdmin()->get('/admin/myaccount')->assertOk()->assertSee('Username');
    $this->actingAsAdmin()->get('/admin/myaccount')->assertOk()->assertSee('Username');
});

it('fires the events a real response fires when it’s sent', function() {
    $fired = [];
    $listener = function($event) use (&$fired) { $fired[] = $event->name; };
    yii\base\Event::on(craft\web\Response::class, craft\web\Response::EVENT_BEFORE_SEND, $listener);
    yii\base\Event::on(craft\web\Response::class, craft\web\Response::EVENT_AFTER_PREPARE, $listener);

    try {
        $this->get('/basin-test/form?uuid=f00000000001')->assertOk();
    } finally {
        yii\base\Event::off(craft\web\Response::class, craft\web\Response::EVENT_BEFORE_SEND, $listener);
        yii\base\Event::off(craft\web\Response::class, craft\web\Response::EVENT_AFTER_PREPARE, $listener);
    }

    expect($fired)->toBe(['beforeSend', 'afterPrepare']);
});
