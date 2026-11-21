<?php

use heano\basin\helpers\FormEditor;

/** Settings as Basin returns them, trimmed to the editable fields and a few others. */
function basinSettings(array $overrides = []): array
{
    return $overrides + [
        'id' => 1001,
        'project_id' => 1004,
        'name' => 'Contact',
        'timezone' => 'UTC',
        'notification_emails' => 'owner@example.com',
        'notification_emails_pending' => '',
        'notification_cc_emails' => '',
        'notification_bcc_emails' => '',
        'notification_subject' => '',
        'notification_from_name' => '',
        'exclude_submitter_from_reply' => false,
        'redirect_url' => null,
        'redirect_heading' => '',
        'redirect_message' => '',
        'redirect_button_text' => '',
        'redirect_button_background_color' => null,
        'redirect_button_text_color' => null,
        'use_ajax' => false,
        'autoreply' => false,
        'autoreply_subject' => '',
        'autoreply_from_name' => '',
        'autoreply_greeting' => '',
        'autoreply_body' => null,
        'autoreply_name' => '',
        'autoreply_title' => '',
        'autoreply_email' => '',
        'retention_days' => 3650,
        'turnstile_secret_set' => true,
    ];
}

/** What the CP posts when nothing is touched. */
function postedUnchanged(): array
{
    $posted = [];
    foreach (FormEditor::normalise(basinSettings()) as $key => $value) {
        $posted[$key] = is_bool($value) ? ($value ? '1' : '') : (string)$value;
    }
    return $posted;
}

describe('changes', function() {
    it('finds nothing to send when nothing changed', function() {
        expect(FormEditor::changes(basinSettings(), postedUnchanged()))->toBe([]);
    });

    it('sends only the fields that changed', function() {
        $posted = ['name' => ' Enquiries ', 'use_ajax' => '1', 'retention_days' => '365'] + postedUnchanged();
        expect(FormEditor::changes(basinSettings(), $posted))->toBe(['name' => 'Enquiries', 'use_ajax' => true, 'retention_days' => 365]);
    });

    it('never sends fields it doesn’t manage', function() {
        $posted = ['project_id' => '99', 'turnstile_secret' => 'x', 'allowed_domains' => 'example.com', 'id' => '5'] + postedUnchanged();
        expect(FormEditor::changes(basinSettings(), $posted))->toBe([]);
    });

    it('ignores recipients that are only reordered or recased', function() {
        $original = basinSettings(['notification_emails' => 'a@example.com, b@example.com']);
        $posted = ['notification_emails' => "B@example.com\na@example.com"] + postedUnchanged();
        expect(FormEditor::changes($original, $posted))->toBe([]);
    });

    it('tidies a recipients list that did change', function() {
        $posted = ['notification_emails' => "owner@example.com; team@example.com\nteam@example.com"] + postedUnchanged();
        expect(FormEditor::changes(basinSettings(), $posted))->toBe(['notification_emails' => 'owner@example.com, team@example.com']);
    });

    it('sends cleared URLs and colours as null', function() {
        $original = basinSettings(['redirect_url' => 'https://example.com/thanks', 'redirect_button_text_color' => '#FFFFFF']);
        $posted = ['redirect_url' => '', 'redirect_button_text_color' => ''] + postedUnchanged();
        expect(FormEditor::changes($original, $posted))->toBe(['redirect_url' => null, 'redirect_button_text_color' => null]);
    });

    it('treats null and empty text from Basin as the same', function() {
        $original = basinSettings(['autoreply_body' => null, 'notification_subject' => null]);
        expect(FormEditor::changes($original, postedUnchanged()))->toBe([]);
    });

    it('ignores Windows line endings in long text', function() {
        $original = basinSettings(['autoreply_body' => "Thanks.\nWe’ll be in touch."]);
        $posted = ['autoreply_body' => "Thanks.\r\nWe’ll be in touch."] + postedUnchanged();
        expect(FormEditor::changes($original, $posted))->toBe([]);
    });

    it('adds the # that Craft’s colour field leaves off', function() {
        $posted = ['redirect_button_background_color' => '24292e'] + postedUnchanged();
        expect(FormEditor::changes(basinSettings(), $posted))->toBe(['redirect_button_background_color' => '#24292E'])
            ->and(FormEditor::validate(['redirect_button_background_color' => '#24292E']))->toBe([]);
    });

    it('uppercases colours, so case alone isn’t a change', function() {
        $original = basinSettings(['redirect_button_background_color' => '#24292E']);
        $posted = ['redirect_button_background_color' => '#24292e'] + postedUnchanged();
        expect(FormEditor::changes($original, $posted))->toBe([]);
    });
});

