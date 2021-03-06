<?php
defined('YII_DEBUG') or define('YII_DEBUG', true);
defined('YII_ENV') or define('YII_ENV', 'test');

require(__DIR__ . '/../vendor/autoload.php');
require(__DIR__ . '/../vendor/yiisoft/yii2/Yii.php');

Yii::setAlias('@tests', __DIR__);

new \yii\console\Application([
    'id' => 'unit',
    'basePath' => __DIR__,
    'components'=> [
        'db' => [
            'class'=>'yii\db\Connection',
            'dsn' => $GLOBALS['DB_DSN'],
            'username' => $GLOBALS['DB_USER'],
            'password' => $GLOBALS['DB_PASSWD'],
            'tablePrefix' => '',
            'charset' => 'utf8',
        ],
    ],
]);