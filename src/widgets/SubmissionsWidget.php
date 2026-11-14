<?php

namespace heano\basin\widgets;

use Craft;
use craft\base\Widget;
use Heano\Basin\Exception\ApiException;
use heano\basin\Plugin;

/**
 * Basin Submissions dashboard widget (spec §14).
 */
class SubmissionsWidget extends Widget
{
    /** Form UUID, or empty for every form in the project. */
    public string $formUuid = '';

    public int $limit = 5;

    public static function displayName(): string
    {
        return Craft::t('basin', 'Basin Submissions');
    }

    public static function icon(): ?string
    {
        return Craft::getAlias('@heano/basin/icon-mask.svg');
    }

    public static function isSelectable(): bool
    {
        return Craft::$app->getUser()->checkPermission('accessPlugin-basin');
    }

    protected function defineRules(): array
    {
        $rules = parent::defineRules();
        $rules[] = ['limit', 'in', 'range' => [5, 10, 20]];
        $rules[] = ['formUuid', 'match', 'pattern' => '/^[A-Za-z0-9]{0,40}$/'];
        return $rules;
    }

    public function getTitle(): ?string
    {
        $form = $this->form();
        return $form ? Craft::t('basin', 'Basin: {name}', ['name' => $form->name]) : Craft::t('basin', 'Basin Submissions');
    }

    public function getSettingsHtml(): ?string
    {
        $forms = [];
        try {
            $forms = Plugin::getInstance()->getForms()->isConfigured() ? Plugin::getInstance()->getForms()->getForms() : [];
        } catch (ApiException) {
        }
        return Craft::$app->getView()->renderTemplate('basin/_widgets/settings', [
            'widget' => $this,
            'basinForms' => $forms,
        ]);
    }

    public function getBodyHtml(): ?string
    {
        $view = Craft::$app->getView();
        if (!Craft::$app->getUser()->checkPermission('basin-viewSubmissions')) {
            return $view->renderTemplate('basin/_widgets/body', ['message' => Craft::t('basin', 'You don’t have permission to view Basin submissions.')]);
        }

        $plugin = Plugin::getInstance();
        if (!$plugin->getForms()->isConfigured()) {
            return $view->renderTemplate('basin/_widgets/body', ['message' => Craft::t('basin', 'Basin isn’t connected yet.')]);
        }

        $form = null;
        if ($this->formUuid !== '') {
            $form = $this->form();
            if ($form === null) {
                return $view->renderTemplate('basin/_widgets/body', ['message' => Craft::t('basin', 'The form chosen for this widget isn’t in the linked Basin project any more.')]);
            }
        }

        try {
            $recent = $plugin->getSubmissions()->getRecent($form);
        } catch (ApiException $e) {
            return $view->renderTemplate('basin/_widgets/body', ['message' => Craft::t('basin', 'Couldn’t reach Basin: {error}', ['error' => $e->getMessage()])]);
        }

        return $view->renderTemplate('basin/_widgets/body', [
            'widget' => $this,
            'form' => $form,
            'recent' => $recent,
            'submissions' => array_slice($recent['submissions'], 0, $this->limit),
            'timezones' => array_map(fn($f) => $f->getPhpTimezone(), $plugin->getForms()->getForms()),
        ]);
    }

    private function form(): ?\heano\basin\models\BasinForm
    {
        if ($this->formUuid === '') {
            return null;
        }
        try {
            return Plugin::getInstance()->getForms()->getFormByUuid($this->formUuid);
        } catch (ApiException) {
            return null;
        }
    }
}
