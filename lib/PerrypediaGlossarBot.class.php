<?php

/*

PHP Klasse, welche ein Update für die Perrypedia Seiten mit dem alphabetischen
sortiertem Glossar erstellt.

Author: Matthias Pfafferodt
Lizenz: CC BY-SA (https://creativecommons.org/licenses/by-sa/4.0/legalcode)

 */

/* define basedir for include files */
define("PGB_BASEDIR", dirname(__FILE__));

class PerrypediaGlossarBot{

    private $config = NULL; /* configuration; loaded from config.php */
    private $l = NULL;      /* log handle */
    private $args = NULL;   /* command line arguments */

    /* constructor
       $config      - configuration (perrypedia account/password)
     */
    function __construct($config) {

        /* save config */
        $this->config = $config;

        /* analyse command line */
        $this->args = $this->commandline();

        /* prepare logging */
        $this->preparelog();

        /* log arguments */
        $this->l->debug(sprintf("args: %s", print_r($this->args, TRUE)));

        $this->l->debug(sprintf("[%s:%s] end", __CLASS__, __FUNCTION__));

    }

    /* destructor */
    function __destruct() {
        /* nothing */
    }

    /* analyse command line
       return value: Console_CommandLine_Result Object
     */
    private function commandline()
    {
        // first try system PEAR
        @require_once('Console/CommandLine.php');
        // if not successfull use the local version
        if (!class_exists("Console_CommandLine")) {
            require_once(PGB_BASEDIR .'/dependencies/PEAR/Console/CommandLine.php');
        }

        $parser = new Console_CommandLine();
        $parser->description = 'Perrypedia Bot welcher die alphabetischen Glossar Seiten aktualisiert.';
        $parser->version = '0.2';
        $parser->addOption('debug', array(
            'short_name'  => '-d',
            'long_name'   => '--debug',
            'description' => 'Detailierte Ausgaben',
            'action'      => 'StoreTrue'
        ));
        $parser->addOption('log', array(
            'short_name'  => '-l',
            'long_name'   => '--log',
            'description' => 'Logdatei',
            'help_name'   => 'FILE',
            'action'      => 'StoreString'
        ));
        $step_all_cmd = $parser->addCommand('all', array(
            'description' => 'Alle Schritte nacheinander ausführen [0-7]'
        ));
        $step00_cmd = $parser->addCommand('prepare', array(
            'description' => 'Verzeichnis erstellen und alte Dateien löschen',
            'aliases'     => array('0'),
        ));
        $step01_cmd = $parser->addCommand('fetch', array(
            'description' => 'Glossar-Seiten von der Perrypedia laden',
            'aliases'     => array('1'),
        ));
        $step02_cmd = $parser->addCommand('extract', array(
            'description' => 'Glossar-Einträge herausfiltern',
            'aliases'     => array('2'),
        ));
        $step03_cmd = $parser->addCommand('check', array(
            'description' => 'Glossar-Einträge überprüfen und wenn nötig umbenennen',
            'aliases'     => array('3'),
        ));
        $step04_cmd = $parser->addCommand('sort', array(
            'description' => 'Glossar-Einträge sortieren',
            'aliases'     => array('4'),
        ));
        $step05_cmd = $parser->addCommand('create', array(
            'description' => 'Alphabetisch sortierte Glossar-Seiten erstellen',
            'aliases'     => array('5'),
        ));
        $step06_cmd = $parser->addCommand('diff', array(
            'description' => 'Unterschiede zu den bestehenden Seiten aufzeigen',
            'aliases'     => array('6'),
        ));
        $step07_cmd = $parser->addCommand('submit', array(
            'description' => 'Neue Glossar-Seiten hochladen',
            'aliases'     => array('7'),
        ));

        try {
            $result = $parser->parse();
            return $result;
        } catch (Exception $exc) {
            $parser->displayError($exc->getMessage());
            /* this will exit the script */
        }
    }

