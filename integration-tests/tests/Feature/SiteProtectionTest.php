<?php

use heano\basin\Plugin;
use heano\basin\tests\craft\FakeBasin;

/*
 * Spam protection on front-end pages (templates/basin-test): the honeypot
 * and CAPTCHA script added to pages with Basin forms, for each form
 * configuration, and the cases where pages must be left alone.
 */

/** Requests a test page for a form; returns the HTML. */
function page(string $template, string $uuid): string
{
    $response = test()->get("/basin-test/$template?uuid=$uuid")->assertOk();
    return $response->content;
}

/** The `window.BasinProtect` settings a page was given, or null. */
function protectConfig(string $html): ?array
{
    return preg_match('/window\.BasinProtect=(\{.*?\});\n/', $html, $m) ? json_decode($m[1], true) : null;
}

it('protects a form on a page: {0}', function(array $fields, ?string $captcha, string $honeypot) {
    $form = $this->basin->addForm('Configured form', $fields);

    $html = page('form', $form['uuid']);

    expect($html)->toContain("name=\"$honeypot\"")
        ->and(preg_match_all('/<div data-basin-extra aria-hidden="true"><input[^>]*name="' . preg_quote($honeypot, '/') . '"/', $html))->toBe(1)
        ->and(substr_count($html, 'data-basin-styles'))->toBe(1);

    $config = protectConfig($html);
    if ($captcha === null) {
        expect($config)->toBeNull()
            ->and($html)->not->toContain('data-basin-protect-script');
    } else {
        expect($config['auto'])->toBeTrue()
            ->and(array_keys($config['forms']))->toBe([$form['uuid']])
            ->and($config['forms'][$form['uuid']]['captcha'])->toBe($captcha);
    }
})->with([
    'no spam protection' => [[], null, '_gotcha'],
    'reCAPTCHA' => [FakeBasin::recaptcha(), 'recaptcha', '_gotcha'],
    'hCaptcha' => [FakeBasin::hcaptcha(), 'hcaptcha', '_gotcha'],
    'Turnstile' => [FakeBasin::turnstile(), 'turnstile', '_gotcha'],
    'honeypot with its own field name' => [FakeBasin::honeypot('_trap'), null, '_trap'],
    'Turnstile and honeypot' => [FakeBasin::turnstile() + FakeBasin::honeypot('_trap'), 'turnstile', '_trap'],
]);

it('gives the page both reCAPTCHA keys and the site’s version', function() {
    settings(['recaptchaVersion' => 'v2']);
    $form = $this->basin->addForm('reCAPTCHA form', FakeBasin::recaptcha());

    $config = protectConfig(page('form', $form['uuid']));

    expect($config['recaptchaKeys'])->toBe([
        'v2' => '6Lew3SMUAAAAAJ82QoS7gqOTkRI_dhYrFy1f7Sqy',
        'v3' => '6Les66kUAAAAANyLrgkl7iuN4JUpNlB5upaMovI4',
    ])->and($config['forms'][$form['uuid']]['recaptchaVersion'])->toBe('v2');
});

it('leaves a form alone that opts out', function() {
    $form = $this->basin->addForm('Opted out', FakeBasin::recaptcha());

    $html = page('opted-out', $form['uuid']);

    expect($html)->not->toContain('<div data-basin-extra')
        ->and($html)->not->toContain('data-basin-protect-script');
});

it('doesn’t add a second honeypot to a form that has one', function() {
    $html = page('own-honeypot', 'f00000000001');

    expect(substr_count($html, 'name="_gotcha"'))->toBe(1)
        ->and($html)->toContain('class="my-honeypot"');
});

it('only handles the Twig helpers’ placeholders when automatic protection is off', function() {
    settings(['autoProtect' => false]);
    $form = $this->basin->addForm('Manual form', FakeBasin::hcaptcha() + FakeBasin::honeypot('_trap'));

    $plain = page('form', $form['uuid']);
    $helpers = page('helpers', $form['uuid']);

    expect($plain)->not->toContain('<div data-basin-extra')
        ->and($plain)->not->toContain('data-basin-protect-script')
        ->and(substr_count($helpers, 'name="_trap"'))->toBe(1)
        ->and($helpers)->toContain("data-basin-captcha=\"{$form['uuid']}\"")
        ->and(protectConfig($helpers)['auto'])->toBeFalse()
        ->and(protectConfig($helpers)['forms'][$form['uuid']]['captcha'])->toBe('hcaptcha');
});

it('uses the page’s CSP nonce for its script', function() {
    $form = $this->basin->addForm('CAPTCHA form', FakeBasin::turnstile());

    expect(page('nonce', $form['uuid']))->toMatch('/<script nonce="test-nonce-123" data-basin-protect-script>/');
});

it('logs a form on a page that isn’t in the project, and leaves the page alone', function() {
    $html = page('form', 'f99999999999');

    expect($html)->not->toContain('<div data-basin-extra')
        ->and(Plugin::getInstance()->getFormIssues()->count())->toBe(1);
});

it('leaves the page alone when Basin is down', function() {
    $this->basin->respond('GET', 'forms', 503);

    $html = page('form', 'f00000000001');

    expect($html)->toContain('<form action="https://usebasin.com/f/f00000000001"')
        ->and($html)->not->toContain('<div data-basin-extra')
        ->and(Plugin::getInstance()->getFormIssues()->count())->toBe(0);
});

it('leaves pages that aren’t HTML alone', function() {
    $form = $this->basin->addForm('CAPTCHA form', FakeBasin::recaptcha());

    $response = $this->get("/basin-test/feed?uuid={$form['uuid']}")->assertOk();

    expect($response->content)->not->toContain('data-basin');
});

it('does nothing until Basin is set up', function() {
    settings(['apiKey' => '']);

    expect(page('form', 'f00000000001'))->not->toContain('data-basin')
        ->and($this->basin->requests)->toBe([]);
});

it('gives bots that read the HTML nothing to say the honeypot is hidden', function() {
    $form = $this->basin->addForm('Honeypot form', FakeBasin::honeypot('website'));

    $html = page('form', $form['uuid']);
    preg_match('/<div data-basin-extra.*?<\/div>/', $html, $m);
    // Screen readers skip the wrapper; that's the one clue left.
    $markup = str_replace(' aria-hidden="true"', '', $m[0]);

    expect($markup)->toContain('type="text"')
        ->and(preg_match('/\bhidden\b|display|style=|honeypot/i', $markup))->toBe(0, $markup)
        ->and($html)->toMatch('/<link href="[^"]*honeypot\.css[^"]*" rel="stylesheet" data-basin-styles>\s*<\/head>/');
});

it('links the honeypot stylesheet for the Twig helper too, with the page’s nonce', function() {
    $form = $this->basin->addForm('Manual form', FakeBasin::honeypot());

    expect(page('nonce', $form['uuid']))->toMatch('/<link [^>]*nonce="test-nonce-123" data-basin-styles>/');

    settings(['autoProtect' => false]);
    expect(page('helpers', $form['uuid']))->toContain('<div data-basin-extra')
        ->toMatch('/<link [^>]*data-basin-styles/');
});

it('leaves pages without a honeypot alone', function() {
    $form = $this->basin->addForm('Opted out', FakeBasin::honeypot());

    expect(page('opted-out', $form['uuid']))->not->toContain('data-basin-styles');
});
