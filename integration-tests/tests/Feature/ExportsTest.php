<?php

use heano\basin\Plugin;
use heano\basin\services\Exports;
use markhuot\craftpest\factories\User;

/*
 * CSV exports: small ones download straight away; large ones are queued and
 * can only be downloaded by the user who asked for them.
 */

function responseBody($response): string
{
    if ($response->stream !== null) {
        [$handle, $begin, $end] = $response->stream;
        fseek($handle, $begin);
        return stream_get_contents($handle, $end - $begin + 1);
    }
    return (string)$response->content;
}

it('downloads a small export straight away', function() {
    $this->actingAsAdmin();

    $response = $this->post('/admin/actions/basin/submissions/export', ['formUuid' => 'f00000000001'])->assertOk();

    $csv = responseBody($response);
    expect($response->headers->get('Content-Disposition'))->toStartWith('attachment;')
        ->and($csv)->toContain('person8@example.com')
        ->and($csv)->toContain('person11@example.com')
        ->and($csv)->toMatch('/^1006,/m')
        ->and($csv)->not->toMatch('/^1001,/m'); // 1001 is spam, so not in the inbox export.
});

it('queues a large export for the user who asked', function() {
    $this->basin->addSubmissions(1001, Exports::SYNC_MAX_ROWS);
    $user = User::factory()->admin(true)->create();
    $this->actingAs($user);

    $this->post('/admin/actions/basin/submissions/export', ['formUuid' => 'f00000000001'])->assertRedirect();

    $exports = Plugin::getInstance()->getExports()->listForUser($user->id);
    expect($exports)->toHaveCount(1);
    $token = $exports[0]['token'];

    runQueue();

    $meta = Plugin::getInstance()->getExports()->getForUser($token, $user->id);
    expect($meta['status'])->toBe('ready')
        ->and($meta['rows'])->toBe(Exports::SYNC_MAX_ROWS + 3)
        // Every page was fetched: 100 a page, like Basin.
        ->and(count($this->basin->requestsTo('GET', 'submissions')))->toBeGreaterThanOrEqual(11);

    $csv = file_get_contents(Plugin::getInstance()->getExports()->csvPath($token));
    expect(substr_count(trim($csv), "\n"))->toBe(Exports::SYNC_MAX_ROWS + 3);

    // Someone else can't see or download it.
    $this->actingAsAdmin()->withExceptionHandling()->get("/admin/basin/exports/$token")->assertNotFound();

    Plugin::getInstance()->getExports()->delete($token);
});

/** Queues a large export of form 1001 as a new admin; returns [user, token]. */
function queueLargeExport($test): array
{
    $test->basin->addSubmissions(1001, Exports::SYNC_MAX_ROWS);
    $user = User::factory()->admin(true)->create();
    $test->actingAs($user);
    $test->post('/admin/actions/basin/submissions/export', ['formUuid' => 'f00000000001'])->assertRedirect();
    return [$user, Plugin::getInstance()->getExports()->listForUser($user->id)[0]['token']];
}

it('lets the user who asked download a finished export', function() {
    [$user, $token] = queueLargeExport($this);
    runQueue();

    // Craft's sendFile() empties PHP's output buffers, PHPUnit's included; put them back.
    $level = ob_get_level();
    $response = $this->actingAs($user)->get("/admin/basin/exports/$token")->assertOk();
    while (ob_get_level() < $level) {
        ob_start();
    }

    expect($response->headers->get('Content-Disposition'))->toStartWith('attachment;')
        ->and(substr_count(trim(responseBody($response)), "\n"))->toBe(Exports::SYNC_MAX_ROWS + 3);

    Plugin::getInstance()->getExports()->delete($token);
});

it('marks an export failed when Basin stops responding, and keeps no partial file', function(Closure $break, string $error) {
    [$user, $token] = queueLargeExport($this);
    $break($this->basin);

    runQueue();

    $exports = Plugin::getInstance()->getExports();
    $meta = $exports->getForUser($token, $user->id);
    expect($meta['status'])->toBe('failed')
        ->and($meta['error'])->toContain($error)
        ->and(is_file($exports->csvPath($token)))->toBeFalse();

    $this->actingAs($user)->withExceptionHandling()->get("/admin/basin/exports/$token")->assertNotFound();

    $exports->delete($token);
})->with([
    'Basin is down' => [fn($basin) => $basin->respond('GET', 'submissions', 503), ''],
    'the form is gone' => [function($basin) {
        unset($basin->forms[1001]);
        newRequest();
    }, 'no longer in the linked Basin project'],
]);

it('deletes expired exports with Craft’s garbage collection', function() {
    [$user, $token] = queueLargeExport($this);
    runQueue();
    $exports = Plugin::getInstance()->getExports();
    $csv = $exports->csvPath($token);
    $metaFile = substr($csv, 0, -4) . '.json';
    $orphan = dirname($csv) . '/' . str_repeat('0', 32) . '.csv';
    file_put_contents($orphan, 'left behind');

    // Still in date: kept, apart from a CSV without its details.
    Craft::$app->getGc()->trigger(craft\services\Gc::EVENT_RUN);
    expect(is_file($csv))->toBeTrue()->and(is_file($orphan))->toBeFalse();

    // A day old: gone.
    $meta = json_decode(file_get_contents($metaFile), true);
    file_put_contents($metaFile, json_encode(['created' => time() - Exports::TTL - 1] + $meta));
    Craft::$app->getGc()->trigger(craft\services\Gc::EVENT_RUN);

    expect(is_file($csv))->toBeFalse()->and(is_file($metaFile))->toBeFalse();
});
