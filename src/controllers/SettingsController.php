<?php

namespace heano\basin\controllers;

use Craft;
use craft\helpers\ProjectConfig as ProjectConfigHelper;
use craft\web\Controller;
use heano\basin\models\Project;
use heano\basin\models\Settings;
use heano\basin\Plugin;
use yii\web\Response;

/**
 * Plugin settings screen (spec §5).
 */
class SettingsController extends Controller
{
    public function beforeAction($action): bool
    {
        if (!parent::beforeAction($action)) {
            return false;
        }
        // Viewing is allowed for admins on any environment; saving needs allowAdminChanges.
        $this->requireAdmin(false);
        return true;
    }

    public function actionIndex(?Settings $settings = null): Response
    {
        $plugin = Plugin::getInstance();
        $settings ??= $plugin->getSettings();

        $projects = [];
        $connectionError = null;
        if ($settings->getApiKey() !== '') {
            $result = $plugin->getApi()->testConnection();
            $projects = $result['projects'];
            if (!$result['success']) {
                $connectionError = $result['message'];
            }
        }

        $linkedProject = null;
        $projectId = $settings->getProjectId();
        foreach ($projects as $project) {
            if ($project->id === $projectId) {
                $linkedProject = $project;
            }
        }

        return $this->renderTemplate('basin/settings/index', [
            'plugin' => $plugin,
            'settings' => $settings,
            'overrides' => $plugin->getConfigOverrides(),
            'readOnly' => !Craft::$app->getConfig()->getGeneral()->allowAdminChanges,
            'projects' => $projects,
            'projectSuggestions' => $this->projectSuggestions($projects),
            'linkedProject' => $linkedProject,
            'connectionError' => $connectionError,
        ]);
    }

    public function actionSave(): ?Response
    {
        $this->requirePostRequest();
        $this->requireAdmin();

        $plugin = Plugin::getInstance();
        $posted = (array)$this->request->getBodyParam('settings', []);

        // Start from what's stored in project config (not the config-file-merged
        // values), and only apply posted values for settings that aren't
        // overridden in config/basin.php. Otherwise overrides would be baked
        // into project config.
        $stored = ProjectConfigHelper::unpackAssociativeArrays(
            (array)(Craft::$app->getProjectConfig()->get("plugins.$plugin->handle.settings") ?? [])
        );
        $overrides = $plugin->getConfigOverrides();
        $incoming = [
            'apiKey' => (string)($posted['apiKey'] ?? ''),
            'projectId' => (string)($posted['projectId'] ?? ''),
            'cacheDuration' => (int)($posted['cacheDuration'] ?? 300),
            'allowDeletion' => (bool)($posted['allowDeletion'] ?? false),
            'trackMissingForms' => (bool)($posted['trackMissingForms'] ?? false),
            'autoProtect' => (bool)($posted['autoProtect'] ?? false),
            'recaptchaVersion' => (string)($posted['recaptchaVersion'] ?? 'v3'),
            'navLabel' => (string)($posted['navLabel'] ?? ''),
            'defaultNotificationEmail' => (string)($posted['defaultNotificationEmail'] ?? ''),
            'showUnreadBadge' => (bool)($posted['showUnreadBadge'] ?? false),
        ];
        foreach ($overrides as $name) {
            unset($incoming[$name]);
        }

        $settings = new Settings();
        $settings->setAttributes(array_intersect_key($stored, $settings->getAttributes()), false);
        $settings->setAttributes($incoming, false);

        if (!$settings->validate()) {
            return $this->asModelFailure($settings, Craft::t('basin', 'Couldn’t save settings.'), 'settings');
        }

        if (!Craft::$app->getPlugins()->savePluginSettings($plugin, $settings->toArray())) {
            return $this->asModelFailure($settings, Craft::t('basin', 'Couldn’t save settings.'), 'settings');
        }

        $plugin->getApi()->invalidateCaches();

        return $this->asSuccess(Craft::t('basin', 'Settings saved.'));
    }

    /**
     * Tests an API key (the one typed into the form, or the saved one).
     */
    public function actionTestConnection(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        $apiKey = trim((string)$this->request->getBodyParam('apiKey', ''));

        if ($apiKey !== '') {
            // Only env var references are accepted, matching what can be saved.
            $check = new Settings(['apiKey' => $apiKey]);
            if (!$check->validate(['apiKey'])) {
                return $this->asJson(['success' => false, 'message' => $check->getFirstError('apiKey')]);
            }
            $apiKey = $check->getApiKey();
        }

        $result = Plugin::getInstance()->getApi()->testConnection($apiKey !== '' ? $apiKey : null);

        return $this->asJson([
            'success' => $result['success'],
            'message' => $result['message'],
            'projects' => array_map(fn(Project $p) => ['id' => $p->id, 'name' => $p->name], $result['projects']),
        ]);
    }

    /**
     * Autosuggest data: Basin projects as `id` with the name as hint.
     *
     * @param Project[] $projects
     */
    private function projectSuggestions(array $projects): array
    {
        if (!$projects) {
            return [];
        }
        return [[
            'label' => Craft::t('basin', 'Basin projects'),
            'data' => array_map(fn(Project $p) => ['name' => (string)$p->id, 'hint' => $p->name], $projects),
        ]];
    }
}
