<?php

namespace heano\basin\controllers;

use Craft;
use craft\helpers\Queue;
use craft\helpers\UrlHelper;
use heano\basin\api\ApiClient;
use heano\basin\api\exceptions\ApiException;
use heano\basin\helpers\CsvExport;
use heano\basin\jobs\BulkSubmissionAction;
use heano\basin\models\BasinForm;
use heano\basin\Plugin;
use heano\basin\services\Exports;
use heano\basin\services\Submissions;
use yii\caching\TagDependency;
use yii\web\BadRequestHttpException;
use yii\web\ForbiddenHttpException;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * Submissions index, detail, triage and attachment downloads (spec §7).
 */
class SubmissionsController extends BaseCpController
{
    private const LAST_FORM_KEY = 'basin.lastSubmissionsForm';

    public function beforeAction($action): bool
    {
        if (!parent::beforeAction($action)) {
            return false;
        }
        $this->requirePermission('basin-viewSubmissions');
        return true;
    }

    public function actionIndex(?string $uuid = null): Response
    {
        $plugin = Plugin::getInstance();
        $variables = $this->baseVariables();
        $variables += ['basinForms' => [], 'form' => null, 'result' => null, 'staleSince' => null];

        if ($variables['state'] !== 'ready') {
            return $this->renderTemplate('basin/submissions/_index', $variables);
        }

        try {
            $variables['basinForms'] = $plugin->getForms()->getForms();
        } catch (ApiException $e) {
            $variables['error'] = $e->getMessage();
            return $this->renderTemplate('basin/submissions/_index', $variables);
        }
        $variables['staleSince'] = $plugin->getForms()->getStaleSince();

        // basin/submissions opens the most recently used form.
        if ($uuid === null) {
            $last = Craft::$app->getSession()->get(self::LAST_FORM_KEY);
            $target = $variables['basinForms'][$last] ?? reset($variables['basinForms']) ?: null;
            if ($target === null) {
                return $this->renderTemplate('basin/submissions/_index', $variables);
            }
            return $this->redirect(UrlHelper::cpUrl("basin/forms/$target->uuid/submissions"));
        }

        $form = $this->requireForm($uuid);
        Craft::$app->getSession()->set(self::LAST_FORM_KEY, $form->uuid);

        $criteria = $this->criteria();
        $variables['form'] = $form;
        $variables['criteria'] = $criteria;
        $variables['pageNum'] = max(1, (int)$this->request->getQueryParam('page', 1));
        $variables += $this->permissionVariables();
        $variables['exports'] = $variables['canExport']
            ? array_values(array_filter(
                Plugin::getInstance()->getExports()->listForUser((int)Craft::$app->getUser()->getId()),
                fn($e) => $e['formUuid'] === $form->uuid,
            ))
            : [];

        try {
            $variables['result'] = $plugin->getSubmissions()->getPage($form, $criteria, $variables['pageNum']);
        } catch (ApiException $e) {
            $variables['error'] = $e->getMessage();
        }

        return $this->renderTemplate('basin/submissions/_index', $variables);
    }

    public function actionView(string $uuid, int $id): Response
    {
        $plugin = Plugin::getInstance();
        $form = $this->requireForm($uuid);
        $service = $plugin->getSubmissions();

        try {
            $submission = $service->getSubmission($form, $id);
        } catch (ApiException $e) {
            throw new \yii\web\ServerErrorHttpException($e->getMessage(), 0, $e);
        }
        if (!$submission) {
            throw new NotFoundHttpException(Craft::t('basin', 'That submission isn’t in this form. It may have been deleted.'));
        }

        $permissions = $this->permissionVariables();

        // Opening a submission marks it as read, as in Basin.
        if (!$submission->read && $permissions['canManage']) {
            $result = $service->applyAction($form, [$submission->id], 'markRead');
            if ($result['done']) {
                $submission->read = true;
            }
        }

        $criteria = $this->criteria();
        $pageNum = max(1, (int)$this->request->getQueryParam('page', 1));
        [$prev, $next] = $this->neighbours($form, $criteria, $pageNum, $submission->id);

        return $this->renderTemplate('basin/submissions/_view', [
            'settings' => $plugin->getSettings(),
            'form' => $form,
            'submission' => $submission,
            'criteria' => $criteria,
            'listParams' => $this->listParams($criteria, $pageNum),
            'prev' => $prev,
            'next' => $next,
        ] + $permissions);
    }

