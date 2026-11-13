<?php

namespace heano\basin\helpers;

use DateTimeZone;
use heano\basin\models\Submission;

/**
 * Builds submission CSVs (spec §8). Rows are spooled to a temp file first,
 * because the columns are every payload key across all rows, which is only
 * known once the last row has been read.
 *
 * Plain PHP with no Craft dependencies, so it's unit-tested.
 */
final class CsvExport
{
    private const META_COLUMNS = ['id', 'submitted_at', 'email', 'read', 'spam', 'trash'];
    private const TECHNICAL_COLUMNS = ['ip', 'user_agent', 'referrer'];

    /** @var resource */
    private $spool;

    /** @var array<string, true> Payload keys, in order of first appearance. */
    private array $keys = [];

    private int $count = 0;

    /**
     * @param callable(Submission, array{id: int, name: string}): string $attachmentUrl
     */
    public function __construct(
        private readonly DateTimeZone $timezone,
        private readonly bool $technical,
        private readonly mixed $attachmentUrl,
    ) {
        $this->spool = fopen('php://temp/maxmemory:4194304', 'w+b');
    }

    public function add(Submission $submission): void
    {
        foreach (array_keys($submission->payload) as $key) {
            $this->keys[(string)$key] = true;
        }

        $attachments = array_map(
            fn(array $a) => $a['name'] . ' (' . ($this->attachmentUrl)($submission, $a) . ')',
            $submission->attachments,
        );

        fwrite($this->spool, json_encode([
            'meta' => [
                $submission->id,
                $submission->dateCreated ? (clone $submission->dateCreated)->setTimezone($this->timezone)->format(DATE_ATOM) : '',
                $submission->email ?? '',
                $submission->read ? 'yes' : 'no',
                $submission->spam ? 'yes' : 'no',
                $submission->trash ? 'yes' : 'no',
            ],
            'payload' => array_map(fn($v) => Submission::stringify($v), $submission->payload),
            'attachments' => implode(', ', $attachments),
            'technical' => [$submission->ip ?? '', $submission->userAgent ?? '', $submission->referrer ?? ''],
        ], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) . "\n");
        $this->count++;
    }

    public function count(): int
    {
        return $this->count;
    }

    /**
     * @return string[]
     */
    public function header(): array
    {
        return array_merge(
            self::META_COLUMNS,
            array_map(fn($key) => in_array($key, self::META_COLUMNS, true) ? "field: $key" : $key, array_keys($this->keys)),
            ['attachments'],
            $this->technical ? self::TECHNICAL_COLUMNS : [],
        );
    }

    /**
     * Writes the CSV (UTF-8 with a BOM, for Excel) to a stream, and closes the spool.
     *
     * @param resource $out
     */
    public function writeTo($out): void
    {
        fwrite($out, "\xEF\xBB\xBF");
        fputcsv($out, $this->header(), escape: '');

        rewind($this->spool);
        $keys = array_keys($this->keys);
        while (($line = fgets($this->spool)) !== false) {
            $row = json_decode($line, true);
            $cells = $row['meta'];
            foreach ($keys as $key) {
                $cells[] = $row['payload'][$key] ?? '';
            }
            $cells[] = $row['attachments'];
            if ($this->technical) {
                array_push($cells, ...$row['technical']);
            }
            fputcsv($out, array_map([self::class, 'safeCell'], $cells), escape: '');
        }
        fclose($this->spool);
    }

    /**
     * Stops spreadsheet apps treating submitted text as a formula ("CSV
     * injection"): values starting with = + - @ or a tab/carriage return
     * get a leading apostrophe.
     */
    public static function safeCell(mixed $value): string
    {
        $value = (string)$value;
        return $value !== '' && strpbrk($value[0], "=+-@\t\r") !== false ? "'" . $value : $value;
    }

    /**
     * A safe file name for an export.
     */
    public static function filename(string $formName, string $tab, string $date): string
    {
        $slug = trim(preg_replace('/[^a-z0-9]+/', '-', strtolower($formName)), '-') ?: 'form';
        return sprintf('%s-%s-%s.csv', $slug, $tab, $date);
    }
}
