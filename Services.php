<?php
// routes/api.php — WAHub Complete API Routes

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\{
    AuthController,
    WebhookController,
    ContactController,
    TemplateController,
    CampaignController,
    ConversationController,
    MessageController,
    AutomationController,
    WooCommerceController,
    TaskController,
    AnalyticsController,
    TeamController,
    SettingsController,
    NotificationController,
    PublicApiController,
    SuperAdmin\TenantController,
    SuperAdmin\PlanController,
};

// ----------------------------------------------------------------
// PUBLIC — Auth
// ----------------------------------------------------------------
Route::prefix('auth')->group(function () {
    Route::post('login',              [AuthController::class, 'login']);
    Route::post('register',           [AuthController::class, 'register']);         // tenant signup
    Route::post('forgot-password',    [AuthController::class, 'forgotPassword']);
    Route::post('reset-password',     [AuthController::class, 'resetPassword']);
    Route::post('2fa/verify',         [AuthController::class, 'verifyTwoFactor']);
});

// ----------------------------------------------------------------
// WEBHOOKS — per tenant (unauthenticated but signature-verified)
// ----------------------------------------------------------------
Route::prefix('webhook/{tenantSlug}')->group(function () {
    Route::get( 'whatsapp',           [WebhookController::class, 'verify']);
    Route::post('whatsapp',           [WebhookController::class, 'handle']);
    Route::post('woocommerce',        [WooCommerceController::class, 'webhook']);
});

