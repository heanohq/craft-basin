<?php

namespace heano\basin\models;

use Craft;
use craft\base\Model;
use craft\helpers\App;

/**
 * Basin plugin settings (stored in project config; spec §5).
 *
 * `apiKey` must be an environment variable reference (e.g. `$BASIN_API_KEY`)
 * so the key itself never lands in project config YAML.
 */
class Settings extends Model
{
    /** Env var reference to a Basin Account API key, e.g. `$BASIN_API_KEY`. */
    public string $apiKey = '';

    /** The Basin project linked to this site. Numeric ID or an env var reference. */
    public string $projectId = '';

    /** Seconds to cache forms and form details. */
    public int $cacheDuration = 300;

    /** Whether submissions can be permanently deleted from the CP. */
    public bool $allowDeletion = false;

    /** Whether to record references to forms that aren't in the linked project. */
    public bool $trackMissingForms = true;

    /** Custom label for the main CP nav item. Blank = "Basin". */
    public string $navLabel = '';

    protected function defineRules(): array
    {
        return [
            [['apiKey', 'projectId', 'navLabel'], 'trim'],
            [['apiKey', 'projectId', 'navLabel'], 'string'],
            ['apiKey', 'validateEnvReference'],
            ['projectId', 'validateProjectId'],
            ['navLabel', 'string', 'max' => 60],
            ['cacheDuration', 'integer', 'min' => 0],
            [['allowDeletion', 'trackMissingForms'], 'boolean'],
        ];
    }

    /**
     * The API key must be stored as an env var reference, never a literal.
     */
    public function validateEnvReference(string $attribute): void
    {
        $value = $this->$attribute;
        if ($value === '') {
            return;
        }
        if (!preg_match('/^\$[A-Z_][A-Z0-9_]*$/i', $value)) {
            $this->addError($attribute, Craft::t('basin', 'Enter an environment variable (e.g. {example}), not the key itself, so it isn’t saved in project config.', [
                'example' => '$BASIN_API_KEY',
            ]));
            return;
        }
        if (App::env(substr($value, 1)) === null) {
            $this->addError($attribute, Craft::t('basin', 'The environment variable {name} isn’t set.', ['name' => $value]));
        }
    }

    public function validateProjectId(string $attribute): void
    {
        $value = $this->$attribute;
        if ($value === '' || str_starts_with($value, '$')) {
            return;
        }
        if (!ctype_digit($value)) {
            $this->addError($attribute, Craft::t('basin', 'Choose a project, or enter an environment variable.'));
        }
    }

    public function getApiKey(): string
    {
        return (string)App::parseEnv($this->apiKey);
    }

    public function getProjectId(): ?int
    {
        $value = (string)App::parseEnv($this->projectId);
        return ctype_digit($value) ? (int)$value : null;
    }

    public function getNavLabel(): string
    {
        return $this->navLabel !== ''
            ? Craft::t('site', $this->navLabel)
            : Craft::t('basin', 'Basin');
    }
}
