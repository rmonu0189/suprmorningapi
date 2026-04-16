<?php

declare(strict_types=1);

namespace App\Core;

use App\Controllers\AdminOrderController;
use App\Controllers\AdminDeliveryController;
use App\Controllers\AdminDashboardController;
use App\Controllers\AdminUsersController;
use App\Controllers\AdminAnalyticsController;
use App\Controllers\AdminWarehouseController;
use App\Controllers\AddressController;
use App\Controllers\AuthController;
use App\Controllers\AdminUploadsController;
use App\Controllers\BrandsController;
use App\Controllers\CartChargesController;
use App\Controllers\CartController;
use App\Controllers\CatalogController;
use App\Controllers\CouponsController;
use App\Controllers\FilesController;
use App\Controllers\InventoryController;
use App\Controllers\InventoryMovementsController;
use App\Controllers\LoveController;
use App\Controllers\OrderController;
use App\Controllers\PagesController;
use App\Controllers\ProductsController;
use App\Controllers\RazorpayWebhookController;
use App\Controllers\VariantsController;
use App\Controllers\CategoriesController;
use App\Controllers\SubcategoriesController;
use App\Controllers\SubscriptionController;
use App\Controllers\AdminSubscriptionsController;

require_once __DIR__ . '/../Controllers/AdminOrderController.php';
require_once __DIR__ . '/../Controllers/AdminDeliveryController.php';
require_once __DIR__ . '/../Controllers/AdminDashboardController.php';
require_once __DIR__ . '/../Controllers/AdminUsersController.php';
require_once __DIR__ . '/../Controllers/AdminAnalyticsController.php';
require_once __DIR__ . '/../Controllers/AdminWarehouseController.php';
require_once __DIR__ . '/../Controllers/AdminUploadsController.php';
require_once __DIR__ . '/../Controllers/FilesController.php';
require_once __DIR__ . '/../Controllers/AddressController.php';
require_once __DIR__ . '/../Controllers/AuthController.php';
require_once __DIR__ . '/../Controllers/BrandsController.php';
require_once __DIR__ . '/../Controllers/CartController.php';
require_once __DIR__ . '/../Controllers/CartChargesController.php';
require_once __DIR__ . '/../Controllers/CatalogController.php';
require_once __DIR__ . '/../Controllers/CouponsController.php';
require_once __DIR__ . '/../Controllers/InventoryController.php';
require_once __DIR__ . '/../Controllers/InventoryMovementsController.php';
require_once __DIR__ . '/../Controllers/LoveController.php';
require_once __DIR__ . '/../Controllers/OrderController.php';
require_once __DIR__ . '/../Controllers/ProductsController.php';
require_once __DIR__ . '/../Controllers/CategoriesController.php';
require_once __DIR__ . '/../Controllers/SubcategoriesController.php';
require_once __DIR__ . '/../Controllers/SubscriptionController.php';
require_once __DIR__ . '/../Controllers/AdminSubscriptionsController.php';
require_once __DIR__ . '/../Controllers/PagesController.php';
require_once __DIR__ . '/../Controllers/RazorpayWebhookController.php';
require_once __DIR__ . '/../Controllers/VariantsController.php';
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
require_once __DIR__ . '/../Repositories/WarehouseRepository.php';
require_once __DIR__ . '/../Repositories/RefreshTokenRepository.php';
require_once __DIR__ . '/../Repositories/PhoneOtpChallengeRepository.php';
require_once __DIR__ . '/../Services/OtpNotifier.php';
require_once __DIR__ . '/../Services/AuthService.php';
require_once __DIR__ . '/../Repositories/PageRepository.php';
require_once __DIR__ . '/../Repositories/BrandRepository.php';
require_once __DIR__ . '/../Repositories/AdminAnalyticsRepository.php';
require_once __DIR__ . '/../Repositories/AdminDashboardRepository.php';
require_once __DIR__ . '/../Repositories/CartChargeRepository.php';
require_once __DIR__ . '/../Repositories/CatalogRepository.php';
require_once __DIR__ . '/../Repositories/CartRepository.php';
require_once __DIR__ . '/../Repositories/ProductRepository.php';
require_once __DIR__ . '/../Repositories/CategoryRepository.php';
require_once __DIR__ . '/../Repositories/SubcategoryRepository.php';
require_once __DIR__ . '/../Repositories/VariantRepository.php';
require_once __DIR__ . '/../Repositories/InventoryRepository.php';
require_once __DIR__ . '/../Repositories/InventoryMovementRepository.php';
require_once __DIR__ . '/../Repositories/AddressRepository.php';
require_once __DIR__ . '/../Repositories/LoveRepository.php';
require_once __DIR__ . '/../Repositories/OrderRepository.php';
require_once __DIR__ . '/../Repositories/PaymentRepository.php';
require_once __DIR__ . '/../Repositories/PaymentEventRepository.php';
require_once __DIR__ . '/../Repositories/CouponRepository.php';
require_once __DIR__ . '/../Repositories/FileRepository.php';
require_once __DIR__ . '/../Repositories/SubscriptionRepository.php';
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
        $cartChargesAdmin = new CartChargesController();
        $addresses = new AddressController();
        $loves = new LoveController();
        $catalog = new CatalogController();
        $orders = new OrderController();
        $adminOrders = new AdminOrderController();
        $adminDelivery = new AdminDeliveryController();
        $adminAnalytics = new AdminAnalyticsController();
        $adminDashboard = new AdminDashboardController();
        $adminUsers = new AdminUsersController();
        $adminWarehouses = new AdminWarehouseController();
        $adminUploads = new AdminUploadsController();
        $files = new FilesController();
        $products = new ProductsController();
        $categories = new CategoriesController();
        $subcategories = new SubcategoriesController();
        $variants = new VariantsController();
        $inventory = new InventoryController();
        $inventoryMovements = new InventoryMovementsController();
        $coupons = new CouponsController();
        $subscriptions = new SubscriptionController();
        $adminSubscriptions = new AdminSubscriptionsController();
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

        $router->add('GET', self::API_PREFIX . '/products', static function (Request $r) use ($products): void {
            $products->index($r);
        });
        $router->add('POST', self::API_PREFIX . '/products', static function (Request $r) use ($products): void {
            $products->create($r);
        });
        $router->add('PUT', self::API_PREFIX . '/products', static function (Request $r) use ($products): void {
            $products->update($r);
        });
        $router->add('DELETE', self::API_PREFIX . '/products', static function (Request $r) use ($products): void {
            $products->delete($r);
        });

        $router->add('GET', self::API_PREFIX . '/categories', static function (Request $r) use ($categories): void {
            $categories->index($r);
        });
        $router->add('POST', self::API_PREFIX . '/categories', static function (Request $r) use ($categories): void {
            $categories->create($r);
        });
        $router->add('PUT', self::API_PREFIX . '/categories', static function (Request $r) use ($categories): void {
            $categories->update($r);
        });
        $router->add('DELETE', self::API_PREFIX . '/categories', static function (Request $r) use ($categories): void {
            $categories->delete($r);
        });

        $router->add('GET', self::API_PREFIX . '/subcategories', static function (Request $r) use ($subcategories): void {
            $subcategories->index($r);
        });
        $router->add('POST', self::API_PREFIX . '/subcategories', static function (Request $r) use ($subcategories): void {
            $subcategories->create($r);
        });
        $router->add('PUT', self::API_PREFIX . '/subcategories', static function (Request $r) use ($subcategories): void {
            $subcategories->update($r);
        });
        $router->add('DELETE', self::API_PREFIX . '/subcategories', static function (Request $r) use ($subcategories): void {
            $subcategories->delete($r);
        });

        $router->add('GET', self::API_PREFIX . '/variants', static function (Request $r) use ($variants): void {
            $variants->index($r);
        });
        $router->add('POST', self::API_PREFIX . '/variants', static function (Request $r) use ($variants): void {
            $variants->create($r);
        });
        $router->add('PUT', self::API_PREFIX . '/variants', static function (Request $r) use ($variants): void {
            $variants->update($r);
        });
        $router->add('DELETE', self::API_PREFIX . '/variants', static function (Request $r) use ($variants): void {
            $variants->delete($r);
        });

        $router->add('GET', self::API_PREFIX . '/inventory', static function (Request $r) use ($inventory): void {
            $inventory->index($r);
        });
        $router->add('PUT', self::API_PREFIX . '/inventory', static function (Request $r) use ($inventory): void {
            $inventory->update($r);
        });

        $router->add('GET', self::API_PREFIX . '/inventory/movements', static function (Request $r) use ($inventoryMovements): void {
            $inventoryMovements->index($r);
        });
        $router->add('POST', self::API_PREFIX . '/inventory/movements', static function (Request $r) use ($inventoryMovements): void {
            $inventoryMovements->create($r);
        });

        $router->add('GET', self::API_PREFIX . '/coupons', static function (Request $r) use ($coupons): void {
            $coupons->index($r);
        });
        $router->add('POST', self::API_PREFIX . '/coupons', static function (Request $r) use ($coupons): void {
            $coupons->create($r);
        });
        $router->add('PUT', self::API_PREFIX . '/coupons', static function (Request $r) use ($coupons): void {
            $coupons->update($r);
        });
        $router->add('DELETE', self::API_PREFIX . '/coupons', static function (Request $r) use ($coupons): void {
            $coupons->delete($r);
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
        $router->add('POST', self::API_PREFIX . '/subscriptions', static function (Request $r) use ($subscriptions): void {
            $subscriptions->create($r);
        });

        $router->add('GET', self::API_PREFIX . '/admin/orders', static function (Request $r) use ($adminOrders): void {
            $adminOrders->index($r);
        });
        $router->add('GET', self::API_PREFIX . '/admin/orders/status-events', static function (Request $r) use ($adminOrders): void {
            $adminOrders->statusEvents($r);
        });
        $router->add('PATCH', self::API_PREFIX . '/admin/orders', static function (Request $r) use ($adminOrders): void {
            $adminOrders->patch($r);
        });

        $router->add('GET', self::API_PREFIX . '/admin/warehouses', static function (Request $r) use ($adminWarehouses): void {
            $adminWarehouses->index($r);
        });
        $router->add('POST', self::API_PREFIX . '/admin/warehouses', static function (Request $r) use ($adminWarehouses): void {
            $adminWarehouses->create($r);
        });
        $router->add('PUT', self::API_PREFIX . '/admin/warehouses', static function (Request $r) use ($adminWarehouses): void {
            $adminWarehouses->update($r);
        });

        $router->add('GET', self::API_PREFIX . '/admin/delivery', static function (Request $r) use ($adminDelivery): void {
            $adminDelivery->index($r);
        });
        $router->add('GET', self::API_PREFIX . '/admin/delivery/order', static function (Request $r) use ($adminDelivery): void {
            $adminDelivery->show($r);
        });
        $router->add('PATCH', self::API_PREFIX . '/admin/delivery/order-checks', static function (Request $r) use ($adminDelivery): void {
            $adminDelivery->patchChecks($r);
        });

        $router->add('GET', self::API_PREFIX . '/admin/analytics/overview', static function (Request $r) use ($adminAnalytics): void {
            $adminAnalytics->overview($r);
        });

        $router->add('GET', self::API_PREFIX . '/admin/dashboard/summary', static function (Request $r) use ($adminDashboard): void {
            $adminDashboard->summary($r);
        });

        $router->add('GET', self::API_PREFIX . '/admin/users', static function (Request $r) use ($adminUsers): void {
            $adminUsers->index($r);
        });
        $router->add('GET', self::API_PREFIX . '/admin/users/by-phone', static function (Request $r) use ($adminUsers): void {
            $adminUsers->byPhone($r);
        });
        $router->add('PATCH', self::API_PREFIX . '/admin/users/role', static function (Request $r) use ($adminUsers): void {
            $adminUsers->updateRole($r);
        });
        $router->add('GET', self::API_PREFIX . '/admin/subscriptions', static function (Request $r) use ($adminSubscriptions): void {
            $adminSubscriptions->index($r);
        });
        $router->add('POST', self::API_PREFIX . '/admin/uploads', static function (Request $r) use ($adminUploads): void {
            $adminUploads->upload($r);
        });

        $router->add('GET', self::API_PREFIX . '/files', static function (Request $r) use ($files): void {
            $files->serve($r);
        });

        $router->add('GET', self::API_PREFIX . '/admin/cart-charges', static function (Request $r) use ($cartChargesAdmin): void {
            $cartChargesAdmin->adminIndex($r);
        });
        $router->add('POST', self::API_PREFIX . '/admin/cart-charges', static function (Request $r) use ($cartChargesAdmin): void {
            $cartChargesAdmin->create($r);
        });
        $router->add('PUT', self::API_PREFIX . '/admin/cart-charges', static function (Request $r) use ($cartChargesAdmin): void {
            $cartChargesAdmin->update($r);
        });
        $router->add('DELETE', self::API_PREFIX . '/admin/cart-charges', static function (Request $r) use ($cartChargesAdmin): void {
            $cartChargesAdmin->delete($r);
        });

        $router->add('POST', self::API_PREFIX . '/webhooks/razorpay', static function (Request $r) use ($razorpayHook): void {
            $razorpayHook->handle($r);
        });

        $router->dispatch($request);
    }
}
