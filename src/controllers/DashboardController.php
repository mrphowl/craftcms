<?php
/**
 * @link https://craftcms.com/
 * @copyright Copyright (c) Pixel & Tonic, Inc.
 * @license https://craftcms.github.io/license/
 */

namespace craft\controllers;

use Craft;
use craft\base\WidgetInterface;
use craft\helpers\App;
use craft\helpers\ArrayHelper;
use craft\helpers\Component;
use craft\helpers\FileHelper;
use craft\helpers\Json;
use craft\helpers\StringHelper;
use craft\i18n\Locale;
use craft\models\CraftSupport;
use craft\web\assets\dashboard\DashboardAsset;
use craft\web\Controller;
use craft\web\UploadedFile;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\RequestOptions;
use Symfony\Component\Yaml\Yaml;
use yii\base\Exception;
use yii\base\InvalidArgumentException;
use yii\web\BadRequestHttpException;
use yii\web\Response;
use ZipArchive;

/**
 * The DashboardController class is a controller that handles various dashboard related actions including managing
 * widgets, getting [[\craft\widgets\Feed]] feeds and sending [[\craft\widgets\CraftSupport]] support ticket requests.
 * Note that all actions in the controller require an authenticated Craft session via [[allowAnonymous]].
 *
 * @author Pixel & Tonic, Inc. <support@pixelandtonic.com>
 * @since 3.0.0
 */
class DashboardController extends Controller
{
    /**
     * @inheritdoc
     */
    public function beforeAction($action)
    {
        if (!parent::beforeAction($action)) {
            return false;
        }

        $this->requireCpRequest();
        return true;
    }

    /**
     * Dashboard index.
     *
     * @return Response
     */
    public function actionIndex(): Response
    {
        $dashboardService = Craft::$app->getDashboard();
        $view = $this->getView();

        $namespace = $view->getNamespace();

        // Assemble the list of available widget types
        $widgetTypes = $dashboardService->getAllWidgetTypes();
        $widgetTypeInfo = [];
        $view->setNamespace('__NAMESPACE__');

        foreach ($widgetTypes as $widgetType) {
            /** @var WidgetInterface $widgetType */
            if (!$widgetType::isSelectable()) {
                continue;
            }

            $view->startJsBuffer();
            $widget = $dashboardService->createWidget($widgetType);
            $settingsHtml = $view->namespaceInputs((string)$widget->getSettingsHtml());
            $settingsJs = (string)$view->clearJsBuffer(false);

            $class = get_class($widget);
            $widgetTypeInfo[$class] = [
                'iconSvg' => $this->_getWidgetIconSvg($widget),
                'name' => $widget::displayName(),
                'maxColspan' => $widget::maxColspan(),
                'settingsHtml' => $settingsHtml,
                'settingsJs' => $settingsJs,
                'selectable' => true,
            ];
        }

        // Sort them by name
        ArrayHelper::multisort($widgetTypeInfo, 'name');

        $view->setNamespace($namespace);
        $variables = [];

        // Assemble the list of existing widgets
        $variables['widgets'] = [];
        $widgets = $dashboardService->getAllWidgets();
        $allWidgetJs = '';

        foreach ($widgets as $widget) {
            $view->startJsBuffer();
            $info = $this->_getWidgetInfo($widget);
            $widgetJs = $view->clearJsBuffer(false);

            if ($info === false) {
                continue;
            }

            // If this widget type didn't come back in our getAllWidgetTypes() call, add it now
            if (!isset($widgetTypeInfo[$info['type']])) {
                $widgetTypeInfo[$info['type']] = [
                    'iconSvg' => $this->_getWidgetIconSvg($widget),
                    'name' => $widget::displayName(),
                    'maxColspan' => $widget::maxColspan(),
                    'selectable' => false,
                ];
            }

            $variables['widgets'][] = $info;

            $allWidgetJs .= 'new Craft.Widget("#widget' . $widget->id . '", ' .
                Json::encode($info['settingsHtml']) . ', ' .
                'function(){' . $info['settingsJs'] . '}' .
                ");\n";

            if (!empty($widgetJs)) {
                // Allow any widget JS to execute *after* we've created the Craft.Widget instance
                $allWidgetJs .= $widgetJs . "\n";
            }
        }

        // Include all the JS and CSS stuff
        $view->registerAssetBundle(DashboardAsset::class);
        $view->registerJs('window.dashboard = new Craft.Dashboard(' . Json::encode($widgetTypeInfo) . ');');
        $view->registerJs($allWidgetJs);

        $variables['widgetTypes'] = $widgetTypeInfo;

        return $this->renderTemplate('dashboard/_index', $variables);
    }

