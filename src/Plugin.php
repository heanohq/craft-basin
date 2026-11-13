<?php

namespace heano\basin;

use Craft;
use craft\base\Model;
use craft\base\Plugin as BasePlugin;
use craft\events\RegisterCacheOptionsEvent;
use craft\events\RegisterUrlRulesEvent;
use craft\helpers\UrlHelper;
use craft\utilities\ClearCaches;
use craft\web\UrlManager;
use heano\basin\models\Settings;
use heano\basin\services\Api;
use yii\base\Event;

/**
 * Basin for Craft CMS.
 *
 * @property-read Api $api
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
            ],
        ];
    }

    public function init(): void
    {
        parent::init();

        if (Craft::$app->getRequest()->getIsCpRequest()) {
            $this->registerCpRoutes();
        }

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

    public function getCpNavItem(): ?array
    {
        $item = parent::getCpNavItem();
        $item['label'] = $this->getSettings()->getNavLabel();

        $subNav = [
            'overview' => ['label' => Craft::t('basin', 'Overview'), 'url' => 'basin'],
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

    protected function createSettingsModel(): ?Model
    {
        return new Settings();
    }

    private function registerCpRoutes(): void
    {
        Event::on(UrlManager::class, UrlManager::EVENT_REGISTER_CP_URL_RULES, function(RegisterUrlRulesEvent $event) {
            $event->rules['basin'] = 'basin/overview/index';
            $event->rules['basin/settings'] = 'basin/settings/index';
        });
    }
}
