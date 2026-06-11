<?php
namespace App\Services;

// ============================================================
// WAHub — Core Service Classes
// ============================================================

// ---- AutomationEngineService.php --------------------------------

class AutomationEngineService
{
    public static function trigger(string $event, \App\Models\Tenant $tenant, array $context = []): void
    {
        $flows = \App\Models\AutomationFlow::where('tenant_id', $tenant->id)
            ->where('trigger_type', $event)
            ->where('is_active', 1)
            ->with(['nodes', 'edges'])
            ->get();

        foreach ($flows as $flow) {
            // Check trigger conditions
            if (!self::matchesTriggerConditions($flow->trigger_config ?? [], $context)) {
                continue;
            }

            \App\Jobs\ExecuteAutomationJob::dispatch($flow->id, $context);
        }
    }

    private static function matchesTriggerConditions(array $config, array $context): bool
    {
        // If no conditions set, always match
        if (empty($config['conditions'])) return true;

        foreach ($config['conditions'] as $condition) {
            $value = data_get($context, $condition['field']);
            $result = match ($condition['operator']) {
                'equals'      => $value == $condition['value'],
                'not_equals'  => $value != $condition['value'],
                'contains'    => str_contains((string)$value, $condition['value']),
                'starts_with' => str_starts_with((string)$value, $condition['value']),
                'greater_than'=> (float)$value > (float)$condition['value'],
                'less_than'   => (float)$value < (float)$condition['value'],
                default       => true,
            };
            if (!$result) return false;
        }
        return true;
    }
}

// ---- ExecuteAutomationJob.php -----------------------------------

namespace App\Jobs;

class ExecuteAutomationJob implements \Illuminate\Contracts\Queue\ShouldQueue
{
    use \Illuminate\Bus\Queueable, \Illuminate\Queue\InteractsWithQueue,
        \Illuminate\Queue\SerializesModels, \Illuminate\Foundation\Bus\Dispatchable;

    public function __construct(public int $flowId, public array $context) {}

    public function handle(): void
    {
        $flow = \App\Models\AutomationFlow::with(['nodes','edges'])->find($this->flowId);
        if (!$flow) return;

        $log = \App\Models\AutomationLog::create([
            'flow_id'      => $flow->id,
            'tenant_id'    => $flow->tenant_id,
            'contact_id'   => $this->context['contact']->id ?? null,
            'trigger_data' => $this->context,
            'status'       => 'running',
        ]);

        $flow->increment('run_count');
        $flow->update(['last_triggered_at' => now()]);

        try {
            // Build adjacency map
            $edges = $flow->edges->groupBy('source_node_id');
            $nodes = $flow->nodes->keyBy('node_id');

            // Find trigger node (start)
            $startNode = $flow->nodes->first(fn($n) => $n->type === 'trigger');
            if (!$startNode) throw new \RuntimeException('No trigger node found');

            $steps = [];
            $this->executeNode($startNode, $nodes, $edges, $this->context, $flow->tenant_id, $steps);

            $log->update(['status' => 'completed', 'steps_executed' => $steps]);
        } catch (\Exception $e) {
            $log->update(['status' => 'failed', 'error_message' => $e->getMessage()]);
        }
    }

    private function executeNode(
        $node, $nodes, $edges, array $context, int $tenantId, array &$steps
    ): void {
        $config = $node->config ?? [];

        $result = match ($node->type) {
            'action'    => $this->executeAction($config, $context, $tenantId),
            'condition' => $this->evaluateCondition($config, $context),
            'delay'     => $this->executeDelay($config),
            'webhook'   => $this->executeWebhook($config, $context),
            'filter'    => true,
            default     => true,
        };

        $steps[] = ['node' => $node->node_id, 'type' => $node->type, 'result' => $result];

        // Follow edges based on result
        $nextEdges = $edges->get($node->node_id, collect());

        foreach ($nextEdges as $edge) {
            // Branch: true/false edges
            if ($edge->condition && is_array($edge->condition)) {
                $expectedResult = $edge->condition['value'] ?? null;
                if ($expectedResult !== null && $result !== $expectedResult) continue;
            }

            $nextNode = $nodes->get($edge->target_node_id);
            if ($nextNode) {
                $this->executeNode($nextNode, $nodes, $edges, $context, $tenantId, $steps);
            }
        }
    }

