<?php
namespace Helhum\Typo3Console\Core\Booting;

/*
 * This file is part of the TYPO3 Console project.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 *
 * For the full copyright and license information, please read
 * LICENSE file that was distributed with this source code.
 *
 */

use Helhum\Typo3Console\Core\Cache\FakeDatabaseBackend;
use Helhum\Typo3Console\Error\ErrorHandler;
use TYPO3\CMS\Core\Authentication\CommandLineUserAuthentication;
use TYPO3\CMS\Core\Cache\Backend\NullBackend;
use TYPO3\CMS\Core\Cache\Backend\Typo3DatabaseBackend;
use TYPO3\CMS\Core\Cache\CacheManager;
use TYPO3\CMS\Core\Core\Bootstrap;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\DatabaseConnection;
use TYPO3\CMS\Core\Package\PackageManager;
use TYPO3\CMS\Core\Utility\ExtensionManagementUtility;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Extbase\Command\HelpCommandController;
use TYPO3\CMS\Extensionmanager\Command\ExtensionCommandController;

/**
 * Class Scripts
 */
class Scripts
{
    /**
     * @var array
     */
    protected static $earlyCachesConfiguration = [];

    /**
     * @param Bootstrap $bootstrap
     */
    public static function initializeConfigurationManagement(Bootstrap $bootstrap)
    {
        call_user_func(\Closure::bind(function () use ($bootstrap) {
            $bootstrap->populateLocalConfiguration();
            if (empty($GLOBALS['TYPO3_CONF_VARS']['SC_OPTIONS']['extbase']['commandControllers'])) {
                $GLOBALS['TYPO3_CONF_VARS']['SC_OPTIONS']['extbase']['commandControllers'] = [];
            }
            if (!Bootstrap::usesComposerClassLoading()) {
                $bootstrap->initializeRuntimeActivatedPackagesFromConfiguration();
            }
            // Because links might be generated from CLI (e.g. by Solr indexer)
            // We need to properly initialize the cache hash calculator here!
            // @deprecated can be removed if TYPO3 8 support is removed
            if (is_callable([$bootstrap, 'setCacheHashOptions'])) {
                $bootstrap->setCacheHashOptions();
            }
            // @deprecated can be removed if TYPO3 8 support is removed
            if (is_callable([$bootstrap, 'defineUserAgentConstant'])) {
                $bootstrap->defineUserAgentConstant();
            }
            // @deprecated can be removed if TYPO3 7 support is removed
            if (is_callable([$bootstrap, 'defineDatabaseConstants'])) {
                $bootstrap->defineDatabaseConstants();
            }
            $bootstrap->setDefaultTimezone();
        }, null, $bootstrap));

        self::disableCachesForObjectManagement();
    }

    public static function disableCachesForObjectManagement()
    {
        $cacheConfigurations = &$GLOBALS['TYPO3_CONF_VARS']['SYS']['caching']['cacheConfigurations'];
        foreach (
            [
                'extbase_object',
                'extbase_reflection',
                'extbase_typo3dbbackend_tablecolumns',
                'extbase_typo3dbbackend_queries',
                'extbase_datamapfactory_datamap',
            ] as $id) {
            if (!isset($cacheConfigurations[$id])) {
                continue;
            }
            self::$earlyCachesConfiguration[$id] = $cacheConfigurations[$id];
            if (empty($cacheConfigurations[$id]['backend']) || $cacheConfigurations[$id]['backend'] === Typo3DatabaseBackend::class) {
                $cacheConfigurations[$id]['backend'] = FakeDatabaseBackend::class;
            } else {
                $cacheConfigurations[$id]['backend'] = NullBackend::class;
            }
            $cacheConfigurations[$id]['options'] = [];
        }
    }

    public static function initializeErrorHandling()
    {
        $errorHandler = new ErrorHandler();
        $errorHandler->setExceptionalErrors([E_WARNING, E_USER_ERROR, E_USER_WARNING, E_USER_NOTICE, E_RECOVERABLE_ERROR]);
        set_error_handler([$errorHandler, 'handleError']);
        ini_set('display_errors', 1);
        if (((bool)ini_get('display_errors') && strtolower(ini_get('display_errors')) !== 'on' && strtolower(ini_get('display_errors')) !== '1') || !(bool)ini_get('display_errors')) {
            echo 'WARNING: Fatal errors will be suppressed due to your PHP config. You should consider enabling display_errors in your php.ini file!' . chr(10);
        }
    }

