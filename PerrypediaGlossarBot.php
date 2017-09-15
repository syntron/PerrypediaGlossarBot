#!/usr/bin/php
<?php

/* load configuration (variable $config) */
include_once('./config.php');

/* load rename table */
include_once('./config-rename.php');

/* load class */
include_once('./lib/PerrypediaGlossarBot.class.php');

/* create an instance of the bot main class*/
$bot = new PerrypediaGlossarBot($config, $rename);
/* and run it */
$bot->run();
