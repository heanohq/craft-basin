<?php

namespace heano\basin\jobs;

use Craft;
use craft\queue\BaseJob;
use heano\basin\Plugin;

/**
 * Builds a large CSV export in the background (spec §8).
 */
class ExportSubmissions extends BaseJob
{
    public string $token = '';
    public array $criteria = [];
    public bool $technical = false;

    public function execute($queue): void
    {
        Plugin::getInstance()->getExports()->run($this->token, $this->criteria, $this->technical, function(float $progress) use ($queue) {
            $this->setProgress($queue, $progress);
        });
    }

    protected function defaultDescription(): ?string
    {
        return Craft::t('basin', 'Exporting Basin submissions');
    }
}