    /**
     * @param Bootstrap $bootstrap
     */
    public static function disableCoreCaches(Bootstrap $bootstrap)
    {
        $cacheConfigurations = &$GLOBALS['TYPO3_CONF_VARS']['SYS']['caching']['cacheConfigurations'];
        self::$earlyCachesConfiguration['cache_core'] = $cacheConfigurations['cache_core'];
        $bootstrap->disableCoreCache();
    }

    /**
     * Reset the internal caches array in the object manager to
     * make it rebuild the caches with new configuration.
     *
     * @param Bootstrap $bootstrap
     */
    public static function reEnableOriginalCoreCaches(Bootstrap $bootstrap)
    {
        $GLOBALS['TYPO3_CONF_VARS']['SYS']['caching']['cacheConfigurations'] = array_replace_recursive($GLOBALS['TYPO3_CONF_VARS']['SYS']['caching']['cacheConfigurations'], self::$earlyCachesConfiguration);

        /** @var CacheManager $cacheManager */
        $cacheManager = $bootstrap->getEarlyInstance(\TYPO3\CMS\Core\Cache\CacheManager::class);
        $cacheManager->setCacheConfigurations($GLOBALS['TYPO3_CONF_VARS']['SYS']['caching']['cacheConfigurations']);

        $reflectionObject = new \ReflectionObject($cacheManager);
        $property = $reflectionObject->getProperty('caches');
        $property->setAccessible(true);
        $property->setValue($cacheManager, []);
    }

    /**
     * @param Bootstrap $bootstrap
     */
    public static function initializeCachingFramework(Bootstrap $bootstrap)
    {
        $cacheManager = new \TYPO3\CMS\Core\Cache\CacheManager();
        $cacheManager->setCacheConfigurations($GLOBALS['TYPO3_CONF_VARS']['SYS']['caching']['cacheConfigurations']);
        \TYPO3\CMS\Core\Utility\GeneralUtility::setSingletonInstance(\TYPO3\CMS\Core\Cache\CacheManager::class, $cacheManager);
        // @deprecated can be removed once TYPO3 7.6 support is removed
        if (!class_exists(ConnectionPool::class)) {
            $cacheFactory = new \TYPO3\CMS\Core\Cache\CacheFactory('production', $cacheManager);
            \TYPO3\CMS\Core\Utility\GeneralUtility::setSingletonInstance(\TYPO3\CMS\Core\Cache\CacheFactory::class, $cacheFactory);
        }
        $bootstrap->setEarlyInstance(\TYPO3\CMS\Core\Cache\CacheManager::class, $cacheManager);
    }

    /**
     * @param Bootstrap $bootstrap
     * @deprecated can be removed when TYPO3 8 support is removed
     */
    public static function initializeDatabaseConnection(Bootstrap $bootstrap)
    {
        if (is_callable([$bootstrap, 'initializeTypo3DbGlobal'])) {
            $bootstrap->initializeTypo3DbGlobal();
        }
    }

    /**
     * @param Bootstrap $bootstrap
     */
    public static function initializeExtensionConfiguration(Bootstrap $bootstrap)
    {
        ExtensionManagementUtility::loadExtLocalconf();
        $bootstrap->setFinalCachingFrameworkCacheConfiguration();
        // @deprecated can be removed once TYPO3 8.7 support is removed
        if (is_callable([$bootstrap, 'defineLoggingAndExceptionConstants'])) {
            $bootstrap->defineLoggingAndExceptionConstants();
        }
        $bootstrap->unsetReservedGlobalVariables();
        ExtensionManagementUtility::loadBaseTca();
        self::filterOverriddenCoreCommandControllers();
    }

    public static function filterOverriddenCoreCommandControllers()
    {
        $GLOBALS['TYPO3_CONF_VARS']['SC_OPTIONS']['extbase']['commandControllers'] = array_filter($GLOBALS['TYPO3_CONF_VARS']['SC_OPTIONS']['extbase']['commandControllers'], function ($value) {
            return !in_array($value, [ExtensionCommandController::class, HelpCommandController::class], true);
        });
    }

    /**
     * @param Bootstrap $bootstrap
     */
    public static function initializeAuthenticatedOperations(Bootstrap $bootstrap)
    {
        // @deprecated can be removed if TYPO3 7 support is removed (directly use $bootstrap->loadExtTables())
        ExtensionManagementUtility::loadExtTables();
        call_user_func(\Closure::bind(function () use ($bootstrap) {
            if (is_callable([$bootstrap, 'executeExtTablesAdditionalFile'])) {
                $bootstrap->executeExtTablesAdditionalFile();
            }
            $bootstrap->runExtTablesPostProcessingHooks();
        }, null, $bootstrap));

        $bootstrap->initializeBackendUser(CommandLineUserAuthentication::class);
        self::loadCommandLineBackendUser();
        // Global language object on CLI? rly? but seems to be needed by some scheduler tasks :(
        $bootstrap->initializeLanguageObject();
    }

