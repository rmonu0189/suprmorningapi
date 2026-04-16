<?php

declare(strict_types=1);

// CLI entrypoint for cron.

require_once __DIR__ . '/../src/Core/Env.php';
require_once __DIR__ . '/../src/Core/App.php';
require_once __DIR__ . '/../src/Core/ExceptionHandler.php';

use App\Core\Env;
use App\Core\ExceptionHandler;
use App\Services\SubscriptionOrderGenerator;

Env::load(__DIR__ . '/../.env');
ExceptionHandler::register();

$dateArg = $argv[1] ?? null;
if (is_string($dateArg) && trim($dateArg) !== '') {
    $dateArg = trim($dateArg);
    $dt = DateTimeImmutable::createFromFormat('Y-m-d', $dateArg);
    if ($dt === false || $dt->format('Y-m-d') !== $dateArg) {
        fwrite(STDERR, "Invalid date argument. Use YYYY-MM-DD.\n");
        exit(2);
    }
    $deliveryDate = $dt;
} else {
    $deliveryDate = new DateTimeImmutable('tomorrow');
}

$result = SubscriptionOrderGenerator::generateForDeliveryDate($deliveryDate);
fwrite(STDOUT, json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n");

