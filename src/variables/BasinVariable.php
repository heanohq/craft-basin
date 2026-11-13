<?php

namespace heano\basin\variables;

use heano\basin\api\exceptions\ApiException;
use heano\basin\models\BasinForm;
use heano\basin\Plugin;
use heano\basin\services\FormIssues;

/**
 * `craft.basin` (spec §10.1).
 */
class BasinVariable
{
    /**
     * A form in the linked project, or null. Always wrap the result in
     * `{% if form %}`: a form can be deleted or moved in Basin at any time.
     */
    public function form(?string $uuid): ?BasinForm
    {
        return Plugin::getInstance()->getForms()->lookup($uuid, FormIssues::SOURCE_TWIG);
    }

    /**
     * Every form in the linked project, sorted by name.
     *
     * @return BasinForm[]
     */
    public function forms(): array
    {
        $forms = Plugin::getInstance()->getForms();
        if (!$forms->isConfigured()) {
            return [];
        }
        try {
            return array_values($forms->getForms());
        } catch (ApiException $e) {
            \Craft::warning('Couldn’t list Basin forms: ' . $e->getMessage(), 'basin');
            return [];
        }
    }
}
