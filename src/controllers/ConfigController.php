<?php

namespace lameco\dash\controllers;

use Craft;
use craft\web\Controller;
use lameco\dash\Plugin;
use lameco\dash\utilities\DashUtility;
use yii\web\Response;

/**
 * Saves the folder selection posted from the Dash utility.
 *
 * The utility's own permission governs whether the page is reachable, but Craft only checks
 * that when rendering the utility — an action has to check for itself, or anyone who can
 * reach the control panel could post to it.
 */
class ConfigController extends Controller
{
    public function actionSaveFolders(): ?Response
    {
        $this->requirePostRequest();
        $this->requirePermission('utility:' . DashUtility::id());

        $folders = Craft::$app->getRequest()->getBodyParam('syncFolders') ?? [];

        if (!is_array($folders)) {
            $folders = [];
        }

        Plugin::getInstance()->getDashConfig()->setSyncFolders($folders);

        Craft::$app->getSession()->setNotice(Craft::t('_craft-dash', 'Folder selection saved.'));

        return $this->redirectToPostedUrl();
    }
}
