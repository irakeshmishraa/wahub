<?php
// ============================================================
// WAHub Laravel Models — app/Models/
// ============================================================

// ---- Tenant.php ------------------------------------------------
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Tenant extends Model
{
    use HasFactory;

    protected $fillable = [
        'uuid','name','slug','email','phone','logo',
        'plan_id','subscription_status','trial_ends_at',
        'subscription_ends_at','is_active','settings',
    ];

    protected $casts = [
        'settings'            => 'array',
        'trial_ends_at'       => 'datetime',
        'subscription_ends_at'=> 'datetime',
        'is_active'           => 'boolean',
    ];

    public function plan()           { return $this->belongsTo(Plan::class); }
    public function users()          { return $this->hasMany(User::class); }
    public function contacts()       { return $this->hasMany(Contact::class); }
    public function campaigns()      { return $this->hasMany(Campaign::class); }
    public function templates()      { return $this->hasMany(Template::class); }
    public function whatsappSetting(){ return $this->hasOne(WhatsappSetting::class); }
    public function conversations()  { return $this->hasMany(Conversation::class); }
    public function automationFlows(){ return $this->hasMany(AutomationFlow::class); }
    public function subscriptions()  { return $this->hasMany(Subscription::class); }

    public function getSetting(string $group, string $key, $default = null)
    {
        return \App\Models\Setting::where('tenant_id', $this->id)
            ->where('group_name', $group)
            ->where('key_name', $key)
            ->value('value') ?? $default;
    }

    public function isTrialing(): bool
    {
        return $this->subscription_status === 'trial'
            && $this->trial_ends_at
            && $this->trial_ends_at->isFuture();
    }

    public function canUseFeatue(string $feature): bool
    {
        $features = $this->plan->features ?? [];
        return ($features[$feature] ?? false) === true;
    }
}

// ---- User.php --------------------------------------------------
class User extends \Illuminate\Foundation\Auth\User
{
    use HasFactory;

    protected $fillable = [
        'uuid','tenant_id','role_id','name','email','phone',
        'password','avatar','is_active','two_factor_enabled',
        'two_factor_secret','settings',
    ];

    protected $hidden   = ['password','two_factor_secret','remember_token'];
    protected $casts    = [
        'is_active'            => 'boolean',
        'two_factor_enabled'   => 'boolean',
        'email_verified_at'    => 'datetime',
        'settings'             => 'array',
    ];

    public function tenant()      { return $this->belongsTo(Tenant::class); }
    public function role()        { return $this->belongsTo(Role::class); }
    public function conversations(){ return $this->hasMany(Conversation::class, 'assigned_to'); }
    public function tasks()       { return $this->hasMany(Task::class, 'assigned_to'); }
    public function notifications(){ return $this->hasMany(Notification::class); }

    public function isSuperAdmin(): bool { return $this->role->slug === 'super_admin'; }
    public function isAdmin(): bool      { return in_array($this->role->slug, ['super_admin','admin']); }

    public function hasPermission(string $permission): bool
    {
        $perms = $this->role->permissions ?? [];
        return isset($perms['*']) || isset($perms[$permission]);
    }
}

// ---- Contact.php -----------------------------------------------
class Contact extends Model
{
    protected $fillable = [
        'uuid','tenant_id','name','phone','email','company',
        'gst_number','address','city','state','country',
        'source','status','opt_in','opt_in_at','custom_fields',
        'notes','created_by',
    ];

    protected $casts = [
        'opt_in'        => 'boolean',
        'opt_in_at'     => 'datetime',
        'custom_fields' => 'array',
        'last_interaction_at' => 'datetime',
    ];

    public function tenant()        { return $this->belongsTo(Tenant::class); }
    public function tags()          { return $this->belongsToMany(Tag::class, 'contact_tags'); }
    public function notes()         { return $this->hasMany(ContactNote::class); }
    public function conversations() { return $this->hasMany(Conversation::class); }
    public function orders()        { return $this->hasMany(Order::class); }
    public function campaigns()     { return $this->belongsToMany(Campaign::class, 'campaign_recipients'); }
}

// ---- Template.php ----------------------------------------------
class Template extends Model
{
    protected $fillable = [
        'uuid','tenant_id','meta_template_id','name','category',
        'language','status','rejection_reason','header_type',
        'header_content','header_media_url','body','footer',
        'buttons','variables','sample_values','created_by',
    ];

    protected $casts = [
        'buttons'       => 'array',
        'variables'     => 'array',
        'sample_values' => 'array',
        'submitted_at'  => 'datetime',
        'approved_at'   => 'datetime',
    ];

    public function tenant()   { return $this->belongsTo(Tenant::class); }
    public function versions() { return $this->hasMany(TemplateVersion::class); }
    public function campaigns(){ return $this->hasMany(Campaign::class); }

