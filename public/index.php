<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/Core/Env.php';
require_once __DIR__ . '/../src/Core/App.php';
require_once __DIR__ . '/../src/Core/ExceptionHandler.php';

use App\Core\App;
use App\Core\Env;
use App\Core\ExceptionHandler;

Env::load(__DIR__ . '/../.env');
ExceptionHandler::register();

$app = new App();
$app->run();
