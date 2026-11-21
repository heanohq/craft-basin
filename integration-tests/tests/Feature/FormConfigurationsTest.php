<?php

use heano\basin\tests\craft\FakeBasin;

/*
 * Forms in each configuration Basin allows. Add a row to a dataset for each
 * new configuration; FakeBasin's presets combine with `+`.
 */

dataset('configurations', fn() => [
    'no spam protection' => [[], 'None', null],
    'reCAPTCHA' => [FakeBasin::recaptcha(), 'reCAPTCHA', 'recaptcha'],
    'hCaptcha' => [FakeBasin::hcaptcha(), 'hCaptcha', 'hcaptcha'],
    'Turnstile' => [FakeBasin::turnstile(), 'Turnstile', 'turnstile'],
    'honeypot only' => [FakeBasin::honeypot(), 'Honeypot', null],
    'reCAPTCHA and honeypot' => [FakeBasin::recaptcha() + FakeBasin::honeypot(), 'reCAPTCHA, Honeypot', 'recaptcha'],
    'Turnstile and honeypot' => [FakeBasin::turnstile() + FakeBasin::honeypot(), 'Turnstile, Honeypot', 'turnstile'],
]);

it('shows the spam protection on the form’s page: {0}', function(array $fields, string $label) {
    $form = $this->basin->addForm('Configured form', $fields);

    $this->actingAsAdmin()->get("/admin/basin/forms/{$form['uuid']}")
        ->assertOk()
        ->assertSee($label);
})->with('configurations');

it('gives templates the form’s CAPTCHA: {0}', function(array $fields, string $label, ?string $captcha) {
    $form = $this->basin->addForm('Configured form', $fields);

    expect(twig("{{ craft.basin.form('{$form['uuid']}').captcha|default('none') }}"))->toBe($captcha ?? 'none');
})->with('configurations');

it('warns when Turnstile is required but has no site key', function() {
    $form = $this->basin->addForm('Broken Turnstile', FakeBasin::turnstileWithoutSiteKey());

    $this->actingAsAdmin()->get("/admin/basin/forms/{$form['uuid']}")
        ->assertOk()
        ->assertSee('no Turnstile site key is set in Basin');
});

it('warns when AJAX is on', function() {
    $form = $this->basin->addForm('AJAX form', FakeBasin::ajax());

    $this->actingAsAdmin()->get("/admin/basin/forms/{$form['uuid']}")
        ->assertOk()
        ->assertSee('“Enable AJAX” is on in Basin');
});

it('doesn’t warn about a form with the defaults', function() {
    $form = $this->basin->addForm('Plain form');

    $this->actingAsAdmin()->get("/admin/basin/forms/{$form['uuid']}")
        ->assertOk()
        ->assertDontSee('“Enable AJAX” is on in Basin')
        ->assertDontSee('no Turnstile site key is set in Basin');
});
