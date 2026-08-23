<?php
namespace App\Http\Routes\V2;

use App\Http\Controllers\V2\Admin\ConfigController;
use App\Http\Controllers\V2\Admin\PlanController;
use App\Http\Controllers\V2\Admin\SiteController;
use App\Http\Controllers\V2\Admin\SiteNavigationController;
use App\Http\Controllers\V2\Admin\Server\GroupController;
use App\Http\Controllers\V2\Admin\Server\MachineController;
use App\Http\Controllers\V2\Admin\Server\MachineReleaseManagementController;
use App\Http\Controllers\V2\Admin\Server\RouteController;
use App\Http\Controllers\V2\Admin\Server\ManageController;
use App\Http\Controllers\V2\Admin\OrderController;
use App\Http\Controllers\V2\Admin\UserController;
use App\Http\Controllers\V2\Admin\StatController;
use App\Http\Controllers\V2\Admin\NoticeController;
use App\Http\Controllers\V2\Admin\TicketController;
use App\Http\Controllers\V2\Admin\CouponController;
use App\Http\Controllers\V2\Admin\GiftCardController;
use App\Http\Controllers\V2\Admin\KnowledgeController;
use App\Http\Controllers\V2\Admin\PaymentController;
use App\Http\Controllers\V2\Admin\BackupController;
use App\Http\Controllers\V2\Admin\AgentCommerceController;
use App\Http\Controllers\V2\Admin\AgentOperationsController;
use App\Http\Controllers\V2\Admin\SystemController;
use App\Http\Controllers\V2\Admin\ThemeController;
use App\Http\Controllers\V2\Admin\TrafficResetController;
use App\Http\Controllers\V2\Admin\StaffSitesController;
use App\Http\Controllers\V2\Admin\OrderUpgradeQuoteController;
use App\Http\Controllers\V2\Admin\OperationTaskController;
use App\Http\Controllers\V2\Admin\SubscriptionControlController;
use App\Http\Controllers\V2\Admin\MarketingController;
use App\Http\Controllers\V2\Admin\SpamRegistrationController;
use App\Http\Controllers\V2\Admin\DomainHealthController;
use App\Http\Controllers\V2\Admin\AiDiagnosticController;
use App\Http\Controllers\V2\Admin\AiCenterController;
use App\Http\Controllers\V2\Admin\InviteMonitorController;
use Illuminate\Contracts\Routing\Registrar;