describe('validate', function() {
    it('accepts good values', function() {
        expect(FormEditor::validate(FormEditor::normalise([
            'name' => 'Contact',
            'timezone' => 'Europe/London',
            'notification_emails' => 'a@example.com, b@example.com',
            'autoreply_email' => 'reply@example.com',
            'redirect_url' => 'https://example.com/thanks?from=basin',
            'redirect_button_background_color' => '#24292e',
            'retention_days' => '1',
            'use_ajax' => '1',
            'notification_subject' => '',
        ])))->toBe([]);
    });

    it('rejects bad values', function(string $key, mixed $value, string $error) {
        expect(FormEditor::validate(FormEditor::normalise([$key => $value])))->toBe([$key => $error]);
    })->with([
        'blank name' => ['name', '  ', FormEditor::ERROR_REQUIRED],
        'bad recipient' => ['notification_emails', 'a@example.com, nope', FormEditor::ERROR_EMAIL],
        'bad reply-to' => ['autoreply_email', 'nope', FormEditor::ERROR_EMAIL],
        'two reply-tos' => ['autoreply_email', 'a@example.com, b@example.com', FormEditor::ERROR_EMAIL],
        'javascript URL' => ['redirect_url', 'javascript:alert(1)', FormEditor::ERROR_URL],
        'relative URL' => ['redirect_url', '/thanks', FormEditor::ERROR_URL],
        'short colour' => ['redirect_button_text_color', '#fff', FormEditor::ERROR_COLOR],
        'named colour' => ['redirect_button_text_color', 'white', FormEditor::ERROR_COLOR],
        'zero days' => ['retention_days', '0', FormEditor::ERROR_DAYS],
        'too many days' => ['retention_days', '3651', FormEditor::ERROR_DAYS],
        'no days' => ['retention_days', '', FormEditor::ERROR_DAYS],
        'not a number' => ['retention_days', 'ten', FormEditor::ERROR_DAYS],
        'unknown timezone' => ['timezone', 'Not/AZone', FormEditor::ERROR_TIMEZONE],
        'long name' => ['name', str_repeat('x', 101), FormEditor::ERROR_TOO_LONG],
        'long subject' => ['notification_subject', str_repeat('x', 1001), FormEditor::ERROR_TOO_LONG],
    ]);

    it('allows optional fields to be blank', function() {
        expect(FormEditor::validate(FormEditor::normalise([
            'notification_emails' => '', 'autoreply_email' => '', 'redirect_url' => '', 'redirect_button_text_color' => '',
        ])))->toBe([]);
    });
});

describe('fieldForMessage', function() {
    it('matches Basin’s messages to fields', function(string $message, ?string $field) {
        expect(FormEditor::fieldForMessage($message))->toBe($field);
    })->with([
        ["Name can't be blank", 'name'],
        ['Timezone is not a valid timezone', 'timezone'],
        ['Retention days must be less than or equal to 3650', 'retention_days'],
        ['Redirect url is invalid', 'redirect_url'],
        ['Redirect button background color must be a hex colour', 'redirect_button_background_color'],
        ['Something else went wrong', null],
    ]);

    it('finds the field that holds an invalid email address', function() {
        $sent = ['notification_emails' => 'a@example.com', 'notification_cc_emails' => 'b@example.com, Bad@Example', 'autoreply_email' => ''];
        expect(FormEditor::fieldForMessage('Invalid email address: bad@example', $sent))->toBe('notification_cc_emails')
            ->and(FormEditor::fieldForMessage('Invalid email address: other@example.com', $sent))->toBeNull();
    });

    it('puts the verification rate limit on the recipients field that was sent', function() {
        $message = 'Verification email rate limit exceeded. Please try again later or add fewer emails.';
        expect(FormEditor::fieldForMessage($message, ['name' => 'Contact', 'notification_cc_emails' => 'a@example.com']))->toBe('notification_cc_emails')
            ->and(FormEditor::fieldForMessage($message, ['name' => 'Contact']))->toBeNull();
    });
});

it('splits email lists on commas, semicolons and new lines, without repeats', function() {
    expect(FormEditor::splitEmails(" a@example.com,b@example.com;\nA@example.com  c@example.com "))
        ->toBe(['a@example.com', 'b@example.com', 'c@example.com']);
});

describe('withPending', function() {
    it('lists pending addresses with the verified ones', function() {
        $settings = basinSettings(['notification_emails' => 'owner@example.com', 'notification_emails_pending' => 'new@example.com, OWNER@example.com']);
        expect(FormEditor::withPending($settings)['notification_emails'])->toBe('owner@example.com, new@example.com');
    });

    it('finds nothing to send when the pending addresses are posted back unchanged', function() {
        $settings = basinSettings(['notification_emails' => '', 'notification_emails_pending' => 'new@example.com']);
        $posted = ['notification_emails' => 'new@example.com'] + postedUnchanged();
        expect(FormEditor::changes(FormEditor::withPending($settings), $posted))->toBe([]);
    });

    it('sends the list without a pending address that was removed', function() {
        $settings = basinSettings(['notification_emails_pending' => 'new@example.com']);
        $posted = ['notification_emails' => 'owner@example.com'] + postedUnchanged();
        expect(FormEditor::changes(FormEditor::withPending($settings), $posted))->toBe(['notification_emails' => 'owner@example.com']);
    });
});
