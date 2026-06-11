<?php
namespace App\Http\Controllers\Api;

// ============================================================
// WAHub API Controllers
// ============================================================

use App\Http\Controllers\Controller;
use App\Models\{Tenant, Contact, Campaign, Template, Conversation, Message, AutomationFlow, Order, Task};
use App\Services\{WhatsAppCloudApiService, CampaignEngineService, AutomationEngineService};
use Illuminate\Http\{Request, JsonResponse};
use Illuminate\Support\Facades\{Auth, Cache, DB};

// ---- WebhookController.php -------------------------------------

class WebhookController extends Controller
{
    public function verify(Request $req, $tenantSlug): mixed
    {
        $tenant = Tenant::where('slug', $tenantSlug)->firstOrFail();
        $wa     = new WhatsAppCloudApiService($tenant);

        $challenge = $wa->verifyWebhook(
            $req->get('hub_mode'),
            $req->get('hub_challenge'),
            $req->get('hub_verify_token')
        );

        return $challenge ? response($challenge, 200) : response('Forbidden', 403);
    }

    public function handle(Request $req, $tenantSlug): JsonResponse
    {
        $tenant = Tenant::where('slug', $tenantSlug)->firstOrFail();
        $wa     = new WhatsAppCloudApiService($tenant);

        // Validate signature
        $sig = $req->header('X-Hub-Signature-256', '');
        if (!$wa->validateWebhookSignature($req->getContent(), $sig)) {
            return response()->json(['error' => 'Invalid signature'], 401);
        }

        // Log raw webhook
        $log = \App\Models\WebhookLog::create([
            'tenant_id'  => $tenant->id,
            'event_type' => $req->input('object'),
            'payload'    => $req->all(),
        ]);

        // Dispatch async processing
        \App\Jobs\ProcessWebhookJob::dispatch($tenant->id, $log->id);

        return response()->json(['status' => 'ok']);
    }
}

// ---- ContactController.php ------------------------------------

class ContactController extends Controller
{
    public function index(Request $req): JsonResponse
    {
        $tenant = auth()->user()->tenant;
        $query = Contact::where('tenant_id', $tenant->id)
            ->with(['tags'])
            ->when($req->search, fn($q, $s) =>
                $q->where(fn($q2) =>
                    $q2->where('name', 'like', "%$s%")
                       ->orWhere('phone', 'like', "%$s%")
                       ->orWhere('email', 'like', "%$s%")
                ))
            ->when($req->tag, fn($q, $t) =>
                $q->whereHas('tags', fn($q2) => $q2->where('tags.id', $t))
            )
            ->when($req->status, fn($q, $s) => $q->where('status', $s));

        return response()->json($query->paginate($req->per_page ?? 25));
    }

    public function store(Request $req): JsonResponse
    {
        $req->validate([
            'name'  => 'required|string|max:255',
            'phone' => 'required|string|max:20',
            'email' => 'nullable|email',
        ]);

        $tenant = auth()->user()->tenant;

        // Duplicate detection
        $exists = Contact::where('tenant_id', $tenant->id)
            ->where('phone', $req->phone)->first();

        if ($exists) {
            return response()->json(['error' => 'Contact with this phone already exists', 'contact' => $exists], 409);
        }

        $contact = Contact::create([
            'uuid'      => \Illuminate\Support\Str::uuid(),
            'tenant_id' => $tenant->id,
            'created_by'=> auth()->id(),
            ...$req->only(['name','phone','email','company','city','state','country','source','custom_fields','notes']),
        ]);

        if ($req->tags) {
            $contact->tags()->sync($req->tags);
        }

        // Fire automation trigger
        AutomationEngineService::trigger('new_contact', $tenant, ['contact' => $contact]);

        return response()->json($contact->load('tags'), 201);
    }

    public function bulkImport(Request $req): JsonResponse
    {
        $req->validate(['file' => 'required|file|mimes:csv,txt|max:10240']);
        $tenant = auth()->user()->tenant;

        \App\Jobs\ImportContactsJob::dispatch($tenant->id, $req->file('file')->store('imports'), auth()->id());

        return response()->json(['message' => 'Import started. You will be notified when done.']);
    }

    public function destroy(Contact $contact): JsonResponse
    {
        $this->authorize('delete', $contact);
        $contact->delete();
        return response()->json(['message' => 'Deleted']);
    }
}

// ---- TemplateController.php -----------------------------------