    /**
     * Triage or delete one or more submissions of a form.
     */
    public function actionApply(): ?Response
    {
        $this->requirePostRequest();
        $plugin = Plugin::getInstance();
        $form = $this->requireForm((string)$this->request->getRequiredBodyParam('formUuid'));
        $action = (string)$this->request->getRequiredBodyParam('basinAction');
        $ids = array_values(array_unique(array_filter(array_map('intval', (array)$this->request->getBodyParam('ids', [])))));

        if (!$ids) {
            return $this->asFailure(Craft::t('basin', 'No submissions were selected.'));
        }

        if ($action === 'delete') {
            if (!$plugin->getSettings()->allowDeletion) {
                throw new ForbiddenHttpException('Deleting submissions is turned off.');
            }
            $this->requirePermission('basin-deleteSubmissions');
        } elseif (isset(Submissions::ACTIONS[$action])) {
            $this->requirePermission('basin-manageSubmissions');
        } else {
            throw new BadRequestHttpException("Unknown action: $action");
        }

        if (count($ids) > Submissions::SYNC_LIMIT) {
            Queue::push(new BulkSubmissionAction([
                'formUuid' => $form->uuid,
                'action' => $action,
                'ids' => $ids,
            ]));
            return $this->asSuccess(Craft::t('basin', '{count} submissions are being updated in the background. Refresh in a moment to see the changes.', ['count' => count($ids)]));
        }

        $service = $plugin->getSubmissions();
        $result = $action === 'delete'
            ? $service->delete($form, $ids)
            : $service->applyAction($form, $ids, $action);

        if ($result['failed']) {
            $message = Craft::t('basin', '{done, plural, =1{1 submission was} other{# submissions were}} updated, but {failed, plural, =1{1} other{#}} failed: {errors}', [
                'done' => $result['done'],
                'failed' => $result['failed'],
                'errors' => implode(' ', $result['errors']),
            ]);
            return $result['done'] ? $this->asSuccess($message) : $this->asFailure($message);
        }

        return $this->asSuccess($this->successMessage($action, $result['done']));
    }

    /**
     * Streams an attachment from Basin, so its unauthenticated public URL is
     * never shown (spec §7.2).
     */
    /**
     * Asks Basin to send one submission to its form's webhooks again.
     */
    public function actionResendWebhooks(): ?Response
    {
        $this->requirePostRequest();
        $this->requirePermission('basin-manageSubmissions');
        $form = $this->requireForm((string)$this->request->getRequiredBodyParam('formUuid'));
        $id = (int)$this->request->getRequiredBodyParam('id');

        try {
            Plugin::getInstance()->getSubmissions()->resendWebhooks($form, $id);
        } catch (\InvalidArgumentException) {
            throw new NotFoundHttpException('Submission not found.');
        } catch (ApiException $e) {
            return $this->asFailure(Craft::t('basin', 'Couldn’t reach Basin: {error}', ['error' => $e->getMessage()]));
        }
        return $this->asSuccess(Craft::t('basin', 'Basin is sending this submission to the form’s webhooks again.'));
    }

    public function actionDownload(string $uuid, int $id, int $attachmentId): Response
    {
        $form = $this->requireForm($uuid);
        $plugin = Plugin::getInstance();

        $submission = $plugin->getSubmissions()->getSubmission($form, $id);
        $attachment = null;
        foreach ($submission?->attachments ?? [] as $a) {
            if ($a['id'] === $attachmentId) {
                $attachment = $a;
            }
        }
        if (!$attachment) {
            throw new NotFoundHttpException('Attachment not found.');
        }

        try {
            $upstream = $plugin->getApi()->getClient()->downloadAttachment($attachmentId);
        } catch (ApiException $e) {
            throw new \yii\web\ServerErrorHttpException(Craft::t('basin', 'Couldn’t download the file from Basin: {error}', ['error' => $e->getMessage()]), 0, $e);
        }

        // Basin doesn't send Content-Length and the upstream stream can't
        // seek, so copy it to a temp stream (memory, spilling to disk past
        // 2 MB) to get an exact size. Nothing is kept after the request.
        $source = $upstream->getBody();
        $temp = fopen('php://temp/maxmemory:2097152', 'w+b');
        while (!$source->eof()) {
            fwrite($temp, $source->read(1048576));
        }
        $size = ftell($temp);
        rewind($temp);

        $filename = preg_replace('/[^\w.\- ()]+/u', '_', $attachment['name']) ?: 'attachment';

        // Always a download, never inline, so an uploaded HTML or SVG file
        // can't run in the CP's origin.
        return $this->response->sendStreamAsFile($temp, $filename, [
            'mimeType' => 'application/octet-stream',
            'inline' => false,
            'fileSize' => $size,
        ]);
    }

