<?php

use heano\basin\Plugin;
use heano\basin\tests\craft\FakeBasin;

/*
 * What the plugin sends to Basin when forms are created, changed and deleted
 * in the Control Panel, and what it refuses to send.
 */

describe('creating a form', function() {
    it('creates it in the linked project, in the site’s timezone', function() {
        $this->actingAsAdmin();

        cpAction('basin/forms/create', ['fields' => ['name' => 'Careers', 'notification_emails' => 'jobs@example.com']])
            ->assertOk();

        $sent = $this->basin->requestsTo('POST', 'forms');
        expect($sent)->toHaveCount(1)
            ->and($sent[0]['body']['form'])->toMatchArray([
                'name' => 'Careers',
                'project_id' => 1002,
                'notification_emails' => 'jobs@example.com',
                'timezone' => Craft::$app->getProjectConfig()->get('system.timeZone') ?: 'UTC',
            ]);
        expect(array_column($this->basin->forms, 'name'))->toContain('Careers');
    });

    it('leaves notifications to Basin’s default when no address is given', function() {
        $this->actingAsAdmin();

        cpAction('basin/forms/create', ['fields' => ['name' => 'Careers', 'notification_emails' => '']])->assertOk();

        expect($this->basin->requestsTo('POST', 'forms')[0]['body']['form'])->not->toHaveKey('notification_emails');
    });

    it('sends nothing when the name is blank', function() {
        $this->actingAsAdmin();

        cpAction('basin/forms/create', ['fields' => ['name' => ' ']])->assertStatus(400);

        expect($this->basin->requestsTo('POST', 'forms'))->toBe([]);
    });

    it('shows Basin’s reason when Basin rejects it', function() {
        $this->basin->respond('POST', 'forms', 422, ['errors' => ['name' => ['is already taken']]]);
        $this->actingAsAdmin();

        cpAction('basin/forms/create', ['fields' => ['name' => 'Alpha form 1']])
            ->assertStatus(400)
            ->assertSee('is already taken');
    });
});

describe('changing a form’s settings', function() {
    it('sends only what changed', function() {
        $this->actingAsAdmin();

        cpAction('basin/forms/save-settings', [
            'formUuid' => 'f00000000001',
            'fields' => ['notification_subject' => 'New enquiry'],
        ])->assertOk();

        $sent = $this->basin->requestsTo('PUT', 'forms/1001');
        expect($sent)->toHaveCount(1)
            ->and($sent[0]['body']['form'])->toBe(['notification_subject' => 'New enquiry'])
            ->and($this->basin->forms[1001]['notification_subject'])->toBe('New enquiry');
    });

    it('sends nothing when nothing changed', function() {
        $this->actingAsAdmin();

        cpAction('basin/forms/save-settings', [
            'formUuid' => 'f00000000001',
            'fields' => ['name' => 'Alpha form 1'],
        ])->assertOk()->assertSee('Nothing has changed');

        expect($this->basin->requestsTo('PUT', 'forms/\d+'))->toBe([]);
    });

    it('sends nothing for an invalid value', function() {
        $this->actingAsAdmin();

        cpAction('basin/forms/save-settings', [
            'formUuid' => 'f00000000001',
            'fields' => ['redirect_url' => 'not a url'],
        ])->assertStatus(400);

        expect($this->basin->requestsTo('PUT', 'forms/\d+'))->toBe([]);
    });

    it('never changes a form that has moved to another project', function() {
        $this->actingAsAdmin();
        // The CP's list still has it, but Basin now says it's elsewhere.
        Plugin::getInstance()->getForms()->getForms();
        $this->basin->forms[1001]['project_id'] = 1003;

        cpAction('basin/forms/save-settings', [
            'formUuid' => 'f00000000001',
            'fields' => ['notification_subject' => 'New enquiry'],
        ])->assertNotFound();

        expect($this->basin->requestsTo('PUT', 'forms/\d+'))->toBe([]);
    })->withExceptionHandling();

    it('ignores fields that can’t be edited', function() {
        $this->actingAsAdmin();

        cpAction('basin/forms/save-settings', [
            'formUuid' => 'f00000000001',
            'fields' => ['notification_subject' => 'New enquiry', 'project_id' => 1003, 'turnstile_secret' => 'x'],
        ])->assertOk();

        expect($this->basin->requestsTo('PUT', 'forms/1001')[0]['body']['form'])->toBe(['notification_subject' => 'New enquiry']);
    });
});

