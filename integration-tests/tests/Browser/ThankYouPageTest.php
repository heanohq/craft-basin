<?php

/*
 * The thank-you page settings on a form's settings page. Basin only shows its
 * thank-you page when the form has no redirect URL, so they're hidden while
 * there is one.
 */

beforeEach(function() {
    useCpAssets();
    $this->actingAsAdmin();
});

const THANK_YOU_HEADING = '#basin-redirect-heading';
const REDIRECT_URL = '#basin-redirect-url';

it('shows the thank-you page settings when there’s no redirect URL', function() {
    $form = $this->basin->addForm('No redirect');
    $page = visit("/admin/basin/forms/{$form['uuid']}/settings");

    $page->assertVisible(THANK_YOU_HEADING)
        ->assertSee('Basin shows visitors its own thank-you page');

    // Typing a redirect URL hides them, and clearing it shows them again.
    $page->type(REDIRECT_URL, 'https://example.com/thanks');
    $page->assertMissing(THANK_YOU_HEADING);
    $page->clear(REDIRECT_URL);
    $page->assertVisible(THANK_YOU_HEADING)
        ->assertNoJavaScriptErrors();
});

it('hides them when the form has a redirect URL', function() {
    $form = $this->basin->addForm('With redirect', ['redirect_url' => 'https://example.com/thanks']);
    $page = visit("/admin/basin/forms/{$form['uuid']}/settings");

    $page->assertMissing(THANK_YOU_HEADING);
    $page->clear(REDIRECT_URL);
    $page->assertVisible(THANK_YOU_HEADING)
        ->assertNoJavaScriptErrors();
});
