<?php

namespace heano\basin\gql;

use Craft;
use craft\gql\GqlEntityRegistry;
use craft\helpers\Gql as GqlHelper;
use GraphQL\Type\Definition\ObjectType;
use GraphQL\Type\Definition\Type;
use heano\basin\models\BasinForm;
use heano\basin\Plugin;
use heano\basin\services\FormIssues;

/**
 * GraphQL support (spec §12): the `BasinForm` type, the `basinForms` and
 * `basinForm` queries, and the "Basin forms" schema component.
 *
 * Only front-end-safe fields are exposed: what a page needs to render or post
 * to the form. No counts, project details, notification settings or
 * submissions.
 */
final class BasinGql
{
    public const SCHEMA_COMPONENT = 'basinForms';

    public static function schemaComponents(): array
    {
        return [
            self::SCHEMA_COMPONENT . ':read' => ['label' => Craft::t('basin', 'Query Basin forms')],
        ];
    }

    public static function formType(): ObjectType
    {
        return GqlEntityRegistry::getOrCreate('BasinForm', fn() => new ObjectType([
            'name' => GqlEntityRegistry::prefixTypeName('BasinForm'),
            'description' => 'A form in the linked Basin project.',
            'fields' => fn() => [
                'uuid' => [
                    'type' => Type::nonNull(Type::string()),
                    'description' => 'The form’s Basin ID, as in its endpoint URL.',
                ],
                'name' => [
                    'type' => Type::nonNull(Type::string()),
                ],
                'action' => [
                    'type' => Type::nonNull(Type::string()),
                    'description' => 'The endpoint URL to use as the form’s `action`.',
                    'resolve' => fn(BasinForm $form) => $form->getAction(),
                ],
                'useAjax' => [
                    'type' => Type::nonNull(Type::boolean()),
                    'description' => 'Whether Basin responds with JSON, as set in Basin.',
                ],
                'redirectUrl' => [
                    'type' => Type::string(),
                    'description' => 'Where Basin sends visitors after submitting, if set in Basin.',
                ],
                'isStudioForm' => [
                    'type' => Type::nonNull(Type::boolean()),
                    'description' => 'Whether the form has Form Studio embed code saved in the Control Panel.',
                    'resolve' => fn(BasinForm $form) => $form->getIsStudioForm(),
                ],
                'studioScriptUrl' => [
                    'type' => Type::string(),
                    'description' => 'The Form Studio embed script, if saved. Load it where the form should appear.',
                    'resolve' => fn(BasinForm $form) => $form->getStudioScriptUrl(),
                ],
                'spamProtection' => [
                    'type' => Type::nonNull(self::spamProtectionType()),
                    'description' => 'What the page must include for Basin to accept submissions.',
                    'resolve' => fn(BasinForm $form) => Plugin::getInstance()->getSpamProtection()->config($form),
                ],
            ],
        ]));
    }

    public static function spamProtectionType(): ObjectType
    {
        return GqlEntityRegistry::getOrCreate('BasinSpamProtection', fn() => new ObjectType([
            'name' => GqlEntityRegistry::prefixTypeName('BasinSpamProtection'),
            'description' => 'The CAPTCHA and honeypot a Basin form requires.',
            'fields' => fn() => [
                'captcha' => [
                    'type' => Type::string(),
                    'description' => '`recaptcha`, `hcaptcha`, `turnstile`, or null if the form doesn’t require one.',
                ],
                'siteKey' => [
                    'type' => Type::string(),
                    'description' => 'The CAPTCHA site key to render the widget with.',
                ],
                'scriptUrl' => [
                    'type' => Type::string(),
                    'description' => 'The CAPTCHA provider’s script.',
                ],
                'recaptchaVersion' => [
                    'type' => Type::string(),
                    'description' => '`v3` or `v2` for reCAPTCHA forms. v3 also needs a hidden `g-recaptcha-version` input set to `v3`.',
                ],
                'honeypotField' => [
                    'type' => Type::nonNull(Type::string()),
                    'description' => 'The name of the hidden honeypot input. Leave it empty.',
                ],
            ],
        ]));
    }

    /**
     * Root queries, for schemas that allow querying Basin forms.
     */
    public static function queries(): array
    {
        if (!GqlHelper::canSchema(self::SCHEMA_COMPONENT, 'read')) {
            return [];
        }

        return [
            'basinForms' => [
                'type' => Type::nonNull(Type::listOf(Type::nonNull(self::formType()))),
                'description' => 'Every form in the linked Basin project, sorted by name.',
                'resolve' => fn() => Plugin::getInstance()->getForms()->listForFrontEnd(),
            ],
            'basinForm' => [
                'type' => self::formType(),
                'description' => 'A form by its Basin ID, or null if it isn’t in the linked project.',
                'args' => [
                    'uuid' => Type::nonNull(Type::string()),
                ],
                'resolve' => fn($root, array $args) => Plugin::getInstance()->getForms()->lookup($args['uuid'], FormIssues::SOURCE_GRAPHQL),
            ],
        ];
    }
}
