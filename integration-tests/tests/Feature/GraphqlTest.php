<?php

use craft\models\GqlSchema;
use heano\basin\Plugin;
use heano\basin\tests\craft\FakeBasin;
use markhuot\craftpest\factories\Entry;

/*
 * The GraphQL API: the `basinForms` and `basinForm` queries, what a form
 * exposes (and doesn't), and the Basin Form field's type.
 */

/** Runs a query against a schema with the given scope; returns `data`, failing on errors. */
function gql(string $query, array $scope = ['basinForms:read'], array $variables = []): ?array
{
    $gql = Craft::$app->getGql();
    $gql->flushCaches();
    $result = $gql->executeQuery(new GqlSchema(['name' => 'Test', 'scope' => $scope]), $query, $variables);
    expect($result['errors'] ?? [])->toBe([]);
    return $result['data'] ?? null;
}

it('lists the project’s forms by name', function() {
    $this->basin->addForm('Newsletter');

    $data = gql('{ basinForms { uuid name action } }');

    expect(array_column($data['basinForms'], 'name'))->toBe(['Alpha form 1', 'Alpha form 2', 'Newsletter'])
        ->and($data['basinForms'][0])->toBe([
            'uuid' => 'f00000000001',
            'name' => 'Alpha form 1',
            'action' => 'https://usebasin.com/f/f00000000001',
        ]);
});

it('finds a form by its Basin ID', function() {
    $form = $this->basin->addForm('Thanks page form', FakeBasin::ajax() + FakeBasin::redirect('https://example.com/thanks'));

    $data = gql('query($uuid: String!) { basinForm(uuid: $uuid) { name useAjax redirectUrl isStudioForm studioScriptUrl } }', variables: ['uuid' => $form['uuid']]);

    expect($data['basinForm'])->toBe([
        'name' => 'Thanks page form',
        'useAjax' => true,
        'redirectUrl' => 'https://example.com/thanks',
        'isStudioForm' => false,
        'studioScriptUrl' => null,
    ]);
});

it('returns null for a form that isn’t in the project, and logs it', function() {
    $data = gql('{ basinForm(uuid: "f99999999999") { name } }');

    expect($data['basinForm'])->toBeNull()
        ->and(Plugin::getInstance()->getFormIssues()->count())->toBe(1);
});

it('returns no forms when Basin is down', function() {
    $this->basin->respond('GET', 'forms', 503);

    expect(gql('{ basinForms { name } }')['basinForms'])->toBe([]);
});

it('gives the spam protection a page needs: {0}', function(array $fields, array $expected) {
    $form = $this->basin->addForm('Configured form', $fields);

    $data = gql("{ basinForm(uuid: \"{$form['uuid']}\") { spamProtection { captcha siteKey scriptUrl recaptchaVersion honeypotField } } }");

    expect($data['basinForm']['spamProtection'])->toBe($expected);
})->with([
    'no spam protection' => [[], ['captcha' => null, 'siteKey' => null, 'scriptUrl' => null, 'recaptchaVersion' => null, 'honeypotField' => '_gotcha']],
    'reCAPTCHA' => [FakeBasin::recaptcha(), [
        'captcha' => 'recaptcha',
        'siteKey' => '6Les66kUAAAAANyLrgkl7iuN4JUpNlB5upaMovI4',
        'scriptUrl' => 'https://www.google.com/recaptcha/api.js?render=6Les66kUAAAAANyLrgkl7iuN4JUpNlB5upaMovI4',
        'recaptchaVersion' => 'v3',
        'honeypotField' => '_gotcha',
    ]],
    'hCaptcha' => [FakeBasin::hcaptcha(), [
        'captcha' => 'hcaptcha',
        'siteKey' => '7fe715a1-151f-4c63-b497-bd971974df05',
        'scriptUrl' => 'https://js.hcaptcha.com/1/api.js',
        'recaptchaVersion' => null,
        'honeypotField' => '_gotcha',
    ]],
    'Turnstile' => [FakeBasin::turnstile(), [
        'captcha' => 'turnstile',
        'siteKey' => '1x00000000000000000000AA',
        'scriptUrl' => 'https://challenges.cloudflare.com/turnstile/v0/api.js',
        'recaptchaVersion' => null,
        'honeypotField' => '_gotcha',
    ]],
    'honeypot with its own field name' => [FakeBasin::honeypot('_trap'), ['captcha' => null, 'siteKey' => null, 'scriptUrl' => null, 'recaptchaVersion' => null, 'honeypotField' => '_trap']],
]);

it('uses reCAPTCHA v2 when the site is set to it', function() {
    settings(['recaptchaVersion' => 'v2']);
    $form = $this->basin->addForm('v2 form', FakeBasin::recaptcha());

    $data = gql("{ basinForm(uuid: \"{$form['uuid']}\") { spamProtection { siteKey scriptUrl recaptchaVersion } } }");

    expect($data['basinForm']['spamProtection'])->toBe([
        'siteKey' => '6Lew3SMUAAAAAJ82QoS7gqOTkRI_dhYrFy1f7Sqy',
        'scriptUrl' => 'https://www.google.com/recaptcha/api.js',
        'recaptchaVersion' => 'v2',
    ]);
});

it('gives a Form Studio form’s embed script', function() {
    $form = $this->basin->addForm('Studio form');
    $this->basin->publishStudioForm($form['uuid'], 'abc123');
    $this->actingAsAdmin();
    cpAction('basin/forms/save-studio-embed', [
        'formUuid' => $form['uuid'],
        'embed' => "<script src=\"https://hf.usebasin.com/hf/{$form['uuid']}-abc123.js\"></script>",
    ])->assertOk();
    newRequest();

    $data = gql("{ basinForm(uuid: \"{$form['uuid']}\") { isStudioForm studioScriptUrl } }");

    expect($data['basinForm'])->toBe([
        'isStudioForm' => true,
        'studioScriptUrl' => "https://hf.usebasin.com/hf/{$form['uuid']}-abc123.js",
    ]);
});

// Craft leaves unknown fields out of the result rather than reporting them.
it('exposes nothing beyond what a page needs', function() {
    $forms = gql('{ basinForms { submissionCount notificationEmails } }')['basinForms'];

    expect(array_map(fn($form) => (array)$form, $forms))->toBe([[], []]);
});

it('has no Basin queries in a schema without the Basin scope', function() {
    expect(gql('{ basinForms { name } basinForm(uuid: "f00000000001") { name } }', []))->toBe([])
        ->and($this->basin->requests)->toBe([]);
});

it('gives the Basin Form field’s form in entry queries', function() {
    [$section] = sectionWithFormField();
    Entry::factory()->section($section)->title('Contact us')->contactForm('f00000000002')->create();
    newRequest();

    $data = gql(
        "{ entries(section: \"{$section->handle}\") { title ... on {$section->getEntryTypes()[0]->handle}_Entry { contactForm { name action } } } }",
        ["sections.{$section->uid}:read", "entrytypes.{$section->getEntryTypes()[0]->uid}:read"],
    );

    expect($data['entries'])->toBe([[
        'title' => 'Contact us',
        'contactForm' => ['name' => 'Alpha form 2', 'action' => 'https://usebasin.com/f/f00000000002'],
    ]]);
});