    /**
     * Creates a new widget.
     *
     * @return Response
     */
    public function actionCreateWidget(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        $dashboardService = Craft::$app->getDashboard();

        $type = $this->request->getRequiredBodyParam('type');
        $settingsNamespace = $this->request->getBodyParam('settingsNamespace');

        if ($settingsNamespace) {
            $settings = $this->request->getBodyParam($settingsNamespace);
        } else {
            $settings = null;
        }

        $widget = $dashboardService->createWidget([
            'type' => $type,
            'settings' => $settings,
        ]);

        return $this->_saveAndReturnWidget($widget);
    }

    /**
     * Saves a widget’s settings.
     *
     * @return Response
     * @throws BadRequestHttpException
     */
    public function actionSaveWidgetSettings(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        $dashboardService = Craft::$app->getDashboard();
        $widgetId = $this->request->getRequiredBodyParam('widgetId');

        // Get the existing widget
        $widget = $dashboardService->getWidgetById($widgetId);

        if (!$widget) {
            throw new BadRequestHttpException();
        }

        // Create a new widget model with the new settings
        $settings = $this->request->getBodyParam('widget' . $widget->id . '-settings');

        $widget = $dashboardService->createWidget([
            'id' => $widget->id,
            'dateCreated' => $widget->dateCreated,
            'dateUpdated' => $widget->dateUpdated,
            'colspan' => $widget->colspan,
            'type' => get_class($widget),
            'settings' => $settings,
        ]);

        return $this->_saveAndReturnWidget($widget);
    }

    /**
     * Deletes a widget.
     *
     * @return Response
     */
    public function actionDeleteUserWidget(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        $widgetId = Json::decode($this->request->getRequiredBodyParam('id'));
        Craft::$app->getDashboard()->deleteWidgetById($widgetId);

        return $this->asJson(['success' => true]);
    }

    /**
     * Changes the colspan of a widget.
     *
     * @return Response
     */
    public function actionChangeWidgetColspan(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        $widgetId = $this->request->getRequiredBodyParam('id');
        $colspan = $this->request->getRequiredBodyParam('colspan');

        Craft::$app->getDashboard()->changeWidgetColspan($widgetId, $colspan);

        return $this->asJson(['success' => true]);
    }

    /**
     * Reorders widgets.
     *
     * @return Response
     */
    public function actionReorderUserWidgets(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        $widgetIds = Json::decode($this->request->getRequiredBodyParam('ids'));
        Craft::$app->getDashboard()->reorderWidgets($widgetIds);

        return $this->asJson(['success' => true]);
    }

    /**
     * Returns the items for the Feed widget.
     *
     * @return Response
     * @deprecated in 3.4.24
     */
    public function actionGetFeedItems(): Response
    {
        $this->requireAcceptsJson();

        $formatter = Craft::$app->getFormatter();

        $url = $this->request->getRequiredParam('url');
        $limit = $this->request->getParam('limit');

        $feed = Craft::$app->getFeeds()->getFeed($url);

        $locale = null;
        if ($feed['language'] !== null) {
            try {
                $locale = new Locale($feed['language']);
            } catch (InvalidArgumentException $e) {
            }
        }
        if ($locale === null) {
            $locale = new Locale('en-US');
        }


        if ($limit) {
            $feed['items'] = array_slice($feed['items'], 0, $limit);
        }

        foreach ($feed['items'] as &$item) {
            if ($item['date'] !== null) {
                $item['date'] = $formatter->asTimestamp($item['date'], Locale::LENGTH_SHORT);
            } else {
                unset($item['date']);
            }
        }

        return $this->asJson([
            'dir' => $locale->getOrientation(),
            'items' => $feed['items'],
        ]);
    }

    /**
     * Caches feed data.
     *
     * @return Response
     * @since 3.4.24
     */
    public function actionCacheFeedData(): Response
    {
        $url = $this->request->getRequiredBodyParam('url');
        $data = $this->request->getRequiredBodyParam('data');
        Craft::$app->getCache()->set("feed:$url", $data);
        return $this->asJson(['success' => true]);
    }