class AdminRoute
{
    public function map(Registrar $router)
    {
        $router->get('/ticket/attachment/{id}/preview', [TicketController::class, 'preview'])
            ->whereNumber('id')
            ->name('api.v2.ticket.attachment.preview');

        $router->group([
            'prefix' => admin_setting('secure_path', admin_setting('frontend_admin_path', hash('crc32b', config('app.key')))),
            'middleware' => ['admin', 'log'],
        ], function ($router) {
            // Config
            $router->group([
                'prefix' => 'config'
            ], function ($router) {
                $router->get('/fetch', [ConfigController::class, 'fetch']);
                $router->post('/save', [ConfigController::class, 'save']);
                $router->get('/realtimeStatus', [ConfigController::class, 'realtimeStatus']);
                $router->get('/getEmailTemplate', [ConfigController::class, 'getEmailTemplate']);
                $router->get('/getThemeTemplate', [ConfigController::class, 'getThemeTemplate']);
                $router->post('/setTelegramWebhook', [ConfigController::class, 'setTelegramWebhook']);
                $router->post('/testSendMail', [ConfigController::class, 'testSendMail']);
            });

            // Plan
            $router->group([
                'prefix' => 'plan'
            ], function ($router) {
                $router->get('/fetch', [PlanController::class, 'fetch']);
                $router->get('/fetchStats', [PlanController::class, 'fetchStats']);
                $router->post('/save', [PlanController::class, 'save']);
                $router->post('/applyUsers', [PlanController::class, 'applyUsers']);
                $router->post('/drop', [PlanController::class, 'drop']);
                $router->post('/update', [PlanController::class, 'update']);
                $router->post('/sort', [PlanController::class, 'sort']);
            });

            // Site
            $router->group([
                'prefix' => 'site'
            ], function ($router) {
                $router->get('/fetch', [SiteController::class, 'fetch']);
                $router->post('/save', [SiteController::class, 'save']);
                $router->get('/health', [SiteController::class, 'health']);
                $router->get('/commerce', [SiteController::class, 'commerce']);
                $router->post('/commerce/save', [SiteController::class, 'saveCommerce']);
                $router->get('/navigation/fetch', [SiteNavigationController::class, 'fetch']);
                $router->post('/navigation/save', [SiteNavigationController::class, 'save']);
            });

            // Unified AI operations overview. Existing module routes remain compatible.
            $router->group([
                'prefix' => 'ai-center'
            ], function ($router) {
                $router->get('/overview', [AiCenterController::class, 'overview']);
            });

            // Read-only local AI diagnostics
            $router->group([
                'prefix' => 'ai-diagnostics'
            ], function ($router) {
                $router->get('/overview', [AiDiagnosticController::class, 'overview']);
                $router->get('/history', [AiDiagnosticController::class, 'history']);
                $router->get('/detail', [AiDiagnosticController::class, 'detail']);
                $router->post('/settings', [AiDiagnosticController::class, 'saveSettings']);
                $router->post('/run', [AiDiagnosticController::class, 'run']);
                $router->post('/disposition', [AiDiagnosticController::class, 'saveDisposition']);
                $router->post('/incident', [AiDiagnosticController::class, 'updateIncident']);
            });

            // Domain health monitoring
            $router->group([
                'prefix' => 'domain-monitor'
            ], function ($router) {
                $router->get('/overview', [DomainHealthController::class, 'overview']);
                $router->post('/settings', [DomainHealthController::class, 'saveSettings']);
                $router->post('/check', [DomainHealthController::class, 'check']);
                $router->post('/check-all', [DomainHealthController::class, 'checkAll']);
            });

            // Server
            $router->group([
                'prefix' => 'server/group'
            ], function ($router) {
                $router->get('/fetch', [GroupController::class, 'fetch']);
                $router->post('/save', [GroupController::class, 'save']);
                $router->post('/drop', [GroupController::class, 'drop']);
            });
            $router->group([
                'prefix' => 'server/route'
            ], function ($router) {
                $router->get('/fetch', [RouteController::class, 'fetch']);
                $router->get('/managed-source-ip', [RouteController::class, 'managedSourceIp']);
                $router->post('/managed-source-ip', [RouteController::class, 'saveManagedSourceIp']);
                $router->post('/save', [RouteController::class, 'save']);
                $router->post('/drop', [RouteController::class, 'drop']);
            });
            $router->group([
                'prefix' => 'server/machine'
            ], function ($router) {
                $router->get('/fetch', [MachineController::class, 'fetch']);
                $router->post('/save', [MachineController::class, 'save']);
                $router->post('/toggleActive', [MachineController::class, 'toggleActive']);
                $router->post('/drop', [MachineController::class, 'drop']);
                $router->post('/resetToken', [MachineController::class, 'resetToken']);
                $router->get('/getToken', [MachineController::class, 'getToken']);
                $router->get('/nodes', [MachineController::class, 'nodes']);
                $router->post('/bindNodes', [MachineController::class, 'bindNodes']);
                $router->post('/batchBindNodes', [MachineController::class, 'batchBindNodes']);
                $router->get('/history', [MachineController::class, 'history']);
                $router->get('/installCommand', [MachineController::class, 'installCommand']);
                $router->get('/versionInfo', [MachineController::class, 'versionInfo']);
                $router->post('/upgrade', [MachineController::class, 'upgrade']);
                $router->get('/release/fetch', [MachineReleaseManagementController::class, 'fetch']);
                $router->post('/release/upload', [MachineReleaseManagementController::class, 'upload']);
                $router->post('/release/setDefault', [MachineReleaseManagementController::class, 'setDefault']);
                $router->post('/release/drop', [MachineReleaseManagementController::class, 'drop']);
            });
            $router->group([
                'prefix' => 'server/manage'
            ], function ($router) {
                $router->get('/getNodes', [ManageController::class, 'getNodes']);
                $router->get('/getOptions', [ManageController::class, 'getOptions']);
                $router->get('/getCapabilities', [ManageController::class, 'getCapabilities']);
                $router->post('/sort', [ManageController::class, 'sort']);
            });

            // 节点更新接口
            $router->group([
                'prefix' => 'server/manage'
            ], function ($router) {
                $router->post('/update', [ManageController::class, 'update']);
                $router->post('/save', [ManageController::class, 'save']);
                $router->post('/drop', [ManageController::class, 'drop']);
                $router->post('/copy', [ManageController::class, 'copy']);
                $router->post('/sort', [ManageController::class, 'sort']);
            });

            // Order
            $router->group([
                'prefix' => 'order'
            ], function ($router) {
                $router->any('/fetch', [OrderController::class, 'fetch']);
                $router->post('/update', [OrderController::class, 'update']);
                $router->post('/assign', [OrderController::class, 'assign']);
                $router->post('/paid', [OrderController::class, 'paid']);
                $router->post('/cancel', [OrderController::class, 'cancel']);
                $router->post('/refund-dispose', [OrderController::class, 'refundDispose']);
                $router->post('/release-agent-hold', [OrderController::class, 'releaseAgentHold']);
                $router->post('/detail', [OrderController::class, 'detail']);
                $router->any('/upgrade-quote/fetch', [OrderUpgradeQuoteController::class, 'fetch']);
                $router->post('/upgrade-quote/detail', [OrderUpgradeQuoteController::class, 'detail']);
            });

            // User
            $router->group([
                'prefix' => 'user'
            ], function ($router) {
                $router->any('/fetch', [UserController::class, 'fetch']);
                $router->get('/invite-monitor/overview', [InviteMonitorController::class, 'overview']);
                $router->post('/update', [UserController::class, 'update']);
                $router->get('/getUserInfoById', [UserController::class, 'getUserInfoById']);
                $router->get('/getOnlineDevices', [UserController::class, 'getOnlineDevices']);
                $router->post('/generate', [UserController::class, 'generate']);
                $router->post('/dumpCSV', [UserController::class, 'dumpCSV']);
                $router->post('/sendMail', [UserController::class, 'sendMail']);
                $router->post('/ban', [UserController::class, 'ban']);
                $router->post('/resetSecret', [UserController::class, 'resetSecret']);
                $router->post('/setInviteUser', [UserController::class, 'setInviteUser']);
                $router->post('/destroy', [UserController::class, 'destroy']);
            });

            // Stat
            $router->group([
                'prefix' => 'stat'
            ], function ($router) {
                $router->get('/getOverride', [StatController::class, 'getOverride']);
                $router->get('/getStats', [StatController::class, 'getStats']);
                $router->get('/getServerLastRank', [StatController::class, 'getServerLastRank']);
                $router->get('/getServerYesterdayRank', [StatController::class, 'getServerYesterdayRank']);
                $router->get('/getOrder', [StatController::class, 'getOrder']);
                $router->any('/getStatUser', [StatController::class, 'getStatUser']);
                $router->get('/getStatUserNodeLog', [StatController::class, 'getStatUserNodeLog']);
                $router->get('/getRanking', [StatController::class, 'getRanking']);
                $router->get('/getStatRecord', [StatController::class, 'getStatRecord']);
                $router->get('/getTrafficRank', [StatController::class, 'getTrafficRank']);
                $router->get('/getInviteRank', [StatController::class, 'getInviteRank']);
            });

            // Notice
            $router->group([
                'prefix' => 'notice'
            ], function ($router) {
                $router->get('/fetch', [NoticeController::class, 'fetch']);
                $router->post('/save', [NoticeController::class, 'save']);
                $router->post('/update', [NoticeController::class, 'update']);
                $router->post('/drop', [NoticeController::class, 'drop']);
                $router->post('/show', [NoticeController::class, 'show']);
                $router->post('/sort', [NoticeController::class, 'sort']);
            });

            // Ticket
            $router->group([
                'prefix' => 'ticket'
            ], function ($router) {
                $router->any('/fetch', [TicketController::class, 'fetch']);
                $router->post('/reply', [TicketController::class, 'reply']);
                $router->post('/close', [TicketController::class, 'close']);
                $router->get('/aiCapabilities', [TicketController::class, 'aiCapabilities']);
                $router->post('/aiTestConnection', [TicketController::class, 'aiTestConnection']);
                $router->post('/aiSuggest', [TicketController::class, 'aiSuggest']);
                $router->post('/aiSuggestionFeedback', [TicketController::class, 'aiSuggestionFeedback']);
                $router->get('/aiStats', [TicketController::class, 'aiStats']);
                $router->get('/autoReplyStats', [TicketController::class, 'autoReplyStats']);
                $router->get('/attachment/{id}', [TicketController::class, 'attachment']);
            });

            // Coupon
            $router->group([
                'prefix' => 'coupon'
            ], function ($router) {
                $router->any('/fetch', [CouponController::class, 'fetch']);
                $router->post('/generate', [CouponController::class, 'generate']);
                $router->post('/drop', [CouponController::class, 'drop']);
                $router->post('/show', [CouponController::class, 'show']);
                $router->post('/update', [CouponController::class, 'update']);
            });

            // Gift Card
            $router->group([
                'prefix' => 'gift-card'
            ], function ($router) {
                // Template management
                $router->any('/templates', [GiftCardController::class, 'templates']);
                $router->post('/create-template', [GiftCardController::class, 'createTemplate']);
                $router->post('/update-template', [GiftCardController::class, 'updateTemplate']);
                $router->post('/delete-template', [GiftCardController::class, 'deleteTemplate']);

                // Code management
                $router->post('/generate-codes', [GiftCardController::class, 'generateCodes']);
                $router->any('/codes', [GiftCardController::class, 'codes']);
                $router->post('/toggle-code', [GiftCardController::class, 'toggleCode']);
                $router->get('/export-codes', [GiftCardController::class, 'exportCodes']);
                $router->post('/update-code', [GiftCardController::class, 'updateCode']);
                $router->post('/delete-code', [GiftCardController::class, 'deleteCode']);

                // Usage records
                $router->any('/usages', [GiftCardController::class, 'usages']);

                // Statistics
                $router->any('/statistics', [GiftCardController::class, 'statistics']);
                $router->get('/types', [GiftCardController::class, 'types']);
            });

            // Knowledge
            $router->group([
                'prefix' => 'knowledge'
            ], function ($router) {
                $router->get('/fetch', [KnowledgeController::class, 'fetch']);
                $router->get('/getCategory', [KnowledgeController::class, 'getCategory']);
                $router->get('/scopeOptions', [KnowledgeController::class, 'scopeOptions']);
                $router->get('/official/status', [KnowledgeController::class, 'officialStatus']);
                $router->post('/official/sync', [KnowledgeController::class, 'officialSync']);
                $router->post('/save', [KnowledgeController::class, 'save']);
                $router->post('/show', [KnowledgeController::class, 'show']);
                $router->post('/drop', [KnowledgeController::class, 'drop']);
                $router->post('/sort', [KnowledgeController::class, 'sort']);
            });

            // Payment  
            $router->group([
                'prefix' => 'payment'
            ], function ($router) {
                $router->get('/fetch', [PaymentController::class, 'fetch']);
                $router->get('/getPaymentMethods', [PaymentController::class, 'getPaymentMethods']);
                $router->post('/getPaymentForm', [PaymentController::class, 'getPaymentForm']);
                $router->post('/save', [PaymentController::class, 'save']);
                $router->post('/drop', [PaymentController::class, 'drop']);
                $router->post('/show', [PaymentController::class, 'show']);
                $router->post('/sort', [PaymentController::class, 'sort']);
            });

            // Agent Commerce
            $router->group([
                'prefix' => 'agent-commerce'
            ], function ($router) {
                $router->get('/domains', [AgentCommerceController::class, 'domains']);
                $router->post('/domains', [AgentCommerceController::class, 'saveDomain']);
                $router->post('/domains/{id}/enable', [AgentCommerceController::class, 'enableDomain']);
                $router->post('/domains/{id}/disable', [AgentCommerceController::class, 'disableDomain']);
                $router->post('/domains/{id}/delete', [AgentCommerceController::class, 'deleteDomain']);
                $router->get('/payments', [AgentCommerceController::class, 'payments']);
                $router->get('/holds', [AgentCommerceController::class, 'holds']);
                $router->get('/orders', [AgentCommerceController::class, 'orders']);
            });

            // Agent Operations
            $router->group([
                'prefix' => 'agent-operations'
            ], function ($router) {
                $router->get('/summary', [AgentOperationsController::class, 'summary']);
                $router->get('/reconciliation', [AgentOperationsController::class, 'reconciliation']);
                $router->get('/agents', [AgentOperationsController::class, 'agents']);
                $router->get('/agents/{agentUserId}', [AgentOperationsController::class, 'agent']);
                $router->get('/agents/{agentUserId}/orders', [AgentOperationsController::class, 'agentOrders']);
                $router->post('/agents/{agentUserId}/cost-site', [AgentOperationsController::class, 'updateAgentCostSite']);
                $router->post('/payments/{paymentId}/disable', [AgentOperationsController::class, 'disablePayment']);
                $router->post('/payments/{paymentId}/enable', [AgentOperationsController::class, 'enablePayment']);
                $router->post('/domains/{domainId}/disable', [AgentOperationsController::class, 'disableDomain']);
            });

            // System
            $router->group([
                'prefix' => 'system'
            ], function ($router) {
                $router->get('/operation-tasks', [OperationTaskController::class, 'index']);
                $router->post('/operation-tasks', [OperationTaskController::class, 'store']);
                $router->get('/operation-tasks/{taskId}', [OperationTaskController::class, 'show']);
                $router->post('/operation-tasks/{taskId}/cancel', [OperationTaskController::class, 'cancel']);
                $router->post('/operation-tasks/{taskId}/retry', [OperationTaskController::class, 'retry']);
                $router->post('/operation-tasks/{taskId}/dismiss', [OperationTaskController::class, 'dismiss']);
                $router->get('/operation-tasks/{taskId}/failures.csv', [OperationTaskController::class, 'exportFailures']);
                $router->get('/getSystemStatus', [SystemController::class, 'getSystemStatus']);
                $router->get('/getHealthDiagnostics', [SystemController::class, 'getHealthDiagnostics']);
                $router->get('/getQueueStats', [SystemController::class, 'getQueueStats']);
                $router->get('/getQueueWorkload', [SystemController::class, 'getQueueWorkload']);
                $router->get('/getQueueMasters', '\\Laravel\\Horizon\\Http\\Controllers\\MasterSupervisorController@index');
                $router->get('/getSystemLog', [SystemController::class, 'getSystemLog']);
                $router->any('/getAuditLog', [SystemController::class, 'getAuditLog']);
                $router->get('/getHorizonFailedJobs', [SystemController::class, 'getHorizonFailedJobs']);
                $router->post('/clearSystemLog', [SystemController::class, 'clearSystemLog']);
                $router->get('/getLogClearStats', [SystemController::class, 'getLogClearStats']);
                $router->get('/backup/overview', [BackupController::class, 'overview']);
                $router->get('/backup/settings', [BackupController::class, 'settings']);
                $router->post('/backup/settings', [BackupController::class, 'updateSettings']);
                $router->post('/backup/remote-storage', [BackupController::class, 'updateRemoteStorage']);
                $router->post('/backup/remote-storage/test', [BackupController::class, 'testRemoteStorage']);
                $router->any('/backup/fetch', [BackupController::class, 'fetch']);
                $router->post('/backup/create', [BackupController::class, 'create']);
                $router->get('/backup/download/{id}', [BackupController::class, 'download'])->whereNumber('id');
                $router->post('/backup/retrieve', [BackupController::class, 'retrieve']);
                $router->post('/backup/verify', [BackupController::class, 'verify']);
                $router->post('/backup/restore-preflight', [BackupController::class, 'restorePreflight']);
                $router->post('/backup/restore-drill/check', [BackupController::class, 'restoreDrillCheck']);
                $router->post('/backup/restore-drill', [BackupController::class, 'restoreDrill']);
                $router->post('/backup/drop', [BackupController::class, 'drop']);
                $router->post('/backup/cleanup', [BackupController::class, 'cleanup']);
            });

            // Risk Center (admin-only)
            $router->group([
                'prefix' => 'risk'
            ], function ($router) {
                $router->get('/subscription-control/overview', [SubscriptionControlController::class, 'overview']);
                $router->get('/subscription-control/source-ip-blocks', [SubscriptionControlController::class, 'sourceIpBlocks']);
                $router->post('/subscription-control/source-ip-blocks/unblock', [SubscriptionControlController::class, 'unblockSourceIp']);
                $router->get('/subscription-control/ai-advisor', [SubscriptionControlController::class, 'aiAdvisor']);
                $router->post('/subscription-control/high-risk-users/{userId}/review', [SubscriptionControlController::class, 'reviewHighRiskCase']);
                $router->post('/subscription-control/ai-advisor/analyze', [SubscriptionControlController::class, 'analyzeWithAi']);
                $router->post('/subscription-control/ai-advisor/{reviewId}/apply', [SubscriptionControlController::class, 'applyAiSuggestions']);
                $router->post('/subscription-control/ai-advisor/{reviewId}/rollback', [SubscriptionControlController::class, 'rollbackAiSuggestions']);
            });

            $router->group([
                'prefix' => 'marketing'
            ], function ($router) {
                $router->get('/overview', [MarketingController::class, 'overview']);
                $router->get('/rules', [MarketingController::class, 'rules']);
                $router->post('/rule/update', [MarketingController::class, 'updateRule']);
                $router->get('/rule/audience-preview', [MarketingController::class, 'audiencePreview']);
                $router->get('/templates', [MarketingController::class, 'templates']);
                $router->post('/template/save', [MarketingController::class, 'saveTemplate']);
                $router->post('/template/test', [MarketingController::class, 'testTemplate']);
                $router->any('/tasks', [MarketingController::class, 'tasks']);
                $router->post('/tasks/cancel', [MarketingController::class, 'cancelTasks']);
                $router->any('/logs', [MarketingController::class, 'logs']);
                $router->post('/log/note', [MarketingController::class, 'saveLogNote']);
            });

            $router->group([
                'prefix' => 'spam-registration'
            ], function ($router) {
                $router->any('/candidates', [SpamRegistrationController::class, 'candidates']);
                $router->post('/detail', [SpamRegistrationController::class, 'detail']);
                $router->post('/preserve', [SpamRegistrationController::class, 'preserve']);
                $router->post('/restore', [SpamRegistrationController::class, 'restore']);
                $router->post('/freeze', [SpamRegistrationController::class, 'freeze']);
                $router->post('/soft-delete', [SpamRegistrationController::class, 'softDelete']);
                $router->post('/note', [SpamRegistrationController::class, 'note']);
            });

            // Update
            // $router->group([
            //     'prefix' => 'update'
            // ], function ($router) {
            //     $router->get('/check', [UpdateController::class, 'checkUpdate']);
            //     $router->post('/execute', [UpdateController::class, 'executeUpdate']);
            // });

            // Theme
            $router->group([
                'prefix' => 'theme'
            ], function ($router) {
                $router->get('/getThemes', [ThemeController::class, 'getThemes']);
                $router->post('/upload', [ThemeController::class, 'upload']);
                $router->post('/delete', [ThemeController::class, 'delete']);
                $router->post('/saveThemeConfig', [ThemeController::class, 'saveThemeConfig']);
                $router->post('/getThemeConfig', [ThemeController::class, 'getThemeConfig']);
            });

            // Plugin
            $router->group([
                'prefix' => 'plugin'
            ], function ($router) {
                $router->get('/types', [\App\Http\Controllers\V2\Admin\PluginController::class, 'types']);
                $router->get('/getPlugins', [\App\Http\Controllers\V2\Admin\PluginController::class, 'index']);
                $router->post('/upload', [\App\Http\Controllers\V2\Admin\PluginController::class, 'upload']);
                $router->post('/delete', [\App\Http\Controllers\V2\Admin\PluginController::class, 'delete']);
                $router->post('install', [\App\Http\Controllers\V2\Admin\PluginController::class, 'install']);
                $router->post('uninstall', [\App\Http\Controllers\V2\Admin\PluginController::class, 'uninstall']);
                $router->post('enable', [\App\Http\Controllers\V2\Admin\PluginController::class, 'enable']);
                $router->post('disable', [\App\Http\Controllers\V2\Admin\PluginController::class, 'disable']);
                $router->get('config', [\App\Http\Controllers\V2\Admin\PluginController::class, 'getConfig']);
                $router->post('config', [\App\Http\Controllers\V2\Admin\PluginController::class, 'updateConfig']);
                $router->post('upgrade', [\App\Http\Controllers\V2\Admin\PluginController::class, 'upgrade']);
            });

            // 流量重置管理
            $router->group([
                'prefix' => 'traffic-reset'
            ], function ($router) {
                $router->get('logs', [TrafficResetController::class, 'logs']);
                $router->get('stats', [TrafficResetController::class, 'stats']);
                $router->get('user/{userId}/history', [TrafficResetController::class, 'userHistory']);
                $router->post('reset-user', [TrafficResetController::class, 'resetUser']);
            });

            // Staff desk (multi-site) config
            $router->group([
                'prefix' => 'staff-sites'
            ], function ($router) {
                $router->get('/fetch', [StaffSitesController::class, 'fetch']);
                $router->post('/save', [StaffSitesController::class, 'save']);
            });
        });

    }
}
