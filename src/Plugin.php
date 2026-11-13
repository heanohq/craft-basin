<?php

namespace heano\basin;

use Craft;
use craft\base\Model;
use craft\base\Plugin as BasePlugin;
use craft\events\RegisterCacheOptionsEvent;
use craft\events\RegisterComponentTypesEvent;
use craft\events\RegisterUrlRulesEvent;
use craft\events\RegisterUserPermissionsEvent;
use craft\services\Dashboard;
use craft\services\Fields;
use craft\services\Gc;
use craft\services\UserPermissions;
use craft\web\Response;
use craft\web\TemplateResponseFormatter;
use craft\web\twig\variables\CraftVariable;
use craft\helpers\UrlHelper;
use craft\utilities\ClearCaches;
use craft\web\UrlManager;
use heano\basin\fields\BasinFormField;
use heano\basin\models\Settings;
use heano\basin\services\Api;
use heano\basin\services\Exports;
use heano\basin\services\FormIssues;
use heano\basin\services\FormSettings;
use heano\basin\services\Forms;
use heano\basin\services\SpamProtection;
use heano\basin\services\Submissions;
use heano\basin\variables\BasinVariable;
use heano\basin\widgets\SubmissionsWidget;
use Throwable;
use yii\base\Event;

/**
 * Basin for Craft CMS.
 *
 * @property-read Api $api
 * @property-read Forms $forms
 * @property-read Submissions $submissions
 * @property-read FormIssues $formIssues
 * @property-read SpamProtection $spamProtection
 * @property-read Exports $exports
 * @method Settings getSettings()
 * @method static Plugin getInstance()
 */
class Plugin extends BasePlugin
{
    public const PERMISSION_MANAGE_FORMS = 'basin-manageForms';

    public string $schemaVersion = '1.0.2';
    public bool $hasCpSettings = true;
    public bool $hasCpSection = true;

    public static function config(): array
    {
        return [
            'components' => [
                'api' => Api::class,
                'forms' => Forms::class,
                'formSettings' => FormSettings::class,
                'submissions' => Submissions::class,
                'formIssues' => FormIssues::class,
                'spamProtection' => SpamProtection::class,
                'exports' => Exports::class,
            ],
        ];
    }

    public function init(): void
    {
        parent::init();

        $request = Craft::$app->getRequest();
        if ($request->getIsCpRequest()) {
            $this->registerCpRoutes();
        } elseif ($request->getIsSiteRequest()) {
            $this->registerSpamProtection();
        }

        Event::on(CraftVariable::class, CraftVariable::EVENT_INIT, function(Event $event) {
            $event->sender->set('basin', BasinVariable::class);
        });

        // Expired CSV exports are deleted with Craft's garbage collection (spec §8).
        Event::on(Dashboard::class, Dashboard::EVENT_REGISTER_WIDGET_TYPES, function(RegisterComponentTypesEvent $event) {
            $event->types[] = SubmissionsWidget::class;
        });

        Event::on(Gc::class, Gc::EVENT_RUN, function() {
            $this->getExports()->gc();
            $this->getFormSettings()->gc();
        });

        Event::on(Fields::class, Fields::EVENT_REGISTER_FIELD_TYPES, function(RegisterComponentTypesEvent $event) {
            $event->types[] = BasinFormField::class;
        });

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

    public function getFormSettings(): FormSettings
    {
        return $this->get('formSettings');
    }

    public function getSubmissions(): Submissions
    {
        return $this->get('submissions');
    }

    public function getFormIssues(): FormIssues
    {
        return $this->get('formIssues');
    }

    public function getSpamProtection(): SpamProtection
    {
        return $this->get('spamProtection');
    }

    public function getExports(): Exports
    {
        return $this->get('exports');
    }

    public function getCpNavItem(): ?array
    {
        $item = parent::getCpNavItem();
        $item['label'] = $this->getSettings()->getNavLabel();

        $subNav = [
            'forms' => ['label' => Craft::t('basin', 'Forms'), 'url' => 'basin/forms'],
        ];
        if (Craft::$app->getUser()->checkPermission('basin-viewSubmissions')) {
            $subNav['submissions'] = ['label' => Craft::t('basin', 'Submissions'), 'url' => 'basin/submissions'];
            // Unread count (spec §15). Never lets Basin break the CP nav.
            $unread = null;
            if ($this->getSettings()->showUnreadBadge) {
                try {
                    $unread = $this->getSubmissions()->getUnreadCount();
                } catch (Throwable) {
                }
            }
            if ($unread) {
                $item['badgeCount'] = $unread;
                $subNav['submissions']['badgeCount'] = $unread;
            }
        }
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
        $manageForms = [
            self::PERMISSION_MANAGE_FORMS => ['label' => Craft::t('basin', 'Manage form settings (Form Studio embed codes, reCAPTCHA version)')],
        ];
        $nested = [
            'basin-manageSubmissions' => ['label' => Craft::t('basin', 'Manage submissions (mark as read, spam or trash)')],
            'basin-exportSubmissions' => ['label' => Craft::t('basin', 'Export submissions')],
        ];
        if ($allowDeletion) {
            $nested['basin-deleteSubmissions'] = ['label' => Craft::t('basin', 'Permanently delete submissions')];
        }

        return $manageForms + [
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

    /**
     * Adds each form's required spam protection to front-end HTML pages after
     * they're rendered, so it works however the form got into the page,
     * including `{% cache %}` and hard-coded markup (spec §10.4).
     */
    private function registerSpamProtection(): void
    {
        Event::on(Response::class, Response::EVENT_AFTER_PREPARE, function(Event $event) {
            /** @var Response $response */
            $response = $event->sender;
            $request = Craft::$app->getRequest();
            if (
                // Template responses use their own format; the Content-Type
                // check below rules out non-HTML templates (feeds, JSON).
                !in_array($response->format, [Response::FORMAT_HTML, TemplateResponseFormatter::FORMAT], true) ||
                $response->stream !== null ||
                !is_string($response->content) ||
                $response->getIsRedirection() ||
                $request->getIsAjax()
            ) {
                return;
            }
            $type = $response->getHeaders()->get('Content-Type');
            if ($type !== null && !str_starts_with(strtolower($type), 'text/html')) {
                return;
            }
            try {
                $response->content = $this->getSpamProtection()->processHtml($response->content);
            } catch (Throwable $e) {
                // Never break a page over spam protection.
                Craft::error('Couldn’t add Basin spam protection: ' . $e->getMessage(), 'basin');
            }
        });
    }

    private function registerCpRoutes(): void
    {
        Event::on(UrlManager::class, UrlManager::EVENT_REGISTER_CP_URL_RULES, function(RegisterUrlRulesEvent $event) {
            $event->rules['basin'] = 'basin/forms/index';
            $event->rules['basin/forms'] = 'basin/forms/index';
            $event->rules['basin/forms/<uuid:[A-Za-z0-9]+>'] = 'basin/forms/view';
            $event->rules['basin/submissions'] = 'basin/submissions/index';
            $event->rules['basin/forms/<uuid:[A-Za-z0-9]+>/submissions'] = 'basin/submissions/index';
            $event->rules['basin/forms/<uuid:[A-Za-z0-9]+>/submissions/<id:\d+>'] = 'basin/submissions/view';
            $event->rules['basin/forms/<uuid:[A-Za-z0-9]+>/submissions/<id:\d+>/attachments/<attachmentId:\d+>'] = 'basin/submissions/download';
            $event->rules['basin/exports/<exportId:[a-f0-9]{32}>'] = 'basin/submissions/download-export';
            $event->rules['basin/settings'] = 'basin/settings/index';
        });
    }
}
