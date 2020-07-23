<?php

/*

PHP Klasse, welche ein Update für die Perrypedia Seiten mit dem alphabetischen
sortiertem Glossar erstellt.

Ablauf:
(1) Einlesen der chronologischen Glossareinträge [[Perry Rhodan-Glossar *]]
    Funktion: run01fetch()
(2) Bearbeiten der Daten (extrahieren, sortieren, überprüfen, neue Seiten
    erstellen
    Funktion: run02create
(3) [optional] darstellen der Unterschiede
    Funktion: run03diff
(4) Hochladen der neuen Seiten in die Perrypedia
    Funktion: run04submit

Author: Matthias Pfafferodt
Lizenz: GPL Version 3.0 or later (http://www.gnu.de/documents/gpl-3.0.de.html)

 */

/* define basedir for include files */
define("PGB_BASEDIR", dirname(__FILE__));
/* force include path */
set_include_path(PGB_BASEDIR . "/dependencies/PEAR/");

class PerrypediaGlossarBot{

    /* ====================================================================== *
     * class variables                                                        *
     * ====================================================================== */

    private $config = NULL; /* configuration; loaded from config.php */
    private $rename = NULL; /* rename table */
    private $l = NULL;      /* log handle */
    private $args = NULL;   /* command line arguments */
    private $ch = NULL;     /* curl handle */
    private $login = FALSE; /* login status */

    private $GlossarAlphPages= array(
        'A','B','C','D','E','F','G','H','I-J','K','L','M','N','O','P-Q','R',
        'S','T','U-W','X-Z'
    ); /* alph Glossar subpages */

    private $dirs = array(
        "00prepare" => "./steps/00prepare",
        "01fetch"   => "./steps/01fetch",
        "02create"  => "./steps/02create",
        "03diff"    => "./steps/03diff",
        "04submit"  => "./steps/04submit",
    ); /* step directories; created if needed */

    /* ====================================================================== *
     * constructor / destructor                                               *
     * ====================================================================== */

    /* constructor */
    function __construct($config, $rename = array()) {

        // first try system PEAR
        @require_once('System.php');
        // if not successfull use the local version
        if (!class_exists("System")) {
            require_once(PGB_BASEDIR .'/dependencies/PEAR/System.php');
        }

        /* initialise curl */
        $this->ch = curl_init();

        /* save config */
        $this->config = $config;

        /* save rename table */
        $this->rename = $rename;

        /* analyse command line */
        $this->args = $this->commandline();

        /* prepare logging */
        $this->preparelog();

        /* log config */
        foreach ($this->config as $k => $v) {
            /* do NOT display the password */
            if ($k == 'password') {
                $v = '***';
            }
            $this->l->debug(sprintf("[config] %s => %s", $k, $v));
        }
        /* log options */
        foreach ($this->args->options as $k => $v) {
            $this->l->debug(sprintf("[options] %s => %s", $k, $v));
        }
        /* log command */
        $this->l->debug(sprintf("[command] %s", $this->args->command_name));

        $this->l->debug(sprintf("[%s:%s] end", __CLASS__, __FUNCTION__));

    }

    /* destructor */
    function __destruct() {
        if ($this->ch !== NULL) {
            curl_close($this->ch);
        }
    }

    /* ====================================================================== *
     * command line arguments / logging                                       *
     * ====================================================================== */

    /* analyse command line; based on PEAR:Console_CommandLine */
    private function commandline()
    {
        // first try system PEAR
        @require_once('Console/CommandLine.php');
        // if not successfull use the local version
        if (!class_exists("Console_CommandLine")) {
            require_once(PGB_BASEDIR .'/dependencies/PEAR/Console/CommandLine.php');
        }

        $parser = new Console_CommandLine();
        $parser->description = 'PerrypediaGlossarBot - Alphabetischen Glossar aktualisieren.';
        $parser->version = '0.2.0';
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
        $parser->addOption('summary', array(
            'short_name'  => '-s',
            'long_name'   => '--summary',
            'description' => 'Perrypedia summary',
            'help_name'   => 'SUMMARY',
            'action'      => 'StoreString'
        ));
        $step_all_cmd = $parser->addCommand('all', array(
            'description' => 'Alle Schritte nacheinander ausführen [0-4]'
        ));
        $step00_cmd = $parser->addCommand('prepare', array(
            'description' => 'Verzeichnis erstellen und alte Dateien löschen',
            'aliases'     => array('0'),
        ));
        $step01_cmd = $parser->addCommand('fetch', array(
            'description' => 'Glossar-Seiten von der Perrypedia laden',
            'aliases'     => array('1'),
        ));
        $step02_cmd = $parser->addCommand('create', array(
            'description' => 'Alphabetisch sortierte Glossar-Seiten erstellen',
            'aliases'     => array('2'),
        ));
        $step03_cmd = $parser->addCommand('diff', array(
            'description' => 'Unterschiede zu den bestehenden Seiten aufzeigen',
            'aliases'     => array('3'),
        ));
        $step04_cmd = $parser->addCommand('submit', array(
            'description' => 'Neue Glossar-Seiten hochladen',
            'aliases'     => array('4'),
        ));

        try {
            $result = $parser->parse();
            return $result;
        } catch (Exception $exc) {
            $parser->displayError($exc->getMessage());
            /* this will exit the script */
        }
    }