    /**
     * Creates a new support ticket for the CraftSupport widget.
     *
     * @return Response
     * @throws BadRequestHttpException
     */
    public function actionSendSupportRequest(): Response
    {
        $this->requirePostRequest();

        App::maxPowerCaptain();

        $widgetId = $this->request->getBodyParam('widgetId');
        $namespace = $this->request->getBodyParam('namespace');
        $namespace = $namespace ? $namespace . '.' : '';

        $getHelpModel = new CraftSupport();
        $getHelpModel->fromEmail = $this->request->getBodyParam($namespace . 'fromEmail');
        $getHelpModel->message = trim($this->request->getBodyParam($namespace . 'message'));
        $getHelpModel->attachLogs = (bool)$this->request->getBodyParam($namespace . 'attachLogs');
        $getHelpModel->attachDbBackup = (bool)$this->request->getBodyParam($namespace . 'attachDbBackup');
        $getHelpModel->attachTemplates = (bool)$this->request->getBodyParam($namespace . 'attachTemplates');
        $getHelpModel->attachment = UploadedFile::getInstanceByName($namespace . 'attachAdditionalFile');

        if (!$getHelpModel->validate()) {
            return $this->renderTemplate('_components/widgets/CraftSupport/response', [
                'widgetId' => $widgetId,
                'success' => false,
                'errors' => $getHelpModel->getErrors(),
            ]);
        }

        $parts = [
            [
                'name' => 'email',
                'contents' => $getHelpModel->fromEmail,
            ],
            [
                'name' => 'name',
                'contents' => Craft::$app->getUser()->getIdentity()->getName(),
            ],
            [
                'name' => 'message',
                'contents' => $getHelpModel->message,
            ],
        ];

        // If there's a custom attachment, see if we should include it in the zip
        $zipAttachment = $getHelpModel->attachment && $this->_shouldZipAttachment($getHelpModel->attachment);

        // Create the SupportAttachment zip
        $zipPath = Craft::$app->getPath()->getTempPath() . '/' . StringHelper::UUID() . '.zip';
        try {
            // Create the zip
            $zip = new ZipArchive();

            if ($zip->open($zipPath, ZipArchive::CREATE) !== true) {
                throw new Exception('Cannot create zip at ' . $zipPath);
            }

            // License key
            if (($licenseKey = App::licenseKey()) !== null) {
                $zip->addFromString('license.key', $licenseKey);
            }

            // Composer files
            try {
                $composerService = Craft::$app->getComposer();
                $zip->addFile($composerService->getJsonPath(), 'composer.json');
                if (($composerLockPath = $composerService->getLockPath()) !== null) {
                    $zip->addFile($composerLockPath, 'composer.lock');
                }
            } catch (Exception $e) {
                // that's fine
            }

            // project.yaml
            $projectConfig = Craft::$app->getProjectConfig()->get();
            $projectConfig = Craft::$app->getSecurity()->redactIfSensitive('', $projectConfig);
            $zip->addFromString('project.yaml', Yaml::dump($projectConfig, 20, 2));

            // project.yaml backups
            $configBackupPath = Craft::$app->getPath()->getConfigBackupPath(false);
            $zip->addGlob($configBackupPath . '/*', 0, [
                'remove_all_path' => true,
                'add_path' => 'config-backups/',
            ]);

            // Logs
            if ($getHelpModel->attachLogs) {
                $logPath = Craft::$app->getPath()->getLogPath();
                FileHelper::addFilesToZip($zip, $logPath, 'logs', [
                    'only' => ['*.log'],
                    'except' => ['web-404s.log'],
                    'recursive' => false,
                ]);
            }

            // DB backups
            if ($getHelpModel->attachDbBackup) {
                // Make a fresh database backup of the current schema/data. We want all data from all tables
                // for debugging.
                try {
                    $backupPath = Craft::$app->getDb()->backup();
                    $zip->addFile($backupPath, basename($backupPath));
                } catch (\Throwable $e) {
                    Craft::warning('Error adding database backup to support request: ' . $e->getMessage(), __METHOD__);
                    $getHelpModel->message .= "\n\n---\n\nError adding database backup: " . $e->getMessage();
                }
            }

            // Templates
            if ($getHelpModel->attachTemplates) {
                $templatesPath = Craft::$app->getPath()->getSiteTemplatesPath();
                FileHelper::addFilesToZip($zip, $templatesPath, 'templates');
            }

            // Attachment?
            if ($zipAttachment) {
                $zip->addFile($getHelpModel->attachment->tempName, $getHelpModel->attachment->name);
            }

            // Close and attach the zip
            $zip->close();
            $parts[] = [
                'name' => 'attachments[0]',
                'contents' => fopen($zipPath, 'rb'),
                'filename' => 'SupportAttachment-' . FileHelper::sanitizeFilename(Craft::$app->getSites()->getPrimarySite()->getName()) . '.zip',
            ];
        } catch (\Throwable $e) {
            Craft::warning('Error creating support zip: ' . $e->getMessage(), __METHOD__);
            $getHelpModel->message .= "\n\n---\n\nError creating zip: " . $e->getMessage();
        }

        // Uploaded attachment separately?
        if ($getHelpModel->attachment && !$zipAttachment) {
            $parts[] = [
                'name' => 'attachments[1]',
                'contents' => fopen($getHelpModel->attachment->tempName, 'rb'),
                'filename' => $getHelpModel->attachment->name,
            ];
        }

        try {
            Craft::$app->getApi()->request('POST', 'support', [
                RequestOptions::MULTIPART => $parts,
            ]);
        } catch (RequestException $requestException) {
        }

        // Delete the zip file
        if (is_file($zipPath)) {
            FileHelper::unlink($zipPath);
        }

        if (isset($requestException)) {
            return $this->renderTemplate('_components/widgets/CraftSupport/response', [
                'widgetId' => $widgetId,
                'success' => false,
                'errors' => [
                    'Support' => [$requestException->getMessage()],
                ],
            ]);
        }

        return $this->renderTemplate('_components/widgets/CraftSupport/response', [
            'widgetId' => $widgetId,
            'success' => true,
            'errors' => [],
        ]);
    }