    /* prepare log */
    private function preparelog()
    {
        // first try system PEAR
        @require_once('Log.php');
        // if not successfull use the local version
        if (!class_exists("Log")) {
            require_once(PGB_BASEDIR .'/dependencies/PEAR/Log.php');
        }

        /* define priority - default: WARNING / DEBUG (if --debug) */
        if ($this->args->options['debug']) {
            $level = PEAR_LOG_DEBUG;
        } else {
            $level = PEAR_LOG_WARNING;
        }

        /* create main log handle */
        $this->l = Log::singleton('composite');
        /* create log handler for console */
        $console = Log::factory('console', '', 'console', array(), $level);
        $this->l->addChild($console);
        /* log to file if requested */
        if (strlen($this->args->options['log']) > 0) {
            $logfile = $this->args->options['log'];
            if (!file_exists($logfile)) {
                $file = Log::factory('file', $logfile, 'file', array(), $level);
                $this->l->addChild($file);
            } else {
                $this->l->warning(
                    sprintf("Log file exists: %s - not logging to file!",
                            $logfile));
            }
        }
    }

    /* main function */
    public function run()
    {

        $this->l->debug(sprintf("[%s:%s] start", __CLASS__, __FUNCTION__));

        $cmd = $this->args->command_name;
        try {
            // find which command was entered
            switch ($cmd) {
            case 'all':
                $this->run00prepare();
                $this->run01fetch();
                $this->run02extract();
                $this->run03check();
                $this->run04sort();
                $this->run05create();
                $this->run06diff();
                $this->run07submit();
                break;
            case 'prepare':
                $this->run00prepare();
                break;
            case 'fetch':
                $this->run01fetch();
                break;
            case 'extract':
                $this->run02extract();
                break;
            case 'check':
                $this->run03check();
                break;
            case 'sort':
                $this->run04sort();
                break;
            case 'create':
                $this->run05create();
                break;
            case 'diff':
                $this->run06diff();
                break;
            case 'submit':
                $this->run07submit();
                break;
            default:
                $this->l->warning("No command defined (try help using '--help')");
                break;
            }
        } catch (Exception $exc) {
            $this->l->error(sprintf("Exception on command handling: %s",
                                    $exc->getMessage()));
        }

        $this->l->debug(sprintf("[%s:%s] end", __CLASS__, __FUNCTION__));

    }

    private function run00prepare()
    {

        $this->l->debug(sprintf("[%s:%s] start", __CLASS__, __FUNCTION__));
        $this->l->debug(sprintf("[%s:%s] end", __CLASS__, __FUNCTION__));

    }

    private function run01fetch()
    {

        $this->l->debug(sprintf("[%s:%s] start", __CLASS__, __FUNCTION__));
        $this->l->debug(sprintf("[%s:%s] end", __CLASS__, __FUNCTION__));

    }

    private function run02extract()
    {

        $this->l->debug(sprintf("[%s:%s] start", __CLASS__, __FUNCTION__));
        $this->l->debug(sprintf("[%s:%s] end", __CLASS__, __FUNCTION__));

    }

    private function run03check()
    {

        $this->l->debug(sprintf("[%s:%s] start", __CLASS__, __FUNCTION__));
        $this->l->debug(sprintf("[%s:%s] end", __CLASS__, __FUNCTION__));

    }

    private function run04sort()
    {

        $this->l->debug(sprintf("[%s:%s] start", __CLASS__, __FUNCTION__));
        $this->l->debug(sprintf("[%s:%s] end", __CLASS__, __FUNCTION__));

    }

    private function run05create()
    {

        $this->l->debug(sprintf("[%s:%s] start", __CLASS__, __FUNCTION__));
        $this->l->debug(sprintf("[%s:%s] end", __CLASS__, __FUNCTION__));

    }

    private function run06diff()
    {

        $this->l->debug(sprintf("[%s:%s] start", __CLASS__, __FUNCTION__));
        $this->l->debug(sprintf("[%s:%s] end", __CLASS__, __FUNCTION__));

    }

    private function run07submit()
    {

        $this->l->debug(sprintf("[%s:%s] start", __CLASS__, __FUNCTION__));
        $this->l->debug(sprintf("[%s:%s] end", __CLASS__, __FUNCTION__));

    }

}

?>