    /**
     * Refreshes the dashboard widget and nav badge counts.
     */
    public function actionRefreshRecent(): Response
    {
        TagDependency::invalidate(Craft::$app->getCache(), Submissions::RECENT_TAG);
        return $this->redirect('dashboard');
    }

    /**
     * Exports the current view as CSV (spec §8). Small exports download
     * straight away; larger ones are queued.
     */
    public function actionExport(): Response
    {
        $this->requirePostRequest();
        $this->requirePermission('basin-exportSubmissions');
        $plugin = Plugin::getInstance();
        $form = $this->requireForm((string)$this->request->getRequiredBodyParam('formUuid'));
        $criteria = $this->criteria();
        $technical = (bool)$this->request->getBodyParam('technical');

        try {
            $total = $plugin->getSubmissions()->getPage($form, $criteria)['total'];
        } catch (ApiException $e) {
            return $this->asFailure(Craft::t('basin', 'Couldn’t reach Basin: {error}', ['error' => $e->getMessage()]));
        }

        if ($total !== null && $total > Exports::SYNC_MAX_ROWS) {
            $plugin->getExports()->queue($form, $criteria, $technical, (int)Craft::$app->getUser()->getId());
            $this->setSuccessFlash(Craft::t('basin', 'Your export of {count, number} submissions is being prepared. It’ll be ready to download here shortly.', ['count' => $total]));
            return $this->redirect(UrlHelper::cpUrl("basin/forms/$form->uuid/submissions", $this->listParams($criteria, 1)));
        }

        $out = fopen('php://temp/maxmemory:8388608', 'w+b');
        try {
            $plugin->getExports()->build($form, $criteria, $technical, $out);
        } catch (ApiException $e) {
            fclose($out);
            return $this->asFailure(Craft::t('basin', 'Couldn’t reach Basin: {error}', ['error' => $e->getMessage()]));
        }
        $size = ftell($out);
        rewind($out);

        return $this->response->sendStreamAsFile($out, CsvExport::filename($form->name, $criteria['tab'], date('Y-m-d')), [
            'mimeType' => 'text/csv',
            'inline' => false,
            'fileSize' => $size,
        ]);
    }

    /**
     * Downloads a finished export. Only the user who asked for it can, and
     * it's deleted once sent.
     */
    public function actionDownloadExport(string $exportId): Response
    {
        $this->requirePermission('basin-exportSubmissions');
        $exports = Plugin::getInstance()->getExports();
        $token = $exportId;
        $meta = $this->requireExport($token);
        if ($meta['status'] !== 'ready' || !is_file($exports->csvPath($token))) {
            throw new NotFoundHttpException(Craft::t('basin', 'That export isn’t ready, or has already been downloaded.'));
        }

        $this->response->on(Response::EVENT_AFTER_SEND, fn() => $exports->delete($token));
        return $this->response->sendFile($exports->csvPath($token), $meta['filename'], [
            'mimeType' => 'text/csv',
            'inline' => false,
        ]);
    }

    /** Status of a queued export, for polling. */
    public function actionExportStatus(string $exportId): Response
    {
        // Not `token`, which Craft reserves for its own tokens.
        $this->requireAcceptsJson();
        $meta = $this->requireExport($exportId);
        return $this->asJson([
            'status' => $meta['status'],
            'progress' => $meta['progress'],
        ]);
    }

    public function actionDeleteExport(): Response
    {
        $this->requirePostRequest();
        $token = (string)$this->request->getRequiredBodyParam('exportId');
        $this->requireExport($token);
        Plugin::getInstance()->getExports()->delete($token);
        return $this->asSuccess(Craft::t('basin', 'Export removed.'));
    }

