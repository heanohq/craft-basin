<?php

namespace heano\basin;

use Craft;
use craft\base\Model;
use craft\base\Plugin as BasePlugin;
use craft\events\RegisterCacheOptionsEvent;
use craft\events\RegisterComponentTypesEvent;
use craft\events\RegisterGqlQueriesEvent;
use craft\events\RegisterGqlSchemaComponentsEvent;
use craft\events\RegisterUrlRulesEvent;
use craft\events\RegisterUserPermissionsEvent;
use craft\helpers\UrlHelper;
use craft\services\Dashboard;
use craft\services\Fields;
use craft\services\Gc;
use craft\services\Gql;
use craft\services\UserPermissions;
use craft\utilities\ClearCaches;
use craft\web\Response;
use craft\web\TemplateResponseFormatter;
use craft\web\twig\variables\CraftVariable;
use craft\web\UrlManager;
use heano\basin\fields\BasinFormField;
use heano\basin\gql\BasinGql;
use heano\basin\models\Settings;
use heano\basin\services\Api;
use heano\basin\services\Exports;
use heano\basin\services\FormIssues;
use heano\basin\services\Forms;
use heano\basin\services\FormSettings;
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
    public const PERMISSION_EDIT_FORMS = 'basin-editForms';
    public const PERMISSION_DELETE_FORMS = 'basin-deleteForms';

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

        Event::on(Gql::class, Gql::EVENT_REGISTER_GQL_QUERIES, function(RegisterGqlQueriesEvent $event) {
            $event->queries = array_merge($event->queries, BasinGql::queries());
        });
        Event::on(Gql::class, Gql::EVENT_REGISTER_GQL_SCHEMA_COMPONENTS, function(RegisterGqlSchemaComponentsEvent $event) {
            $event->queries[Craft::t('basin', 'Basin')] = BasinGql::schemaComponents();
        });
        // Site-wide spam settings appear in GraphQL results, which Craft caches.
        Event::on(self::class, self::EVENT_AFTER_SAVE_SETTINGS, function() {
            Craft::$app->getGql()->invalidateCaches();
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
        // Error log (spec §13), with a badge while it has entries.
        $subNav['errors'] = ['label' => Craft::t('basin', 'Error log'), 'url' => 'basin/errors'];
        try {
            $errorCount = $this->getFormIssues()->count();
        } catch (Throwable) {
            $errorCount = 0;
        }
        if ($errorCount) {
            $subNav['errors']['badgeCount'] = $errorCount;
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
            self::PERMISSION_EDIT_FORMS => [
                'label' => Craft::t('basin', 'Create and edit forms in Basin'),
                'info' => Craft::t('basin', 'Changes the linked Basin project directly.'),
                'nested' => [
                    self::PERMISSION_DELETE_FORMS => [
                        'label' => Craft::t('basin', 'Delete forms'),
                        'warning' => Craft::t('basin', 'Deleting a form also deletes all its submissions in Basin.'),
                    ],
                ],
            ],
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
            if (is_string($response->content) && $response->stream === null && self::isProtectableResponse($response)) {
                // Blitz has just saved this page, already protected below.
                $response->content = $this->blitzSaved !== null && $this->blitzSaved[0] === $response->content
                    ? $this->blitzSaved[1]
                    : $this->protectHtml($response->content);
            }
        });

        // Blitz saves its static cache in its own EVENT_AFTER_PREPARE handler,
        // which runs before ours. Pages served straight from those files (by
        // a server rewrite) never reach PHP, so protect the HTML before it's
        // saved. Running again when a cached page is served through PHP
        // changes nothing: protection that's already there is left alone.
        if (class_exists(self::BLITZ_GENERATE_CACHE)) {
            Event::on(self::BLITZ_GENERATE_CACHE, 'beforeSaveCache', function(Event $event) {
                /** @var \putyourlightson\blitz\events\SaveCacheEvent $event */
                if (self::isProtectableResponse(Craft::$app->getResponse())) {
                    $protected = $this->protectHtml($event->output);
                    // Blitz then sends the page it was given, so ours is reused rather than run twice.
                    $this->blitzSaved = [$event->output, $protected];
                    $event->output = $protected;
                }
            });
        }
    }

    /** Blitz's service class, named as a string so Blitz isn't a dependency. */
    private const BLITZ_GENERATE_CACHE = 'putyourlightson\\blitz\\services\\GenerateCacheService';

    /** @var array{0: string, 1: string}|null The page Blitz last saved this request: as given, and protected. */
    private ?array $blitzSaved = null;

    /**
     * Whether a response is a front-end HTML page that spam protection
     * should be added to.
     */
    private static function isProtectableResponse(Response $response): bool
    {
        // Template responses use their own format; the Content-Type check
        // below rules out non-HTML templates (feeds, JSON).
        if (
            !in_array($response->format, [Response::FORMAT_HTML, TemplateResponseFormatter::FORMAT], true) ||
            $response->getIsRedirection() ||
            Craft::$app->getRequest()->getIsAjax()
        ) {
            return false;
        }
        $type = $response->getHeaders()->get('Content-Type');
        return $type === null || str_starts_with(strtolower($type), 'text/html');
    }

    private function protectHtml(string $html): string
    {
        try {
            return $this->getSpamProtection()->processHtml($html);
        } catch (Throwable $e) {
            // Never break a page over spam protection.
            Craft::error('Couldn’t add Basin spam protection: ' . $e->getMessage(), 'basin');
            return $html;
        }
    }

    private function registerCpRoutes(): void
    {
        Event::on(UrlManager::class, UrlManager::EVENT_REGISTER_CP_URL_RULES, function(RegisterUrlRulesEvent $event) {
            $event->rules['basin'] = 'basin/forms/index';
            $event->rules['basin/forms'] = 'basin/forms/index';
            $event->rules['basin/forms/new'] = 'basin/forms/new';
            $event->rules['basin/forms/<uuid:[A-Za-z0-9]+>'] = 'basin/forms/view';
            $event->rules['basin/forms/<uuid:[A-Za-z0-9]+>/settings'] = 'basin/forms/edit';
            $event->rules['basin/submissions'] = 'basin/submissions/index';
            $event->rules['basin/forms/<uuid:[A-Za-z0-9]+>/submissions'] = 'basin/submissions/index';
            $event->rules['basin/forms/<uuid:[A-Za-z0-9]+>/submissions/<id:\d+>'] = 'basin/submissions/view';
            $event->rules['basin/forms/<uuid:[A-Za-z0-9]+>/submissions/<id:\d+>/attachments/<attachmentId:\d+>'] = 'basin/submissions/download';
            $event->rules['basin/exports/<exportId:[a-f0-9]{32}>'] = 'basin/submissions/download-export';
            $event->rules['basin/settings'] = 'basin/settings/index';
            $event->rules['basin/errors'] = 'basin/errors/index';
        });
    }
}
