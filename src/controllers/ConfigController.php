<?php

namespace lameco\dash\controllers;

use Craft;
use craft\web\Controller;
use lameco\dash\Plugin;
use lameco\dash\queue\jobs\SyncJob;
use lameco\dash\utilities\DashUtility;
use yii\web\Response;

/**
 * Actions behind the Dash utility.
 *
 * The utility's own permission governs whether the page is reachable, but Craft only checks
 * that when rendering the utility — an action has to check for itself, or anyone who can
 * reach the control panel could post to it.
 */
class ConfigController extends Controller
{
    /**
     * Queues a reconcile rather than running one, because a real library takes minutes.
     */
    public function actionSync(): ?Response
    {
        $this->requirePostRequest();
        $this->requirePermission('utility:' . DashUtility::id());

        Craft::$app->getQueue()->push(new SyncJob(['force' => true]));

        Craft::$app->getSession()->setNotice(Craft::t(
            'dash-dam',
            'Syncing with Dash. Reload this page once the job has finished to see the result.',
        ));

        return $this->redirectToPostedUrl();
    }

    public function actionSaveFolders(): ?Response
    {
        $this->requirePostRequest();
        $this->requirePermission('utility:' . DashUtility::id());

        $folders = Craft::$app->getRequest()->getBodyParam('syncFolders') ?? [];

        if (!is_array($folders)) {
            $folders = [];
        }

        Plugin::getInstance()->getDashConfig()->setSyncFolders($folders);

        Craft::$app->getSession()->setNotice(Craft::t('dash-dam', 'Folder selection saved.'));

        return $this->redirectToPostedUrl();
    }
}
