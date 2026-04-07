<?php

declare(strict_types=1);

namespace App\Core;

use App\Controllers\AddressController;
use App\Controllers\AuthController;
use App\Controllers\BrandsController;
use App\Controllers\CartController;
use App\Controllers\CatalogController;
use App\Controllers\LoveController;
use App\Controllers\OrderController;
use App\Controllers\PagesController;
use App\Controllers\RazorpayWebhookController;

require_once __DIR__ . '/../Controllers/AddressController.php';
require_once __DIR__ . '/../Controllers/AuthController.php';
require_once __DIR__ . '/../Controllers/BrandsController.php';
require_once __DIR__ . '/../Controllers/CartController.php';
require_once __DIR__ . '/../Controllers/CatalogController.php';
require_once __DIR__ . '/../Controllers/LoveController.php';
require_once __DIR__ . '/../Controllers/OrderController.php';
require_once __DIR__ . '/../Controllers/PagesController.php';
require_once __DIR__ . '/../Controllers/RazorpayWebhookController.php';
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
require_once __DIR__ . '/../Repositories/CartChargeRepository.php';
require_once __DIR__ . '/../Repositories/CatalogRepository.php';
require_once __DIR__ . '/../Repositories/CartRepository.php';
require_once __DIR__ . '/../Repositories/AddressRepository.php';
require_once __DIR__ . '/../Repositories/LoveRepository.php';
require_once __DIR__ . '/../Repositories/OrderRepository.php';
require_once __DIR__ . '/../Repositories/PaymentRepository.php';
require_once __DIR__ . '/../Services/RazorpayService.php';
require_once __DIR__ . '/../Services/OrderPlacementService.php';

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
        $cart = new CartController();
        $addresses = new AddressController();
        $loves = new LoveController();
        $catalog = new CatalogController();
        $orders = new OrderController();
        $razorpayHook = new RazorpayWebhookController();

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
        $router->add('PATCH', self::API_PREFIX . '/auth/me', static function (Request $r) use ($auth): void {
            $auth->patchMe($r);
        });

        $router->add('GET', self::API_PREFIX . '/cart/charges', static function (Request $r) use ($cart): void {
            $cart->charges($r);
        });
        $router->add('GET', self::API_PREFIX . '/cart', static function (Request $r) use ($cart): void {
            $cart->show($r);
        });
        $router->add('POST', self::API_PREFIX . '/cart/items/add', static function (Request $r) use ($cart): void {
            $cart->addItem($r);
        });
        $router->add('POST', self::API_PREFIX . '/cart/items/decrease', static function (Request $r) use ($cart): void {
            $cart->decreaseItem($r);
        });
        $router->add('POST', self::API_PREFIX . '/cart/lock', static function (Request $r) use ($cart): void {
            $cart->lock($r);
        });

        $router->add('GET', self::API_PREFIX . '/addresses', static function (Request $r) use ($addresses): void {
            $addresses->index($r);
        });
        $router->add('POST', self::API_PREFIX . '/addresses', static function (Request $r) use ($addresses): void {
            $addresses->create($r);
        });
        $router->add('PUT', self::API_PREFIX . '/addresses', static function (Request $r) use ($addresses): void {
            $addresses->update($r);
        });
        $router->add('DELETE', self::API_PREFIX . '/addresses', static function (Request $r) use ($addresses): void {
            $addresses->delete($r);
        });
        $router->add('POST', self::API_PREFIX . '/addresses/default', static function (Request $r) use ($addresses): void {
            $addresses->setDefault($r);
        });
        $router->add('POST', self::API_PREFIX . '/addresses/default-latest', static function (Request $r) use ($addresses): void {
            $addresses->setDefaultLatest($r);
        });

        $router->add('GET', self::API_PREFIX . '/loves', static function (Request $r) use ($loves): void {
            $loves->index($r);
        });
        $router->add('GET', self::API_PREFIX . '/loves/ids', static function (Request $r) use ($loves): void {
            $loves->ids($r);
        });
        $router->add('POST', self::API_PREFIX . '/loves', static function (Request $r) use ($loves): void {
            $loves->add($r);
        });
        $router->add('DELETE', self::API_PREFIX . '/loves', static function (Request $r) use ($loves): void {
            $loves->remove($r);
        });

        $router->add('GET', self::API_PREFIX . '/catalog/variant-detail', static function (Request $r) use ($catalog): void {
            $catalog->variantDetail($r);
        });

        $router->add('POST', self::API_PREFIX . '/orders/place', static function (Request $r) use ($orders): void {
            $orders->place($r);
        });
        $router->add('GET', self::API_PREFIX . '/orders', static function (Request $r) use ($orders): void {
            $orders->index($r);
        });
        $router->add('GET', self::API_PREFIX . '/orders/by-id', static function (Request $r) use ($orders): void {
            $orders->byId($r);
        });
        $router->add('GET', self::API_PREFIX . '/orders/by-gateway', static function (Request $r) use ($orders): void {
            $orders->byGateway($r);
        });
        $router->add('GET', self::API_PREFIX . '/orders/payment-status', static function (Request $r) use ($orders): void {
            $orders->paymentStatus($r);
        });

        $router->add('POST', self::API_PREFIX . '/webhooks/razorpay', static function (Request $r) use ($razorpayHook): void {
            $razorpayHook->handle($r);
        });

        $router->dispatch($request);
    }
}
