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

    private $urls = array(  /* URLs used to connect to perrypedia */
        'api:query' => "https://www.perrypedia.proc.org/mediawiki/api.php?action=query&titles=%s&prop=revisions&rvprop=content&format=json",
    );

    private $GlossarAlphPages= array(
        'A','B','C','D','E','F','G','H','I-J','K','L','M','N','O','P-Q','R',
        'S','T','U-W','X-Z'
    );

    private $dirs = array(
        "01fetch"   => "./steps/01fetch",
        "02extract" => "./steps/02extract",
        "03check"   => "./steps/03check",
        "04sort"    => "./steps/04sort",
        "05create"  => "./steps/05create",
        "06diff"    => "./steps/06diff",
        "07submit"  => "./steps/07submit",
    );

    /* constructor
       $config      - configuration (perrypedia account/password)
     */
    function __construct($config) {

        // first try system PEAR
        @require_once('System.php');
        // if not successfull use the local version
        if (!class_exists("System")) {
            require_once(PGB_BASEDIR .'/dependencies/PEAR/System.php');
        }

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

        /* create directory */
        $directory = $this->dirs['01fetch'];
        if (!@System::mkdir('-p '. $directory)) {
            $this->l->error(sprintf("Can not create directory: %s", $directory));
            exit(1);
        }

        /* prepare list of perrypedia pages for alphabetical list */
        /* build list of perrypedia pages to fetch */
        $titles = "";
        for ($ii = 0; $ii < count($this->GlossarAlphPages); $ii++) {
            $titles .= "Perry_Rhodan-Glossar_". $this->GlossarAlphPages[$ii];
            if ($ii < count($this->GlossarAlphPages) - 1) {
                $titles .= "|";
            }
        }

        /* fetch current versions of the alphabetical list */
        $json = $this->fetchPPjson($titles);
        foreach ($json['query']['pages'] as $p) {
            $this->savePerrypediaJSON($directory, $p);
        }

        /* fetch PR-Glossar page*/
        $json = $this->fetchPPjson('Vorlage:PR-Glossar');
        $pageID = array_keys($json['query']['pages']);
        $p = $json['query']['pages'][$pageID[0]];
        $this->savePerrypediaJSON($directory, $p);

        /* extract all overview pages */
        $content = $p['revisions'][0]['*'];
        preg_match_all("!(Perry Rhodan-Glossar (\d{4}) - (\d{4}))!m", $content, $m);
        $titles = "";
        for ($ii = 0; $ii < count($m[0]); $ii++) {
            $titles .= $m[0][$ii];
            if ($ii < count($m[0]) - 1) {
                $titles .= "|";
            }
        }

        $json = $this->fetchPPjson($titles);
        foreach ($json['query']['pages'] as $p) {
            $this->savePerrypediaJSON($directory, $p);
        }

        $this->l->debug(sprintf("[%s:%s] end", __CLASS__, __FUNCTION__));

    }

    private function run02extract()
    {

        $this->l->debug(sprintf("[%s:%s] start", __CLASS__, __FUNCTION__));

        /* create directory */
        $directory = $this->dirs['02extract'];
        if (!@System::mkdir('-p '. $directory)) {
            $this->l->error(sprintf("Can not create directory: %s", $directory));
            exit(1);
        }

        /* get all data */
        $files = System::find($this->dirs['01fetch'] .' -name Perry_Rhodan-Glossar_*_-_*.perrypedia.json');
        foreach ($files as $f) {
            $content = file_get_contents($f);
//            preg_match_all("!\{\|.*?Quelle:PR(\d{4})(.*?)(-\||\|\})!m", $content, $m);
            preg_match_all("!Quelle:PR(\d{4}).*?(|-)(.*?)(|-)!m", $content, $m);
            print_r($m);
            exit();
        }

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

    /*
     see: https://www.mediawiki.org/wiki/API
     */
    private function fetchPPjson($titles)
    {

        $this->l->debug(sprintf("[%s:%s] start", __CLASS__, __FUNCTION__));

        /* no spaces in titles - replace them by underscore */
        $titles = strtr($titles, " ", "_");

        /* define URL */
        $url = sprintf($this->urls['api:query'], $titles);
        $this->l->info(sprintf("Fetch URL '%s'", $url));

        /* fetch data using curl */
        $ch = curl_init($url);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($ch, CURLOPT_HEADER, false);
		$res = curl_exec($ch);
		curl_close($ch);

		/* parse return data */
		if ($res !== FALSE) {
            $json = json_decode($res, true);
            $this->l->debug(sprintf("Fetch URL '%s' - success", $url));
        } else {
            $this->l->error(sprintf("Error fetching URL '%s': [%d] %s",
                                    $url, curl_errno($ch), curl_error($ch)));
            $json = FALSE;
        }

		$this->l->debug(sprintf("[%s:%s] end", __CLASS__, __FUNCTION__));

		return $json;
    }

    private function savePerrypediaJSON($directory, $pagedata)
    {
        $filename = $pagedata['title'];
        $filename = strtr($filename, " ", "_");

        $file = $directory .'/'. $filename .'.perrypedia.json';
        $fh = fopen($file, "w+");
        fwrite($fh, serialize($pagedata));
        fclose($fh);
    }

}

?>
