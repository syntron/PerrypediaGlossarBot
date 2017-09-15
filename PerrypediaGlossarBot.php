#!/usr/bin/php
<?php

/* load configuration (variable $config) */
include_once('./config.php');

/* load class */
include_once('./lib/PerrypediaGlossarBot.class.php');

/* create an instance of the bot main class*/
$bot = new PerrypediaGlossarBot($config);
/* and run it */
$bot->run();