    /**
     * Returns the info about a widget required to display its body and settings in the Dashboard.
     *
     * @param WidgetInterface $widget
     * @return array|false
     */
    private function _getWidgetInfo(WidgetInterface $widget)
    {
        $view = $this->getView();
        $namespace = $view->getNamespace();

        // Get the body HTML
        $widgetBodyHtml = $widget->getBodyHtml();

        if ($widgetBodyHtml === false) {
            return false;
        }

        // Get the settings HTML + JS
        $view->setNamespace('widget' . $widget->id . '-settings');
        $view->startJsBuffer();
        $settingsHtml = $view->namespaceInputs((string)$widget->getSettingsHtml());
        $settingsJs = $view->clearJsBuffer(false);

        // Get the colspan (limited to the widget type's max allowed colspan)
        $colspan = ($widget->colspan ?: 1);

        if (($maxColspan = $widget::maxColspan()) && $colspan > $maxColspan) {
            $colspan = $maxColspan;
        }

        $view->setNamespace($namespace);

        return [
            'id' => $widget->id,
            'type' => get_class($widget),
            'colspan' => $colspan,
            'title' => $widget->getTitle(),
            'subtitle' => $widget->getSubtitle(),
            'name' => $widget->displayName(),
            'bodyHtml' => $widgetBodyHtml,
            'settingsHtml' => $settingsHtml,
            'settingsJs' => (string)$settingsJs,
        ];
    }

    /**
     * Returns a widget type’s SVG icon.
     *
     * @param WidgetInterface $widget
     * @return string
     */
    private function _getWidgetIconSvg(WidgetInterface $widget): string
    {
        return Component::iconSvg($widget::icon(), $widget::displayName());
    }

    /**
     * Attempts to save a widget and responds with JSON.
     *
     * @param WidgetInterface $widget
     * @return Response
     */
    private function _saveAndReturnWidget(WidgetInterface $widget): Response
    {
        $dashboardService = Craft::$app->getDashboard();

        if ($dashboardService->saveWidget($widget)) {
            $info = $this->_getWidgetInfo($widget);
            $view = $this->getView();

            return $this->asJson([
                'success' => true,
                'info' => $info,
                'headHtml' => $view->getHeadHtml(),
                'footHtml' => $view->getBodyHtml(),
            ]);
        }

        $allErrors = [];

        foreach ($widget->getErrors() as $attribute => $errors) {
            foreach ($errors as $error) {
                $allErrors[] = $error;
            }
        }

        return $this->asJson([
            'errors' => $allErrors,
        ]);
    }

    /**
     * Returns whether we should zip the custom support attachment.
     *
     * @param string $file
     * @return bool
     */
    private function _shouldZipAttachment(UploadedFile $file): bool
    {
        // If it's > 2 MB, just do it
        if (filesize($file->tempName) > 1024 * 1024 * 2) {
            return true;
        }

        $mimeType = $file->getMimeType();

        if ($mimeType === null) {
            return true;
        }

        return (
            !in_array($mimeType, [
                'application/json',
                'application/pdf',
                'application/x-yaml',
            ], true) &&
            strpos($mimeType, 'text/') !== 0 &&
            strpos($mimeType, 'image/') !== 0 &&
            strpos($mimeType, 'xml') === false
        );
    }
}
