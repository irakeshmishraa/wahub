<?php
namespace App\Jobs;

// ============================================================
// WAHub Queue Jobs
// ============================================================

use App\Models\{Campaign, CampaignRecipient, Tenant, Contact, AutomationFlow, AutomationLog};
use App\Services\WhatsAppCloudApiService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\{InteractsWithQueue, SerializesModels};
use Illuminate\Support\Facades\{Log, DB};

// ---- SendCampaignJob.php ----------------------------------------

class SendCampaignJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 3600;
    public int $tries   = 3;

    public function __construct(public int $campaignId) {}

    public function handle(): void
    {
        $campaign = Campaign::with(['tenant.whatsappSetting', 'template'])->findOrFail($this->campaignId);

        if (!in_array($campaign->status, ['scheduled','running','paused'])) {
            Log::info("Campaign {$this->campaignId} not in runnable state: {$campaign->status}");
            return;
        }

        if (!$campaign->template->isApproved()) {
            $campaign->update(['status' => 'failed']);
            \App\Models\Notification::create([
                'tenant_id' => $campaign->tenant_id,
                'type'      => 'campaign_failed',
                'title'     => "Campaign \"{$campaign->name}\" failed",
                'body'      => 'Template is not approved.',
            ]);
            return;
        }

        $campaign->update(['status' => 'running', 'started_at' => now()]);
        $wa = new WhatsAppCloudApiService($campaign->tenant);

        // Resolve audience
        $contacts = $this->resolveAudience($campaign);
        $campaign->update(['audience_count' => $contacts->count()]);

        $batchSize = 50;
        $contacts->chunk($batchSize, function ($chunk) use ($campaign, $wa) {
            foreach ($chunk as $contact) {
                // Re-check paused
                $campaign->refresh();
                if ($campaign->status === 'paused') return false;

                if ($contact->status !== 'active' || !$contact->opt_in) continue;

                $recipient = CampaignRecipient::firstOrCreate(
                    ['campaign_id' => $campaign->id, 'contact_id' => $contact->id],
                    ['status' => 'pending']
                );

                if ($recipient->status !== 'pending') continue;

                $variables  = $this->buildVariables($campaign, $contact);
                $components = $wa->buildTemplateComponents($campaign->template, $variables);
                $result     = $wa->sendTemplate(
                    $contact->phone,
                    $campaign->template->name,
                    $campaign->template->language,
                    $components
                );

                $recipient->update([
                    'status'       => $result['success'] ? 'sent' : 'failed',
                    'wa_message_id'=> $result['wa_message_id'] ?? null,
                    'error_message'=> $result['success'] ? null : ($result['data']['error']['message'] ?? 'Unknown error'),
                    'sent_at'      => $result['success'] ? now() : null,
                ]);

                $campaign->increment($result['success'] ? 'sent_count' : 'failed_count');

                // Throttle to stay within Meta rate limits
                usleep(100000); // 100ms = ~10 msg/sec per phone number
            }
        });

        $campaign->update(['status' => 'completed', 'completed_at' => now()]);

        \App\Models\Notification::create([
            'tenant_id' => $campaign->tenant_id,
            'type'      => 'campaign_completed',
            'title'     => "Campaign \"{$campaign->name}\" completed",
            'body'      => "Sent: {$campaign->sent_count}, Failed: {$campaign->failed_count}",
        ]);
    }

    private function resolveAudience(Campaign $campaign)
    {
        $query = Contact::where('tenant_id', $campaign->tenant_id)
            ->where('status', 'active')
            ->where('opt_in', 1);

        match ($campaign->audience_type) {
            'segment' => $query->whereIn('id',
                \App\Services\SegmentService::resolveIds($campaign->audience_ids ?? [])),
            'tag'     => $query->whereHas('tags', fn($q) =>
                $q->whereIn('tags.id', $campaign->audience_ids ?? [])),
            'manual'  => $query->whereIn('id', $campaign->audience_ids ?? []),
            default   => null,
        };

        return $query;
    }

    private function buildVariables(Campaign $campaign, Contact $contact): array
    {
        // Replace placeholders with contact data
        $map = [
            'name'    => $contact->name,
            'phone'   => $contact->phone,
            'company' => $contact->company ?? '',
            'email'   => $contact->email ?? '',
        ];

        $variables = $campaign->template->variables ?? [];
        return array_map(fn($v) => $map[trim($v, '{} ')] ?? '', $variables);
    }
}