class TemplateController extends Controller
{
    public function index(): JsonResponse
    {
        $templates = Template::where('tenant_id', auth()->user()->tenant_id)
            ->orderByDesc('created_at')->paginate(20);
        return response()->json($templates);
    }

    public function store(Request $req): JsonResponse
    {
        $req->validate([
            'name'     => 'required|string|max:512',
            'category' => 'required|in:MARKETING,UTILITY,AUTHENTICATION',
            'body'     => 'required|string',
            'language' => 'required|string|max:10',
        ]);

        $tenant = auth()->user()->tenant;

        $template = Template::create([
            'uuid'       => \Illuminate\Support\Str::uuid(),
            'tenant_id'  => $tenant->id,
            'created_by' => auth()->id(),
            ...$req->only(['name','category','language','header_type','header_content',
                          'header_media_url','body','footer','buttons','variables','sample_values']),
            'status'     => 'draft',
        ]);

        return response()->json($template, 201);
    }

    public function submit(Template $template): JsonResponse
    {
        $this->authorize('update', $template);

        if ($template->status !== 'draft' && $template->status !== 'rejected') {
            return response()->json(['error' => 'Template cannot be submitted in current status'], 422);
        }

        $wa     = new WhatsAppCloudApiService(auth()->user()->tenant);
        $result = $wa->submitTemplate($template);

        // Save version snapshot
        $template->versions()->create([
            'version'  => $template->versions()->count() + 1,
            'snapshot' => $template->toArray(),
        ]);

        return response()->json($result);
    }

    public function syncStatus(Template $template): JsonResponse
    {
        $wa     = new WhatsAppCloudApiService(auth()->user()->tenant);
        $result = $wa->fetchTemplateStatus($template);
        return response()->json($result);
    }

    public function clone(Template $template): JsonResponse
    {
        $cloned = $template->replicate();
        $cloned->uuid       = \Illuminate\Support\Str::uuid();
        $cloned->name       = $template->name . ' (Copy)';
        $cloned->status     = 'draft';
        $cloned->meta_template_id = null;
        $cloned->save();
        return response()->json($cloned, 201);
    }
}

// ---- CampaignController.php -----------------------------------

class CampaignController extends Controller
{
    public function index(Request $req): JsonResponse
    {
        $campaigns = Campaign::where('tenant_id', auth()->user()->tenant_id)
            ->with(['template'])
            ->when($req->status, fn($q, $s) => $q->where('status', $s))
            ->orderByDesc('created_at')
            ->paginate(20);
        return response()->json($campaigns);
    }

    public function store(Request $req): JsonResponse
    {
        $req->validate([
            'name'          => 'required|string',
            'template_id'   => 'required|exists:templates,id',
            'audience_type' => 'required|in:all,segment,tag,csv,manual',
        ]);

        $tenant = auth()->user()->tenant;

        // Check plan limits
        $campaignCount = Campaign::where('tenant_id', $tenant->id)
            ->whereMonth('created_at', now()->month)->count();
        if ($tenant->plan->max_campaigns !== -1 && $campaignCount >= $tenant->plan->max_campaigns) {
            return response()->json(['error' => 'Campaign limit reached for your plan'], 403);
        }

        $campaign = Campaign::create([
            'uuid'       => \Illuminate\Support\Str::uuid(),
            'tenant_id'  => $tenant->id,
            'created_by' => auth()->id(),
            ...$req->only(['name','type','template_id','audience_type','audience_ids',
                          'schedule_type','scheduled_at','recurrence_rule']),
        ]);

        // Schedule immediately or queue
        if ($req->schedule_type === 'immediate') {
            \App\Jobs\SendCampaignJob::dispatch($campaign->id);
        } else {
            // Will be picked up by scheduler
            $campaign->update(['status' => 'scheduled']);
        }

        return response()->json($campaign, 201);
    }

    public function pause(Campaign $campaign): JsonResponse
    {
        $this->authorize('update', $campaign);
        $campaign->update(['status' => 'paused']);
        return response()->json(['status' => 'paused']);
    }

    public function resume(Campaign $campaign): JsonResponse
    {
        $this->authorize('update', $campaign);
        $campaign->update(['status' => 'running']);
        \App\Jobs\SendCampaignJob::dispatch($campaign->id);
        return response()->json(['status' => 'resumed']);
    }

