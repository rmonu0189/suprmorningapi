<?php

declare(strict_types=1);

// CLI entrypoint for cron. Recommended cadence: every 5 minutes.

require_once __DIR__ . '/../src/Core/Env.php';
require_once __DIR__ . '/../src/Core/App.php';
require_once __DIR__ . '/../src/Core/ExceptionHandler.php';

use App\Core\Env;
use App\Core\ExceptionHandler;
use App\Services\CommerceGatewayPaymentService;

Env::load(__DIR__ . '/../.env');
ExceptionHandler::register();

$expired = CommerceGatewayPaymentService::expireStaleWalletHolds();
fwrite(STDOUT, json_encode(['expired_wallet_holds' => $expired], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n");
