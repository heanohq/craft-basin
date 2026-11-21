<?php

namespace heano\basin\controllers;

use Craft;
use craft\helpers\App;
use Heano\Basin\Exception\ApiException;
use Heano\Basin\Exception\NotFoundException;
use Heano\Basin\Exception\ValidationException;
use heano\basin\helpers\FormEditor;
use heano\basin\helpers\StudioEmbed;
use heano\basin\models\BasinForm;
use heano\basin\Plugin;
use heano\basin\services\FormSettings;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * Forms index and form detail (spec §6.4, §6.5), saving Form Studio embed
 * codes (spec §10.3), and creating, editing and deleting forms in Basin
 * (spec §6.7).
 */
class FormsController extends BaseCpController
{
    public function actionIndex(): Response
    {
        $variables = $this->baseVariables();
        $variables['basinForms'] = [];

        if ($variables['state'] === 'ready') {
            try {
                $variables['basinForms'] = Plugin::getInstance()->getForms()->getForms();
            } catch (ApiException $e) {
                $variables['error'] = $e->getMessage();
            }
        }

        return $this->renderTemplate('basin/forms/_index', $variables + [
            'staleSince' => Plugin::getInstance()->getForms()->getStaleSince(),
            'canEdit' => Craft::$app->getUser()->checkPermission(Plugin::PERMISSION_EDIT_FORMS),
        ]);
    }

    public function actionView(string $uuid, ?string $embedInput = null): Response
    {
        $plugin = Plugin::getInstance();
        $variables = $this->baseVariables();
        if ($variables['state'] !== 'ready') {
            return $this->redirect('basin/forms');
        }

        try {
            $form = $plugin->getForms()->getFormByUuid($uuid);
        } catch (ApiException $e) {
            return $this->renderTemplate('basin/forms/_index', $variables + ['basinForms' => [], 'error' => $e->getMessage(), 'staleSince' => null]);
        }
        if (!$form) {
            throw new NotFoundHttpException(Craft::t('basin', 'There’s no form “{uuid}” in the linked Basin project. It may have been deleted or moved to another project.', ['uuid' => $uuid]));
        }

        $studioStatus = null;
        if ($form->hostedFormId !== null) {
            $ok = $plugin->getForms()->checkStudioScript($form->uuid, $form->hostedFormId);
            $studioStatus = match ($ok) {
                true => 'ok',
                false => 'broken',
                null => 'unknown',
            };
        }

        return $this->renderTemplate('basin/forms/_view', $variables + [
            'form' => $form,
            'studioStatus' => $studioStatus,
            'embedInput' => $embedInput,
            'canManage' => FormSettings::canManage(),
            'canEdit' => Craft::$app->getUser()->checkPermission(Plugin::PERMISSION_EDIT_FORMS),
            'staleSince' => $plugin->getForms()->getStaleSince(),
        ]);
    }

    // Managing forms in Basin (spec §6.7) -----------------------------------

    /**
     * @param array<string, mixed>|null $values Posted values, after a failed save
     * @param array<string, string>|null $errors Field => message
     */
    public function actionNew(?array $values = null, ?array $errors = null): Response
    {
        $this->requirePermission(Plugin::PERMISSION_EDIT_FORMS);
        $variables = $this->baseVariables();
        if ($variables['state'] !== 'ready' || $variables['project'] === null) {
            return $this->redirect('basin/forms');
        }

        return $this->renderTemplate('basin/forms/_new', $variables + [
            'values' => $values ?? ['name' => '', 'notification_emails' => ''],
            'errors' => array_map(fn(string $message) => [$message], $errors ?? []),
        ]);
    }

