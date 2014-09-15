<?php

class WpTesting_Facade
{

    /**
     * @var WpTesting_ShortcodeProcessor
     */
    private $shortcodeProcessor = null;

    /**
     * @var WpTesting_TestEditor
     */
    private $testEditor = null;

    /**
     * @var WpTesting_WordPressFacade
     */
    private $wp = null;

    private $isWordPressEntitiesRegistered = false;

    private $isOrmSettedUp = false;

    public function __construct(WpTesting_WordPressFacade $wp)
    {
        $this->wp = $wp;
        $this->registerWordPressHooks();
    }

    public function onPluginActivate()
    {
        $this->migrateDatabase(array(__FILE__, 'db:migrate'));
        $this->registerWordPressEntities();
    }

    public function onPluginDeactivate()
    {
        // do nothing currently
    }

    public static function onPluginUninstall()
    {
        $adapter = $this->migrateDatabase(array(__FILE__, 'db:migrate', 'VERSION=0'));
        $adapter->drop_table(RUCKUSING_TS_SCHEMA_TBL_NAME);
        $adapter->logger->close();
    }

    public function shortcodeList()
    {
        return $this->getShortcodeProcessor()->getList();
    }

    protected function registerWordPressHooks()
    {
        $class = get_class($this);
        $this->wp
            ->registerActivationHook(    array($this,  'onPluginActivate'))
            ->registerDeactivationHook(  array($this,  'onPluginDeactivate'))
            ->registerUninstallHook(     array($class, 'onPluginUninstall'))
            ->addAction('init',          array($this,  'registerWordPressEntities'))
            ->addShortcode('wptlist',    array($this,  'shortcodeList'))
            ->addAction('admin_init',    array($this,  'setupTestEditor'))
            ->addAction('save_post',     array($this,  'saveTest'), 10, 3)
        ;
    }

    public function registerWordPressEntities()
    {
        if ($this->isWordPressEntitiesRegistered) {
            return;
        }

        require_once dirname(__FILE__) . '/WordPressEntitiesRegistrator.php';
        new WpTesting_WordPressEntitiesRegistrator($this->wp);

        $this->isWordPressEntitiesRegistered = true;
    }

    public function setupTestEditor()
    {
        $this->getTestEditor()->customizeUi();
    }

    public function saveTest($id, $item, $isUpdate)
    {
        $this->getTestEditor()->saveTest($id, $item, $isUpdate);
    }

    protected function getShortcodeProcessor()
    {
        if (!is_null($this->shortcodeProcessor)) {
            return $this->shortcodeProcessor;
        }

        $this->setupORM();
        require_once dirname(__FILE__) . '/Doer.php';
        require_once dirname(__FILE__) . '/ShortcodeProcessor.php';
        $this->shortcodeProcessor = new WpTesting_ShortcodeProcessor();

        return $this->shortcodeProcessor;
    }

    protected function getTestEditor()
    {
        if (!is_null($this->testEditor)) {
            return $this->testEditor;
        }

        $this->setupORM();
        require_once dirname(__FILE__) . '/Doer.php';
        require_once dirname(__FILE__) . '/TestEditor.php';
        $this->testEditor = new WpTesting_TestEditor($this->wp);

        return $this->testEditor;
    }

    protected function setupORM()
    {
        if ($this->isOrmSettedUp) {
            return;
        }
        $this->autoloadComposer();
        $this->defineConstants();

        // Extract port from host. See wpdb::db_connect
        $port = null;
        $host = $this->wp->getDbHost();
        if (preg_match('/^(.+):(\d+)$/', trim($host), $m)) {
            $host = $m[1];
            $port = $m[2];
        }
        $database = new fDatabase('mysql', $this->wp->getDbName(), $this->wp->getDbUser(), $this->wp->getDbPassword(), $host, $port);
        fORMDatabase::attach($database);

        require_once dirname(__FILE__) . '/Model/AbstractModel.php';
        require_once dirname(__FILE__) . '/Model/Test.php';
        require_once dirname(__FILE__) . '/Model/Question.php';
        require_once dirname(__FILE__) . '/Query/AbstractQuery.php';
        require_once dirname(__FILE__) . '/Query/Test.php';

        fORM::mapClassToTable('WpTesting_Model_Test',        WP_DB_PREFIX . 'posts');
        fORM::mapClassToTable('WpTesting_Model_Question',    WPT_DB_PREFIX . 'questions');

        $this->isOrmSettedUp = true;
    }