    public function analytics(Campaign $campaign): JsonResponse
    {
        return response()->json([
            'sent'          => $campaign->sent_count,
            'delivered'     => $campaign->delivered_count,
            'read'          => $campaign->read_count,
            'failed'        => $campaign->failed_count,
            'clicked'       => $campaign->clicked_count,
            'delivery_rate' => $campaign->deliveryRate(),
            'read_rate'     => $campaign->readRate(),
            'recipients'    => $campaign->recipients()
                ->select('status', DB::raw('count(*) as count'))
                ->groupBy('status')->get(),
        ]);
    }
}

// ---- ConversationController.php --------------------------------

class ConversationController extends Controller
{
    public function index(Request $req): JsonResponse
    {
        $conversations = Conversation::where('tenant_id', auth()->user()->tenant_id)
            ->with(['contact', 'agent', 'lastMessage'])
            ->when($req->status, fn($q, $s) => $q->where('status', $s))
            ->when($req->assigned_to, fn($q, $a) => $q->where('assigned_to', $a))
            ->when($req->search, fn($q, $s) =>
                $q->whereHas('contact', fn($q2) =>
                    $q2->where('name', 'like', "%$s%")->orWhere('phone', 'like', "%$s%")
                ))
            ->orderByDesc('last_message_at')
            ->paginate(30);
        return response()->json($conversations);
    }

    public function messages(Conversation $conv): JsonResponse
    {
        $this->authorize('view', $conv);
        $messages = $conv->messages()->with(['contact'])->paginate(50);
        // Mark unread as read
        $conv->update(['unread_count' => 0]);
        return response()->json($messages);
    }

    public function sendMessage(Request $req, Conversation $conv): JsonResponse
    {
        $req->validate(['type' => 'required', 'content' => 'required_if:type,text']);
        $this->authorize('update', $conv);

        $tenant = auth()->user()->tenant;
        $wa     = new WhatsAppCloudApiService($tenant);

        if ($req->type === 'text') {
            $result = $wa->sendText($conv->contact->phone, $req->content);
        } elseif ($req->type === 'media') {
            $result = $wa->sendMedia($conv->contact->phone, $req->media_type, $req->media_url, $req->caption);
        } elseif ($req->type === 'template') {
            $template   = Template::findOrFail($req->template_id);
            $components = $wa->buildTemplateComponents($template, $req->variables ?? []);
            $result     = $wa->sendTemplate($conv->contact->phone, $template->name, $template->language, $components);
        }

        $message = Message::create([
            'uuid'            => \Illuminate\Support\Str::uuid(),
            'tenant_id'       => $tenant->id,
            'conversation_id' => $conv->id,
            'sender_type'     => 'agent',
            'sender_id'       => auth()->id(),
            'type'            => $req->type,
            'content'         => $req->content,
            'wa_message_id'   => $result['wa_message_id'] ?? null,
            'status'          => $result['success'] ? 'sent' : 'failed',
            'is_internal_note'=> $req->is_internal_note ?? false,
        ]);

        $conv->update([
            'last_message_at'      => now(),
            'last_message_preview' => substr($req->content ?? '', 0, 100),
        ]);

        broadcast(new \App\Events\NewMessageEvent($message));

        return response()->json($message);
    }

    public function assign(Request $req, Conversation $conv): JsonResponse
    {
        $req->validate(['agent_id' => 'nullable|exists:users,id']);
        $conv->update(['assigned_to' => $req->agent_id]);

        if ($req->agent_id) {
            \App\Models\Notification::create([
                'tenant_id' => $conv->tenant_id,
                'user_id'   => $req->agent_id,
                'type'      => 'conversation_assigned',
                'title'     => 'Conversation assigned to you',
                'body'      => "Contact: {$conv->contact->name}",
            ]);
        }

        return response()->json($conv->load('agent'));
    }
}

// ---- AutomationController.php ---------------------------------

class AutomationController extends Controller
{
    public function index(): JsonResponse
    {
        $flows = AutomationFlow::where('tenant_id', auth()->user()->tenant_id)
            ->withCount('logs')
            ->orderByDesc('created_at')
            ->get();
        return response()->json($flows);
    }

