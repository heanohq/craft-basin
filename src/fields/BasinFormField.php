<?php

namespace heano\basin\fields;

use Craft;
use craft\base\ElementInterface;
use craft\base\Field;
use craft\helpers\Cp;
use craft\helpers\Html;
use GraphQL\Type\Definition\Type;
use heano\basin\api\exceptions\ApiException;
use heano\basin\models\BasinForm;
use heano\basin\Plugin;
use heano\basin\services\FormIssues;
use yii\db\Schema;

/**
 * Basin Form field (spec §11). Stores a form UUID; templates get a
 * `BasinForm`, or null when the form isn't in the linked project.
 */
class BasinFormField extends Field
{
    public const SELECTABLE_ALL = 'all';
    public const SELECTABLE_ENDPOINT = 'endpoint';
    public const SELECTABLE_STUDIO = 'studio';

    /** Which kinds of form can be chosen. */
    public string $selectable = self::SELECTABLE_ALL;

    /**
     * Form UUIDs that can be chosen. Empty means any form of the selected kind.
     *
     * @var string[]
     */
    public array $allowedForms = [];

    /**
     * Stored UUIDs that couldn't be resolved (missing from the project, or
     * Basin unavailable), so saving the element keeps them until an editor
     * picks something else. Keyed by canonical element and site, so drafts
     * and revisions (copies of the element) keep the value too.
     *
     * @var array<string, string>
     */
    private static array $unresolved = [];

    public static function displayName(): string
    {
        return Craft::t('basin', 'Basin Form');
    }

    public static function icon(): string
    {
        return '@heano/basin/icon-mask.svg';
    }

    public static function phpType(): string
    {
        return sprintf('\\%s|null', BasinForm::class);
    }

    public static function dbType(): array|string|null
    {
        return Schema::TYPE_STRING;
    }

    protected function defineRules(): array
    {
        $rules = parent::defineRules();
        $rules[] = ['selectable', 'in', 'range' => [self::SELECTABLE_ALL, self::SELECTABLE_ENDPOINT, self::SELECTABLE_STUDIO]];
        $rules[] = ['allowedForms', 'each', 'rule' => ['match', 'pattern' => '/^[A-Za-z0-9]{1,40}$/']];
        return $rules;
    }

    public function setAttributes($values, $safeOnly = true): void
    {
        // Checkbox groups post '' when nothing is checked.
        if (array_key_exists('allowedForms', (array)$values) && !is_array($values['allowedForms'])) {
            $values['allowedForms'] = [];
        }
        parent::setAttributes($values, $safeOnly);
    }

    public function getSettingsHtml(): ?string
    {
        return Craft::$app->getView()->renderTemplate('basin/_fields/settings', [
            'field' => $this,
            'basinForms' => $this->allForms(),
        ]);
    }

    public function normalizeValue(mixed $value, ?ElementInterface $element): mixed
    {
        if ($element !== null) {
            $this->setUnresolved($element, null);
        }
        if ($value instanceof BasinForm) {
            return $value;
        }
        $uuid = is_string($value) ? trim($value) : '';
        if ($uuid === '') {
            return null;
        }

        $forms = Plugin::getInstance()->getForms();
        $form = null;
        if ($forms->isConfigured()) {
            try {
                $form = $forms->getFormByUuid($uuid);
                if ($form === null && !$forms->isStale) {
                    $url = $element?->getCpEditUrl() ?? FormIssues::currentPath();
                    Plugin::getInstance()->getFormIssues()->record($uuid, FormIssues::REASON_MISSING, FormIssues::SOURCE_FIELD, $url);
                }
            } catch (ApiException $e) {
                Craft::warning("Couldn’t look up Basin form $uuid: " . $e->getMessage(), 'basin');
            }
        }

        if ($form === null && $element !== null) {
            $this->setUnresolved($element, $uuid);
        }
        return $form;
    }

    public function serializeValue(mixed $value, ?ElementInterface $element): mixed
    {
        if ($value instanceof BasinForm) {
            return $value->uuid;
        }
        if (is_string($value) && $value !== '') {
            return $value;
        }
        return $element !== null ? $this->getUnresolved($element) : null;
    }

    /**
     * A stored UUID that can't be resolved isn't empty: it's kept, and a
     * required field doesn't block saving while Basin is unreachable.
     */
    public function isValueEmpty(mixed $value, ElementInterface $element): bool
    {
        return !$value instanceof BasinForm && $this->getUnresolved($element) === null;
    }

    public function getElementValidationRules(): array
    {
        return [['validateForm', 'skipOnEmpty' => true]];
    }