    private function requireExport(string $token): array
    {
        if (!preg_match('/^[a-f0-9]{32}$/', $token)) {
            throw new NotFoundHttpException();
        }
        return Plugin::getInstance()->getExports()->getForUser($token, (int)Craft::$app->getUser()->getId())
            ?? throw new NotFoundHttpException(Craft::t('basin', 'That export isn’t available. Exports are deleted once downloaded, or after 24 hours.'));
    }

    /**
     * Filters from the query string, validated.
     *
     * @return array{tab: string, query: string, order: ?string, dateFrom: ?string, dateTo: ?string}
     */
    private function criteria(): array
    {
        $date = function(string $name): ?string {
            $value = (string)$this->request->getParam($name, '');
            return preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) ? $value : null;
        };
        $tab = (string)$this->request->getParam('tab', 'inbox');
        $order = (string)$this->request->getParam('order', '');

        return [
            'tab' => array_key_exists($tab, Submissions::TABS) ? $tab : 'inbox',
            'query' => mb_substr(trim((string)$this->request->getParam('q', '')), 0, 200),
            'order' => in_array($order, ApiClient::SUBMISSION_ORDERS, true) ? $order : null,
            'dateFrom' => $date('from'),
            'dateTo' => $date('to'),
        ];
    }

    private function listParams(array $criteria, int $page): array
    {
        return array_filter([
            'tab' => $criteria['tab'] !== 'inbox' ? $criteria['tab'] : null,
            'q' => $criteria['query'] !== '' ? $criteria['query'] : null,
            'order' => $criteria['order'],
            'from' => $criteria['dateFrom'],
            'to' => $criteria['dateTo'],
            'page' => $page > 1 ? $page : null,
        ]);
    }

    /**
     * The submissions before and after this one in the current filtered list.
     *
     * @return array{0: ?array{id: int, page: int}, 1: ?array{id: int, page: int}}
     */
    private function neighbours(BasinForm $form, array $criteria, int $page, int $id): array
    {
        $service = Plugin::getInstance()->getSubmissions();
        try {
            $current = $service->getPage($form, $criteria, $page);
            $ids = array_map(fn($s) => $s->id, $current['submissions']);
            $index = array_search($id, $ids, true);
            if ($index === false) {
                return [null, null];
            }

            $prev = $index > 0 ? ['id' => $ids[$index - 1], 'page' => $page] : null;
            if ($prev === null && $page > 1) {
                $before = $service->getPage($form, $criteria, $page - 1)['submissions'];
                $prev = $before ? ['id' => end($before)->id, 'page' => $page - 1] : null;
            }

            $next = $index < count($ids) - 1 ? ['id' => $ids[$index + 1], 'page' => $page] : null;
            $more = $current['total'] !== null && $page * $current['perPage'] < $current['total'];
            if ($next === null && $more) {
                $after = $service->getPage($form, $criteria, $page + 1)['submissions'];
                $next = $after ? ['id' => $after[0]->id, 'page' => $page + 1] : null;
            }
            return [$prev, $next];
        } catch (ApiException) {
            return [null, null];
        }
    }

    private function permissionVariables(): array
    {
        $user = Craft::$app->getUser();
        return [
            'canManage' => $user->checkPermission('basin-manageSubmissions'),
            'canExport' => $user->checkPermission('basin-exportSubmissions'),
            'canDelete' => Plugin::getInstance()->getSettings()->allowDeletion && $user->checkPermission('basin-deleteSubmissions'),
        ];
    }

    private function successMessage(string $action, int $count): string
    {
        $params = ['count' => $count];
        return match ($action) {
            'markRead' => Craft::t('basin', '{count, plural, =1{Marked as read} other{# submissions marked as read}}.', $params),
            'markUnread' => Craft::t('basin', '{count, plural, =1{Marked as unread} other{# submissions marked as unread}}.', $params),
            'markSpam' => Craft::t('basin', '{count, plural, =1{Marked as spam} other{# submissions marked as spam}}.', $params),
            'markNotSpam' => Craft::t('basin', '{count, plural, =1{Moved to the inbox} other{# submissions moved to the inbox}}.', $params),
            'trash' => Craft::t('basin', '{count, plural, =1{Moved to trash} other{# submissions moved to trash}}.', $params),
            'restore' => Craft::t('basin', '{count, plural, =1{Restored} other{# submissions restored}}.', $params),
            'delete' => Craft::t('basin', '{count, plural, =1{Deleted} other{# submissions deleted}}.', $params),
            default => Craft::t('basin', 'Done.'),
        };
    }
}
