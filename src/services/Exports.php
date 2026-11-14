<?php

namespace heano\basin\services;

use Craft;
use craft\base\Component;
use craft\helpers\FileHelper;
use craft\helpers\Queue;
use craft\helpers\UrlHelper;
use DateTimeZone;
use Heano\Basin\Exception\ApiException;
use heano\basin\helpers\CsvExport;
use heano\basin\jobs\ExportSubmissions;
use heano\basin\models\BasinForm;
use heano\basin\models\Submission;
use heano\basin\Plugin;
use Throwable;

/**
 * CSV exports of a form's submissions (spec §8).
 *
 * Small exports are built in the request. Larger ones run in the queue and
 * are saved to `storage/runtime/basin/exports/`, where only the user who
 * asked for them can download them. Files are deleted once downloaded, or
 * after 24 hours.
 */
class Exports extends Component
{
    /** Exports with up to this many rows are built in the request. */
    public const SYNC_MAX_ROWS = 1000;

    /** A safety limit: 500 pages of 100. Exports stop here and say so. */
    public const MAX_PAGES = 500;
    public const MAX_ROWS = self::MAX_PAGES * 100;

    /** How long a finished export is kept. */
    public const TTL = 86400;

    /**
     * Writes a CSV of the submissions matching the criteria to a stream.
     *
     * @param resource $out
     * @param callable(int $done, ?int $total): void|null $progress
     * @return array{rows: int, total: ?int, truncated: bool} Rows written, how many matched, and whether the row limit was hit
     * @throws ApiException
     */
    public function build(BasinForm $form, array $criteria, bool $technical, $out, ?callable $progress = null): array
    {
        $csv = new CsvExport(
            new DateTimeZone($form->getPhpTimezone()),
            $technical,
            fn(Submission $s, array $a) => UrlHelper::cpUrl("basin/forms/$form->uuid/submissions/$s->id/attachments/{$a['id']}"),
        );

        // Not through the cached list: an export shouldn't fill the cache.
        $client = Plugin::getInstance()->getApi()->getClient();
        $apiCriteria = Submissions::apiCriteria($form, $criteria);
        $previousIds = null;
        $total = null;
        $truncated = false;
        for ($page = 1; $page <= self::MAX_PAGES; $page++) {
            $result = $client->listSubmissions($apiCriteria, $page);
            $total ??= $result->total;
            $ids = array_column($result->items, 'id');
            if (!$ids || $ids === $previousIds) {
                break;
            }
            foreach ($result->items as $row) {
                // The key can read other projects, so only this form's rows.
                if ((int)($row['form_id'] ?? 0) === $form->id) {
                    $csv->add(Submission::fromApi($row));
                }
            }
            if ($progress) {
                $progress($csv->count(), $result->total);
            }
            if (!$result->hasMore()) {
                break;
            }
            if ($page === self::MAX_PAGES) {
                $truncated = true;
            }
            $previousIds = $ids;
        }

        $csv->writeTo($out);
        return ['rows' => $csv->count(), 'total' => $total, 'truncated' => $truncated];
    }

    /**
     * Queues an export for a user.
     *
     * @return string The export's token
     */
    public function queue(BasinForm $form, array $criteria, bool $technical, int $userId): string
    {
        $token = bin2hex(random_bytes(16));
        $this->writeMeta($token, [
            'token' => $token,
            'userId' => $userId,
            'formUuid' => $form->uuid,
            'formName' => $form->name,
            'filename' => CsvExport::filename($form->name, $criteria['tab'] ?? 'inbox', date('Y-m-d')),
            'status' => 'pending',
            'progress' => 0,
            'rows' => null,
            'total' => null,
            'truncated' => false,
            'error' => null,
            'created' => time(),
        ]);
        Queue::push(new ExportSubmissions([
            'token' => $token,
            'criteria' => $criteria,
            'technical' => $technical,
        ]));
        return $token;
    }

