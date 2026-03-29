<?php

declare(strict_types=1);

namespace App\Core;

use App\Controllers\AuthController;
use App\Controllers\BrandsController;
use App\Controllers\PagesController;

require_once __DIR__ . '/../Controllers/AuthController.php';
require_once __DIR__ . '/../Controllers/BrandsController.php';
require_once __DIR__ . '/../Controllers/PagesController.php';
require_once __DIR__ . '/../Core/Exceptions/HttpException.php';
require_once __DIR__ . '/../Core/Exceptions/ValidationException.php';
require_once __DIR__ . '/ExceptionHandler.php';
require_once __DIR__ . '/Request.php';
require_once __DIR__ . '/Response.php';
require_once __DIR__ . '/Router.php';
require_once __DIR__ . '/SecurityHeaders.php';
require_once __DIR__ . '/Validator.php';
require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/Jwt.php';
require_once __DIR__ . '/Uuid.php';
require_once __DIR__ . '/../Middleware/AuthMiddleware.php';
require_once __DIR__ . '/../Security/RateLimiter.php';
require_once __DIR__ . '/../Security/LoginLockout.php';
require_once __DIR__ . '/../Core/Phone.php';
require_once __DIR__ . '/../Repositories/UserRepository.php';
require_once __DIR__ . '/../Repositories/RefreshTokenRepository.php';
require_once __DIR__ . '/../Repositories/PhoneOtpChallengeRepository.php';
require_once __DIR__ . '/../Services/OtpNotifier.php';
require_once __DIR__ . '/../Services/AuthService.php';
require_once __DIR__ . '/../Repositories/PageRepository.php';
require_once __DIR__ . '/../Repositories/BrandRepository.php';

final class App
{
    private const API_PREFIX = '/v1';

    public function run(): void
    {
        SecurityHeaders::apply();

        $router = new Router();
        $request = new Request();

        // CORS preflight
        if ($request->method() === 'OPTIONS') {
            http_response_code(204);
            return;
        }

        $auth = new AuthController();
        $pages = new PagesController();
        $brands = new BrandsController();

        $router->add('GET', self::API_PREFIX . '/brands', static function (Request $r) use ($brands): void {
            $brands->index($r);
        });
        $router->add('POST', self::API_PREFIX . '/brands', static function (Request $r) use ($brands): void {
            $brands->create($r);
        });
        $router->add('PUT', self::API_PREFIX . '/brands', static function (Request $r) use ($brands): void {
            $brands->update($r);
        });
        $router->add('DELETE', self::API_PREFIX . '/brands', static function (Request $r) use ($brands): void {
            $brands->delete($r);
        });

        $router->add('GET', self::API_PREFIX . '/pages', static function (Request $r) use ($pages): void {
            $pages->index($r);
        });
        $router->add('POST', self::API_PREFIX . '/pages', static function (Request $r) use ($pages): void {
            $pages->create($r);
        });
        $router->add('PUT', self::API_PREFIX . '/pages', static function (Request $r) use ($pages): void {
            $pages->update($r);
        });
        $router->add('DELETE', self::API_PREFIX . '/pages', static function (Request $r) use ($pages): void {
            $pages->delete($r);
        });

        $router->add('POST', self::API_PREFIX . '/auth/otp/send', static function (Request $r) use ($auth): void {
            $auth->otpSend($r);
        });
        $router->add('POST', self::API_PREFIX . '/auth/otp/verify', static function (Request $r) use ($auth): void {
            $auth->otpVerify($r);
        });
        $router->add('POST', self::API_PREFIX . '/auth/refresh', static function (Request $r) use ($auth): void {
            $auth->refresh($r);
        });
        $router->add('POST', self::API_PREFIX . '/auth/logout', static function (Request $r) use ($auth): void {
            $auth->logout($r);
        });
        $router->add('GET', self::API_PREFIX . '/auth/me', static function (Request $r) use ($auth): void {
            $auth->me($r);
        });

        $router->dispatch($request);
    }
}
