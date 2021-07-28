<?php
/**
 * @link https://craftcms.com/
 * @copyright Copyright (c) Pixel & Tonic, Inc.
 * @license https://craftcms.github.io/license/
 */

namespace craft\web\assets\login;

use craft\validators\UserPasswordValidator;
use craft\web\assets\authentication\chain\ChainAsset;
use craft\web\assets\cp\CpAsset;
use craft\web\View;
use yii\web\AssetBundle;

/**
 * Asset bundle for the Login page
 */
class LoginAsset extends AssetBundle
{
    /**
     * @inheritdoc
     */
    public $sourcePath = __DIR__ . '/dist';

    /**
     * @inheritdoc
     */
    public $depends = [
        CpAsset::class,
        ChainAsset::class
    ];

    /**
     * @inheritdoc
     */
    public $css = [
        'login.css',
    ];

    /**
     * @inheritdoc
     */
    public $js = [
        'LoginForm.min.js',
    ];
}
