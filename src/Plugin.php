<?php

namespace heano\basin;

use Craft;
use craft\base\Model;
use craft\base\Plugin as BasePlugin;
use craft\events\RegisterCacheOptionsEvent;
use craft\events\RegisterUrlRulesEvent;
use craft\events\RegisterUserPermissionsEvent;
use craft\services\UserPermissions;
use craft\helpers\UrlHelper;
use craft\utilities\ClearCaches;
use craft\web\UrlManager;
use heano\basin\models\Settings;
use heano\basin\services\Api;
use heano\basin\services\Forms;
use yii\base\Event;

/**
 * Basin for Craft CMS.
 *
 * @property-read Api $api
 * @property-read Forms $forms
 * @method Settings getSettings()
 * @method static Plugin getInstance()
 */
class Plugin extends BasePlugin
{
    public string $schemaVersion = '1.0.0';
    public bool $hasCpSettings = true;
    public bool $hasCpSection = true;

    public static function config(): array
    {
        return [
            'components' => [
                'api' => Api::class,
                'forms' => Forms::class,
            ],
        ];
    }

    public function init(): void
    {
        parent::init();

        if (Craft::$app->getRequest()->getIsCpRequest()) {
            $this->registerCpRoutes();
        }

        Event::on(UserPermissions::class, UserPermissions::EVENT_REGISTER_PERMISSIONS, function(RegisterUserPermissionsEvent $event) {
            $event->permissions[] = [
                'heading' => Craft::t('basin', 'Basin'),
                'permissions' => self::permissions($this->getSettings()->allowDeletion),
            ];
        });

        Event::on(ClearCaches::class, ClearCaches::EVENT_REGISTER_TAG_OPTIONS, function(RegisterCacheOptionsEvent $event) {
            $event->options[] = [
                'tag' => Api::CACHE_TAG,
                'label' => Craft::t('basin', 'Basin data'),
            ];
        });
    }

    public function getApi(): Api
    {
        return $this->get('api');
    }

    public function getForms(): Forms
    {
        return $this->get('forms');
    }

    public function getCpNavItem(): ?array
    {
        $item = parent::getCpNavItem();
        $item['label'] = $this->getSettings()->getNavLabel();

        $subNav = [
            'forms' => ['label' => Craft::t('basin', 'Forms'), 'url' => 'basin/forms'],
        ];
        if (Craft::$app->getUser()->getIsAdmin()) {
            $subNav['settings'] = ['label' => Craft::t('basin', 'Settings'), 'url' => 'basin/settings'];
        }
        $item['subnav'] = $subNav;

        return $item;
    }

    public function getSettingsResponse(): mixed
    {
        return Craft::$app->getResponse()->redirect(UrlHelper::cpUrl('basin/settings'));
    }

    /**
     * Setting names currently overridden by config/basin.php.
     *
     * @return string[]
     */
    public function getConfigOverrides(): array
    {
        return array_keys(Craft::$app->getConfig()->getConfigFromFile($this->handle));
    }

    /**
     * The plugin's permissions (spec §9). `accessPlugin-basin` is registered by Craft.
     */
    public static function permissions(bool $allowDeletion): array
    {
        $nested = [
            'basin-manageSubmissions' => ['label' => Craft::t('basin', 'Manage submissions (mark as read, spam or trash)')],
            'basin-exportSubmissions' => ['label' => Craft::t('basin', 'Export submissions')],
        ];
        if ($allowDeletion) {
            $nested['basin-deleteSubmissions'] = ['label' => Craft::t('basin', 'Permanently delete submissions')];
        }

        return [
            'basin-viewSubmissions' => [
                'label' => Craft::t('basin', 'View submissions and submission counts'),
                'nested' => $nested,
            ],
        ];
    }

    protected function createSettingsModel(): ?Model
    {
        return new Settings();
    }

    private function registerCpRoutes(): void
    {
        Event::on(UrlManager::class, UrlManager::EVENT_REGISTER_CP_URL_RULES, function(RegisterUrlRulesEvent $event) {
            $event->rules['basin'] = 'basin/forms/index';
            $event->rules['basin/forms'] = 'basin/forms/index';
            $event->rules['basin/forms/<uuid:[A-Za-z0-9]+>'] = 'basin/forms/view';
            $event->rules['basin/settings'] = 'basin/settings/index';
        });
    }
}