    public function validateForm(ElementInterface $element): void
    {
        $value = $element->getFieldValue($this->handle);
        if ($value instanceof BasinForm && !$this->canSelect($value)) {
            $element->addError("field:$this->handle", Craft::t('basin', '“{name}” can’t be chosen in this field.', ['name' => $value->name]));
        }
    }

    protected function inputHtml(mixed $value, ?ElementInterface $element, bool $inline): string
    {
        $forms = Plugin::getInstance()->getForms();
        $unresolved = $element !== null ? $this->getUnresolved($element) : null;
        $available = null;
        try {
            $available = $forms->isConfigured() ? $forms->getForms() : [];
            // Flags Studio forms that a template tried to render without embed code.
            Plugin::getInstance()->getFormIssues()->getActive($available, !$forms->isStale);
        } catch (ApiException) {
        }

        $options = [['label' => Craft::t('basin', 'None'), 'value' => '']];
        foreach ($available ?? [] as $form) {
            if ($this->canSelect($form) || ($value instanceof BasinForm && $value->uuid === $form->uuid)) {
                $options[] = [
                    'label' => $form->getIsStudioForm() ? Craft::t('basin', '{name} (Form Studio)', ['name' => $form->name]) : $form->name,
                    'value' => $form->uuid,
                ];
            }
        }
        if ($unresolved !== null) {
            $options[] = [
                'label' => Craft::t('basin', 'Form unavailable ({uuid})', ['uuid' => $unresolved]),
                'value' => $unresolved,
            ];
        }

        $html = Cp::selectHtml([
            'id' => $this->getInputId(),
            'describedBy' => $this->describedBy,
            'name' => $this->handle,
            'options' => $options,
            'value' => $value instanceof BasinForm ? $value->uuid : ($unresolved ?? ''),
        ]);

        $notice = null;
        if ($available === null) {
            $notice = Craft::t('basin', 'Couldn’t reach Basin to list forms. The current choice is kept.');
        } elseif ($unresolved !== null) {
            $notice = Craft::t('basin', 'This form isn’t in the linked Basin project. It may have been deleted or moved to another project. Nothing will show on the site until another form is chosen.');
        } elseif ($value instanceof BasinForm && $value->getNeedsEmbedCode()) {
            $notice = Craft::t('basin', 'This Form Studio form won’t appear on the site until an admin adds its embed code.');
        }
        if ($notice !== null) {
            $html .= Html::tag('p', Html::encode($notice), ['class' => 'warning with-icon', 'style' => 'margin-top: var(--s);']);
        }

        return $html;
    }

    public function getPreviewHtml(mixed $value, ElementInterface $element): string
    {
        if ($value instanceof BasinForm) {
            return Html::encode($value->name);
        }
        $unresolved = $this->getUnresolved($element);
        return $unresolved !== null ? Html::tag('span', Html::encode(Craft::t('basin', 'Unavailable ({uuid})', ['uuid' => $unresolved])), ['class' => 'light']) : '';
    }

    protected function searchKeywords(mixed $value, ElementInterface $element): string
    {
        return $value instanceof BasinForm ? $value->name : '';
    }

    /**
     * The form's UUID for now; the full `BasinForm` type comes with GraphQL support.
     */
    public function getContentGqlType(): Type|array
    {
        return [
            'name' => $this->handle,
            'type' => Type::string(),
            'resolve' => fn($source) => ($v = $source->getFieldValue($this->handle)) instanceof BasinForm ? $v->uuid : null,
        ];
    }

    public function canSelect(BasinForm $form): bool
    {
        if ($this->allowedForms && !in_array($form->uuid, $this->allowedForms, true)) {
            return false;
        }
        return match ($this->selectable) {
            self::SELECTABLE_ENDPOINT => !$form->getIsStudioForm(),
            self::SELECTABLE_STUDIO => $form->getIsStudioForm(),
            default => true,
        };
    }

    /**
     * @return BasinForm[]
     */
    private function allForms(): array
    {
        $forms = Plugin::getInstance()->getForms();
        try {
            return $forms->isConfigured() ? array_values($forms->getForms()) : [];
        } catch (ApiException) {
            return [];
        }
    }

    private function getUnresolved(ElementInterface $element): ?string
    {
        return self::$unresolved[$this->unresolvedKey($element)] ?? null;
    }

    private function setUnresolved(ElementInterface $element, ?string $uuid): void
    {
        $key = $this->unresolvedKey($element);
        if ($uuid === null) {
            unset(self::$unresolved[$key]);
        } else {
            self::$unresolved[$key] = $uuid;
        }
    }

    private function unresolvedKey(ElementInterface $element): string
    {
        $id = $element->getCanonicalId();
        $owner = $id !== null ? "e$id:$element->siteId" : 'o' . spl_object_id($element);
        return $owner . ':' . ($this->layoutElement?->uid ?? $this->handle);
    }
}
