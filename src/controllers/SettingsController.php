<?php

namespace heano\basin\controllers;

use Craft;
use craft\helpers\App;
use craft\helpers\ProjectConfig as ProjectConfigHelper;
use craft\web\Controller;
use heano\basin\helpers\ProjectOptions;
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
            'projectOptions' => $this->projectOptions($projects, $this->savedProjectId($settings), $settings->getApiKey() !== '' && $connectionError === null),
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
            'newFormNotificationEmail' => (string)($posted['newFormNotificationEmail'] ?? ''),
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
        $projectId = trim((string)$this->request->getBodyParam('projectId', ''));

        return $this->asJson([
            'success' => $result['success'],
            'message' => $result['message'],
            'projects' => array_map(fn(Project $p) => ['id' => $p->id, 'name' => $p->name], $result['projects']),
            // The project dropdown's options for this key, so it can be refilled.
            'projectOptions' => $result['success'] ? $this->projectOptions($result['projects'], $projectId, true) : null,
        ]);
    }

    /**
     * The project value the settings page shows: the one in effect when
     * config/basin.php sets it, otherwise the saved (or just submitted) one.
     */
    private function savedProjectId(Settings $settings): string
    {
        $plugin = Plugin::getInstance();
        return trim((string)(in_array('projectId', $plugin->getConfigOverrides(), true)
            ? $plugin->getSettings()->projectId
            : $settings->projectId));
    }

    /**
     * The project dropdown's options, including the env vars that hold one
     * of the project IDs.
     *
     * @param Project[] $projects
     * @param string $current The chosen value, kept as an option
     * @param bool $loaded Whether Basin answered, so an empty list means no projects
     */
    private function projectOptions(array $projects, string $current, bool $loaded): array
    {
        $byId = [];
        foreach ($projects as $project) {
            $byId[$project->id] = $project->name;
        }

        // Craft's env var list skips numeric values, so these are found here.
        $env = [];
        foreach (array_keys($_SERVER) as $name) {
            if (is_string($name) && !str_starts_with($name, 'HTTP_') && (is_string($value = App::env($name)) || is_int($value))) {
                $env[$name] = (string)$value;
            }
        }

        return ProjectOptions::build($byId, $current, $env, [
            'choose' => match (true) {
                (bool)$byId => Craft::t('basin', 'Choose a project'),
                $loaded => Craft::t('basin', 'This Basin account has no projects'),
                default => Craft::t('basin', 'Enter your API key to load your projects'),
            },
            'unknown' => $loaded && $byId
                ? Craft::t('basin', 'Not found in your Basin account')
                : Craft::t('basin', 'Saved project'),
            'notSet' => Craft::t('basin', 'not set'),
            'envVars' => Craft::t('app', 'Environment Variables'),
        ]);
    }
}
