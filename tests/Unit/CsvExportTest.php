<?php

use heano\basin\helpers\CsvExport;
use heano\basin\models\Submission;

function csvSubmission(array $data): Submission
{
    return Submission::fromApi($data + [
        'id' => 1,
        'form_id' => 1004,
        'email' => 'a@example.com',
        'read' => true,
        'spam' => false,
        'trash' => false,
        'created_at' => '2026-11-13T20:00:00.000Z',
        'payload_params' => [],
        'attachments' => [],
        'ip' => '192.0.2.1',
        'user_agent' => 'Agent',
        'referrer' => 'https://example.com/contact',
    ]);
}

function csvOutput(CsvExport $csv): array
{
    $out = fopen('php://memory', 'w+b');
    $csv->writeTo($out);
    rewind($out);
    $raw = stream_get_contents($out);
    $rows = [];
    $in = fopen('php://memory', 'w+b');
    fwrite($in, substr($raw, 3));
    rewind($in);
    while (($row = fgetcsv($in, escape: '')) !== false) {
        $rows[] = $row;
    }
    return ['raw' => $raw, 'rows' => $rows];
}

$url = fn(Submission $s, array $a) => "https://cp.test/a/$s->id/{$a['id']}";

it('writes a BOM and a column for every payload key, in order of first appearance', function() use ($url) {
    $csv = new CsvExport(new DateTimeZone('UTC'), false, $url);
    $csv->add(csvSubmission(['id' => 1, 'payload_params' => ['name' => 'Ann', 'message' => 'Hi']]));
    $csv->add(csvSubmission(['id' => 2, 'payload_params' => ['name' => 'Bob', 'phone' => '0123']]));
    ['raw' => $raw, 'rows' => $rows] = csvOutput($csv);

    expect(substr($raw, 0, 3))->toBe("\xEF\xBB\xBF")
        ->and($rows[0])->toBe(['id', 'submitted_at', 'email', 'read', 'spam', 'trash', 'name', 'message', 'phone', 'attachments'])
        ->and($rows[1])->toBe(['1', '2026-11-13T20:00:00+00:00', 'a@example.com', 'yes', 'no', 'no', 'Ann', 'Hi', '', ''])
        ->and($rows[2])->toBe(['2', '2026-11-13T20:00:00+00:00', 'a@example.com', 'yes', 'no', 'no', 'Bob', '', '0123', '']);
});

it('uses the form timezone for dates', function() use ($url) {
    $csv = new CsvExport(new DateTimeZone('Europe/London'), false, $url);
    $csv->add(csvSubmission(['created_at' => '2026-07-01T12:00:00.000Z']));
    expect(csvOutput($csv)['rows'][1][1])->toBe('2026-07-01T13:00:00+01:00');
});

it('renames payload keys that clash with metadata columns', function() use ($url) {
    $csv = new CsvExport(new DateTimeZone('UTC'), false, $url);
    $csv->add(csvSubmission(['payload_params' => ['email' => 'typed@example.com']]));
    expect(csvOutput($csv)['rows'][0])->toContain('field: email');
});

it('lists attachments with CP links, and technical columns only when asked', function() use ($url) {
    $data = ['id' => 5, 'attachments' => [['id' => 9, 'name' => 'cv.pdf', 'public_url' => 'https://secret']]];

    $csv = new CsvExport(new DateTimeZone('UTC'), true, $url);
    $csv->add(csvSubmission($data));
    $rows = csvOutput($csv)['rows'];
    expect(array_slice($rows[0], -4))->toBe(['attachments', 'ip', 'user_agent', 'referrer'])
        ->and(array_slice($rows[1], -4))->toBe(['cv.pdf (https://cp.test/a/5/9)', '192.0.2.1', 'Agent', 'https://example.com/contact']);

    $csv = new CsvExport(new DateTimeZone('UTC'), false, $url);
    $csv->add(csvSubmission($data));
    expect(csvOutput($csv)['rows'][0])->not->toContain('ip');
});

it('neutralises spreadsheet formulas', function(string $value, string $expected) {
    expect(CsvExport::safeCell($value))->toBe($expected);
})->with([
    ['=HYPERLINK("http://evil")', '\'=HYPERLINK("http://evil")'],
    ['+1+1', "'+1+1"],
    ['-2', "'-2"],
    ['@SUM(A1)', "'@SUM(A1)"],
    ["\tx", "'\tx"],
    ['hello', 'hello'],
    ['', ''],
]);

it('writes nested values and multi-line text safely', function() use ($url) {
    $csv = new CsvExport(new DateTimeZone('UTC'), false, $url);
    $csv->add(csvSubmission(['payload_params' => ['tags' => ['a', 'b'], 'note' => "line 1\nline \"2\""]]));
    $rows = csvOutput($csv)['rows'];
    expect($rows[1][7])->toBe("line 1\nline \"2\"")
        ->and($rows[1][6])->not->toBe('');
});

it('makes safe file names', function() {
    expect(CsvExport::filename('Contact form: Sales!', 'inbox', '2026-11-13'))->toBe('contact-form-sales-inbox-2026-11-13.csv')
        ->and(CsvExport::filename('日本語', 'spam', '2026-11-13'))->toBe('form-spam-2026-11-13.csv');
});
