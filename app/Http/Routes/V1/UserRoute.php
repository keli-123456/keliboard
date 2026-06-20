<?php
namespace App\Http\Routes\V1;

use App\Http\Controllers\V1\User\CommController;
use App\Http\Controllers\V1\User\AgentCommerceController;
use App\Http\Controllers\V1\User\AgentController;
use App\Http\Controllers\V1\User\AgentOperationsController;
use App\Http\Controllers\V1\User\AgentSiteContextController;
use App\Http\Controllers\V1\User\CouponController;
use App\Http\Controllers\V1\User\GiftCardController;
use App\Http\Controllers\V1\User\InviteController;
use App\Http\Controllers\V1\User\KnowledgeController;
use App\Http\Controllers\V1\User\NoticeController;
use App\Http\Controllers\V1\User\OrderController;
use App\Http\Controllers\V1\User\PlanController;
use App\Http\Controllers\V1\User\ServerController;
use App\Http\Controllers\V1\User\StatController;
use App\Http\Controllers\V1\User\TelegramController;
use App\Http\Controllers\V1\User\TicketController;
use App\Http\Controllers\V1\User\UserController;
use Illuminate\Contracts\Routing\Registrar;

class UserRoute
{
    public function map(Registrar $router)
    {
        $router->group([
            'prefix' => 'user',
            'middleware' => 'user'
        ], function ($router) {
            // User
            $router->get('/resetSecurity', [UserController::class, 'resetSecurity']);
            $router->get('/info', [UserController::class, 'info']);
            $router->post('/changePassword', [UserController::class, 'changePassword']);
            $router->post('/update', [UserController::class, 'update']);
            $router->get('/getSubscribe', [UserController::class, 'getSubscribe']);
            $router->get('/getOnlineDevices', [UserController::class, 'getOnlineDevices']);
            $router->get('/getStat', [UserController::class, 'getStat']);
            $router->get('/checkLogin', [UserController::class, 'checkLogin']);
            $router->post('/transfer', [UserController::class, 'transfer']);
            $router->post('/getQuickLoginUrl', [UserController::class, 'getQuickLoginUrl']);
            $router->get('/getActiveSession', [UserController::class, 'getActiveSession']);
            $router->post('/removeActiveSession', [UserController::class, 'removeActiveSession']);
            // Agent Center
            $router->get('/agent/overview', [AgentController::class, 'overview']);
            $router->post('/agent/unlock', [AgentController::class, 'unlock']);
            $router->get('/agent/users', [AgentController::class, 'users']);
            $router->post('/agent/users', [AgentController::class, 'createUser']);
            $router->post('/agent/users/{id}/delete', [AgentController::class, 'deleteUser']);
            $router->get('/agent/users/{id}/subscribe-link', [AgentController::class, 'subscribeLink']);
            $router->post('/agent/users/{id}/reset-subscription', [AgentController::class, 'resetSubscription']);
            $router->post('/agent/users/{id}/assign-plan/preview', [AgentController::class, 'assignPlanPreview']);
            $router->post('/agent/users/{id}/assign-plan', [AgentController::class, 'assignPlan']);
            $router->post('/agent/users/{id}/reset-traffic/preview', [AgentController::class, 'resetTrafficPreview']);
            $router->post('/agent/users/{id}/reset-traffic', [AgentController::class, 'resetTraffic']);
            $router->post('/agent/users/{id}/bonus-days/preview', [AgentController::class, 'bonusDaysPreview']);
            $router->post('/agent/users/{id}/bonus-days', [AgentController::class, 'grantBonusDays']);
            $router->get('/agent/ledger', [AgentController::class, 'ledger']);
            $router->get('/agent/domains', [AgentCommerceController::class, 'domains']);
            $router->post('/agent/domains', [AgentCommerceController::class, 'saveDomain']);
            $router->post('/agent/domains/{id}/verify', [AgentCommerceController::class, 'verifyDomain']);
            $router->post('/agent/domains/{id}/delete', [AgentCommerceController::class, 'deleteDomain']);
            $router->get('/agent/payment-methods/available', [AgentCommerceController::class, 'availablePaymentMethods']);
            $router->get('/agent/payments', [AgentCommerceController::class, 'payments']);
            $router->post('/agent/payments/form', [AgentCommerceController::class, 'paymentForm']);
            $router->post('/agent/payments', [AgentCommerceController::class, 'savePayment']);
            $router->post('/agent/payments/{id}', [AgentCommerceController::class, 'savePayment']);
            $router->post('/agent/payments/{id}/toggle', [AgentCommerceController::class, 'togglePayment']);
            $router->post('/agent/payments/{id}/delete', [AgentCommerceController::class, 'deletePayment']);
            $router->get('/agent/prices', [AgentCommerceController::class, 'prices']);
            $router->post('/agent/prices', [AgentCommerceController::class, 'savePrices']);
            $router->get('/agent/site-settings', [AgentCommerceController::class, 'siteSettings']);
            $router->post('/agent/site-settings', [AgentCommerceController::class, 'saveSiteSetting']);
            $router->get('/agent/commerce/summary', [AgentCommerceController::class, 'commerceSummary']);
            $router->get('/agent/commerce/diagnostics', [AgentCommerceController::class, 'diagnostics']);
            $router->get('/agent/operations/summary', [AgentOperationsController::class, 'summary']);
            $router->get('/agent/operations/orders', [AgentOperationsController::class, 'orders']);
            $router->get('/agent/operations/orders/{tradeNo}', [AgentOperationsController::class, 'order']);
            $router->get('/agent/site-context', [AgentSiteContextController::class, 'show']);
            // Order
            $router->post('/order/save', [OrderController::class, 'save']);
            $router->post('/order/recharge', [OrderController::class, 'recharge']);
            $router->post('/order/upgrade/preview', [OrderController::class, 'previewUpgrade']);
            $router->post('/order/upgrade/confirm', [OrderController::class, 'confirmUpgrade']);
            $router->post('/order/checkout', [OrderController::class, 'checkout']);
            $router->get('/order/check', [OrderController::class, 'check']);
            $router->get('/order/detail', [OrderController::class, 'detail']);
            $router->get('/order/fetch', [OrderController::class, 'fetch']);
            $router->get('/order/getPaymentMethod', [OrderController::class, 'getPaymentMethod']);
            $router->post('/order/cancel', [OrderController::class, 'cancel']);
            // Plan
            $router->get('/plan/fetch', [PlanController::class, 'fetch']);
            // Invite
            $router->get('/invite/save', [InviteController::class, 'save']);
            $router->get('/invite/fetch', [InviteController::class, 'fetch']);
            $router->get('/invite/details', [InviteController::class, 'details']);
            // Notice
            $router->get('/notice/fetch', [NoticeController::class, 'fetch']);
            // Ticket
            $router->post('/ticket/reply', [TicketController::class, 'reply']);
            $router->post('/ticket/escalate', [TicketController::class, 'escalate']);
            $router->post('/ticket/close', [TicketController::class, 'close']);
            $router->post('/ticket/save', [TicketController::class, 'save']);
            $router->get('/ticket/fetch', [TicketController::class, 'fetch']);
            $router->get('/ticket/attachment/{id}', [TicketController::class, 'attachment']);
            $router->post('/ticket/withdraw', [TicketController::class, 'withdraw']);
            // Server
            $router->get('/server/fetch', [ServerController::class, 'fetch']);
            // Coupon
            $router->post('/coupon/check', [CouponController::class, 'check']);
            // Gift Card
            $router->post('/gift-card/check', [GiftCardController::class, 'check']);
            $router->post('/gift-card/redeem', [GiftCardController::class, 'redeem']);
            $router->get('/gift-card/history', [GiftCardController::class, 'history']);
            $router->get('/gift-card/detail', [GiftCardController::class, 'detail']);
            $router->get('/gift-card/types', [GiftCardController::class, 'types']);
            // Telegram
            $router->get('/telegram/getBotInfo', [TelegramController::class, 'getBotInfo']);
            $router->post('/telegram/unbind', [TelegramController::class, 'unbind']);
            $router->get('/unbindTelegram', [TelegramController::class, 'unbind']); // compatibility
            // Comm
            $router->get('/comm/config', [CommController::class, 'config']);
            $router->Post('/comm/getStripePublicKey', [CommController::class, 'getStripePublicKey']);
            // Knowledge
            $router->get('/knowledge/fetch', [KnowledgeController::class, 'fetch']);
            $router->get('/knowledge/getCategory', [KnowledgeController::class, 'getCategory']);
            // Stat
            $router->get('/stat/getTrafficLog', [StatController::class, 'getTrafficLog']);
            $router->get('/stat/getTrafficNodeLog', [StatController::class, 'getTrafficNodeLog']);
        });
    }
}
