<?php

use heano\basin\tests\craft\FakeBasin;

/*
 * The spam protection script (src/web/protect.js) in a real browser. Test
 * pages (templates/basin-test/browser.twig) block the CAPTCHA providers'
 * scripts, so the tests check what the script asks for, then stand in for
 * the provider by calling the onload callback with a fake API.
 */

/** Stands in for a CAPTCHA provider whose script has loaded: records each widget rendered. */
function fakeProvider($page, string $global, string $callback): void
{
    $page->script(<<<JS
        () => {
            window.rendered = [];
            window.$global = { render: (el, options) => window.rendered.push({ sitekey: options.sitekey, inForm: el.closest('form')?.id ?? null }) };
            window.$callback();
        }
        JS);
}

it('loads the provider’s script and renders its widget', function(array $fields, string $global, string $script, string $siteKey) {
    // Forms use v2 widgets; v3 is tested below.
    settings(['recaptchaVersion' => 'v2']);
    $form = $this->basin->addForm('CAPTCHA form', $fields);

    $page = visit("/basin-test/browser?uuid={$form['uuid']}");

    // A honeypot, and a spot for the widget just before the submit button.
    $page->assertPresent('#contact input[name="_gotcha"]')
        ->assertPresent('#contact [data-basin-captcha] + button[type="submit"]');
    $src = $page->script('() => [...document.querySelectorAll("script[src]")].map((s) => s.src)');
    expect($src)->toHaveCount(1)
        ->and($src[0])->toStartWith($script);

    $callback = 'basinOnLoad_' . ($global === 'grecaptcha' ? 'recaptcha' : $global);
    fakeProvider($page, $global, $callback);

    expect($page->script('() => window.rendered'))->toBe([['sitekey' => $siteKey, 'inForm' => 'contact']]);
})->with([
    'hCaptcha' => [FakeBasin::hcaptcha(), 'hcaptcha', 'https://js.hcaptcha.com/1/api.js?render=explicit&onload=basinOnLoad_hcaptcha', '7fe715a1-151f-4c63-b497-bd971974df05'],
    'Turnstile' => [FakeBasin::turnstile(), 'turnstile', 'https://challenges.cloudflare.com/turnstile/v0/api.js?render=explicit&onload=basinOnLoad_turnstile', '1x00000000000000000000AA'],
    'reCAPTCHA v2' => [FakeBasin::recaptcha(), 'grecaptcha', 'https://www.google.com/recaptcha/api.js?render=explicit&onload=basinOnLoad_recaptcha', '6Lew3SMUAAAAAJ82QoS7gqOTkRI_dhYrFy1f7Sqy'],
]);

it('gets a reCAPTCHA v3 token when the form is submitted', function() {
    $form = $this->basin->addForm('reCAPTCHA form', FakeBasin::recaptcha());

    $page = visit("/basin-test/browser?uuid={$form['uuid']}");

    $src = $page->script('() => [...document.querySelectorAll("script[src]")].map((s) => s.src)');
    expect($src)->toBe(['https://www.google.com/recaptcha/api.js?render=6Les66kUAAAAANyLrgkl7iuN4JUpNlB5upaMovI4&onload=basinOnLoad_recaptcha']);
    $page->assertValue('#contact input[name="g-recaptcha-version"]', 'v3');

    // Submit, with a fake reCAPTCHA, and catch what would be sent to Basin.
    $page->script(<<<'JS'
        () => {
            window.grecaptcha = {
                ready: (fn) => fn(),
                execute: (key, options) => Promise.resolve(`token for ${key} (${options.action})`),
            };
            const form = document.getElementById('contact');
            form.addEventListener('submit', (event) => {
                event.preventDefault();
                window.sent = Object.fromEntries(new FormData(form));
            });
            form.querySelector('button').click();
        }
        JS);
    $page->wait(0.3);

    expect($page->script('() => window.sent'))->toBe([
        '_gotcha' => '',
        'email' => 'person@example.com',
        'g-recaptcha-response' => 'token for 6Les66kUAAAAANyLrgkl7iuN4JUpNlB5upaMovI4 (submit)',
        'g-recaptcha-version' => 'v3',
    ]);
});

it('protects a form added after the page loads', function() {
    $form = $this->basin->addForm('CAPTCHA form', FakeBasin::turnstile());

    $page = visit("/basin-test/browser?uuid={$form['uuid']}&late=1");
    $page->wait(0.5);

    $page->assertPresent('#late [data-basin-extra] input[name="_gotcha"]')
        ->assertPresent('#late [data-basin-captcha]');
    expect($page->script('() => document.querySelector(\'#late [data-basin-extra]\').getBoundingClientRect().right'))->toBeLessThanOrEqual(0);
    fakeProvider($page, 'turnstile', 'basinOnLoad_turnstile');

    expect(array_column($page->script('() => window.rendered'), 'inForm'))->toBe(['contact', 'late']);
});

it('adds only a honeypot to a form without a CAPTCHA', function() {
    $form = $this->basin->addForm('Plain form', FakeBasin::honeypot('_trap'));

    $page = visit("/basin-test/browser?uuid={$form['uuid']}");

    $page->assertPresent('#contact input[name="_trap"]')
        ->assertMissing('#contact [data-basin-captcha]');
    expect($page->script('() => document.querySelectorAll("script[src]").length'))->toBe(0);
});
