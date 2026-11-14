<?php

namespace heano\basin\variables;

use Craft;
use craft\helpers\Template;
use craft\helpers\UrlHelper;
use heano\basin\models\BasinForm;
use heano\basin\Plugin;
use heano\basin\services\FormIssues;
use Twig\Markup;

/**
 * `craft.basin` (spec §10.1).
 */
class BasinVariable
{
    /**
     * A form in the linked project, or null. Wrap your own markup in
     * `{% if form %}`: a form can be deleted or moved in Basin at any time.
     * For Form Studio embeds, {@see render()} does that for you.
     */
    public function form(?string $uuid): ?BasinForm
    {
        return Plugin::getInstance()->getForms()->lookup($uuid, FormIssues::SOURCE_TWIG);
    }

    /**
     * Outputs a form's Form Studio embed, given its Basin ID, the form, or a
     * Basin Form field's value. Handles a missing form itself, so no
     * `{% if form %}` is needed: an empty field outputs nothing, and a form
     * that isn't in the linked project outputs nothing and is recorded in
     * the error log.
     *
     * @param BasinForm|string|null $form
     * @param array{hiddenFields?: array<string, scalar|null>} $options See {@see BasinForm::render()}.
     */
    public function render(BasinForm|string|null $form, array $options = []): Markup
    {
        if (is_string($form) && trim($form) !== '') {
            $uuid = trim($form);
            $form = $this->form($uuid);
            if ($form === null) {
                return BasinForm::notice(
                    Craft::t('basin', 'The Basin form “{uuid}” isn’t in the linked Basin project, so it isn’t shown.', ['uuid' => $uuid]),
                    Craft::t('basin', 'See the error log'),
                    UrlHelper::cpUrl('basin/errors'),
                );
            }
        }
        return $form instanceof BasinForm ? $form->render($options) : Template::raw('');
    }

    /**
     * Every form in the linked project, sorted by name.
     *
     * @return BasinForm[]
     */
    public function forms(): array
    {
        return Plugin::getInstance()->getForms()->listForFrontEnd();
    }
}