    private function executeAction(array $config, array $context, int $tenantId): mixed
    {
        $tenant = \App\Models\Tenant::find($tenantId);

        return match ($config['action'] ?? '') {
            'send_message' => (function () use ($config, $context, $tenant) {
                $contact = $context['contact'] ?? null;
                if (!$contact) return false;
                $wa = new WhatsAppCloudApiService($tenant);
                $text = $this->interpolate($config['message'] ?? '', $context);
                return $wa->sendText($contact->phone, $text)['success'];
            })(),

            'send_template' => (function () use ($config, $context, $tenant) {
                $contact  = $context['contact'] ?? null;
                $template = \App\Models\Template::find($config['template_id'] ?? null);
                if (!$contact || !$template) return false;
                $wa         = new WhatsAppCloudApiService($tenant);
                $components = $wa->buildTemplateComponents($template, $config['variables'] ?? []);
                return $wa->sendTemplate($contact->phone, $template->name, $template->language, $components)['success'];
            })(),

            'add_tag' => (function () use ($config, $context) {
                $contact = $context['contact'] ?? null;
                if ($contact && isset($config['tag_id'])) {
                    $contact->tags()->syncWithoutDetaching([$config['tag_id']]);
                }
                return true;
            })(),

            'remove_tag' => (function () use ($config, $context) {
                $contact = $context['contact'] ?? null;
                if ($contact && isset($config['tag_id'])) {
                    $contact->tags()->detach($config['tag_id']);
                }
                return true;
            })(),

            'assign_agent' => (function () use ($config, $context) {
                $conversation = $context['conversation'] ?? null;
                if ($conversation && isset($config['agent_id'])) {
                    $conversation->update(['assigned_to' => $config['agent_id']]);
                }
                return true;
            })(),

            'create_task' => (function () use ($config, $context, $tenantId) {
                \App\Models\Task::create([
                    'tenant_id'   => $tenantId,
                    'title'       => $this->interpolate($config['title'] ?? 'Task', $context),
                    'priority'    => $config['priority'] ?? 'medium',
                    'assigned_to' => $config['assigned_to'] ?? null,
                    'due_date'    => isset($config['due_days']) ? now()->addDays($config['due_days']) : null,
                    'created_by'  => 0,
                    'contact_id'  => $context['contact']->id ?? null,
                ]);
                return true;
            })(),

            'call_webhook' => $this->executeWebhook($config, $context),

            default => false,
        };
    }

    private function evaluateCondition(array $config, array $context): bool
    {
        $field    = $config['field'] ?? '';
        $operator = $config['operator'] ?? 'equals';
        $value    = $config['value'] ?? null;
        $actual   = data_get($context, $field);

        return match ($operator) {
            'equals'      => $actual == $value,
            'not_equals'  => $actual != $value,
            'contains'    => str_contains((string)$actual, (string)$value),
            'greater_than'=> (float)$actual > (float)$value,
            'less_than'   => (float)$actual < (float)$value,
            'is_empty'    => empty($actual),
            'is_not_empty'=> !empty($actual),
            default       => false,
        };
    }

    private function executeDelay(array $config): bool
    {
        $seconds = ($config['value'] ?? 0) * match ($config['unit'] ?? 'seconds') {
            'minutes' => 60,
            'hours'   => 3600,
            'days'    => 86400,
            default   => 1,
        };
        if ($seconds > 0) sleep(min($seconds, 3600)); // max 1h inline, use scheduler for longer
        return true;
    }

    private function executeWebhook(array $config, array $context): bool
    {
        $url     = $config['url'] ?? null;
        $method  = strtoupper($config['method'] ?? 'POST');
        $payload = $this->interpolate(json_encode($config['payload'] ?? $context), $context);

        if (!$url) return false;

        try {
            \Illuminate\Support\Facades\Http::timeout(10)
                ->withHeaders($config['headers'] ?? [])
                ->{strtolower($method)}($url, json_decode($payload, true) ?? []);
            return true;
        } catch (\Exception $e) {
            return false;
        }
    }

    private function interpolate(string $text, array $context): string
    {
        $contact = $context['contact'] ?? null;
        $order   = $context['order'] ?? null;

        $replacements = [
            '{{contact.name}}'    => $contact?->name ?? '',
            '{{contact.phone}}'   => $contact?->phone ?? '',
            '{{contact.email}}'   => $contact?->email ?? '',
            '{{order.number}}'    => $order?->order_number ?? '',
            '{{order.total}}'     => $order?->total ?? '',
            '{{order.status}}'    => $order?->status ?? '',
        ];

        return str_replace(array_keys($replacements), array_values($replacements), $text);
    }
}

// ---- WooCommerceService.php ------------------------------------

namespace App\Services;

class WooCommerceService
{
    private \App\Models\WoocommerceSetting $settings;

    public function __construct(\App\Models\Tenant $tenant)
    {
        $this->settings = $tenant->woocommerceSettings;
        if (!$this->settings?->is_connected) {
            throw new \RuntimeException('WooCommerce not connected');
        }
    }

    private function client(): \Illuminate\Http\Client\PendingRequest
    {
        return \Illuminate\Support\Facades\Http::timeout(30)->withBasicAuth(
            $this->settings->consumer_key,
            $this->settings->consumer_secret
        );
    }

    public function testConnection(): bool
    {
        $resp = $this->client()->get("{$this->settings->store_url}/wp-json/wc/v3/system_status");
        return $resp->successful();
    }

    public function syncOrders(int $page = 1, int $perPage = 50): int
    {
        $resp = $this->client()->get("{$this->settings->store_url}/wp-json/wc/v3/orders", [
            'per_page' => $perPage,
            'page'     => $page,
            'orderby'  => 'date',
            'order'    => 'desc',
        ]);

        if (!$resp->successful()) return 0;

        $count = 0;
        foreach ($resp->json() as $wooOrder) {
            $this->upsertOrder($wooOrder);
            $count++;
        }

        $this->settings->update(['last_sync_at' => now()]);
        return $count;
    }