    public function actionCreate(): ?Response
    {
        $this->requirePostRequest();
        $this->requirePermission(Plugin::PERMISSION_EDIT_FORMS);

        $values = FormEditor::normalise(array_intersect_key(
            (array)$this->request->getBodyParam('fields', []),
            ['name' => 1, 'notification_emails' => 1],
        ));
        // New forms use the site's timezone, as Basin's dashboard uses the
        // account's. It can be changed in the editor afterwards.
        $values += ['name' => '', 'notification_emails' => ''];
        $values['timezone'] = $this->siteTimeZone();
        $fail = fn(array $errors, ?string $message = null) => $this->asFailure(
            $message ?? Craft::t('basin', 'Couldn’t create the form.'),
            routeParams: ['values' => $values, 'errors' => $errors],
        );

        $errors = $this->errorMessages(FormEditor::validate($values));
        if ($errors) {
            return $fail($errors);
        }

        try {
            $result = Plugin::getInstance()->getForms()->createForm($values['name'], $values['notification_emails'], $values['timezone']);
        } catch (ValidationException $e) {
            [$errors, $message] = $this->basinErrors($e, $values);
            return $fail($errors, $message);
        } catch (ApiException $e) {
            return $fail([], Craft::t('basin', 'Couldn’t create the form: {message}', ['message' => $e->getMessage()]));
        }

        $settings = $result['settings'];
        $live = (string)($settings['notification_emails'] ?? '');
        $pending = (string)($settings['notification_emails_pending'] ?? '');
        $notice = match (true) {
            $pending !== '' && $live === '' => Craft::t('basin', 'Basin has emailed {emails} asking them to verify. Until one is verified, this form doesn’t email anyone.', ['emails' => $pending]),
            $pending !== '' => Craft::t('basin', 'Basin has emailed {pending} asking them to verify. Until then, notifications go to {emails}.', ['pending' => $pending, 'emails' => $live]),
            $values['notification_emails'] === '' && $live !== '' => Craft::t('basin', 'Notifications go to {emails}, the Basin account owner. Change this under Notifications.', ['emails' => $live]),
            default => null,
        };
        if ($notice !== null) {
            Craft::$app->getSession()->setFlash('basin:created', $notice);
        }

        return $this->asSuccess(
            Craft::t('basin', 'Form created.'),
            redirect: $result['form']->getSettingsCpUrl(),
        );
    }

    /**
     * @param array<string, mixed>|null $values Posted values, after a failed save
     * @param array<string, string>|null $errors Field => message
     */
    public function actionEdit(string $uuid, ?array $values = null, ?array $errors = null): Response
    {
        $this->requirePermission(Plugin::PERMISSION_EDIT_FORMS);
        $variables = $this->baseVariables();
        if ($variables['state'] !== 'ready') {
            return $this->redirect('basin/forms');
        }
        $form = $this->requireForm($uuid);

        try {
            $settings = Plugin::getInstance()->getForms()->getEditableSettings($form);
        } catch (NotFoundException $e) {
            throw new NotFoundHttpException($e->getMessage());
        } catch (ApiException $e) {
            return $this->renderTemplate('basin/forms/_edit', $variables + [
                'form' => $form,
                'loadError' => $e->getMessage(),
            ]);
        }

        return $this->renderTemplate('basin/forms/_edit', $variables + [
            'form' => $form,
            'loadError' => null,
            'basinSettings' => $settings,
            'values' => ($values ?? []) + FormEditor::normalise(FormEditor::withPending($settings)),
            'errors' => array_map(fn(string $message) => [$message], $errors ?? []),
            // Each recipients field's verified and pending addresses, listed apart.
            'recipients' => array_map(
                fn(string $key) => [
                    'live' => FormEditor::splitEmails((string)($settings[$key] ?? '')),
                    'pending' => FormEditor::splitEmails((string)($settings[FormEditor::PENDING_FIELDS[$key]] ?? '')),
                ],
                array_combine(array_keys(FormEditor::PENDING_FIELDS), array_keys(FormEditor::PENDING_FIELDS)),
            ),
            'canDelete' => Craft::$app->getUser()->checkPermission(Plugin::PERMISSION_DELETE_FORMS),
            'createdNotice' => Craft::$app->getSession()->getFlash('basin:created'),
        ]);
    }