    /**
     * If the backend script is in CLI mode, it will try to load a backend user named _cli_lowlevel
     *
     * @throws \RuntimeException if a non-admin Backend user could not be loaded
     */
    protected static function loadCommandLineBackendUser()
    {
        /** @var CommandLineUserAuthentication $backendUser */
        $backendUser = $GLOBALS['BE_USER'];
        if ($backendUser->user['uid']) {
            throw new \RuntimeException('Another user was already loaded which is impossible in CLI mode!', 3);
        }
        if (is_callable([$backendUser, 'authenticate'])) {
            $backendUser->authenticate();
        } else {
            // @deprecated can be removed once TYPO3 7.6 support is removed
            $userName = '_cli_lowlevel';
            $backendUser->setBeUserByName($userName);
            if (!$backendUser->user['uid']) {
                /** @var DatabaseConnection $db */
                $db = $GLOBALS['TYPO3_DB'];
                $db->exec_INSERTquery(
                    'be_users',
                    [
                        'username' => $userName,
                        'password' => GeneralUtility::getRandomHexString(48),
                    ]
                );
                $backendUser->setBeUserByName($userName);
            }
            if (!$backendUser->user['uid']) {
                throw new \RuntimeException('No backend user named "' . $userName . '" was found or could not be created! Please create it manually!', 3);
            }
            if ($backendUser->isAdmin()) {
                throw new \RuntimeException('CLI backend user "' . $userName . '" was ADMIN which is not allowed!', 3);
            }
            $backendUser->backendCheckLogin();
        }
    }

    /**
     * Provide cleaned implementation of TYPO3 core classes.
     * Can only be called *after* extension configuration is loaded (needs extbase configuration)!
     *
     * @param Bootstrap $bootstrap
     */
    public static function provideCleanClassImplementations(Bootstrap $bootstrap)
    {
        if (!class_exists(\TYPO3\CMS\Core\Database\Schema\SqlReader::class)) {
            // Register the legacy schema update in case new API does not exist
            // @deprecated since TYPO3 8.x will be removed once TYPO3 7.6 support is removed
            self::registerImplementation(\Helhum\Typo3Console\Database\Schema\SchemaUpdateInterface::class, \Helhum\Typo3Console\Database\Schema\LegacySchemaUpdate::class);
            self::registerImplementation(\Helhum\Typo3Console\Service\Persistence\PersistenceContextInterface::class, \Helhum\Typo3Console\Service\Persistence\LegacyPersistenceContext::class);
        }
        self::overrideImplementation(\TYPO3\CMS\Extbase\Command\HelpCommandController::class, \Helhum\Typo3Console\Command\HelpCommandController::class);
        self::overrideImplementation(\TYPO3\CMS\Extbase\Mvc\Cli\Command::class, \Helhum\Typo3Console\Mvc\Cli\Command::class);
        if (empty($GLOBALS['TYPO3_CONF_VARS']['EXTCONF']['extbase']['typeConverters'])) {
            $GLOBALS['TYPO3_CONF_VARS']['EXTCONF']['extbase']['typeConverters'] = [];
        }
        $GLOBALS['TYPO3_CONF_VARS']['EXTCONF']['extbase']['typeConverters'][] = \Helhum\Typo3Console\Property\TypeConverter\ArrayConverter::class;
    }

    /**
     * Tell Extbase, TYPO3 and PHP that we have another implementation
     *
     * @param string $originalClassName
     * @param string $overrideClassName
     */
    public static function overrideImplementation($originalClassName, $overrideClassName)
    {
        self::registerImplementation($originalClassName, $overrideClassName);
        $GLOBALS['TYPO3_CONF_VARS']['SYS']['Objects'][$originalClassName]['className'] = $overrideClassName;
        class_alias($overrideClassName, $originalClassName);
    }

    /**
     * Tell Extbase about this implementation
     *
     * @param string $className
     * @param string $alternativeClassName
     */
    private static function registerImplementation($className, $alternativeClassName)
    {
        /** @var $extbaseObjectContainer \TYPO3\CMS\Extbase\Object\Container\Container */
        $extbaseObjectContainer = \TYPO3\CMS\Core\Utility\GeneralUtility::makeInstance(\TYPO3\CMS\Extbase\Object\Container\Container::class);
        $extbaseObjectContainer->registerImplementation($className, $alternativeClassName);
    }
}
