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
    private $rename = NULL; /* rename table */
    private $l = NULL;      /* log handle */
    private $args = NULL;   /* command line arguments */

    private $urls = array(  /* URLs used to connect to perrypedia */
        'api' => "https://www.perrypedia.proc.org/mediawiki/api.php",
    );

    private $GlossarAlphPages= array(
        'A','B','C','D','E','F','G','H','I-J','K','L','M','N','O','P-Q','R',
        'S','T','U-W','X-Z'
    );

    private $dirs = array(
        "01fetch"   => "./steps/01fetch",
        "02create"  => "./steps/02create",
        "03diff"    => "./steps/03diff",
        "04submit"  => "./steps/04submit",
    );

    /* constructor
       $config      - configuration (perrypedia account/password)
     */
    function __construct($config, $rename = array()) {

        // first try system PEAR
        @require_once('System.php');
        // if not successfull use the local version
        if (!class_exists("System")) {
            require_once(PGB_BASEDIR .'/dependencies/PEAR/System.php');
        }

        /* save config */
        $this->config = $config;

        /* save rename table */
        $this->rename = $rename;

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

    /* prepare log */
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
        $json = $this->fetchPPjson($titles);
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
                        'orig' => $entry[1],
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

        /* build pages */
        $str_pre = sprintf("{{Navigationsleiste Glossar alphabetisch}}

Stand: [[Quelle:PR%1\$d|PR&nbsp;%1\$d]]

", $pr_max);
        $str_post = "
[[Kategorie:Beilage]]

{{PPDefaultsort}}
";
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

        /* definitoins from http://code.stephenmorley.org/php/diff-implementation/ */
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

    private function run04submit()
    {

        $this->l->debug(sprintf("[%s:%s] start", __CLASS__, __FUNCTION__));
        $this->l->debug(sprintf("[%s:%s] end", __CLASS__, __FUNCTION__));

    }

    private function fetchPPjson($titles)
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
        $json = $this->request4PP($this->urls['api'], NULL, $pGET);

        $this->l->debug(sprintf("[%s:%s] end", __CLASS__, __FUNCTION__));

        return $json;
    }

    /*
     sources:
     - https://www.mediawiki.org/wiki/API
     - https://www.mediawiki.org/wiki/User:Bcoughlan/Login_with_curl
     */
    private function request4PP($url, $pPOST = array(), $pGET = array())
    {

        $this->l->debug(sprintf("[%s:%s] start", __CLASS__, __FUNCTION__));

        /* check arguments */
        $pPOST = (array)$pPOST;
        $pGET = (array)$pGET;

        /* force json */
        $pPOST['format'] = 'json';
        $pGET['format'] = 'json';

        /* define URL (api URL + GET parameters) */
        $this->l->debug(sprintf("URL:  '%s'", $url));
        $url = $url .'?';
        foreach ($pGET as $k => $v) {
          $url = $url . sprintf("%s=%s&", urlencode($k), urlencode($v));
          $this->l->debug(sprintf("GET:  '%s' => '%s'", $k, $v));
        }
        /* define POST parameters */
        $strPOST = "";
        foreach ($pPOST as $k => $v) {
          $strPOST = $strPOST . sprintf("%s=%s&", urlencode($k), urlencode($v));
          /* check for long values */
          if ($strlen = strlen($v) > 33) {
            // additional '-1' for the NULL terminated string
            $v = substr_replace($v, "...", 15, $strlen - 15 - 1);
          }
          $this->l->debug(sprintf("POST: '%s' => '%s'", $k, $v));
        }

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
            $errstr = sprintf("Error fetching URL '%s': [%d] %s",
                              $url, curl_errno($ch), curl_error($ch));
            $this->l->err($errstr);
            throw new Exception($errstr);
        }

        $this->l->debug(sprintf("[%s:%s] end", __CLASS__, __FUNCTION__));

        return $json;
    }

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
        $pattern = "!\[\[([^\]]+\||)(.*?)\]\]!";

        $str = preg_replace($pattern, "$2", $str);
        $str = trim($str);

        return $str;
    }
}

?>