    /**
     * Runs a queued export.
     *
     * @param callable(float $progress): void|null $progress
     */
    public function run(string $token, array $criteria, bool $technical, ?callable $progress = null): void
    {
        $meta = $this->getMeta($token) ?? throw new \RuntimeException('The export was cancelled or has expired.');
        try {
            $form = Plugin::getInstance()->getForms()->getFormByUuid($meta['formUuid'])
                ?? throw new \RuntimeException('The form is no longer in the linked Basin project.');
            $out = fopen($this->path($token, 'csv'), 'wb');
            $built = $this->build($form, $criteria, $technical, $out, function(int $done, ?int $total) use ($token, $progress) {
                $fraction = $total ? min(1, $done / $total) : 0;
                $this->updateMeta($token, ['progress' => $fraction]);
                if ($progress) {
                    $progress($fraction);
                }
            });
            fclose($out);
            $this->updateMeta($token, ['status' => 'ready', 'progress' => 1] + $built);
        } catch (Throwable $e) {
            Craft::warning("Basin export $token failed: " . $e->getMessage(), 'basin');
            @unlink($this->path($token, 'csv'));
            $this->updateMeta($token, ['status' => 'failed', 'error' => $e->getMessage()]);
        }
    }

    /**
     * An export, if it exists, belongs to the user and hasn't expired.
     */
    public function getForUser(string $token, int $userId): ?array
    {
        $meta = $this->getMeta($token);
        return $meta && (int)$meta['userId'] === $userId && !$this->expired($meta) ? $meta : null;
    }

    /**
     * A user's exports, newest first.
     *
     * @return array<int, array<string, mixed>>
     */
    public function listForUser(int $userId): array
    {
        $exports = [];
        foreach (glob($this->dir() . '/*.json') ?: [] as $file) {
            $meta = json_decode((string)file_get_contents($file), true);
            if (is_array($meta) && (int)($meta['userId'] ?? 0) === $userId && !$this->expired($meta)) {
                $exports[] = $meta;
            }
        }
        usort($exports, fn($a, $b) => $b['created'] <=> $a['created']);
        return $exports;
    }

    public function csvPath(string $token): string
    {
        return $this->path($token, 'csv');
    }

    public function delete(string $token): void
    {
        @unlink($this->path($token, 'csv'));
        @unlink($this->path($token, 'json'));
    }

    /**
     * Deletes expired exports. Run by Craft's garbage collection.
     */
    public function gc(): void
    {
        foreach (glob($this->dir() . '/*.json') ?: [] as $file) {
            $meta = json_decode((string)file_get_contents($file), true);
            if (!is_array($meta) || $this->expired($meta)) {
                $this->delete(basename($file, '.json'));
            }
        }
        // CSVs whose metadata has gone.
        foreach (glob($this->dir() . '/*.csv') ?: [] as $file) {
            if (!is_file(substr($file, 0, -4) . '.json')) {
                @unlink($file);
            }
        }
    }

    private function expired(array $meta): bool
    {
        return (int)($meta['created'] ?? 0) < time() - self::TTL;
    }

    private function getMeta(string $token): ?array
    {
        $file = $this->path($token, 'json');
        if (!is_file($file)) {
            return null;
        }
        $meta = json_decode((string)file_get_contents($file), true);
        return is_array($meta) ? $meta : null;
    }

    private function writeMeta(string $token, array $meta): void
    {
        file_put_contents($this->path($token, 'json'), json_encode($meta), LOCK_EX);
    }

    private function updateMeta(string $token, array $changes): void
    {
        if (($meta = $this->getMeta($token)) !== null) {
            $this->writeMeta($token, array_merge($meta, $changes));
        }
    }

    private function path(string $token, string $ext): string
    {
        if (!preg_match('/^[a-f0-9]{32}$/', $token)) {
            throw new \InvalidArgumentException('Invalid export token.');
        }
        return $this->dir() . "/$token.$ext";
    }

    private function dir(): string
    {
        $dir = Craft::$app->getPath()->getRuntimePath() . '/basin/exports';
        FileHelper::createDirectory($dir);
        return $dir;
    }
}
