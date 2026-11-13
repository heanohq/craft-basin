<?php

namespace heano\basin\jobs;

use Craft;
use craft\queue\BaseJob;
use heano\basin\Plugin;

/**
 * Runs a triage action or deletion on many submissions (spec §7.3).
 *
 * Permissions are checked when the job is queued. Each submission is checked
 * against the form again when it's processed.
 */
class BulkSubmissionAction extends BaseJob
{
    public string $formUuid;

    /** A key of Submissions::ACTIONS, or 'delete'. */
    public string $action;

    /** @var int[] */
    public array $ids = [];

    public function execute($queue): void
    {
        $plugin = Plugin::getInstance();
        $form = $plugin->getForms()->getFormByUuid($this->formUuid);
        if (!$form) {
            throw new \RuntimeException("Basin form $this->formUuid isn’t in the linked project.");
        }

        $done = 0;
        $failed = 0;
        $total = count($this->ids);
        foreach (array_chunk($this->ids, 10) as $i => $chunk) {
            $this->setProgress($queue, ($i * 10) / max($total, 1), Craft::t('basin', '{done} of {total}', ['done' => $i * 10, 'total' => $total]));
            $result = $this->action === 'delete'
                ? $plugin->getSubmissions()->delete($form, $chunk)
                : $plugin->getSubmissions()->applyAction($form, $chunk, $this->action);
            $done += $result['done'];
            $failed += $result['failed'];
        }

        if ($failed) {
            throw new \RuntimeException("$failed of $total Basin submissions couldn’t be updated ($done succeeded).");
        }
    }

    protected function defaultDescription(): ?string
    {
        return Craft::t('basin', 'Updating {count} Basin submissions', ['count' => count($this->ids)]);
    }
}