    public function isApproved(): bool  { return $this->status === 'approved'; }
    public function buildPayload(): array
    {
        $components = [];
        if ($this->header_type !== 'none') {
            $header = ['type' => 'HEADER', 'format' => strtoupper($this->header_type)];
            if ($this->header_type === 'text') {
                $header['text'] = $this->header_content;
            }
            $components[] = $header;
        }
        $components[] = ['type' => 'BODY', 'text' => $this->body];
        if ($this->footer) {
            $components[] = ['type' => 'FOOTER', 'text' => $this->footer];
        }
        if ($this->buttons) {
            $components[] = ['type' => 'BUTTONS', 'buttons' => $this->buttons];
        }
        return [
            'name'       => $this->name,
            'language'   => $this->language,
            'category'   => $this->category,
            'components' => $components,
        ];
    }
}

// ---- Campaign.php ----------------------------------------------
class Campaign extends Model
{
    protected $fillable = [
        'uuid','tenant_id','name','type','template_id',
        'audience_type','audience_ids','audience_count',
        'schedule_type','scheduled_at','recurrence_rule',
        'status','sent_count','delivered_count','read_count',
        'failed_count','clicked_count','replied_count','created_by',
    ];

    protected $casts = [
        'audience_ids'    => 'array',
        'recurrence_rule' => 'array',
        'scheduled_at'    => 'datetime',
        'started_at'      => 'datetime',
        'completed_at'    => 'datetime',
    ];

    public function tenant()     { return $this->belongsTo(Tenant::class); }
    public function template()   { return $this->belongsTo(Template::class); }
    public function recipients() { return $this->hasMany(CampaignRecipient::class); }

    public function deliveryRate(): float
    {
        return $this->sent_count > 0
            ? round(($this->delivered_count / $this->sent_count) * 100, 2)
            : 0;
    }

    public function readRate(): float
    {
        return $this->delivered_count > 0
            ? round(($this->read_count / $this->delivered_count) * 100, 2)
            : 0;
    }
}

// ---- Conversation.php ------------------------------------------
class Conversation extends Model
{
    protected $fillable = [
        'uuid','tenant_id','contact_id','assigned_to',
        'status','is_pinned','unread_count','last_message_at',
        'last_message_preview','labels','meta',
    ];

    protected $casts = [
        'is_pinned'       => 'boolean',
        'labels'          => 'array',
        'meta'            => 'array',
        'last_message_at' => 'datetime',
    ];

    public function tenant()  { return $this->belongsTo(Tenant::class); }
    public function contact() { return $this->belongsTo(Contact::class); }
    public function agent()   { return $this->belongsTo(User::class, 'assigned_to'); }
    public function messages(){ return $this->hasMany(Message::class)->orderBy('created_at'); }
    public function lastMessage(){ return $this->hasOne(Message::class)->latestOfMany(); }
}

// ---- Message.php -----------------------------------------------
class Message extends Model
{
    protected $fillable = [
        'uuid','tenant_id','conversation_id','contact_id',
        'sender_type','sender_id','wa_message_id','type',
        'content','media_url','media_type','template_id',
        'template_variables','status','error_code','error_message',
        'is_internal_note','sent_at','delivered_at','read_at',
    ];

    protected $casts = [
        'template_variables' => 'array',
        'is_internal_note'   => 'boolean',
        'sent_at'            => 'datetime',
        'delivered_at'       => 'datetime',
        'read_at'            => 'datetime',
    ];

    public function conversation() { return $this->belongsTo(Conversation::class); }
    public function contact()      { return $this->belongsTo(Contact::class); }
    public function template()     { return $this->belongsTo(Template::class); }
}

// ---- AutomationFlow.php ----------------------------------------
class AutomationFlow extends Model
{
    protected $fillable = [
        'uuid','tenant_id','name','description','trigger_type',
        'trigger_config','is_active','run_count','created_by',
    ];

    protected $casts = [
        'trigger_config'   => 'array',
        'is_active'        => 'boolean',
        'last_triggered_at'=> 'datetime',
    ];

    public function tenant() { return $this->belongsTo(Tenant::class); }
    public function nodes()  { return $this->hasMany(AutomationNode::class, 'flow_id'); }
    public function edges()  { return $this->hasMany(AutomationEdge::class, 'flow_id'); }
    public function logs()   { return $this->hasMany(AutomationLog::class, 'flow_id'); }
}

// ---- Order.php -------------------------------------------------
class Order extends Model
{
    protected $fillable = [
        'tenant_id','contact_id','woo_order_id','order_number',
        'status','total','currency','items','billing_address',
        'shipping_address','tracking_number','tracking_url','notes','meta',
        'ordered_at',
    ];

    protected $casts = [
        'items'            => 'array',
        'billing_address'  => 'array',
        'shipping_address' => 'array',
        'meta'             => 'array',
        'ordered_at'       => 'datetime',
    ];

    public function tenant()  { return $this->belongsTo(Tenant::class); }
    public function contact() { return $this->belongsTo(Contact::class); }
}