    /**
     * @param array $argv
     * @return Ruckusing_Adapter_Interface
     */
    protected function migrateDatabase($argv)
    {
        $this->autoloadComposer();
        $this->defineConstants();

        $runnerReflection = new ReflectionClass('Ruckusing_FrameworkRunner');
        defined('RUCKUSING_SCHEMA_TBL_NAME')    or define('RUCKUSING_SCHEMA_TBL_NAME',      WPT_DB_PREFIX . 'schema_info');
        defined('RUCKUSING_TS_SCHEMA_TBL_NAME') or define('RUCKUSING_TS_SCHEMA_TBL_NAME',   WPT_DB_PREFIX . 'schema_migrations');
        defined('RUCKUSING_WORKING_BASE')       or define('RUCKUSING_WORKING_BASE',         dirname(dirname(__FILE__)));
        defined('RUCKUSING_BASE')               or define('RUCKUSING_BASE',                 dirname(dirname(dirname($runnerReflection->getFileName()))));

        $databaseDirectory = RUCKUSING_WORKING_BASE . DIRECTORY_SEPARATOR . 'db';
        $config = array(
            'db' => array(
                'development' => array(
                    'type'     => DB_TYPE,
                    'host'     => reset(explode(':', $this->wp->getDbHost())),
                    'port'     => next(explode(':', $this->wp->getDbHost() . ':3306')),
                    'database' => $this->wp->getDbName(),
                    'user'     => $this->wp->getDbUser(),
                    'password' => $this->wp->getDbPassword(),
                    'charset'  => $this->wp->getDbCharset(),
                ),
            ),
            'db_dir'         => $databaseDirectory,
            'migrations_dir' => array('default' => $databaseDirectory . DIRECTORY_SEPARATOR . 'migrations'),
            'log_dir'        => $databaseDirectory . DIRECTORY_SEPARATOR . 'log',
        );

        $runner = new Ruckusing_FrameworkRunner($config, $argv);
        restore_error_handler();
        restore_exception_handler();
        $runner->execute();

        /* @var $adapter Ruckusing_Adapter_Interface */
        $adapter = $runner->get_adapter();
        $adapter->logger = new Ruckusing_Util_Logger($config['log_dir'] . DIRECTORY_SEPARATOR . 'development.log');
        return $adapter;
    }

    protected function autoloadComposer()
    {
        // 1. Try to find composer.json
        $composerFullName = null;
        foreach (array($this->wp->getAbsPath(), dirname(dirname($this->wp->getPluginDir()))) as $path) {
            $candidateFile = $path . DIRECTORY_SEPARATOR . 'composer.json';
            if (file_exists($candidateFile)) {
                $composerFullName = $candidateFile;
                break;
            }
        }

        // 2. Not found? Use default php52 generated autoloader
        if (!$composerFullName) {
            $autoloadPath = implode(DIRECTORY_SEPARATOR, array(dirname(dirname(__FILE__)), 'vendor', 'autoload_52.php'));
            require_once ($autoloadPath);
            return;
        }

        // 3. Found? Determine vendor dirname and load autoload file
        $vendorDirectory = 'vendor';
        if (function_exists('json_decode')) {
            $composerJson = json_decode(file_get_contents($composerFullName), true);
            if (!empty($composerJson['config']['vendor-dir'])) {
                $vendorDirectory = $composerJson['config']['vendor-dir'];
            }
        }

        $autoloadPath = implode(DIRECTORY_SEPARATOR, array(dirname($composerFullName), $vendorDirectory, 'autoload.php'));
        require_once ($autoloadPath);
    }

    protected function defineConstants()
    {
        defined('WP_DB_PREFIX')                 or define('WP_DB_PREFIX',                   $this->wp->getTablePrefix());
        defined('WPT_DB_PREFIX')                or define('WPT_DB_PREFIX',                  WP_DB_PREFIX . 't_');
        defined('DB_TYPE')                      or define('DB_TYPE',                        'mysql');
    }

}
