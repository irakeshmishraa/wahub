<?php
namespace App\Http\Middleware;

// ============================================================
// WAHub Middleware Stack
// ============================================================

// ---- TenantActive.php -------------------------------------------

class TenantActive
{
    public function handle($request, \Closure $next)
    {
        $user = auth()->user();
        if (!$user) return response()->json(['error' => 'Unauthenticated'], 401);

        // Super admins bypass tenant checks
        if ($user->isSuperAdmin()) return $next($request);

        $tenant = $user->tenant;
        if (!$tenant) {
            return response()->json(['error' => 'Tenant not found'], 404);
        }

        if (!$tenant->is_active) {
            return response()->json(['error' => 'Account suspended. Contact support.'], 403);
        }

        // Check subscription
        if (
            $tenant->subscription_status === 'cancelled' ||
            ($tenant->subscription_status === 'trial' && $tenant->trial_ends_at?->isPast())
        ) {
            return response()->json([
                'error' => 'Subscription expired',
                'action' => 'upgrade',
                'billing_url' => url('/billing'),
            ], 402);
        }

        // Share tenant with request
        $request->merge(['_tenant' => $tenant]);
        \Illuminate\Support\Facades\View::share('tenant', $tenant);

        return $next($request);
    }
}

// ---- AuditLog.php -----------------------------------------------

class AuditLog
{
    private const LOG_METHODS = ['POST', 'PUT', 'PATCH', 'DELETE'];

    public function handle($request, \Closure $next)
    {
        $response = $next($request);

        if (
            in_array($request->method(), self::LOG_METHODS) &&
            $response->getStatusCode() < 400 &&
            auth()->check()
        ) {
            \App\Models\AuditLog::create([
                'tenant_id'   => auth()->user()->tenant_id,
                'user_id'     => auth()->id(),
                'action'      => $request->method() . ' ' . $request->path(),
                'ip_address'  => $request->ip(),
                'user_agent'  => substr($request->userAgent() ?? '', 0, 500),
            ]);
        }

        return $response;
    }
}

// ---- CheckRole.php ----------------------------------------------

class CheckRole
{
    public function handle($request, \Closure $next, string $role)
    {
        $user = auth()->user();
        if (!$user || $user->role->slug !== $role) {
            return response()->json(['error' => 'Insufficient permissions'], 403);
        }
        return $next($request);
    }
}

// ---- ApiTokenAuth.php -------------------------------------------

class ApiTokenAuth
{
    public function handle($request, \Closure $next)
    {
        $token = $request->header('X-API-Token')
                 ?? $request->bearerToken()
                 ?? $request->get('api_token');

        if (!$token) {
            return response()->json(['error' => 'API token required'], 401);
        }

        $hash   = hash('sha256', $token);
        $record = \App\Models\ApiToken::where('token_hash', $hash)
            ->where('is_active', 1)
            ->where(fn($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->first();

        if (!$record) {
            return response()->json(['error' => 'Invalid or expired API token'], 401);
        }

        // Rate limiting
        $rateKey = "api_token_rate:{$record->id}:" . now()->format('Y-m-d-H');
        $current = \Illuminate\Support\Facades\Cache::increment($rateKey);
        if ($current === 1) {
            \Illuminate\Support\Facades\Cache::expire($rateKey, 3600);
        }
        if ($current > $record->rate_limit) {
            return response()->json(['error' => 'Rate limit exceeded'], 429);
        }

        $record->update(['last_used_at' => now()]);

        // Attach tenant to request
        $request->merge(['_api_tenant_id' => $record->tenant_id]);
        \App\Support\TenantContext::set($record->tenant_id);

        return $next($request);
    }
}

// ---- IpRestriction.php ------------------------------------------

class IpRestriction
{
    public function handle($request, \Closure $next)
    {
        $user = auth()->user();
        if (!$user) return $next($request);

        $allowed = $user->settings['allowed_ips'] ?? null;
        if (!$allowed || empty($allowed)) return $next($request);

        $clientIp = $request->ip();
        if (!in_array($clientIp, (array)$allowed)) {
            // Log suspicious access
            \App\Models\AuditLog::create([
                'user_id'     => $user->id,
                'tenant_id'   => $user->tenant_id,
                'action'      => 'blocked_ip_access',
                'ip_address'  => $clientIp,
            ]);
            return response()->json(['error' => 'Access denied from this IP'], 403);
        }

        return $next($request);
    }
}

// ---- PlanFeatureCheck.php (example gate middleware) -------------

class PlanFeatureCheck
{
    public function handle($request, \Closure $next, string $feature)
    {
        $tenant = auth()->user()?->tenant;
        if (!$tenant?->canUseFeatue($feature)) {
            return response()->json([
                'error'   => "Feature '{$feature}' not available in your plan",
                'action'  => 'upgrade',
            ], 403);
        }
        return $next($request);
    }
}

// ---- Kernel.php registration snippet ---------------------------
// In app/Http/Kernel.php, register:
//
// protected $middlewareAliases = [
//     'tenant.active'   => TenantActive::class,
//     'audit.log'       => AuditLog::class,
//     'role'            => CheckRole::class,
//     'api.token'       => ApiTokenAuth::class,
//     'ip.restrict'     => IpRestriction::class,
//     'plan.feature'    => PlanFeatureCheck::class,
// ];