    public function store(Request $req): JsonResponse
    {
        $req->validate([
            'name'         => 'required|string',
            'trigger_type' => 'required|string',
            'nodes'        => 'required|array',
            'edges'        => 'required|array',
        ]);

        $tenant = auth()->user()->tenant;
        if (!$tenant->canUseFeatue('workflow')) {
            return response()->json(['error' => 'Workflow feature not available in your plan'], 403);
        }

        DB::transaction(function () use ($req, $tenant, &$flow) {
            $flow = AutomationFlow::create([
                'uuid'           => \Illuminate\Support\Str::uuid(),
                'tenant_id'      => $tenant->id,
                'created_by'     => auth()->id(),
                'name'           => $req->name,
                'description'    => $req->description,
                'trigger_type'   => $req->trigger_type,
                'trigger_config' => $req->trigger_config ?? [],
            ]);

            foreach ($req->nodes as $node) {
                $flow->nodes()->create([
                    'node_id'    => $node['id'],
                    'type'       => $node['type'],
                    'config'     => $node['data'] ?? [],
                    'position_x' => $node['position']['x'] ?? 0,
                    'position_y' => $node['position']['y'] ?? 0,
                ]);
            }

            foreach ($req->edges as $edge) {
                $flow->edges()->create([
                    'source_node_id' => $edge['source'],
                    'target_node_id' => $edge['target'],
                    'label'          => $edge['label'] ?? null,
                ]);
            }
        });

        return response()->json($flow->load(['nodes','edges']), 201);
    }

    public function toggle(AutomationFlow $flow): JsonResponse
    {
        $this->authorize('update', $flow);
        $flow->update(['is_active' => !$flow->is_active]);
        return response()->json(['is_active' => $flow->is_active]);
    }
}

// ---- AnalyticsController.php ----------------------------------

class AnalyticsController extends Controller
{
    public function dashboard(): JsonResponse
    {
        $tenant   = auth()->user()->tenant;
        $tenantId = $tenant->id;
        $cacheKey = "analytics.dashboard.{$tenantId}";

        return response()->json(Cache::remember($cacheKey, 300, function () use ($tenantId) {
            $today = now()->startOfDay();
            $month = now()->startOfMonth();

            return [
                'total_contacts'  => Contact::where('tenant_id', $tenantId)->count(),
                'new_contacts_today' => Contact::where('tenant_id', $tenantId)
                    ->where('created_at', '>=', $today)->count(),
                'total_campaigns' => Campaign::where('tenant_id', $tenantId)->count(),
                'campaigns_this_month' => Campaign::where('tenant_id', $tenantId)
                    ->where('created_at', '>=', $month)->count(),
                'messages_today'  => Message::where('tenant_id', $tenantId)
                    ->where('created_at', '>=', $today)->count(),
                'messages_this_month' => Message::where('tenant_id', $tenantId)
                    ->where('created_at', '>=', $month)->count(),
                'open_conversations' => Conversation::where('tenant_id', $tenantId)
                    ->where('status', 'open')->count(),
                'delivery_rate'   => $this->avgDeliveryRate($tenantId),
                'read_rate'       => $this->avgReadRate($tenantId),
                'campaign_chart'  => $this->campaignChart($tenantId),
                'message_chart'   => $this->messageChart($tenantId),
            ];
        }));
    }

    private function avgDeliveryRate(int $tenantId): float
    {
        $campaigns = Campaign::where('tenant_id', $tenantId)
            ->where('sent_count', '>', 0)
            ->selectRaw('AVG(delivered_count / sent_count * 100) as rate')
            ->value('rate');
        return round($campaigns ?? 0, 2);
    }

    private function avgReadRate(int $tenantId): float
    {
        $campaigns = Campaign::where('tenant_id', $tenantId)
            ->where('delivered_count', '>', 0)
            ->selectRaw('AVG(read_count / delivered_count * 100) as rate')
            ->value('rate');
        return round($campaigns ?? 0, 2);
    }

    private function campaignChart(int $tenantId): array
    {
        return Campaign::where('tenant_id', $tenantId)
            ->where('created_at', '>=', now()->subDays(30))
            ->selectRaw('DATE(created_at) as date, COUNT(*) as count')
            ->groupBy('date')->orderBy('date')
            ->pluck('count', 'date')->toArray();
    }

    private function messageChart(int $tenantId): array
    {
        return Message::where('tenant_id', $tenantId)
            ->where('created_at', '>=', now()->subDays(7))
            ->selectRaw('DATE(created_at) as date, COUNT(*) as count')
            ->groupBy('date')->orderBy('date')
            ->pluck('count', 'date')->toArray();
    }
}
