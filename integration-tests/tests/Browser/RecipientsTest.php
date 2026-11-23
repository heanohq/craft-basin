<?php

/*
 * The notification recipients field on a form's settings page: addresses
 * become chips, and the hidden input sent to Basin follows them.
 */

beforeEach(function() {
    useCpAssets();
    $this->actingAsAdmin();
    // One verified recipient.
    $this->form = $this->basin->addForm('Recipients form', ['notification_emails' => 'person1@example.com']);
});

const SEND_TO = '#basin-notification-emails';
const SEND_TO_VALUE = '#basin-notification-emails-value';

it('turns typed addresses into chips', function() {
    $page = visit("/admin/basin/forms/{$this->form['uuid']}/settings");
    $page->assertCount('#basin-notification-emails ~ .basin-chips .basin-chip', 1)
        ->assertValue(SEND_TO_VALUE, 'person1@example.com');

    $page->type(SEND_TO, 'person2@example.com')->keys(SEND_TO, 'Enter');
    // A comma works too, as does pasting several at once.
    $page->type(SEND_TO, 'person3@example.com')->keys(SEND_TO, ',');

    $page->assertCount('.basin-recipients:has(' . SEND_TO . ') .basin-chip', 3)
        ->assertValue(SEND_TO_VALUE, 'person1@example.com, person2@example.com, person3@example.com')
        ->assertValue(SEND_TO, '')
        ->assertNoJavaScriptErrors();
});

it('ignores an address that’s already there, whatever its case', function() {
    $page = visit("/admin/basin/forms/{$this->form['uuid']}/settings");

    $page->type(SEND_TO, 'PERSON1@example.com')->keys(SEND_TO, 'Enter');

    $page->assertCount('.basin-recipients:has(' . SEND_TO . ') .basin-chip', 1)
        ->assertValue(SEND_TO_VALUE, 'person1@example.com')
        ->assertNoJavaScriptErrors();
});

it('leaves something that isn’t an address in the box, marked as an error', function() {
    $page = visit("/admin/basin/forms/{$this->form['uuid']}/settings");

    $page->type(SEND_TO, 'not an address')->keys(SEND_TO, 'Enter');

    $page->assertCount('.basin-recipients:has(' . SEND_TO . ') .basin-chip', 1)
        ->assertValue(SEND_TO_VALUE, 'person1@example.com')
        ->assertAttributeContains(SEND_TO, 'class', 'error');
    // Only the part that isn't an address stays.
    $page->assertValue(SEND_TO, 'not, an, address')
        ->assertNoJavaScriptErrors();
});

it('removes a chip', function() {
    $page = visit("/admin/basin/forms/{$this->form['uuid']}/settings");

    $page->click('[aria-label="Remove person1@example.com"]');

    $page->assertCount('.basin-recipients:has(' . SEND_TO . ') .basin-chip', 0)
        ->assertValue(SEND_TO_VALUE, '')
        ->assertNoJavaScriptErrors();
});