// ---- ProcessWebhookJob.php --------------------------------------

class ProcessWebhookJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function __construct(public int $tenantId, public int $logId) {}

    public function handle(): void
    {
        $log    = \App\Models\WebhookLog::find($this->logId);
        $tenant = Tenant::find($this->tenantId);
        if (!$log || !$tenant) return;

        try {
            $payload = $log->payload;

            foreach (data_get($payload, 'entry', []) as $entry) {
                foreach (data_get($entry, 'changes', []) as $change) {
                    $value = $change['value'] ?? [];

                    // Incoming messages
                    foreach (data_get($value, 'messages', []) as $waMsg) {
                        $this->handleIncomingMessage($tenant, $waMsg, $value);
                    }

                    // Status updates
                    foreach (data_get($value, 'statuses', []) as $status) {
                        $this->handleStatusUpdate($tenant, $status);
                    }

                    // Template status updates
                    if (isset($value['message_template_status_update'])) {
                        $this->handleTemplateStatusUpdate($tenant, $value['message_template_status_update']);
                    }
                }
            }

            $log->update(['processed' => 1]);
        } catch (\Exception $e) {
            $log->update(['error_message' => $e->getMessage()]);
            throw $e;
        }
    }

    private function handleIncomingMessage(Tenant $tenant, array $waMsg, array $context): void
    {
        $from    = $waMsg['from'];
        $waId    = $waMsg['id'];
        $type    = $waMsg['type'];
        $content = $this->extractMessageContent($waMsg, $type);

        // Find or create contact
        $contact = Contact::firstOrCreate(
            ['tenant_id' => $tenant->id, 'phone' => $from],
            [
                'uuid'   => \Illuminate\Support\Str::uuid(),
                'name'   => $context['contacts'][0]['profile']['name'] ?? $from,
                'source' => 'webhook',
            ]
        );

        // Find or create conversation
        $conversation = \App\Models\Conversation::firstOrCreate(
            ['tenant_id' => $tenant->id, 'contact_id' => $contact->id, 'status' => 'open'],
            [
                'uuid'        => \Illuminate\Support\Str::uuid(),
                'tenant_id'   => $tenant->id,
                'contact_id'  => $contact->id,
            ]
        );

        // Prevent duplicate processing
        if (Message::where('wa_message_id', $waId)->exists()) return;

        $message = Message::create([
            'uuid'            => \Illuminate\Support\Str::uuid(),
            'tenant_id'       => $tenant->id,
            'conversation_id' => $conversation->id,
            'contact_id'      => $contact->id,
            'sender_type'     => 'contact',
            'wa_message_id'   => $waId,
            'type'            => $type,
            'content'         => $content,
            'status'          => 'delivered',
        ]);

        $conversation->increment('unread_count');
        $conversation->update([
            'last_message_at'      => now(),
            'last_message_preview' => substr($content, 0, 100),
        ]);
        $contact->update(['last_interaction_at' => now()]);

        // Mark read via API
        (new WhatsAppCloudApiService($tenant))->markRead($waId);

        // Broadcast to agents via WebSocket
        broadcast(new \App\Events\NewMessageEvent($message));

        // AI Chatbot check
        $chatbotSettings = $tenant->chatbotSettings ?? null;
        if ($chatbotSettings?->is_active && $conversation->assigned_to === null) {
            \App\Jobs\ChatbotResponseJob::dispatch($message->id);
        }

        // Automation trigger: incoming_message
        \App\Services\AutomationEngineService::trigger('incoming_message', $tenant, [
            'contact'      => $contact,
            'message'      => $message,
            'conversation' => $conversation,
        ]);
    }

    private function handleStatusUpdate(Tenant $tenant, array $status): void
    {
        $message = Message::where('wa_message_id', $status['id'])->first();
        if (!$message) return;

        $waStatus = $status['status'];
        $update   = ['status' => $waStatus];

        match ($waStatus) {
            'delivered' => $update['delivered_at'] = now(),
            'read'      => $update['read_at'] = now(),
            'failed'    => $update['error_code'] = $status['errors'][0]['code'] ?? null,
            default     => null,
        };

        $message->update($update);

        // Update campaign recipient if applicable
        if ($message->sender_type === 'system') {
            CampaignRecipient::where('wa_message_id', $status['id'])
                ->update(array_merge(
                    ['status' => $waStatus],
                    $waStatus === 'delivered' ? ['delivered_at' => now()] : [],
                    $waStatus === 'read' ? ['read_at' => now()] : [],
                ));

            if ($waStatus === 'delivered') {
                Campaign::where('id', CampaignRecipient::where('wa_message_id', $status['id'])->value('campaign_id'))
                    ->increment('delivered_count');
            }
            if ($waStatus === 'read') {
                Campaign::where('id', CampaignRecipient::where('wa_message_id', $status['id'])->value('campaign_id'))
                    ->increment('read_count');
            }
        }
    }

    private function handleTemplateStatusUpdate(Tenant $tenant, array $data): void
    {
        $template = \App\Models\Template::where('tenant_id', $tenant->id)
            ->where('name', $data['message_template_name'])
            ->latest()->first();

        if (!$template) return;

        $status = strtolower($data['event']);
        $template->update([
            'status'           => $status,
            'rejection_reason' => $data['reason'] ?? null,
            'approved_at'      => $status === 'approved' ? now() : null,
        ]);

        \App\Models\Notification::create([
            'tenant_id' => $tenant->id,
            'type'      => 'template_' . $status,
            'title'     => "Template \"{$template->name}\" {$status}",
            'body'      => $data['reason'] ?? "Status updated to {$status}",
        ]);
    }

    private function extractMessageContent(array $waMsg, string $type): string
    {
        return match ($type) {
            'text'     => $waMsg['text']['body'] ?? '',
            'image'    => $waMsg['image']['caption'] ?? '[Image]',
            'video'    => $waMsg['video']['caption'] ?? '[Video]',
            'audio'    => '[Audio]',
            'document' => $waMsg['document']['filename'] ?? '[Document]',
            'button'   => $waMsg['button']['text'] ?? '[Button click]',
            'interactive' => $waMsg['interactive']['button_reply']['title']
                          ?? $waMsg['interactive']['list_reply']['title'] ?? '[Interactive]',
            'location' => '[Location: '.$waMsg['location']['latitude'].','.$waMsg['location']['longitude'].']',
            default    => '[' . ucfirst($type) . ']',
        };
    }
}

