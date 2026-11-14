<?php

namespace heano\basin\controllers;

use Craft;
use craft\web\Controller;
use Heano\Basin\Exception\ApiException;
use heano\basin\models\BasinForm;
use heano\basin\Plugin;
use yii\web\NotFoundHttpException;

/**
 * Shared set-up for Basin's CP screens.
 */
abstract class BaseCpController extends Controller
{
    public function beforeAction($action): bool
    {
        if (!parent::beforeAction($action)) {
            return false;
        }
        $this->requirePermission('accessPlugin-basin');
        return true;
    }

    /**
     * Variables every Basin screen needs, including which empty state applies.
     */
    protected function baseVariables(): array
    {
        $plugin = Plugin::getInstance();
        $settings = $plugin->getSettings();

        $state = match (true) {
            $settings->getApiKey() === '' => 'noKey',
            $settings->getProjectId() === null => 'noProject',
            default => 'ready',
        };

        $project = null;
        $error = null;
        if ($state === 'ready') {
            try {
                $project = $plugin->getApi()->getLinkedProject();
                if ($project === null) {
                    $state = 'projectMissing';
                }
            } catch (ApiException $e) {
                // The forms list may still be available from the last known copy.
                $error = $e->getMessage();
            }
        }

        $issues = [];
        if ($state === 'ready') {
            $forms = $plugin->getForms();
            try {
                $issues = $plugin->getFormIssues()->getActive($forms->getForms(), !$forms->isStale);
            } catch (ApiException) {
            }
        }

        return [
            'settings' => $settings,
            'state' => $state,
            'issues' => $issues,
            'project' => $project,
            'error' => $error,
            'canViewCounts' => Craft::$app->getUser()->checkPermission('basin-viewSubmissions'),
        ];
    }


    /**
     * A form in the linked project, or a 404.
     */
    protected function requireForm(string $uuid): BasinForm
    {
        try {
            $form = Plugin::getInstance()->getForms()->getFormByUuid($uuid);
        } catch (ApiException) {
            $form = null;
        }
        if (!$form) {
            throw new NotFoundHttpException(Craft::t('basin', 'There’s no form “{uuid}” in the linked Basin project. It may have been deleted or moved to another project.', ['uuid' => $uuid]));
        }
        return $form;
    }
}