describe('deleting a form', function() {
    it('needs the form’s name typed exactly', function() {
        $this->actingAsAdmin();

        cpAction('basin/forms/delete', ['formUuid' => 'f00000000001', 'confirmName' => 'Alpha form'])->assertStatus(400);

        expect($this->basin->requestsTo('DELETE', 'forms/\d+'))->toBe([])
            ->and($this->basin->forms)->toHaveKey(1001);
    });

    it('deletes it in Basin, and the plugin’s own settings for it', function() {
        $plugin = Plugin::getInstance();
        $plugin->getFormSettings()->save('f00000000001', ['recaptchaVersion' => 'v2']);
        $this->actingAsAdmin();

        cpAction('basin/forms/delete', ['formUuid' => 'f00000000001', 'confirmName' => 'Alpha form 1'])->assertOk();

        expect($this->basin->requestsTo('DELETE', 'forms/1001'))->toHaveCount(1)
            ->and($this->basin->forms)->not->toHaveKey(1001)
            ->and($plugin->getFormSettings()->getRecaptchaVersion('f00000000001'))->toBeNull();
    });
});

describe('Form Studio embed code', function() {
    $embed = '<script src="https://hf.usebasin.com/hf/f00000000001-studio1.js"></script>';

    it('saves embed code once Basin serves its script', function() use ($embed) {
        $this->basin->publishStudioForm('f00000000001', 'studio1');
        $this->actingAsAdmin();

        cpAction('basin/forms/save-studio-embed', ['formUuid' => 'f00000000001', 'embed' => $embed])->assertOk();

        expect(Plugin::getInstance()->getFormSettings()->getHostedFormId('f00000000001'))->toBe('studio1');
    });

    it('refuses embed code Basin doesn’t recognise', function() use ($embed) {
        $this->actingAsAdmin();

        cpAction('basin/forms/save-studio-embed', ['formUuid' => 'f00000000001', 'embed' => $embed])
            ->assertStatus(400)
            ->assertSee('doesn’t recognise this embed code');

        expect(Plugin::getInstance()->getFormSettings()->getHostedFormId('f00000000001'))->toBeNull();
    });

    it('refuses another form’s embed code', function() use ($embed) {
        $this->actingAsAdmin();

        cpAction('basin/forms/save-studio-embed', ['formUuid' => 'f00000000002', 'embed' => $embed])
            ->assertStatus(400)
            ->assertSee('for a different Basin form');
    });

    it('refuses something that isn’t embed code', function() {
        $this->actingAsAdmin();

        cpAction('basin/forms/save-studio-embed', ['formUuid' => 'f00000000001', 'embed' => '<p>hello</p>'])
            ->assertStatus(400);
    });

    it('removes embed code', function() {
        Plugin::getInstance()->getForms()->saveHostedFormId('f00000000001', 'studio1');
        $this->actingAsAdmin();

        cpAction('basin/forms/remove-studio-embed', ['formUuid' => 'f00000000001'])->assertOk();

        expect(Plugin::getInstance()->getFormSettings()->getHostedFormId('f00000000001'))->toBeNull();
    });
});

it('saves a form’s reCAPTCHA version on this site only', function(string $posted, ?string $saved) {
    $form = $this->basin->addForm('Contact', FakeBasin::recaptcha());
    $this->actingAsAdmin();

    cpAction('basin/forms/save-recaptcha-version', ['formUuid' => $form['uuid'], 'recaptchaVersion' => $posted])->assertOk();

    expect(Plugin::getInstance()->getFormSettings()->getRecaptchaVersion($form['uuid']))->toBe($saved)
        ->and($this->basin->requestsTo('PUT', 'forms/\d+'))->toBe([]);
})->with([
    'v2' => ['v2', 'v2'],
    'v3' => ['v3', 'v3'],
    'the default' => ['', null],
    'nonsense' => ['v9', null],
]);