    public function actionSaveSettings(): ?Response
    {
        $this->requirePostRequest();
        $this->requirePermission(Plugin::PERMISSION_EDIT_FORMS);

        $forms = Plugin::getInstance()->getForms();
        $form = $this->requireForm((string)$this->request->getRequiredBodyParam('formUuid'));
        $posted = array_intersect_key((array)$this->request->getBodyParam('fields', []), FormEditor::FIELDS);
        $fail = fn(array $errors, ?string $message = null) => $this->asFailure(
            $message ?? Craft::t('basin', 'Couldn’t save the form’s settings.'),
            routeParams: ['values' => FormEditor::normalise($posted), 'errors' => $errors],
        );

        try {
            $original = $forms->getEditableSettings($form);
        } catch (NotFoundException $e) {
            throw new NotFoundHttpException($e->getMessage());
        } catch (ApiException $e) {
            return $fail([], Craft::t('basin', 'Couldn’t reach Basin: {message}', ['message' => $e->getMessage()]));
        }

        // The CP lists pending addresses with the verified ones, so compare against both.
        $changes = FormEditor::changes(FormEditor::withPending($original), $posted);
        $errors = $this->errorMessages(FormEditor::validate($changes));
        if ($errors) {
            return $fail($errors);
        }
        if ($changes === []) {
            return $this->asSuccess(Craft::t('basin', 'Nothing has changed.'));
        }

        try {
            $forms->updateForm($form, $changes);
        } catch (ValidationException $e) {
            [$errors, $message] = $this->basinErrors($e, $changes);
            return $fail($errors, $message);
        } catch (NotFoundException $e) {
            throw new NotFoundHttpException($e->getMessage());
        } catch (ApiException $e) {
            return $fail([], Craft::t('basin', 'Couldn’t save the form’s settings: {message}', ['message' => $e->getMessage()]));
        }

        return $this->asSuccess(Craft::t('basin', 'Form settings saved.'));
    }

    public function actionDelete(): ?Response
    {
        $this->requirePostRequest();
        $this->requirePermission(Plugin::PERMISSION_EDIT_FORMS);
        $this->requirePermission(Plugin::PERMISSION_DELETE_FORMS);

        $form = $this->requireForm((string)$this->request->getRequiredBodyParam('formUuid'));
        if (trim((string)$this->request->getBodyParam('confirmName', '')) !== $form->name) {
            return $this->asFailure(Craft::t('basin', 'Type the form’s name exactly to delete it.'));
        }

        try {
            Plugin::getInstance()->getForms()->deleteForm($form);
        } catch (NotFoundException $e) {
            throw new NotFoundHttpException($e->getMessage());
        } catch (ApiException $e) {
            return $this->asFailure(Craft::t('basin', 'Couldn’t delete the form: {message}', ['message' => $e->getMessage()]));
        }

        return $this->asSuccess(
            Craft::t('basin', '“{name}” deleted.', ['name' => $form->name]),
            redirect: 'basin/forms',
        );
    }

    /**
     * The site's timezone. Craft::$app->getTimeZone() can't be used: on CP
     * requests it's the signed-in user's own preference.
     */
    private function siteTimeZone(): string
    {
        $timeZone = (string)App::parseEnv(
            Craft::$app->getConfig()->getGeneral()->timezone ?? Craft::$app->getProjectConfig()->get('system.timeZone') ?? 'UTC'
        );
        return FormEditor::isTimezone($timeZone) ? $timeZone : 'UTC';
    }

    /**
     * Translates {@see FormEditor::validate()} codes.
     *
     * @param array<string, string> $codes
     * @return array<string, string>
     */
    private function errorMessages(array $codes): array
    {
        return array_map(fn(string $code) => match ($code) {
            FormEditor::ERROR_REQUIRED => Craft::t('basin', 'This can’t be blank.'),
            FormEditor::ERROR_EMAIL => Craft::t('basin', 'Enter valid email addresses, separated by commas.'),
            FormEditor::ERROR_URL => Craft::t('basin', 'Enter a full URL, starting with https:// or http://.'),
            FormEditor::ERROR_COLOR => Craft::t('basin', 'Enter a colour like #24292E.'),
            FormEditor::ERROR_DAYS => Craft::t('basin', 'Enter a number of days from 1 to {max}.', ['max' => FormEditor::MAX_DAYS]),
            FormEditor::ERROR_TIMEZONE => Craft::t('basin', 'Choose a timezone.'),
            FormEditor::ERROR_TOO_LONG => Craft::t('basin', 'This is too long.'),
            default => Craft::t('basin', 'This isn’t valid.'),
        }, $codes);
    }

