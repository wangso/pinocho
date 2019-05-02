<?php

/**
 * @link https://www.humhub.org/
 * @copyright Copyright (c) 2017 HumHub GmbH & Co. KG
 * @license https://www.humhub.com/licences
 */

namespace humhub\libs;

use Yii;
use yii\base\BaseObject;
use yii\helpers\ArrayHelper;

/**
 * DynamicConfig provides access to the dynamic configuration file.
 *
 * @author luke
 */
class DynamicConfig extends BaseObject
{

    /**
     * Add an array to the dynamic configuration
     *
     * @param array $new
     */
    public static function merge($new)
    {
        $config = ArrayHelper::merge(self::load(), $new);
        self::save($config);
    }

    /**
     * Returns the dynamic configuration
     *
     * @return array
     */
    public static function load()
    {
        $configFile = self::getConfigFilePath();

        if (!is_file($configFile)) {
            self::save([]);
        }

        // Load config file with 'file_get_contents' and 'eval'
        // because 'require' don't reload the file when it's changed on runtime
        $configContent = str_replace(['<' . '?php', '<' . '?', '?' . '>'], '', file_get_contents($configFile));
        $config = eval($configContent);

        if (!is_array($config)) {
            return [];
        }

        return $config;
    }

    /**
     * Sets a new dynamic configuration
     *
     * @param array $config
     */
    public static function save($config)
    {
        $content = '<' . '?php return ';
        $content .= var_export($config, true);
        $content .= '; ?' . '>';

        $configFile = self::getConfigFilePath();
        file_put_contents($configFile, $content);

        if (function_exists('opcache_invalidate')) {
            opcache_invalidate($configFile);
        }

        if (function_exists('apc_compile_file')) {
            apc_compile_file($configFile);
        }
    }

    /**
     * Rewrites DynamicConfiguration based on Database Stored Settings
     */
    public static function rewrite()
    {
        // Get Current Configuration
        $config = self::load();

        // Add Application Name to Configuration
        $config['name'] = Yii::$app->settings->get('name');

        // Add Default language
        $defaultLanguage = Yii::$app->settings->get('defaultLanguage');
        if ($defaultLanguage !== null && $defaultLanguage != '') {
            $config['language'] = Yii::$app->settings->get('defaultLanguage');
        } else {
            $config['language'] = Yii::$app->language;
        }

        $timeZone = Yii::$app->settings->get('timeZone');
        if ($timeZone != '') {
            $config['timeZone'] = $timeZone;
            $config['components']['formatter']['defaultTimeZone'] = $timeZone;
            $config['components']['formatterApp']['defaultTimeZone'] = $timeZone;
            $config['components']['formatterApp']['timeZone'] = $timeZone;
        }

        // Add Caching
        $cacheClass = Yii::$app->settings->get('cache.class');
        if (in_array($cacheClass, ['yii\caching\DummyCache', 'yii\caching\FileCache'])) {
            $config['components']['cache'] = [
                'class' => $cacheClass,
                'keyPrefix' => Yii::$app->id
            ];
        } elseif ($cacheClass == 'yii\caching\ApcCache' && (function_exists('apcu_add') || function_exists('apc_add'))) {
            $config['components']['cache'] = [
                'class' => $cacheClass,
                'keyPrefix' => Yii::$app->id,
                'useApcu' => (function_exists('apcu_add'))
            ];
        } elseif ($cacheClass === \yii\redis\Cache::class) {
            $config['components']['cache'] = [
                'class' => \yii\redis\Cache::class,
                'keyPrefix' => Yii::$app->id
            ];
        }

        // Add User settings
        $config['components']['user'] = [];
        if (Yii::$app->getModule('user')->settings->get('auth.defaultUserIdleTimeoutSec')) {
            $config['components']['user']['authTimeout'] = Yii::$app->getModule('user')->settings->get('auth.defaultUserIdleTimeoutSec');
        }

        // Install Mail Component
        $mail = [];
        $mail['transport'] = [];
        if (Yii::$app->settings->get('mailer.transportType') == 'smtp') {
            $mail['transport']['class'] = 'Swift_SmtpTransport';

            if (Yii::$app->settings->get('mailer.hostname')) {
                $mail['transport']['host'] = Yii::$app->settings->get('mailer.hostname');
            }

            if (Yii::$app->settings->get('mailer.username')) {
                $mail['transport']['username'] = Yii::$app->settings->get('mailer.username');
            } elseif (!Yii::$app->settings->get('mailer.password')) {
                $mail['transport']['authMode'] = 'null';
            }

            if (Yii::$app->settings->get('mailer.password')) {
                $mail['transport']['password'] = Yii::$app->settings->get('mailer.password');
            }

            if (Yii::$app->settings->get('mailer.encryption')) {
                $mail['transport']['encryption'] = Yii::$app->settings->get('mailer.encryption');
            }

            if (Yii::$app->settings->get('mailer.port')) {
                $mail['transport']['port'] = Yii::$app->settings->get('mailer.port');
            }
        } elseif (Yii::$app->settings->get('mailer.transportType') == 'php') {
            $mail['transport']['class'] = 'Swift_MailTransport';
        } else {
            $mail['useFileTransport'] = true;
        }
        $config['components']['mailer'] = $mail;


        // Remove old theme/view stuff
        unset($config['components']['view']);
        unset($config['components']['mailer']['view']);

        $config['params']['config_created_at'] = time();
        $config['params']['horImageScrollOnMobile'] = Yii::$app->settings->get('horImageScrollOnMobile');

        self::save($config);
    }

    public static function getConfigFilePath()
    {
        return Yii::getAlias(Yii::$app->params['dynamicConfigFile']);
    }
}
