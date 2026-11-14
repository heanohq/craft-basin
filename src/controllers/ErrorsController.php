<?php

namespace heano\basin\controllers;

use Craft;
use heano\basin\Plugin;
use yii\web\Response;

/**
 * The error log (spec §13): forms this site uses but can't show.
 */
class ErrorsController extends BaseCpController
{
    public function actionIndex(): Response
    {
        return $this->renderTemplate('basin/errors/_index', $this->baseVariables() + [
            'staleSince' => Plugin::getInstance()->getForms()->getStaleSince(),
        ]);
    }

    /**
     * Removes an entry. It comes back if the problem happens again.
     */
    public function actionDismiss(): ?Response
    {
        $this->requirePostRequest();
        Plugin::getInstance()->getFormIssues()->dismiss((int)$this->request->getRequiredBodyParam('id'));
        return $this->asSuccess(Craft::t('basin', 'Entry dismissed.'));
    }
}