    /* prepare log - based on PEAR:Log */
    private function preparelog()
    {
        // first try system PEAR
        @require_once('Log.php');
        // if not successfull use the local version
        if (!class_exists("Log")) {
            require_once(PGB_BASEDIR .'/dependencies/PEAR/Log.php');
        }

        /* define priority - default: INFO / DEBUG (if --debug) */
        if ($this->args->options['debug']) {
            $level = PEAR_LOG_DEBUG;
        } else {
            $level = PEAR_LOG_INFO;
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

    /* ====================================================================== *
     * main functions                                                         *
     * ====================================================================== */

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
                $this->run02create();
                $this->run03diff();
                $this->run04submit();
                break;
            case 'prepare':
                $this->run00prepare();
                break;
            case 'fetch':
                $this->run01fetch();
                break;
            case 'create':
                $this->run02create();
                break;
            case 'diff':
                $this->run03diff();
                break;
            case 'submit':
                $this->run04submit();
                break;
            default:
                $this->l->warning("No command defined (try help using '--help')");
                break;
            }
        } catch (Exception $exc) {
            $this->l->err(sprintf("Exception: %s", $exc->getMessage()));
            exit(1);
        }

        $this->l->debug(sprintf("[%s:%s] end", __CLASS__, __FUNCTION__));

    }

    private function run00prepare()
    {

        $this->l->debug(sprintf("[%s:%s] start", __CLASS__, __FUNCTION__));

        /* nothing */

        $this->l->debug(sprintf("[%s:%s] end", __CLASS__, __FUNCTION__));

    }

    private function run01fetch()
    {

        $this->l->debug(sprintf("[%s:%s] start", __CLASS__, __FUNCTION__));

        /* create directory */
        $directory = $this->dirs['01fetch'];
        if (!@System::mkdir('-p '. $directory)) {
            $errstr = sprintf("Can not create directory: %s", $directory);
            $this->l->err($errstr);
            throw new Exception($errstr);
        }

        /* prepare list of perrypedia pages for alphabetical list */
        /* build list of perrypedia pages to fetch */
        $titles = "";
        for ($ii = 0; $ii < count($this->GlossarAlphPages); $ii++) {
            $titles .= "Perry Rhodan-Glossar ". $this->GlossarAlphPages[$ii];
            if ($ii < count($this->GlossarAlphPages) - 1) {
                $titles .= "|";
            }
        }
        $titles = strtr($titles, " ", "_");

        /* fetch current versions of the alphabetical list */
        $json = $this->PP_fetchJSON($titles);
        foreach ($json['query']['pages'] as $p) {
            /* save json data */
            $this->savePerrypediaJSON($directory, $p);

            /* save page for comparison */
            $pagename = $p['title'];
            $pagename = strtr($pagename, " ", "_");
            $filename = $directory .'/'. $pagename .'.perrypedia.txt';
            $content = $p['revisions'][0]['*'];
            $this->writeFile($filename, $content);
        }

        /* fetch PR-Glossar page*/
        $json = $this->PP_fetchJSON('Vorlage:PR-Glossar');
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

        $json = $this->PP_fetchJSON($titles);
        foreach ($json['query']['pages'] as $p) {
            $this->savePerrypediaJSON($directory, $p);
        }

        $this->l->debug(sprintf("[%s:%s] end", __CLASS__, __FUNCTION__));

    }

    private function run02create()
    {

        $this->l->debug(sprintf("[%s:%s] start", __CLASS__, __FUNCTION__));

        /* create directory */
        $directory = $this->dirs['02create'];
        if (!@System::mkdir('-p '. $directory)) {
            $errstr = sprintf("Can not create directory: %s", $directory);
            $this->l->err($errstr);
            throw new Exception($errstr);
        }

        /* save latest pr entry */
        $pr_max = 0;

        /* get all data */
        $entries = array();
        $files = System::find($this->dirs['01fetch'] .' -name Perry_Rhodan-Glossar_*_-_*.perrypedia.json');
        foreach ($files as $f) {
            $content = file_get_contents($f);
            preg_match_all("!colspan=\"3\" \| \[\[Quelle:PR(\d{4}).*?\|-(.*?)(\|-|\|\})!ms",
                $content, $m1, PREG_SET_ORDER);
            foreach ($m1 as $one) {
                $valid = FALSE;
                $pr = $one[1];
                preg_match_all("!^\* (.*?)$!ms", $one[2], $m2, PREG_SET_ORDER);
                foreach ($m2 as $entry) {
                    /* skip empty entries*/
                    if (strlen($entry[1]) == 0) {
                        continue;
                    }

                    $valid = TRUE;

                    $visible = $this->perrypediaVisible($entry[1]);
                    $entries[] = array(
                        'pr' => $pr,
                        'orig' => trim($entry[1]),
                        /* the command below creates the entry as it will be
                           visible in the HTML page */
                        'visible' => $visible,
                    );
                }
                /* consider only pr with glossar entries for latest pr */
                if ($valid) {
                    $pr_max = max($pr, $pr_max);
                }
            }
        }
        $this->l->info(sprintf("max pr: %d", $pr_max));
        $this->l->info(sprintf("glossar entries: %d", count($entries)));

        /* rename entries if listed in rename table */
        $rename_keys = array_keys($this->rename);
        $entries_keys = array_keys($entries);
        for ($ii = 0; $ii < count($entries_keys); $ii++) {
            $key = $entries[$entries_keys[$ii]]['visible'];
            if (in_array($key, $rename_keys)) {
                /* update data */
                $this->l->notice(sprintf("update entry '%s' [PR%d]: %s",
                    $entries[$entries_keys[$ii]]['orig'],
                    $entries[$entries_keys[$ii]]['pr'],
                    $this->rename[$key]));

                $entries[$entries_keys[$ii]]['orig'] = $this->rename[$key];
                $entries[$entries_keys[$ii]]['visible'] =
                    $this->perrypediaVisible($this->rename[$key]);
           }
        }

        /* sort glossar entries */
        $glossar = array();
        foreach ($entries as $e) {
            /* define sort key */
            $key = $e['visible'];
            /* - translate characters */
            $key = $this->transliterateString($key);
            /* - all lower case characters */
            $key = strtolower($key);
            /* - first character upper case */
            $key = ucfirst($key);

            /* skip empty entries*/
            if (strlen($key) == 0) {
              continue;
            }

            /* get first character */
            $char = $key[0];
            $char = ctype_alpha($char) ? $char : '0-9';

            if (!isset($glossar[$char])) {
              $glossar[$char] = array();
            }

            /* add entry to array */
            if (!isset($glossar[$char][$key])) {
                /* add additional fields */
                $e['count'] = 1;
                $e['pr'] = (array)$e['pr'];
                $glossar[$char][$key] = $e;
            } else {
                $glossar[$char][$key]['count']++;
                $glossar[$char][$key]['pr'][] = $e['pr'];
                /* update entry if str is longer */
                if (strlen($glossar[$char][$key]['orig']) < strlen($e['orig'])) {
                    $this->l->debug(sprintf("update entry: '%s' => '%s'",
                        $glossar[$char][$key]['orig'], $e['orig']));
                    $glossar[$char][$key]['orig'] = $e['orig'];
                }
            }
        }

        /* sort array of glossar entries */
        ksort($glossar);
        $glossar_total = 0;
        foreach ($glossar as $k => $v) {
            ksort($v);
            $glossar[$k] = $v;

            $this->l->info(sprintf("sorted glossar entries '%s': %d",
                $k, count($v)));
            $glossar_total += count($v);
        }
        $this->l->info(sprintf("total sorted glossar entries: %d",
            $glossar_total));

        /* build basic tables for each letter */
        $perrypedia = array();
        foreach ($glossar as $key => $entries) {
            $perrypedia[$key] = $this->createPerrypediaGlossarAlph($key, $entries);
        }

        /* Perrypedia glossar pre/post strings */
        $str_pre = sprintf("{{Navigationsleiste Glossar alphabetisch}}

Stand: [[Quelle:PR%1\$d|PR&nbsp;%1\$d]]

", $pr_max);
        $str_post = "
[[Kategorie:Beilage]]

{{PPDefaultsort}}
";

        /* build pages */
        $pages = array();
        foreach ($this->GlossarAlphPages as $p) {
            switch ($p) {
                case "A":
                    $str = $perrypedia['0-9'] ."\n".
                           $perrypedia['A'];
                    break;
                case "I-J":
                    $str = $perrypedia['I'] ."\n".
                           $perrypedia['J'];
                    break;
                case "P-Q":
                    $str = $perrypedia['P'] ."\n".
                           $perrypedia['Q'];
                    break;
                case "U-W":
                    $str = $perrypedia['U'] ."\n".
                           $perrypedia['V'] ."\n".
                           $perrypedia['W'];
                    break;
                case "X-Z":
                    $str = $perrypedia['X'] ."\n".
                           $perrypedia['Y'] ."\n".
                           $perrypedia['Z'];
                    break;
                default:
                    $str = $perrypedia[$p];
                    break;
            }

            $page_key = "Perry Rhodan-Glossar ". $p;
            $pages[$page_key] = $str_pre . $str . $str_post;
        }
 
        /* save data to file */
        $filename = $directory .'/glossar_new.phpvar';
        $this->writePHP2file($filename, $pages);

        foreach ($pages as $k => $v) {
            $pagename = $k;
            $pagename = strtr($pagename, " ", "_");
            $filename = $directory .'/'. $pagename .'.perrypedia.txt';
            $content = $v;
            $this->writeFile($filename, $content);
        }

        $this->l->debug(sprintf("[%s:%s] end", __CLASS__, __FUNCTION__));

    }

    private function run03diff()
    {

        $this->l->debug(sprintf("[%s:%s] start", __CLASS__, __FUNCTION__));

        require_once(PGB_BASEDIR .'/dependencies/diff/class.Diff.php');

        /* create directory */
        $directory = $this->dirs['03diff'];
        if (!@System::mkdir('-p '. $directory)) {
            $errstr = sprintf("Can not create directory: %s", $directory);
            $this->l->err($errstr);
            throw new Exception($errstr);
        }

        /* definitions from http://code.stephenmorley.org/php/diff-implementation/ */
        $diff_pre = "<!DOCTYPE html>
<html>
  <head>
    <title>
      Diff for '%1\$s'
    </title>
    <style type=\"text/css\">

      .diff td{
        padding:0 0.667em;
        vertical-align:top;
        white-space:pre;
        white-space:pre-wrap;
        font-family:Consolas,'Courier New',Courier,monospace;
        font-size:0.75em;
        line-height:1.333;
      }

      .diff span{
        display:block;
        min-height:1.333em;
        margin-top:-1px;
        padding:0 3px;
      }

      * html .diff span{
        height:1.333em;
      }

      .diff span:first-child{
        margin-top:0;
      }

      .diffDeleted span{
        border:1px solid rgb(255,192,192);
        background:rgb(255,224,224);
      }

      .diffInserted span{
        border:1px solid rgb(192,255,192);
        background:rgb(224,255,224);
      }

    </style>
  </head>
  <body>
    <h1>%1\$s</h1>

";
        $diff_post = "";
        foreach ($this->GlossarAlphPages as $p) {
            $pagename = "Perry Rhodan-Glossar ". $p;
            $pagename = strtr($pagename, " ", "_");

            $this->l->info(sprintf("Creating diff for '%s' ...", $pagename));

            $filename_old = $this->dirs['01fetch'] .'/'. $pagename .'.perrypedia.txt';
            $filename_new = $this->dirs['02create'] .'/'. $pagename .'.perrypedia.txt';

            $diff = Diff::compareFiles($filename_old, $filename_new);

            /* diff as html table */
            $difftable = Diff::toTable($diff);
            $content = sprintf($diff_pre, $pagename) . $difftable . $diff_post;
            $diffname = $directory .'/'. $pagename .'.diff.html';
            $this->writeFile($diffname, $content);

            /* diff as standard diff (diff -u) */
            $diffstr = Diff::toString($diff);
            $diffname = $directory .'/'. $pagename .'.diff';
            $this->writeFile($diffname, $diffstr);
        }

        $this->l->debug(sprintf("[%s:%s] end", __CLASS__, __FUNCTION__));

    }

    /*
     based on:
     - https://www.mediawiki.org/wiki/API:Edit/Editing_with_Python
     */
    private function run04submit()
    {

        $this->l->debug(sprintf("[%s:%s] start", __CLASS__, __FUNCTION__));

        /* check for an extra summary entry */
        $summary_extra = NULL;
        if (strlen($this->args->options['summary']) > 0) {
            $summary_extra = $this->args->options['summary'];
        }
        $this->l->debug(sprintf("[%s:%s] extra summary: '%s'",
            __CLASS__, __FUNCTION__, $summary_extra));

        /* login to Perrypedia */
        $this->PP_login();

        /* get edit token */
        $pGET = array();
        $pPOST = array(
            "action" => "query",
            "meta" => "tokens",
        );
        $json = $this->PP_request($pPOST, $pGET);
        $edittoken = $json['query']['tokens']['csrftoken'];
        $this->l->debug(sprintf("edit token: %s", $edittoken));

        /* upload new pages */
        foreach ($this->GlossarAlphPages as $p) {
            $pagename = "Perry Rhodan-Glossar ". $p;
            $pagename = strtr($pagename, " ", "_");

            $this->l->info(sprintf("Update Perrypedia page '%s' ...", $pagename));

            /* get content of updated page */
            $filename = $this->dirs['02create'] .'/'. $pagename .'.perrypedia.txt';
            $content = file_get_contents($filename);
            $summary = sprintf("SyntronsBot Update %s", date("c", time()));
            if ($summary_extra !== NULL) {
                $summary .= " - ". $summary_extra;
            }

            /* update page */
            $pGET = array();
            $pPOST = array(
                /* https://www.mediawiki.org/wiki/Special:MyLanguage/API:Edit */
                "action" => "edit",
                "text" => $content,
                "md5" => md5($content),
                "summary" => $summary,
                "bot" => 1, /* this is a bot */
                "nocreate" => 1, /* never create a page */
                "title" => $pagename,
                "token" => $edittoken,
            );
            $json = $this->PP_request($pPOST, $pGET);
            if (!isset($json['edit']['result'])
                || $json['edit']['result'] != "Success") {
                $errstr = sprintf("Error updating page '%s'", $pagename);
                $this->l->err($errstr);
                throw new Exception($errstr);
            }
            $this->l->info(sprintf("Update Perrypedia page '%s' - success",
                $pagename));
        }

        $this->l->debug(sprintf("[%s:%s] end", __CLASS__, __FUNCTION__));

    }

    /* ====================================================================== *
     * Perrypedia related functions                                           *
     * ====================================================================== */

    private function PP_fetchJSON($titles)
    {

        $this->l->debug(sprintf("[%s:%s] start", __CLASS__, __FUNCTION__));

        /* log some information */
        $this->l->info(sprintf("Fetching PP pages: '%s'", $titles));

        /* no spaces in titles - replace them by underscore */
        $titles = strtr($titles, " ", "_");

        /* define get parameters for PP api */
        $pGET = array(
            'action' => 'query',
            'titles' => $titles,
            'prop' => 'revisions',
            'rvprop' => 'content',
            'format' => 'json',
        );

        /* send request */
        $json = $this->PP_request(NULL, $pGET);

        $this->l->debug(sprintf("[%s:%s] end", __CLASS__, __FUNCTION__));

        return $json;
    }

    /*
     sources:
     - https://www.mediawiki.org/wiki/API
     - https://serverfault.com/questions/520797/how-to-add-content-to-all-pages-in-a-mediawiki
     - https://www.mediawiki.org/wiki/User:Bcoughlan/Login_with_curl
     */
    private function PP_request($pPOST = array(), $pGET = array())
    {

        $this->l->debug(sprintf("[%s:%s] start", __CLASS__, __FUNCTION__));

        /* check arguments */
        $pPOST = (array)$pPOST;
        $pGET = (array)$pGET;

        /* force json */
        $pPOST['format'] = 'json';
        $pGET['format'] = 'json';

        /* define URL (api URL + GET parameters) */
        $this->l->debug(sprintf("URL:  '%s'", $this->config['apiurl']));
        $strGET = '?';
        foreach ($pGET as $k => $v) {
            $strGET = $strGET . sprintf("%s=%s&", urlencode($k), urlencode($v));

            /* no newlines for log message */
            $v = str_replace(array("\r\n", "\r", "\n"), "", $v);
            /* do NOT display the password */
            if (strpos($k, "password") !== FALSE) {
                $v = '***';
            }
            /* check for long values and shorten them */
            if ($strlen = strlen($v) > 33) {
                // additional '-1' for the NULL terminated string
                $v = substr_replace($v, "...", 15, $strlen - 15 - 1);
            }
            $this->l->debug(sprintf("GET:  '%s' => '%s'", $k, $v));
        }
        /* define POST parameters */
        $strPOST = "";
        foreach ($pPOST as $k => $v) {
            $strPOST = $strPOST . sprintf("%s=%s&", urlencode($k), urlencode($v));

            /* no newlines for log message */
            $v = str_replace(array("\r\n", "\r", "\n"), "", $v);
            /* do NOT display the password */
            if (strpos($k, "password") !== FALSE) {
                $v = '***';
            }
            /* check for long values and shorten them */
            if ($strlen = strlen($v) > 33) {
                // additional '-1' for the NULL terminated string
                $v = substr_replace($v, "...", 15, $strlen - 15 - 1);
            }
            $this->l->debug(sprintf("POST: '%s' => '%s'", $k, $v));
        }

        /* fetch data using curl */
        curl_setopt($this->ch, CURLOPT_USERAGENT, $this->config['useragent']);
        curl_setopt($this->ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($this->ch, CURLOPT_HEADER, false);
        curl_setopt($this->ch, CURLOPT_URL, $this->config['apiurl'] . $strGET);
        curl_setopt($this->ch, CURLOPT_ENCODING, "UTF-8" );
        curl_setopt($this->ch, CURLOPT_COOKIEFILE, $this->config['cookiefile']);
        curl_setopt($this->ch, CURLOPT_COOKIEJAR, $this->config['cookiefile']);
        //curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($this->ch, CURLOPT_POSTFIELDS, $strPOST);
        /* if activated, debug information are appended to 'curl.debug' */
        if (FALSE) {
          curl_setopt($this->ch, CURLOPT_VERBOSE, true); // verbose output
          $fh = fopen("curl.debug", "a+");
          curl_setopt($this->ch, CURLOPT_STDERR, $fh); // write it to file
          fclose($fh);
        }
        /* execute request */
        $res = curl_exec($this->ch);

        /* parse return data */
        if ($res !== FALSE) {
            $json = json_decode($res, true);
            $this->l->debug(sprintf("URL '%s' - success",
                $this->config['apiurl']));
        } else {
            $errstr = sprintf("Error fetching URL '%s': [%d] %s",
                              $url,
                              curl_errno($this->ch),
                              curl_error($this->ch));
            $this->l->err($errstr);
            throw new Exception($errstr);
        }

        /* debug output */
        $this->l->debug(sprintf("request result (json format): %s",
            print_r($json, TRUE)));

        $this->l->debug(sprintf("[%s:%s] end", __CLASS__, __FUNCTION__));

        return $json;
    }

    private function PP_login() {

        $this->l->debug(sprintf("[%s:%s] start", __CLASS__, __FUNCTION__));

        /* login step 1 - get logintoken */
        $pGET = array(
            "action" => "query",
            "meta" => "tokens",
            "type" => "login",
        );
        $pPOST = array();

        $json = $this->PP_request($pPOST, $pGET);
        if (!$json['query']['tokens']['logintoken']) {
            $errstr = "Could not acquire login token - check apiurl/account/password!";
            $this->l->err($errstr);
            throw new Exception($errstr);
        }
        $this->l->info("PP login (step 1): OK");

        /* login step 2 - login as Bot */
        $pGET = array();
        $pPOST = array(
            "action" => "login",
            "lgname" => $this->config['account'],
            "lgpassword" => $this->config['password'],
            "lgtoken" => $json['query']['tokens']['logintoken'],
        );
        $json = $this->PP_request($pPOST, $pGET);
        $this->l->info("PP login (step 2): OK");
        if (!$json['login']['result'] == "Success") {
            $errstr = "Login failed possible - check account/password!";
            $this->l->err($errstr);
            throw new Exception($errstr);
        }

        /* login successfull */
        $this->login = TRUE;
        $this->l->notice("Login to Perrypedia successful!");

        $this->l->debug(sprintf("[%s:%s] end", __CLASS__, __FUNCTION__));

    }

    /* ====================================================================== *
     * filesystem interactions                                                *
     * ====================================================================== */

    private function savePerrypediaJSON($directory, $pagedata)
    {
        $filename = $pagedata['title'];
        $filename = strtr($filename, " ", "_");

        $file = $directory .'/'. $filename .'.perrypedia.json';

        return $this->writePHP2file($file, $pagedata);
    }

    private function writeFile($file, $str)
    {
        $fh = fopen($file, "w+");
        fwrite($fh, $str);
        fclose($fh);
    }

    private function writePHP2file($file, $data)
    {
        $fh = fopen($file, "w+");
        fwrite($fh, serialize($data));
        fclose($fh);
    }

    private function readfile2PHP($file)
    {
        $content = file_get_contents($file);
        return unserialize($content);
    }

    /* ====================================================================== *
     * additional helper functions                                            *
     * ====================================================================== */

    /*
     https://stackoverflow.com/questions/6837148/change-foreign-characters-to-normal-equivalent

     modified: ü => u / ö => o / ä => a
     */
    private function transliterateString($txt) {
        $transliterationTable = array(
            'á' => 'a', 'Á' => 'A', 'à' => 'a', 'À' => 'A', 'ă' => 'a',
            'Ă' => 'A', 'â' => 'a', 'Â' => 'A', 'å' => 'a', 'Å' => 'A',
            'ã' => 'a', 'Ã' => 'A', 'ą' => 'a', 'Ą' => 'A', 'ā' => 'a',
            'Ā' => 'A', 'ä' => 'a', 'Ä' => 'A', 'æ' => 'ae', 'Æ' => 'AE',
            'ḃ' => 'b', 'Ḃ' => 'B', 'ć' => 'c', 'Ć' => 'C', 'ĉ' => 'c',
            'Ĉ' => 'C', 'č' => 'c', 'Č' => 'C', 'ċ' => 'c', 'Ċ' => 'C',
            'ç' => 'c', 'Ç' => 'C', 'ď' => 'd', 'Ď' => 'D', 'ḋ' => 'd',
            'Ḋ' => 'D', 'đ' => 'd', 'Đ' => 'D', 'ð' => 'dh', 'Ð' => 'Dh',
            'é' => 'e', 'É' => 'E', 'è' => 'e', 'È' => 'E', 'ĕ' => 'e',
            'Ĕ' => 'E', 'ê' => 'e', 'Ê' => 'E', 'ě' => 'e', 'Ě' => 'E',
            'ë' => 'e', 'Ë' => 'E', 'ė' => 'e', 'Ė' => 'E', 'ę' => 'e',
            'Ę' => 'E', 'ē' => 'e', 'Ē' => 'E', 'ḟ' => 'f', 'Ḟ' => 'F',
            'ƒ' => 'f', 'Ƒ' => 'F', 'ğ' => 'g', 'Ğ' => 'G', 'ĝ' => 'g',
            'Ĝ' => 'G', 'ġ' => 'g', 'Ġ' => 'G', 'ģ' => 'g', 'Ģ' => 'G',
            'ĥ' => 'h', 'Ĥ' => 'H', 'ħ' => 'h', 'Ħ' => 'H', 'í' => 'i',
            'Í' => 'I', 'ì' => 'i', 'Ì' => 'I', 'î' => 'i', 'Î' => 'I',
            'ï' => 'i', 'Ï' => 'I', 'ĩ' => 'i', 'Ĩ' => 'I', 'į' => 'i',
            'Į' => 'I', 'ī' => 'i', 'Ī' => 'I', 'ĵ' => 'j', 'Ĵ' => 'J',
            'ķ' => 'k', 'Ķ' => 'K', 'ĺ' => 'l', 'Ĺ' => 'L', 'ľ' => 'l',
            'Ľ' => 'L', 'ļ' => 'l', 'Ļ' => 'L', 'ł' => 'l', 'Ł' => 'L',
            'ṁ' => 'm', 'Ṁ' => 'M', 'ń' => 'n', 'Ń' => 'N', 'ň' => 'n',
            'Ň' => 'N', 'ñ' => 'n', 'Ñ' => 'N', 'ņ' => 'n', 'Ņ' => 'N',
            'ó' => 'o', 'Ó' => 'O', 'ò' => 'o', 'Ò' => 'O', 'ô' => 'o',
            'Ô' => 'O', 'ő' => 'o', 'Ő' => 'O', 'õ' => 'o', 'Õ' => 'O',
            'ø' => 'o', 'Ø' => 'O', 'ō' => 'o', 'Ō' => 'O', 'ơ' => 'o',
            'Ơ' => 'O', 'ö' => 'o', 'Ö' => 'O', 'ṗ' => 'p', 'Ṗ' => 'P',
            'ŕ' => 'r', 'Ŕ' => 'R', 'ř' => 'r', 'Ř' => 'R', 'ŗ' => 'r',
            'Ŗ' => 'R', 'ś' => 's', 'Ś' => 'S', 'ŝ' => 's', 'Ŝ' => 'S',
            'š' => 's', 'Š' => 'S', 'ṡ' => 's', 'Ṡ' => 'S', 'ş' => 's',
            'Ş' => 'S', 'ș' => 's', 'Ș' => 'S', 'ß' => 'S', 'ť' => 't',
            'Ť' => 'T', 'ṫ' => 't', 'Ṫ' => 'T', 'ţ' => 't', 'Ţ' => 'T',
            'ț' => 't', 'Ț' => 'T', 'ŧ' => 't', 'Ŧ' => 'T', 'ú' => 'u',
            'Ú' => 'U', 'ù' => 'u', 'Ù' => 'U', 'ŭ' => 'u', 'Ŭ' => 'U',
            'û' => 'u', 'Û' => 'U', 'ů' => 'u', 'Ů' => 'U', 'ű' => 'u',
            'Ű' => 'U', 'ũ' => 'u', 'Ũ' => 'U', 'ų' => 'u', 'Ų' => 'U',
            'ū' => 'u', 'Ū' => 'U', 'ư' => 'u', 'Ư' => 'U', 'ü' => 'u',
            'Ü' => 'U', 'ẃ' => 'w', 'Ẃ' => 'W', 'ẁ' => 'w', 'Ẁ' => 'W',
            'ŵ' => 'w', 'Ŵ' => 'W', 'ẅ' => 'w', 'Ẅ' => 'W', 'ý' => 'y',
            'Ý' => 'Y', 'ỳ' => 'y', 'Ỳ' => 'Y', 'ŷ' => 'y', 'Ŷ' => 'Y',
            'ÿ' => 'y', 'Ÿ' => 'Y', 'ź' => 'z', 'Ź' => 'Z', 'ž' => 'z',
            'Ž' => 'Z', 'ż' => 'z', 'Ż' => 'Z', 'þ' => 'th', 'Þ' => 'Th',
            'µ' => 'u', 'а' => 'a', 'А' => 'a', 'б' => 'b', 'Б' => 'b',
            'в' => 'v', 'В' => 'v', 'г' => 'g', 'Г' => 'g', 'д' => 'd',
            'Д' => 'd', 'е' => 'e', 'Е' => 'E', 'ё' => 'e', 'Ё' => 'E',
            'ж' => 'zh', 'Ж' => 'zh', 'з' => 'z', 'З' => 'z', 'и' => 'i',
            'И' => 'i', 'й' => 'j', 'Й' => 'j', 'к' => 'k', 'К' => 'k',
            'л' => 'l', 'Л' => 'l', 'м' => 'm', 'М' => 'm', 'н' => 'n',
            'Н' => 'n', 'о' => 'o', 'О' => 'o', 'п' => 'p', 'П' => 'p',
            'р' => 'r', 'Р' => 'r', 'с' => 's', 'С' => 's', 'т' => 't',
            'Т' => 't', 'у' => 'u', 'У' => 'u', 'ф' => 'f', 'Ф' => 'f',
            'х' => 'h', 'Х' => 'h', 'ц' => 'c', 'Ц' => 'c', 'ч' => 'ch',
            'Ч' => 'ch', 'ш' => 'sh', 'Ш' => 'sh', 'щ' => 'sch', 'Щ' => 'sch',
            'ъ' => '', 'Ъ' => '', 'ы' => 'y', 'Ы' => 'y', 'ь' => '',
            'Ь' => '', 'э' => 'e', 'Э' => 'e', 'ю' => 'ju', 'Ю' => 'ju',
            'я' => 'ja', 'Я' => 'ja',
            '»' => '', '«' => '');

        return str_replace(
            array_keys($transliterationTable),
            array_values($transliterationTable),
            $txt);
    }

    private function createPerrypediaGlossarAlph($key, $entries)
    {
        /* get number of entries */
        $keys = array_keys($entries);
        $count = count($keys);
        $column = ceil($count / 3);

        /* build perrypedia page for each entry */
        $str = sprintf("== %s ==\n\n", $key);
/* 1: use table */
/*
        $str .= "{| valign=\"top\" border=\"0\" cellpadding=\"4\" cellspacing=\"2\" width=\"100%\"\n";
        $str .= "| width=\"33%\" valign=\"top\" |\n";
        for ($ii = 0; $ii < min($column, $count); $ii++) {
            $str .= $this->createPerrypediaGlossarAlphEntry($entries[$keys[$ii]]);
        }
        $str .= "| width=\"33%\" valign=\"top\" |\n";
        for ($ii = $column; $ii < min(2*$column, $count); $ii++) {
            $str .= $this->createPerrypediaGlossarAlphEntry($entries[$keys[$ii]]);
        }
        $str .= "| width=\"33%\" valign=\"top\" |\n";
        for ($ii = 2*$column; $ii < $count; $ii++) {
            $str .= $this->createPerrypediaGlossarAlphEntry($entries[$keys[$ii]]);
        }
        $str .= "|}\n";
*/

/* 2: use diff */
        $str .= "<div style=\"column-width:30em\">\n";
        for ($ii = 0; $ii < $count; $ii++) {
            $str .= $this->createPerrypediaGlossarAlphEntry($entries[$keys[$ii]]);
        }
        $str .= "</div>\n";

        $str .= sprintf("''Anzahl der Einträge: '''%d''' ''\n", $count);

        return $str;
    }

    private function createPerrypediaGlossarAlphEntry($entry)
    {
        /* build data for one entry */
        $str = sprintf("* %s (", $entry['orig']);
        for ($ii = 0; $ii < count($entry['pr']); $ii++) {
            $str .= sprintf("[[Quelle:PR%1\$d|PR&nbsp;%1\$d]]", $entry['pr'][$ii]);
            if ($ii < count($entry['pr']) - 1) {
                $str .= ", ";
            }
        }
        $str .= ")\n";

        return $str;
    }

    private function perrypediaVisible($str)
    {
      /* find entries like:

       * [[9-Imbariem]] ([[Quelle:PR2092|PR&nbsp;2092]])
       * {{WP|al-Biruni|al-Biruni, Abu}} ([[Quelle:PR2909|PR&nbsp;2909]])

       */
        $pattern = "!({{WP\||\[\[)([^\]]+\||)(.*?)(}}|\]\])!";

        $str = preg_replace($pattern, "$3", $str);
        $str = htmlspecialchars_decode($str);
        $str = trim($str);

        return $str;
    }
}

?>
