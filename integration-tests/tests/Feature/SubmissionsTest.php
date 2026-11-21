<?php

use heano\basin\jobs\BulkSubmissionAction;
use heano\basin\services\Submissions;

/*
 * What the plugin sends to Basin when submissions are triaged, deleted,
 * re-sent and exported, and what it refuses to send.
 */

it('sends the flags for {0}', function(string $action, array $flags) {
    $this->actingAsAdmin();

    cpAction('basin/submissions/apply', ['formUuid' => 'f00000000001', 'basinAction' => $action, 'ids' => [1006, 1007]])
        ->assertOk();

    $sent = $this->basin->requestsTo('PATCH', 'submissions/\d+');
    expect(array_column($sent, 'path'))->toBe(['submissions/1006', 'submissions/1007'])
        ->and(array_column(array_column($sent, 'body'), 'submission'))->each->toBe($flags);
})->with(fn() => array_map(fn($action, $flags) => [$action, $flags], array_keys(Submissions::ACTIONS), Submissions::ACTIONS));

it('won’t touch a submission from another form', function() {
    $other = $this->basin->addSubmission(1005)['id'];
    $this->actingAsAdmin();

    cpAction('basin/submissions/apply', ['formUuid' => 'f00000000001', 'basinAction' => 'markRead', 'ids' => [$other]])
        ->assertStatus(400);

    expect($this->basin->requestsTo('PATCH', 'submissions/\d+'))->toBe([]);
});

it('updates the rest when some IDs are from another form', function() {
    $other = $this->basin->addSubmission(1005)['id'];
    $this->actingAsAdmin();

    cpAction('basin/submissions/apply', ['formUuid' => 'f00000000001', 'basinAction' => 'markRead', 'ids' => [1006, $other]])
        ->assertOk()
        ->assertSee('1 failed');

    expect(array_column($this->basin->requestsTo('PATCH', 'submissions/\d+'), 'path'))->toBe(['submissions/1006']);
});

it('rejects an unknown action', function() {
    $this->actingAsAdmin();

    cpAction('basin/submissions/apply', ['formUuid' => 'f00000000001', 'basinAction' => 'explode', 'ids' => [1006]])
        ->assertStatus(400);
})->withExceptionHandling();

describe('permanent deletion', function() {
    it('is refused while it’s turned off in the settings', function() {
        $this->actingAsAdmin();

        cpAction('basin/submissions/apply', ['formUuid' => 'f00000000001', 'basinAction' => 'delete', 'ids' => [1006]])
            ->assertForbidden();

        expect($this->basin->requestsTo('DELETE', 'submissions/\d+'))->toBe([]);
    })->withExceptionHandling();

    it('deletes in Basin once it’s turned on', function() {
        settings(['allowDeletion' => true]);
        $this->actingAsAdmin();

        cpAction('basin/submissions/apply', ['formUuid' => 'f00000000001', 'basinAction' => 'delete', 'ids' => [1006]])
            ->assertOk();

        expect($this->basin->submissions)->not->toHaveKey(1006);
    });
});

it('queues large bulk actions and runs them in batches', function() {
    $ids = $this->basin->addSubmissions(1001, Submissions::SYNC_LIMIT + 1);
    $this->actingAsAdmin();

    cpAction('basin/submissions/apply', ['formUuid' => 'f00000000001', 'basinAction' => 'markRead', 'ids' => $ids])
        ->assertOk()
        ->assertSee('in the background');
    expect($this->basin->requestsTo('PATCH', 'submissions/\d+'))->toBe([]);

    runQueue();

    expect($this->basin->requestsTo('PATCH', 'submissions/\d+'))->toHaveCount(count($ids))
        ->and(array_filter($ids, fn($id) => !$this->basin->submissions[$id]['read']))->toBe([]);
});

it('fails a queued bulk action that couldn’t finish, so Craft shows it', function() {
    $this->basin->respond('PATCH', 'submissions/1006', 500);
    $job = new BulkSubmissionAction(['formUuid' => 'f00000000001', 'action' => 'markRead', 'ids' => [1006, 1007]]);

    expect(fn() => $job->execute(Craft::$app->getQueue()))->toThrow(RuntimeException::class, '1 of 2');
    expect($this->basin->submissions[1007]['read'])->toBeTrue();
});

it('asks Basin to resend a submission’s webhooks', function() {
    $this->actingAsAdmin();

    cpAction('basin/submissions/resend-webhooks', ['formUuid' => 'f00000000001', 'id' => 1006])->assertOk();

    expect($this->basin->requestsTo('POST', 'submissions/1006/refire_webhooks'))->toHaveCount(1);
});

describe('attachments', function() {
    it('downloads through Craft, never inline', function() {
        $attachment = $this->basin->addAttachment(1006, 'floor plan.pdf', '%PDF-1.4 fake');
        $this->actingAsAdmin();

        $response = $this->get("/admin/basin/forms/f00000000001/submissions/1006/attachments/$attachment")->assertOk();

        expect($response->headers->get('Content-Type'))->toStartWith('application/octet-stream')
            ->and($response->headers->get('Content-Disposition'))->toStartWith('attachment;')
            ->and($response->headers->get('Content-Disposition'))->toContain('floor plan.pdf');
    });

    it('won’t download an attachment from another submission', function() {
        $attachment = $this->basin->addAttachment(1007, 'secret.pdf', 'x');
        $this->actingAsAdmin();

        $this->get("/admin/basin/forms/f00000000001/submissions/1006/attachments/$attachment")->assertNotFound();

        expect($this->basin->requestsTo('GET', 'attachments/\d+'))->toBe([]);
    })->withExceptionHandling();
});