// ----------------------------------------------------------------
// AUTHENTICATED — Sanctum
// ----------------------------------------------------------------
Route::middleware(['auth:sanctum', 'tenant.active', 'audit.log'])->group(function () {

    // Auth management
    Route::prefix('auth')->group(function () {
        Route::post('logout',         [AuthController::class, 'logout']);
        Route::get( 'me',             [AuthController::class, 'me']);
        Route::put( 'profile',        [AuthController::class, 'updateProfile']);
        Route::post('2fa/enable',     [AuthController::class, 'enableTwoFactor']);
        Route::delete('2fa/disable',  [AuthController::class, 'disableTwoFactor']);
        Route::get( 'login-history',  [AuthController::class, 'loginHistory']);
        Route::get( 'sessions',       [AuthController::class, 'sessions']);
        Route::delete('sessions/{id}',[AuthController::class, 'revokeSession']);
    });

    // WhatsApp Settings
    Route::prefix('whatsapp')->group(function () {
        Route::get(   '/',            [\App\Http\Controllers\Api\WhatsAppSettingsController::class, 'index']);
        Route::post(  '/',            [\App\Http\Controllers\Api\WhatsAppSettingsController::class, 'store']);
        Route::post(  'connect',      [\App\Http\Controllers\Api\WhatsAppSettingsController::class, 'connect']);
        Route::post(  'disconnect',   [\App\Http\Controllers\Api\WhatsAppSettingsController::class, 'disconnect']);
        Route::get(   'health',       [\App\Http\Controllers\Api\WhatsAppSettingsController::class, 'health']);
        Route::post(  'test-message', [\App\Http\Controllers\Api\WhatsAppSettingsController::class, 'testMessage']);
        Route::get(   'webhook-logs', [\App\Http\Controllers\Api\WhatsAppSettingsController::class, 'webhookLogs']);
        Route::post(  'validate-token',[\App\Http\Controllers\Api\WhatsAppSettingsController::class,'validateToken']);
    });

    // Contacts & CRM
    Route::prefix('contacts')->group(function () {
        Route::get(   '/',                [ContactController::class, 'index']);
        Route::post(  '/',                [ContactController::class, 'store']);
        Route::get(   '{contact}',        [ContactController::class, 'show']);
        Route::put(   '{contact}',        [ContactController::class, 'update']);
        Route::delete('{contact}',        [ContactController::class, 'destroy']);
        Route::post(  'bulk-import',      [ContactController::class, 'bulkImport']);
        Route::get(   'export',           [ContactController::class, 'export']);
        Route::post(  '{contact}/merge',  [ContactController::class, 'merge']);
        Route::get(   '{contact}/timeline',[ContactController::class, 'timeline']);
        Route::post(  '{contact}/notes',  [ContactController::class, 'addNote']);
    });

    // Tags
    Route::apiResource('tags', \App\Http\Controllers\Api\TagController::class);

    // Segments
    Route::apiResource('segments', \App\Http\Controllers\Api\SegmentController::class);

    // Templates
    Route::prefix('templates')->group(function () {
        Route::get(   '/',                  [TemplateController::class, 'index']);
        Route::post(  '/',                  [TemplateController::class, 'store']);
        Route::get(   '{template}',         [TemplateController::class, 'show']);
        Route::put(   '{template}',         [TemplateController::class, 'update']);
        Route::delete('{template}',         [TemplateController::class, 'destroy']);
        Route::post(  '{template}/submit',  [TemplateController::class, 'submit']);
        Route::post(  '{template}/sync',    [TemplateController::class, 'syncStatus']);
        Route::post(  '{template}/clone',   [TemplateController::class, 'clone']);
        Route::get(   '{template}/versions',[TemplateController::class, 'versions']);
        Route::get(   'sync-all',           [TemplateController::class, 'syncAll']);
    });

    // Campaigns
    Route::prefix('campaigns')->group(function () {
        Route::get(   '/',                    [CampaignController::class, 'index']);
        Route::post(  '/',                    [CampaignController::class, 'store']);
        Route::get(   '{campaign}',           [CampaignController::class, 'show']);
        Route::put(   '{campaign}',           [CampaignController::class, 'update']);
        Route::delete('{campaign}',           [CampaignController::class, 'destroy']);
        Route::post(  '{campaign}/pause',     [CampaignController::class, 'pause']);
        Route::post(  '{campaign}/resume',    [CampaignController::class, 'resume']);
        Route::post(  '{campaign}/cancel',    [CampaignController::class, 'cancel']);
        Route::get(   '{campaign}/analytics', [CampaignController::class, 'analytics']);
        Route::get(   '{campaign}/recipients',[CampaignController::class, 'recipients']);
    });

    // Conversations (Inbox)
    Route::prefix('conversations')->group(function () {
        Route::get(   '/',                       [ConversationController::class, 'index']);
        Route::get(   '{conversation}',          [ConversationController::class, 'show']);
        Route::get(   '{conversation}/messages', [ConversationController::class, 'messages']);
        Route::post(  '{conversation}/messages', [ConversationController::class, 'sendMessage']);
        Route::post(  '{conversation}/assign',   [ConversationController::class, 'assign']);
        Route::post(  '{conversation}/transfer', [ConversationController::class, 'transfer']);
        Route::post(  '{conversation}/label',    [ConversationController::class, 'addLabel']);
        Route::put(   '{conversation}/status',   [ConversationController::class, 'updateStatus']);
        Route::post(  '{conversation}/pin',      [ConversationController::class, 'togglePin']);
    });

    // Automations / Workflows
    Route::prefix('automations')->group(function () {
        Route::get(   '/',                    [AutomationController::class, 'index']);
        Route::post(  '/',                    [AutomationController::class, 'store']);
        Route::get(   '{flow}',               [AutomationController::class, 'show']);
        Route::put(   '{flow}',               [AutomationController::class, 'update']);
        Route::delete('{flow}',               [AutomationController::class, 'destroy']);
        Route::post(  '{flow}/toggle',        [AutomationController::class, 'toggle']);
        Route::get(   '{flow}/logs',          [AutomationController::class, 'logs']);
    });

    // WooCommerce
    Route::prefix('woocommerce')->group(function () {
        Route::get(   '/',                [WooCommerceController::class, 'settings']);
        Route::post(  '/',                [WooCommerceController::class, 'save']);
        Route::post(  'connect',          [WooCommerceController::class, 'connect']);
        Route::post(  'sync',             [WooCommerceController::class, 'sync']);
        Route::get(   'orders',           [WooCommerceController::class, 'orders']);
    });

    // Orders (internal)
    Route::apiResource('orders', \App\Http\Controllers\Api\OrderController::class);

    // Tasks
    Route::prefix('tasks')->group(function () {
        Route::get(   '/',              [TaskController::class, 'index']);
        Route::post(  '/',              [TaskController::class, 'store']);
        Route::get(   '{task}',         [TaskController::class, 'show']);
        Route::put(   '{task}',         [TaskController::class, 'update']);
        Route::delete('{task}',         [TaskController::class, 'destroy']);
        Route::post(  '{task}/comments',[TaskController::class, 'addComment']);
    });

    // Team & Users
    Route::prefix('team')->group(function () {
        Route::get(   '/',              [TeamController::class, 'index']);
        Route::post(  '/',              [TeamController::class, 'invite']);
        Route::put(   '{user}',         [TeamController::class, 'update']);
        Route::delete('{user}',         [TeamController::class, 'remove']);
        Route::get(   'performance',    [TeamController::class, 'performance']);
        Route::get(   'activity',       [TeamController::class, 'activity']);
    });

    // Analytics
    Route::prefix('analytics')->group(function () {
        Route::get('dashboard',         [AnalyticsController::class, 'dashboard']);
        Route::get('campaigns',         [AnalyticsController::class, 'campaigns']);
        Route::get('contacts',          [AnalyticsController::class, 'contacts']);
        Route::get('conversations',     [AnalyticsController::class, 'conversations']);
        Route::get('agents',            [AnalyticsController::class, 'agents']);
        Route::get('revenue',           [AnalyticsController::class, 'revenue']);
    });

    // Notifications
    Route::prefix('notifications')->group(function () {
        Route::get(   '/',              [NotificationController::class, 'index']);
        Route::post(  'read-all',       [NotificationController::class, 'markAllRead']);
        Route::post(  '{id}/read',      [NotificationController::class, 'markRead']);
    });

    // Settings
    Route::prefix('settings')->group(function () {
        Route::get(   '/',              [SettingsController::class, 'index']);
        Route::put(   'general',        [SettingsController::class, 'updateGeneral']);
        Route::put(   'smtp',           [SettingsController::class, 'updateSmtp']);
        Route::post(  'smtp/test',      [SettingsController::class, 'testSmtp']);
        Route::put(   'brand',          [SettingsController::class, 'updateBrand']);
        Route::put(   'ai',             [SettingsController::class, 'updateAi']);
        Route::put(   'storage',        [SettingsController::class, 'updateStorage']);
        Route::get(   'api-tokens',     [SettingsController::class, 'apiTokens']);
        Route::post(  'api-tokens',     [SettingsController::class, 'createApiToken']);
        Route::delete('api-tokens/{id}',[SettingsController::class, 'revokeApiToken']);
    });

    // Knowledge Base (AI)
    Route::apiResource('knowledge-base', \App\Http\Controllers\Api\KnowledgeBaseController::class);

    // Subscription / Billing
    Route::prefix('billing')->group(function () {
        Route::get(   'plan',           [\App\Http\Controllers\Api\BillingController::class, 'currentPlan']);
        Route::get(   'invoices',       [\App\Http\Controllers\Api\BillingController::class, 'invoices']);
        Route::post(  'upgrade',        [\App\Http\Controllers\Api\BillingController::class, 'upgrade']);
        Route::post(  'cancel',         [\App\Http\Controllers\Api\BillingController::class, 'cancel']);
        Route::get(   'usage',          [\App\Http\Controllers\Api\BillingController::class, 'usage']);
    });
});