    private function upsertOrder(array $wooOrder): void
    {
        $tenantId = $this->settings->tenant_id;
        $phone    = $this->extractPhone($wooOrder);

        $contact = null;
        if ($phone) {
            $contact = \App\Models\Contact::firstOrCreate(
                ['tenant_id' => $tenantId, 'phone' => $phone],
                [
                    'uuid'    => \Illuminate\Support\Str::uuid(),
                    'name'    => trim(($wooOrder['billing']['first_name'] ?? '') . ' ' . ($wooOrder['billing']['last_name'] ?? '')),
                    'email'   => $wooOrder['billing']['email'] ?? null,
                    'company' => $wooOrder['billing']['company'] ?? null,
                    'city'    => $wooOrder['billing']['city'] ?? null,
                    'source'  => 'woocommerce',
                ]
            );
        }

        $order = \App\Models\Order::updateOrCreate(
            ['tenant_id' => $tenantId, 'woo_order_id' => (string)$wooOrder['id']],
            [
                'contact_id'       => $contact?->id,
                'order_number'     => $wooOrder['number'],
                'status'           => $wooOrder['status'],
                'total'            => $wooOrder['total'],
                'currency'         => $wooOrder['currency'],
                'items'            => array_map(fn($i) => [
                    'id' => $i['product_id'], 'name' => $i['name'],
                    'qty' => $i['quantity'], 'price' => $i['price']
                ], $wooOrder['line_items'] ?? []),
                'billing_address'  => $wooOrder['billing'] ?? null,
                'shipping_address' => $wooOrder['shipping'] ?? null,
                'ordered_at'       => $wooOrder['date_created'],
            ]
        );

        // Trigger automation for order events
        if ($contact) {
            $tenant = \App\Models\Tenant::find($tenantId);
            AutomationEngineService::trigger(
                'order_' . $wooOrder['status'],
                $tenant,
                ['contact' => $contact, 'order' => $order]
            );
        }
    }

    private function extractPhone(array $wooOrder): ?string
    {
        return $wooOrder['billing']['phone']
            ?? $wooOrder['shipping']['phone']
            ?? null;
    }
}

// ---- AiChatService.php -----------------------------------------

namespace App\Services;

class AiChatService
{
    private \App\Models\ChatbotSetting $settings;

    public function __construct(\App\Models\ChatbotSetting $settings)
    {
        $this->settings = $settings;
    }

    public function chat(string $systemPrompt, array $history): ?string
    {
        return match ($this->settings->provider) {
            'openai' => $this->chatOpenAI($systemPrompt, $history),
            'gemini' => $this->chatGemini($systemPrompt, $history),
            default  => null,
        };
    }

    private function chatOpenAI(string $systemPrompt, array $history): ?string
    {
        $messages = array_merge(
            [['role' => 'system', 'content' => $systemPrompt]],
            $history
        );

        $resp = \Illuminate\Support\Facades\Http::timeout(30)
            ->withToken($this->settings->api_key)
            ->post('https://api.openai.com/v1/chat/completions', [
                'model'      => $this->settings->model ?? 'gpt-4o-mini',
                'messages'   => $messages,
                'max_tokens' => 500,
                'temperature'=> 0.7,
            ]);

        if ($resp->successful()) {
            return $resp->json('choices.0.message.content');
        }

        \Illuminate\Support\Facades\Log::error('OpenAI error', $resp->json());
        return null;
    }

    private function chatGemini(string $systemPrompt, array $history): ?string
    {
        $contents = [];
        foreach ($history as $msg) {
            $contents[] = [
                'role'  => $msg['role'] === 'assistant' ? 'model' : 'user',
                'parts' => [['text' => $msg['content']]],
            ];
        }

        $resp = \Illuminate\Support\Facades\Http::timeout(30)
            ->post("https://generativelanguage.googleapis.com/v1beta/models/{$this->settings->model}:generateContent?key={$this->settings->api_key}", [
                'system_instruction' => ['parts' => [['text' => $systemPrompt]]],
                'contents'           => $contents,
                'generationConfig'   => ['maxOutputTokens' => 500, 'temperature' => 0.7],
            ]);

        if ($resp->successful()) {
            return $resp->json('candidates.0.content.parts.0.text');
        }

        \Illuminate\Support\Facades\Log::error('Gemini error', $resp->json());
        return null;
    }
}

// ---- KnowledgeBaseService.php -----------------------------------

namespace App\Services;

class KnowledgeBaseService
{
    public static function search(int $tenantId, string $query): string
    {
        $items = \App\Models\KnowledgeBase::where('tenant_id', $tenantId)
            ->where('is_active', 1)
            ->where(function ($q) use ($query) {
                $q->where('title', 'like', "%{$query}%")
                  ->orWhere('content', 'like', "%{$query}%");
            })
            ->take(5)
            ->get();

        if ($items->isEmpty()) return '';

        return $items->map(fn($item) =>
            "--- {$item->title} ---\n" . substr($item->content ?? '', 0, 500)
        )->implode("\n\n");
    }
}
