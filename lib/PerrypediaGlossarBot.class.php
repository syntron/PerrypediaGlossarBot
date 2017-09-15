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
        "02create"  => "./steps/02create",
        "03diff"    => "./steps/03diff",
        "04submit"  => "./steps/04submit",
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
                $this->run02create();
                $this->run04diff();
                $this->run07submit();
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
//                 $this->run04submit();
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
            /* save json data */
            $this->savePerrypediaJSON($directory, $p);

            /* save page for comparison */
            $pagename = $p['title'];
            $pagename = strtr($pagename, " ", "_");
            $filename = $directory .'/'. $pagename .'.perrypedia.txt';
            $content = $p['revisions'][0]['*'];
            $this->writePerrypedia($filename, $content);
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
            $this->l->error(sprintf("Can not create directory: %s", $directory));
            exit(1);
        }

        /* save latest pr entry */
        $pr_max = 0;

        /* get all data */
        $entries = array();
        $files = System::find($this->dirs['01fetch'] .' -name Perry_Rhodan-Glossar_*_-_*.perrypedia.json');
        foreach ($files as $f) {
            $content = file_get_contents($f);
            preg_match_all("!\[\[Quelle:PR(\d{4}).*?\|-(.*?)(\|-|\|\})!ms",
                $content, $m1, PREG_SET_ORDER);
            foreach ($m1 as $one) {
                $pr = $one[1];
                preg_match_all("!^\* (.*?)$!ms", $one[2], $m2, PREG_SET_ORDER);
                foreach ($m2 as $entry) {
                    $entries[] = array(
                        'pr' => $pr,
                        'orig' => $entry[1],
                        /* the command below creates the entry as it will be
                           visible in the HTML page */
                        'visible' => preg_replace("!\[\[([^\]]+\||)(.*?)\]\]!",
                            "$2", $entry[1]),
                    );
                }
                /* consider only pr with glossar for lates pr */
                if (count($m2) > 0) {
                    $pr_max = max($pr, $pr_max);
                }
            }
        }

        /* TODO: renames if needed */

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
        foreach ($glossar as $k => $v) {
            ksort($v);
            $glossar[$k] = $v;
        }
        ksort($glossar);

        /* build basic tables for each letter */
        $perrypedia = array();
        foreach ($glossar as $key => $entries) {
            $perrypedia[$key] = $this->createPerrypediaGlossarAlph($key, $entries);
        }

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

            $str_pre = sprintf("{{Navigationsleiste Glossar alphabetisch}}

Stand: [[Quelle:PR%1\$d|PR&nbsp;%1\$d]]

", $pr_max);

            $str_post = "

[[Kategorie:Beilage]]

{{PPDefaultsort}}
";

            $page_key = "Perry_Rhodan-Glossar_". $p;
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
            $this->writePerrypedia($filename, $content);
        }

        $this->l->debug(sprintf("[%s:%s] end", __CLASS__, __FUNCTION__));

    }

    private function run03diff()
    {

        $this->l->debug(sprintf("[%s:%s] start", __CLASS__, __FUNCTION__));

        /* save new pages */
        foreach ($pages as $k => $v) {
            $filename = $k;
            
        }

        $this->l->debug(sprintf("[%s:%s] end", __CLASS__, __FUNCTION__));

    }

    private function run04submit()
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

		$this->l->debug(sprintf("[%s:%s] start", __CLASS__, __FUNCTION__));

		$filename = $pagedata['title'];
        $filename = strtr($filename, " ", "_");

        $file = $directory .'/'. $filename .'.perrypedia.json';

		$this->l->debug(sprintf("[%s:%s] end", __CLASS__, __FUNCTION__));

        return $this->writePHP2file($file, $pagedata);
    }

    private function writePerrypedia($file, $str)
    {

		$this->l->debug(sprintf("[%s:%s] start", __CLASS__, __FUNCTION__));

		$fh = fopen($file, "w+");
        fwrite($fh, $str);
        fclose($fh);

		$this->l->debug(sprintf("[%s:%s] end", __CLASS__, __FUNCTION__));

    }

    private function writePHP2file($file, $data)
    {

		$this->l->debug(sprintf("[%s:%s] start", __CLASS__, __FUNCTION__));

		$fh = fopen($file, "w+");
        fwrite($fh, serialize($data));
        fclose($fh);

		$this->l->debug(sprintf("[%s:%s] end", __CLASS__, __FUNCTION__));

    }

    private function readfile2PHP($file)
    {

		$this->l->debug(sprintf("[%s:%s] start", __CLASS__, __FUNCTION__));

        $content = file_get_contents($file);
        return unserialize($content);

		$this->l->debug(sprintf("[%s:%s] end", __CLASS__, __FUNCTION__));

    }

    /*
     https://stackoverflow.com/questions/6837148/change-foreign-characters-to-normal-equivalent
     */
    private function transliterateString($txt) {
        $transliterationTable = array(
            'á' => 'a', 'Á' => 'A', 'à' => 'a', 'À' => 'A', 'ă' => 'a',
            'Ă' => 'A', 'â' => 'a', 'Â' => 'A', 'å' => 'a', 'Å' => 'A',
            'ã' => 'a', 'Ã' => 'A', 'ą' => 'a', 'Ą' => 'A', 'ā' => 'a',
            'Ā' => 'A', 'ä' => 'ae', 'Ä' => 'AE', 'æ' => 'ae', 'Æ' => 'AE',
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
            'ø' => 'oe', 'Ø' => 'OE', 'ō' => 'o', 'Ō' => 'O', 'ơ' => 'o',
            'Ơ' => 'O', 'ö' => 'oe', 'Ö' => 'OE', 'ṗ' => 'p', 'Ṗ' => 'P',
            'ŕ' => 'r', 'Ŕ' => 'R', 'ř' => 'r', 'Ř' => 'R', 'ŗ' => 'r',
            'Ŗ' => 'R', 'ś' => 's', 'Ś' => 'S', 'ŝ' => 's', 'Ŝ' => 'S',
            'š' => 's', 'Š' => 'S', 'ṡ' => 's', 'Ṡ' => 'S', 'ş' => 's',
            'Ş' => 'S', 'ș' => 's', 'Ș' => 'S', 'ß' => 'SS', 'ť' => 't',
            'Ť' => 'T', 'ṫ' => 't', 'Ṫ' => 'T', 'ţ' => 't', 'Ţ' => 'T',
            'ț' => 't', 'Ț' => 'T', 'ŧ' => 't', 'Ŧ' => 'T', 'ú' => 'u',
            'Ú' => 'U', 'ù' => 'u', 'Ù' => 'U', 'ŭ' => 'u', 'Ŭ' => 'U',
            'û' => 'u', 'Û' => 'U', 'ů' => 'u', 'Ů' => 'U', 'ű' => 'u',
            'Ű' => 'U', 'ũ' => 'u', 'Ũ' => 'U', 'ų' => 'u', 'Ų' => 'U',
            'ū' => 'u', 'Ū' => 'U', 'ư' => 'u', 'Ư' => 'U', 'ü' => 'ue',
            'Ü' => 'UE', 'ẃ' => 'w', 'Ẃ' => 'W', 'ẁ' => 'w', 'Ẁ' => 'W',
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
        $str = sprintf("== %s ==\n", $key);
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

}

?>