// ----------------------------------------------------------------
// SUPER ADMIN
// ----------------------------------------------------------------
Route::middleware(['auth:sanctum', 'role:super_admin'])->prefix('super-admin')->group(function () {
    Route::apiResource('tenants', TenantController::class);
    Route::post('tenants/{tenant}/suspend',   [TenantController::class, 'suspend']);
    Route::post('tenants/{tenant}/activate',  [TenantController::class, 'activate']);
    Route::apiResource('plans', PlanController::class);
    Route::get('stats',                       [\App\Http\Controllers\Api\SuperAdmin\StatsController::class, 'index']);
    Route::get('audit-logs',                  [\App\Http\Controllers\Api\SuperAdmin\StatsController::class, 'auditLogs']);
});

// ----------------------------------------------------------------
// PUBLIC API (for external integrations, token auth)
// ----------------------------------------------------------------
Route::middleware('api.token')->prefix('v1')->group(function () {
    Route::post('send-message',             [PublicApiController::class, 'sendMessage']);
    Route::post('send-template',            [PublicApiController::class, 'sendTemplate']);
    Route::post('create-contact',           [PublicApiController::class, 'createContact']);
    Route::post('create-campaign',          [PublicApiController::class, 'createCampaign']);
    Route::get( 'contacts',                 [PublicApiController::class, 'contacts']);
    Route::get( 'campaigns',                [PublicApiController::class, 'campaigns']);
    Route::get( 'templates',                [PublicApiController::class, 'templates']);
    Route::get( 'analytics',                [PublicApiController::class, 'analytics']);
});
