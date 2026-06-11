-- ============================================================
-- WAHub Complete Database Schema
-- MySQL 8.0+ | Multi-tenant SaaS
-- ============================================================

SET NAMES utf8mb4;
SET time_zone = '+00:00';

-- ------------------------------------------------------------
-- TENANTS & SUBSCRIPTIONS
-- ------------------------------------------------------------

CREATE TABLE plans (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,                    -- Starter, Professional, Enterprise
    slug VARCHAR(100) NOT NULL UNIQUE,
    price_monthly DECIMAL(10,2) DEFAULT 0,
    price_yearly  DECIMAL(10,2) DEFAULT 0,
    max_contacts  INT DEFAULT 500,
    max_campaigns INT DEFAULT 10,
    max_messages_per_month INT DEFAULT 5000,
    max_agents    INT DEFAULT 2,
    max_templates INT DEFAULT 10,
    features JSON,                                 -- {"ai_chatbot":true,"woocommerce":false}
    is_active TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE tenants (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    uuid VARCHAR(36) NOT NULL UNIQUE,
    name VARCHAR(255) NOT NULL,
    slug VARCHAR(100) NOT NULL UNIQUE,            -- subdomain or path prefix
    email VARCHAR(255) NOT NULL UNIQUE,
    phone VARCHAR(20),
    logo VARCHAR(500),
    plan_id BIGINT UNSIGNED,
    subscription_status ENUM('trial','active','past_due','cancelled','suspended') DEFAULT 'trial',
    trial_ends_at TIMESTAMP NULL,
    subscription_ends_at TIMESTAMP NULL,
    is_active TINYINT(1) DEFAULT 1,
    settings JSON,                                -- brand colors, timezone, etc.
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (plan_id) REFERENCES plans(id)
) ENGINE=InnoDB;

CREATE TABLE subscriptions (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id BIGINT UNSIGNED NOT NULL,
    plan_id BIGINT UNSIGNED NOT NULL,
    status ENUM('active','cancelled','past_due','trialing') DEFAULT 'active',
    starts_at TIMESTAMP NOT NULL,
    ends_at TIMESTAMP NULL,
    amount DECIMAL(10,2),
    currency VARCHAR(10) DEFAULT 'INR',
    payment_gateway VARCHAR(50),
    gateway_subscription_id VARCHAR(255),
    invoice_url VARCHAR(500),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (tenant_id) REFERENCES tenants(id),
    FOREIGN KEY (plan_id) REFERENCES plans(id)
) ENGINE=InnoDB;

CREATE TABLE usage_logs (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id BIGINT UNSIGNED NOT NULL,
    type ENUM('message','campaign','contact','api_call') NOT NULL,
    count INT DEFAULT 1,
    date DATE NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    KEY idx_tenant_type_date (tenant_id, type, date),
    FOREIGN KEY (tenant_id) REFERENCES tenants(id)
) ENGINE=InnoDB;

-- ------------------------------------------------------------
-- USERS & ROLES
-- ------------------------------------------------------------

CREATE TABLE roles (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id BIGINT UNSIGNED NULL,              -- NULL = global/super
    name VARCHAR(100) NOT NULL,
    slug VARCHAR(100) NOT NULL,
    permissions JSON,
    is_system TINYINT(1) DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE users (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    uuid VARCHAR(36) NOT NULL UNIQUE,
    tenant_id BIGINT UNSIGNED NULL,
    role_id BIGINT UNSIGNED NOT NULL,
    name VARCHAR(255) NOT NULL,
    email VARCHAR(255) NOT NULL,
    phone VARCHAR(20),
    password VARCHAR(255) NOT NULL,
    avatar VARCHAR(500),
    is_active TINYINT(1) DEFAULT 1,
    is_online TINYINT(1) DEFAULT 0,
    last_seen_at TIMESTAMP NULL,
    two_factor_secret VARCHAR(255) NULL,
    two_factor_enabled TINYINT(1) DEFAULT 0,
    email_verified_at TIMESTAMP NULL,
    settings JSON,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY unique_email_tenant (email, tenant_id),
    FOREIGN KEY (tenant_id) REFERENCES tenants(id),
    FOREIGN KEY (role_id) REFERENCES roles(id)
) ENGINE=InnoDB;

CREATE TABLE login_history (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    ip_address VARCHAR(50),
    user_agent TEXT,
    country VARCHAR(100),
    city VARCHAR(100),
    status ENUM('success','failed') DEFAULT 'success',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id)
) ENGINE=InnoDB;

CREATE TABLE sessions (
    id VARCHAR(191) PRIMARY KEY,
    user_id BIGINT UNSIGNED NULL,
    ip_address VARCHAR(50),
    user_agent TEXT,
    payload LONGTEXT NOT NULL,
    last_activity INT NOT NULL,
    KEY idx_user (user_id),
    KEY idx_last_activity (last_activity)
) ENGINE=InnoDB;

-- ------------------------------------------------------------
-- WHATSAPP CLOUD API SETTINGS
-- ------------------------------------------------------------

CREATE TABLE whatsapp_settings (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id BIGINT UNSIGNED NOT NULL UNIQUE,
    meta_app_id VARCHAR(255),
    meta_app_secret TEXT,
    business_manager_id VARCHAR(255),
    waba_id VARCHAR(255),               -- WhatsApp Business Account ID
    phone_number_id VARCHAR(255),
    access_token TEXT,
    token_expires_at TIMESTAMP NULL,
    webhook_verify_token VARCHAR(255),
    webhook_url VARCHAR(500),
    display_name VARCHAR(255),
    display_phone VARCHAR(50),
    quality_rating VARCHAR(20),
    is_connected TINYINT(1) DEFAULT 0,
    last_health_check TIMESTAMP NULL,
    health_status ENUM('healthy','degraded','disconnected') DEFAULT 'disconnected',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (tenant_id) REFERENCES tenants(id)
) ENGINE=InnoDB;

CREATE TABLE webhook_logs (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id BIGINT UNSIGNED NOT NULL,
    event_type VARCHAR(100),
    payload JSON,
    processed TINYINT(1) DEFAULT 0,
    error_message TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_tenant_processed (tenant_id, processed),
    FOREIGN KEY (tenant_id) REFERENCES tenants(id)
) ENGINE=InnoDB;

-- ------------------------------------------------------------
-- CONTACTS & CRM
-- ------------------------------------------------------------

CREATE TABLE contacts (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    uuid VARCHAR(36) NOT NULL UNIQUE,
    tenant_id BIGINT UNSIGNED NOT NULL,
    name VARCHAR(255) NOT NULL,
    phone VARCHAR(20) NOT NULL,
    email VARCHAR(255) NULL,
    company VARCHAR(255) NULL,
    gst_number VARCHAR(20) NULL,
    address TEXT NULL,
    city VARCHAR(100) NULL,
    state VARCHAR(100) NULL,
    country VARCHAR(100) DEFAULT 'India',
    source ENUM('manual','import','api','woocommerce','webhook','chatbot') DEFAULT 'manual',
    status ENUM('active','blocked','unsubscribed') DEFAULT 'active',
    opt_in TINYINT(1) DEFAULT 1,
    opt_in_at TIMESTAMP NULL,
    last_interaction_at TIMESTAMP NULL,
    custom_fields JSON,
    notes TEXT NULL,
    created_by BIGINT UNSIGNED NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY unique_phone_tenant (phone, tenant_id),
    INDEX idx_tenant (tenant_id),
    INDEX idx_phone (phone),
    FOREIGN KEY (tenant_id) REFERENCES tenants(id)
) ENGINE=InnoDB;

CREATE TABLE tags (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id BIGINT UNSIGNED NOT NULL,
    name VARCHAR(100) NOT NULL,
    color VARCHAR(10) DEFAULT '#6366f1',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY unique_tag_tenant (name, tenant_id),
    FOREIGN KEY (tenant_id) REFERENCES tenants(id)
) ENGINE=InnoDB;

CREATE TABLE contact_tags (
    contact_id BIGINT UNSIGNED NOT NULL,
    tag_id BIGINT UNSIGNED NOT NULL,
    PRIMARY KEY (contact_id, tag_id),
    FOREIGN KEY (contact_id) REFERENCES contacts(id) ON DELETE CASCADE,
    FOREIGN KEY (tag_id) REFERENCES tags(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE contact_segments (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id BIGINT UNSIGNED NOT NULL,
    name VARCHAR(255) NOT NULL,
    filter_rules JSON,                            -- [{field,operator,value}]
    contact_count INT DEFAULT 0,
    last_synced_at TIMESTAMP NULL,
    created_by BIGINT UNSIGNED NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (tenant_id) REFERENCES tenants(id)
) ENGINE=InnoDB;

CREATE TABLE contact_notes (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    contact_id BIGINT UNSIGNED NOT NULL,
    user_id BIGINT UNSIGNED NOT NULL,
    note TEXT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (contact_id) REFERENCES contacts(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id)
) ENGINE=InnoDB;

CREATE TABLE custom_fields (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id BIGINT UNSIGNED NOT NULL,
    entity_type ENUM('contact','order') DEFAULT 'contact',
    label VARCHAR(255) NOT NULL,
    key_name VARCHAR(100) NOT NULL,
    field_type ENUM('text','number','date','boolean','select') DEFAULT 'text',
    options JSON NULL,
    is_required TINYINT(1) DEFAULT 0,
    FOREIGN KEY (tenant_id) REFERENCES tenants(id)
) ENGINE=InnoDB;

-- ------------------------------------------------------------
-- TEMPLATES
-- ------------------------------------------------------------

CREATE TABLE templates (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    uuid VARCHAR(36) NOT NULL UNIQUE,
    tenant_id BIGINT UNSIGNED NOT NULL,
    meta_template_id VARCHAR(255) NULL,
    name VARCHAR(255) NOT NULL,
    category ENUM('MARKETING','UTILITY','AUTHENTICATION') NOT NULL,
    language VARCHAR(10) DEFAULT 'en',
    status ENUM('draft','submitted','pending','approved','rejected','disabled','paused') DEFAULT 'draft',
    rejection_reason TEXT NULL,
    header_type ENUM('none','text','image','video','document') DEFAULT 'none',
    header_content TEXT NULL,
    header_media_url VARCHAR(500) NULL,
    body TEXT NOT NULL,
    footer TEXT NULL,
    buttons JSON,                                 -- [{type,text,url,phone}]
    variables JSON,                               -- ["{{1}}","{{2}}"]
    sample_values JSON,
    submitted_at TIMESTAMP NULL,
    approved_at TIMESTAMP NULL,
    created_by BIGINT UNSIGNED NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_tenant_status (tenant_id, status),
    FOREIGN KEY (tenant_id) REFERENCES tenants(id)
) ENGINE=InnoDB;

CREATE TABLE template_versions (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    template_id BIGINT UNSIGNED NOT NULL,
    version INT DEFAULT 1,
    snapshot JSON,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (template_id) REFERENCES templates(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ------------------------------------------------------------
-- CONVERSATIONS & MESSAGES (Inbox)
-- ------------------------------------------------------------

CREATE TABLE conversations (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    uuid VARCHAR(36) NOT NULL UNIQUE,
    tenant_id BIGINT UNSIGNED NOT NULL,
    contact_id BIGINT UNSIGNED NOT NULL,
    assigned_to BIGINT UNSIGNED NULL,
    status ENUM('open','pending','resolved','snoozed') DEFAULT 'open',
    is_pinned TINYINT(1) DEFAULT 0,
    unread_count INT DEFAULT 0,
    last_message_at TIMESTAMP NULL,
    last_message_preview VARCHAR(500),
    labels JSON,
    meta JSON,                                    -- WA conversation window info
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_tenant_status (tenant_id, status),
    INDEX idx_contact (contact_id),
    FOREIGN KEY (tenant_id) REFERENCES tenants(id),
    FOREIGN KEY (contact_id) REFERENCES contacts(id),
    FOREIGN KEY (assigned_to) REFERENCES users(id)
) ENGINE=InnoDB;

CREATE TABLE messages (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    uuid VARCHAR(36) NOT NULL UNIQUE,
    tenant_id BIGINT UNSIGNED NOT NULL,
    conversation_id BIGINT UNSIGNED NOT NULL,
    contact_id BIGINT UNSIGNED NULL,
    sender_type ENUM('contact','agent','bot','system') NOT NULL,
    sender_id BIGINT UNSIGNED NULL,
    wa_message_id VARCHAR(255) NULL UNIQUE,
    type ENUM('text','image','video','audio','document','template','interactive','sticker','location','contacts','reaction') DEFAULT 'text',
    content TEXT NULL,
    media_url VARCHAR(500) NULL,
    media_type VARCHAR(100) NULL,
    template_id BIGINT UNSIGNED NULL,
    template_variables JSON NULL,
    status ENUM('sending','sent','delivered','read','failed') DEFAULT 'sending',
    error_code VARCHAR(50) NULL,
    error_message TEXT NULL,
    is_internal_note TINYINT(1) DEFAULT 0,
    sent_at TIMESTAMP NULL,
    delivered_at TIMESTAMP NULL,
    read_at TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_conversation (conversation_id),
    INDEX idx_wa_message (wa_message_id),
    FOREIGN KEY (tenant_id) REFERENCES tenants(id),
    FOREIGN KEY (conversation_id) REFERENCES conversations(id)
) ENGINE=InnoDB;

CREATE TABLE conversation_labels (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id BIGINT UNSIGNED NOT NULL,
    name VARCHAR(100) NOT NULL,
    color VARCHAR(10) DEFAULT '#6366f1',
    UNIQUE KEY unique_label_tenant (name, tenant_id)
) ENGINE=InnoDB;

-- ------------------------------------------------------------
-- CAMPAIGNS
-- ------------------------------------------------------------

CREATE TABLE campaigns (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    uuid VARCHAR(36) NOT NULL UNIQUE,
    tenant_id BIGINT UNSIGNED NOT NULL,
    name VARCHAR(255) NOT NULL,
    type ENUM('marketing','promotional','transactional','festival','abandoned_cart','retention','winback') DEFAULT 'marketing',
    template_id BIGINT UNSIGNED NOT NULL,
    audience_type ENUM('all','segment','tag','csv','manual') DEFAULT 'all',
    audience_ids JSON,                            -- segment_id or tag_ids
    audience_count INT DEFAULT 0,
    schedule_type ENUM('immediate','scheduled','recurring') DEFAULT 'immediate',
    scheduled_at TIMESTAMP NULL,
    recurrence_rule JSON,                         -- {frequency,day,time}
    status ENUM('draft','scheduled','running','paused','completed','cancelled','failed') DEFAULT 'draft',
    sent_count INT DEFAULT 0,
    delivered_count INT DEFAULT 0,
    read_count INT DEFAULT 0,
    failed_count INT DEFAULT 0,
    clicked_count INT DEFAULT 0,
    replied_count INT DEFAULT 0,
    started_at TIMESTAMP NULL,
    completed_at TIMESTAMP NULL,
    created_by BIGINT UNSIGNED NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_tenant_status (tenant_id, status),
    FOREIGN KEY (tenant_id) REFERENCES tenants(id),
    FOREIGN KEY (template_id) REFERENCES templates(id)
) ENGINE=InnoDB;

CREATE TABLE campaign_recipients (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    campaign_id BIGINT UNSIGNED NOT NULL,
    contact_id BIGINT UNSIGNED NOT NULL,
    wa_message_id VARCHAR(255) NULL,
    variables JSON,
    status ENUM('pending','sent','delivered','read','failed','clicked') DEFAULT 'pending',
    error_message TEXT NULL,
    sent_at TIMESTAMP NULL,
    delivered_at TIMESTAMP NULL,
    read_at TIMESTAMP NULL,
    clicked_at TIMESTAMP NULL,
    INDEX idx_campaign (campaign_id),
    FOREIGN KEY (campaign_id) REFERENCES campaigns(id) ON DELETE CASCADE,
    FOREIGN KEY (contact_id) REFERENCES contacts(id)
) ENGINE=InnoDB;

-- ------------------------------------------------------------
-- WORKFLOWS / AUTOMATION
-- ------------------------------------------------------------

CREATE TABLE automation_flows (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    uuid VARCHAR(36) NOT NULL UNIQUE,
    tenant_id BIGINT UNSIGNED NOT NULL,
    name VARCHAR(255) NOT NULL,
    description TEXT NULL,
    trigger_type VARCHAR(100) NOT NULL,          -- new_contact, order_created, etc.
    trigger_config JSON,
    is_active TINYINT(1) DEFAULT 0,
    run_count INT DEFAULT 0,
    last_triggered_at TIMESTAMP NULL,
    created_by BIGINT UNSIGNED NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (tenant_id) REFERENCES tenants(id)
) ENGINE=InnoDB;

CREATE TABLE automation_nodes (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    flow_id BIGINT UNSIGNED NOT NULL,
    node_id VARCHAR(50) NOT NULL,                 -- react-flow node id
    type ENUM('trigger','condition','action','delay','filter','branch','webhook') NOT NULL,
    config JSON,
    position_x FLOAT DEFAULT 0,
    position_y FLOAT DEFAULT 0,
    FOREIGN KEY (flow_id) REFERENCES automation_flows(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE automation_edges (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    flow_id BIGINT UNSIGNED NOT NULL,
    source_node_id VARCHAR(50) NOT NULL,
    target_node_id VARCHAR(50) NOT NULL,
    label VARCHAR(100) NULL,
    condition JSON NULL,
    FOREIGN KEY (flow_id) REFERENCES automation_flows(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE automation_logs (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    flow_id BIGINT UNSIGNED NOT NULL,
    tenant_id BIGINT UNSIGNED NOT NULL,
    contact_id BIGINT UNSIGNED NULL,
    trigger_data JSON,
    status ENUM('running','completed','failed') DEFAULT 'running',
    steps_executed JSON,
    error_message TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_flow (flow_id),
    FOREIGN KEY (flow_id) REFERENCES automation_flows(id)
) ENGINE=InnoDB;

-- ------------------------------------------------------------
-- WOOCOMMERCE INTEGRATION
-- ------------------------------------------------------------

CREATE TABLE woocommerce_settings (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id BIGINT UNSIGNED NOT NULL UNIQUE,
    store_url VARCHAR(500) NOT NULL,
    consumer_key VARCHAR(255) NOT NULL,
    consumer_secret VARCHAR(255) NOT NULL,
    is_connected TINYINT(1) DEFAULT 0,
    webhook_secret VARCHAR(255) NULL,
    sync_orders TINYINT(1) DEFAULT 1,
    sync_customers TINYINT(1) DEFAULT 1,
    last_sync_at TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (tenant_id) REFERENCES tenants(id)
) ENGINE=InnoDB;

CREATE TABLE orders (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id BIGINT UNSIGNED NOT NULL,
    contact_id BIGINT UNSIGNED NULL,
    woo_order_id VARCHAR(100) NULL,
    order_number VARCHAR(100) NULL,
    status VARCHAR(50) DEFAULT 'pending',
    total DECIMAL(12,2) DEFAULT 0,
    currency VARCHAR(10) DEFAULT 'INR',
    items JSON,
    billing_address JSON,
    shipping_address JSON,
    tracking_number VARCHAR(255) NULL,
    tracking_url VARCHAR(500) NULL,
    notes TEXT NULL,
    meta JSON,
    ordered_at TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_tenant (tenant_id),
    INDEX idx_contact (contact_id),
    FOREIGN KEY (tenant_id) REFERENCES tenants(id)
) ENGINE=InnoDB;

-- ------------------------------------------------------------
-- AI CHATBOT
-- ------------------------------------------------------------

CREATE TABLE chatbot_settings (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id BIGINT UNSIGNED NOT NULL UNIQUE,
    provider ENUM('openai','gemini') DEFAULT 'openai',
    api_key TEXT,
    model VARCHAR(100) DEFAULT 'gpt-4o-mini',
    system_prompt TEXT,
    is_active TINYINT(1) DEFAULT 0,
    human_handover_keyword VARCHAR(100) DEFAULT 'agent',
    confidence_threshold FLOAT DEFAULT 0.7,
    languages JSON,
    FOREIGN KEY (tenant_id) REFERENCES tenants(id)
) ENGINE=InnoDB;

CREATE TABLE knowledge_base (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id BIGINT UNSIGNED NOT NULL,
    type ENUM('faq','product','service','pdf','webpage','catalog') NOT NULL,
    title VARCHAR(255) NOT NULL,
    content LONGTEXT,
    file_url VARCHAR(500) NULL,
    source_url VARCHAR(500) NULL,
    embedding_status ENUM('pending','processing','done','failed') DEFAULT 'pending',
    is_active TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (tenant_id) REFERENCES tenants(id)
) ENGINE=InnoDB;

-- ------------------------------------------------------------
-- TASKS
-- ------------------------------------------------------------

CREATE TABLE tasks (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id BIGINT UNSIGNED NOT NULL,
    title VARCHAR(500) NOT NULL,
    description TEXT NULL,
    priority ENUM('low','medium','high','critical') DEFAULT 'medium',
    status ENUM('open','pending','in_progress','completed','cancelled') DEFAULT 'open',
    assigned_to BIGINT UNSIGNED NULL,
    created_by BIGINT UNSIGNED NOT NULL,
    contact_id BIGINT UNSIGNED NULL,
    due_date DATE NULL,
    attachments JSON,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (tenant_id) REFERENCES tenants(id),
    FOREIGN KEY (assigned_to) REFERENCES users(id),
    FOREIGN KEY (created_by) REFERENCES users(id)
) ENGINE=InnoDB;

CREATE TABLE task_comments (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    task_id BIGINT UNSIGNED NOT NULL,
    user_id BIGINT UNSIGNED NOT NULL,
    comment TEXT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (task_id) REFERENCES tasks(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id)
) ENGINE=InnoDB;

-- ------------------------------------------------------------
-- NOTIFICATIONS
-- ------------------------------------------------------------

CREATE TABLE notifications (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id BIGINT UNSIGNED NOT NULL,
    user_id BIGINT UNSIGNED NULL,
    type VARCHAR(100) NOT NULL,
    title VARCHAR(255) NOT NULL,
    body TEXT,
    data JSON,
    channel ENUM('in_app','email','whatsapp') DEFAULT 'in_app',
    is_read TINYINT(1) DEFAULT 0,
    read_at TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_user_read (user_id, is_read),
    FOREIGN KEY (tenant_id) REFERENCES tenants(id)
) ENGINE=InnoDB;

-- ------------------------------------------------------------
-- AUDIT LOGS
-- ------------------------------------------------------------

CREATE TABLE audit_logs (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id BIGINT UNSIGNED NULL,
    user_id BIGINT UNSIGNED NULL,
    action VARCHAR(100) NOT NULL,
    entity_type VARCHAR(100) NULL,
    entity_id BIGINT NULL,
    old_values JSON NULL,
    new_values JSON NULL,
    ip_address VARCHAR(50),
    user_agent VARCHAR(500),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_tenant_action (tenant_id, action),
    INDEX idx_entity (entity_type, entity_id)
) ENGINE=InnoDB;

-- ------------------------------------------------------------
-- API TOKENS (Public API)
-- ------------------------------------------------------------

CREATE TABLE api_tokens (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id BIGINT UNSIGNED NOT NULL,
    name VARCHAR(255) NOT NULL,
    token_hash VARCHAR(255) NOT NULL UNIQUE,
    last_used_at TIMESTAMP NULL,
    expires_at TIMESTAMP NULL,
    rate_limit INT DEFAULT 1000,
    permissions JSON,
    is_active TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (tenant_id) REFERENCES tenants(id)
) ENGINE=InnoDB;

-- ------------------------------------------------------------
-- SETTINGS
-- ------------------------------------------------------------

CREATE TABLE settings (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id BIGINT UNSIGNED NULL,              -- NULL = global
    group_name VARCHAR(100) NOT NULL,
    key_name VARCHAR(100) NOT NULL,
    value LONGTEXT,
    type ENUM('string','integer','boolean','json') DEFAULT 'string',
    UNIQUE KEY unique_setting (tenant_id, group_name, key_name)
) ENGINE=InnoDB;

-- ------------------------------------------------------------
-- SEED: Default roles
-- ------------------------------------------------------------

INSERT INTO roles (name, slug, is_system, permissions) VALUES
('Super Admin', 'super_admin', 1, '{"*": true}'),
('Admin',       'admin',       1, '{"dashboard":true,"contacts":true,"campaigns":true,"inbox":true,"templates":true,"workflows":true,"settings":true,"team":true,"tasks":true,"analytics":true}'),
('Manager',     'manager',     1, '{"dashboard":true,"contacts":true,"campaigns":true,"inbox":true,"templates":true,"workflows":true,"tasks":true,"analytics":true}'),
('Agent',       'agent',       1, '{"dashboard":true,"contacts":["read","update"],"inbox":true,"tasks":true}'),
('Client',      'client',      1, '{"dashboard":true,"analytics":["read"]}'),
('Viewer',      'viewer',      1, '{"dashboard":true,"analytics":["read"],"campaigns":["read"]}');

INSERT INTO plans (name, slug, price_monthly, price_yearly, max_contacts, max_campaigns, max_messages_per_month, max_agents, max_templates, features) VALUES
('Starter',      'starter',      999,  9990,   2000,  20,  10000,  3,  20, '{"ai_chatbot":false,"woocommerce":false,"api_access":false,"workflow":false}'),
('Professional', 'professional', 2499, 24990,  10000, 100, 100000, 10, 100,'{"ai_chatbot":true,"woocommerce":true,"api_access":true,"workflow":true}'),
('Enterprise',   'enterprise',   7999, 79990,  -1,    -1,  -1,     -1, -1, '{"ai_chatbot":true,"woocommerce":true,"api_access":true,"workflow":true,"dedicated_support":true}');
