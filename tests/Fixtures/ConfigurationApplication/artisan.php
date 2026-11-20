<?php

declare(strict_types=1);

use Illuminate\Foundation\Application;
use Symfony\Component\Console\Input\ArgvInput;

require dirname(__DIR__, 3).'/vendor/autoload.php';

/** @var Application $app */
$app = require getcwd().'/bootstrap/app.php';

exit($app->handleCommand(new ArgvInput));
