<?php

namespace heano\basin\controllers;

use craft\web\Controller;
use heano\basin\api\exceptions\ApiException;
use heano\basin\Plugin;
use yii\web\Response;

/**
 * Landing page for the plugin's CP section. Replaced by the Forms index in
 * milestone 3; for now it shows connection status and the linked project.
 */
class OverviewController extends Controller
{
    public function actionIndex(): Response
    {
        $this->requirePermission('accessPlugin-basin');

        $plugin = Plugin::getInstance();
        $settings = $plugin->getSettings();
        $project = null;
        $error = null;

        if ($settings->getApiKey() !== '' && $settings->getProjectId() !== null) {
            try {
                $project = $plugin->getApi()->getLinkedProject();
            } catch (ApiException $e) {
                $error = $e->getMessage();
            }
        }

        return $this->renderTemplate('basin/_overview', [
            'settings' => $settings,
            'project' => $project,
            'error' => $error,
        ]);
    }
}