// ---- ChatbotResponseJob.php -------------------------------------

class ChatbotResponseJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public int $messageId) {}

    public function handle(): void
    {
        $message      = Message::with(['conversation.contact', 'conversation.tenant'])->findOrFail($this->messageId);
        $tenant       = $message->conversation->tenant;
        $chatbotSetting = \App\Models\ChatbotSetting::where('tenant_id', $tenant->id)->first();

        if (!$chatbotSetting?->is_active) return;

        // Human handover keyword
        if (str_contains(strtolower($message->content), $chatbotSetting->human_handover_keyword)) {
            $message->conversation->update(['assigned_to' => null]);
            \App\Models\Notification::create([
                'tenant_id' => $tenant->id,
                'type'      => 'human_handover_requested',
                'title'     => 'Human handover requested',
                'body'      => "Contact: {$message->conversation->contact->name}",
            ]);
            return;
        }

        // Get conversation history (last 10 messages)
        $history = $message->conversation->messages()
            ->whereNotNull('content')
            ->latest()->take(10)->get()->reverse()
            ->map(fn($m) => [
                'role'    => $m->sender_type === 'contact' ? 'user' : 'assistant',
                'content' => $m->content,
            ])->values()->toArray();

        // Knowledge base context
        $knowledgeContext = \App\Services\KnowledgeBaseService::search($tenant->id, $message->content);

        $systemPrompt = $chatbotSetting->system_prompt
            . "\n\nKnowledge base:\n" . $knowledgeContext;

        // Call AI provider
        $aiService = new \App\Services\AiChatService($chatbotSetting);
        $reply     = $aiService->chat($systemPrompt, $history);

        if (!$reply) return;

        // Send WhatsApp reply
        $wa = new WhatsAppCloudApiService($tenant);
        $result = $wa->sendText($message->conversation->contact->phone, $reply, $message->wa_message_id);

        // Store reply message
        Message::create([
            'uuid'            => \Illuminate\Support\Str::uuid(),
            'tenant_id'       => $tenant->id,
            'conversation_id' => $message->conversation_id,
            'sender_type'     => 'bot',
            'type'            => 'text',
            'content'         => $reply,
            'wa_message_id'   => $result['wa_message_id'] ?? null,
            'status'          => $result['success'] ? 'sent' : 'failed',
        ]);

        $message->conversation->update([
            'last_message_at'      => now(),
            'last_message_preview' => substr($reply, 0, 100),
        ]);
    }
}
