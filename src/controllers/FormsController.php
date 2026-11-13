<?php

namespace heano\basin\controllers;

use Craft;
use heano\basin\api\exceptions\ApiException;
use heano\basin\helpers\StudioEmbed;
use heano\basin\models\BasinForm;
use heano\basin\Plugin;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * Forms index and form detail (spec §6.4, §6.5), plus saving Form Studio
 * embed codes (spec §10.3).
 */
class FormsController extends BaseCpController
{
    public function actionIndex(): Response
    {
        $variables = $this->baseVariables();
        $variables['basinForms'] = [];

        if ($variables['state'] === 'ready') {
            try {
                $variables['basinForms'] = Plugin::getInstance()->getForms()->getForms();
                // Only cached checks here; each form page refreshes its own.
                foreach ($variables['basinForms'] as $form) {
                    if ($form->hostedFormId !== null && Plugin::getInstance()->getForms()->peekStudioScript($form->uuid, $form->hostedFormId) === false) {
                        $variables['brokenStudio'][$form->uuid] = true;
                    }
                }
            } catch (ApiException $e) {
                $variables['error'] = $e->getMessage();
            }
        }

        return $this->renderTemplate('basin/forms/_index', $variables + [
            'brokenStudio' => [],
            'staleSince' => Plugin::getInstance()->getForms()->getStaleSince(),
        ]);
    }

    public function actionView(string $uuid, ?string $embedInput = null): Response
    {
        $plugin = Plugin::getInstance();
        $variables = $this->baseVariables();
        if ($variables['state'] !== 'ready') {
            return $this->redirect('basin/forms');
        }

        try {
            $form = $plugin->getForms()->getFormByUuid($uuid);
        } catch (ApiException $e) {
            return $this->renderTemplate('basin/forms/_index', $variables + ['basinForms' => [], 'error' => $e->getMessage(), 'staleSince' => null]);
        }
        if (!$form) {
            throw new NotFoundHttpException(Craft::t('basin', 'There’s no form “{uuid}” in the linked Basin project. It may have been deleted or moved to another project.', ['uuid' => $uuid]));
        }

        $studioStatus = null;
        if ($form->hostedFormId !== null) {
            $ok = $plugin->getForms()->checkStudioScript($form->uuid, $form->hostedFormId);
            $studioStatus = match ($ok) {
                true => 'ok',
                false => 'broken',
                null => 'unknown',
            };
        }

        return $this->renderTemplate('basin/forms/_view', $variables + [
            'form' => $form,
            'studioStatus' => $studioStatus,
            'embedInput' => $embedInput,
            'canEditStudio' => $this->canEditStudio(),
            'staleSince' => $plugin->getForms()->getStaleSince(),
        ]);
    }

    public function actionSaveStudioEmbed(): ?Response
    {
        $this->requirePostRequest();
        $this->requireAdmin();

        $plugin = Plugin::getInstance();
        $form = $this->requireForm((string)$this->request->getRequiredBodyParam('formUuid'));
        $input = (string)$this->request->getBodyParam('embed', '');
        $fail = fn(string $message) => $this->asFailure($message, routeParams: ['embedInput' => $input]);

        if (!$this->canEditStudio()) {
            return $fail(Craft::t('basin', 'Form Studio embed codes are being set in `config/basin.php`.'));
        }

        $parsed = StudioEmbed::parse($input);
        if ($parsed === null) {
            return $fail(Craft::t('basin', 'That doesn’t look like a Basin Form Studio embed code. Copy it from Form Studio’s Share → Embed options.'));
        }
        if ($parsed['formUuid'] !== strtolower($form->uuid)) {
            return $fail(Craft::t('basin', 'This embed code is for a different Basin form ({uuid}).', ['uuid' => $parsed['formUuid']]));
        }

        $ok = $plugin->getForms()->checkStudioScript($form->uuid, $parsed['hostedId'], refresh: true);
        if ($ok === false) {
            return $fail(Craft::t('basin', 'Basin doesn’t recognise this embed code. Make sure the form is published in Form Studio, then copy the code again.'));
        }
        if ($ok === null) {
            return $fail(Craft::t('basin', 'Couldn’t reach Basin to check the embed code. Try again in a moment.'));
        }

        $plugin->getForms()->saveHostedFormId($form->uuid, $parsed['hostedId']);

        return $this->asSuccess(Craft::t('basin', 'Embed code saved.'));
    }

    public function actionRemoveStudioEmbed(): ?Response
    {
        $this->requirePostRequest();
        $this->requireAdmin();

        $form = $this->requireForm((string)$this->request->getRequiredBodyParam('formUuid'));
        if (!$this->canEditStudio()) {
            return $this->asFailure(Craft::t('basin', 'Form Studio embed codes are being set in `config/basin.php`.'));
        }

        Plugin::getInstance()->getForms()->saveHostedFormId($form->uuid, null);

        return $this->asSuccess(Craft::t('basin', 'Embed code removed.'));
    }

    private function canEditStudio(): bool
    {
        return Craft::$app->getUser()->getIsAdmin()
            && Craft::$app->getConfig()->getGeneral()->allowAdminChanges
            && !in_array('studioForms', Plugin::getInstance()->getConfigOverrides(), true);
    }

    /**
     * Dismisses a missing-form or missing-embed warning. It comes back if the
     * form is requested again.
     */
    public function actionDismissIssue(): ?Response
    {
        $this->requirePostRequest();
        Plugin::getInstance()->getFormIssues()->dismiss((int)$this->request->getRequiredBodyParam('id'));
        return $this->asSuccess(Craft::t('basin', 'Warning dismissed.'));
    }
}