    /**
     * Basin's validation messages, on the fields they're about where that's
     * clear, and the rest as the page's error message.
     *
     * @param array<string, mixed> $sent
     * @return array{0: array<string, string>, 1: string}
     */
    private function basinErrors(ValidationException $e, array $sent): array
    {
        $errors = [];
        $other = [];
        foreach ($e->messages ?: [$e->getMessage()] as $message) {
            $field = FormEditor::fieldForMessage($message, $sent);
            if ($field !== null && !isset($errors[$field])) {
                $errors[$field] = $message;
            } else {
                $other[] = $message;
            }
        }
        $summary = $other
            ? Craft::t('basin', 'Basin said: {message}', ['message' => implode(' ', $other)])
            : Craft::t('basin', 'Basin couldn’t save these settings.');
        return [$errors, $summary];
    }

    public function actionSaveStudioEmbed(): ?Response
    {
        $this->requirePostRequest();
        $this->requirePermission(Plugin::PERMISSION_MANAGE_FORMS);

        $plugin = Plugin::getInstance();
        $form = $this->requireForm((string)$this->request->getRequiredBodyParam('formUuid'));
        $input = (string)$this->request->getBodyParam('embed', '');
        $fail = fn(string $message) => $this->asFailure($message, routeParams: ['embedInput' => $input]);

        $parsed = StudioEmbed::parse($input);
        if ($parsed === null) {
            return $fail(Craft::t('basin', 'That doesn’t look like a Basin Form Studio embed code. Copy it from Form Studio’s Share → Embed options.'));
        }
        if ($parsed['formUuid'] !== strtolower($form->uuid)) {
            return $fail(Craft::t('basin', 'This embed code is for a different Basin form ({uuid}).', ['uuid' => $parsed['formUuid']]));
        }

        $ok = $plugin->getForms()->checkStudioScript($form->uuid, $parsed['hostedId'], refresh: true);
        if ($ok === false) {
            return $fail(Craft::t('basin', 'Basin doesn’t recognise this embed code. Make sure the form is published in Form Studio, then copy the code again.'));
        }
        if ($ok === null) {
            return $fail(Craft::t('basin', 'Couldn’t reach Basin to check the embed code. Try again in a moment.'));
        }

        $plugin->getForms()->saveHostedFormId($form->uuid, $parsed['hostedId']);

        return $this->asSuccess(Craft::t('basin', 'Embed code saved.'));
    }

    public function actionRemoveStudioEmbed(): ?Response
    {
        $this->requirePostRequest();
        $this->requirePermission(Plugin::PERMISSION_MANAGE_FORMS);

        $form = $this->requireForm((string)$this->request->getRequiredBodyParam('formUuid'));

        Plugin::getInstance()->getForms()->saveHostedFormId($form->uuid, null);

        return $this->asSuccess(Craft::t('basin', 'Embed code removed.'));
    }

    public function actionSaveRecaptchaVersion(): ?Response
    {
        $this->requirePostRequest();
        $this->requirePermission(Plugin::PERMISSION_MANAGE_FORMS);

        $form = $this->requireForm((string)$this->request->getRequiredBodyParam('formUuid'));

        $version = $this->request->getBodyParam('recaptchaVersion');
        $version = in_array($version, ['v2', 'v3'], true) ? $version : null;
        Plugin::getInstance()->getForms()->saveRecaptchaVersion($form->uuid, $version);

        return $this->asSuccess(Craft::t('basin', 'reCAPTCHA version saved.'));
    }
}
