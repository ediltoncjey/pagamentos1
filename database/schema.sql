SET NAMES utf8mb4;
SET time_zone = '+00:00';

CREATE DATABASE IF NOT EXISTS sistem_pay
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

USE sistem_pay;

CREATE TABLE IF NOT EXISTS roles (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(40) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT uq_roles_name UNIQUE (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS users (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    uuid CHAR(36) NOT NULL,
    role_id BIGINT UNSIGNED NOT NULL,
    name VARCHAR(120) NOT NULL,
    email VARCHAR(160) NOT NULL,
    phone VARCHAR(20) NULL,
    password_hash VARCHAR(255) NOT NULL,
    status ENUM('active', 'inactive', 'suspended') NOT NULL DEFAULT 'active',
    last_login_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    deleted_at DATETIME NULL,
    CONSTRAINT uq_users_uuid UNIQUE (uuid),
    CONSTRAINT uq_users_email UNIQUE (email),
    CONSTRAINT uq_users_phone UNIQUE (phone),
    CONSTRAINT fk_users_role FOREIGN KEY (role_id) REFERENCES roles(id)
        ON UPDATE CASCADE ON DELETE RESTRICT,
    INDEX idx_users_role_id (role_id),
    INDEX idx_users_status (status),
    INDEX idx_users_created_at (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS products (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    reseller_id BIGINT UNSIGNED NOT NULL,
    name VARCHAR(180) NOT NULL,
    description TEXT NULL,
    product_type ENUM('digital', 'physical') NOT NULL DEFAULT 'digital',
    price DECIMAL(14,2) NOT NULL,
    currency CHAR(3) NOT NULL DEFAULT 'MZN',
    image_path VARCHAR(255) NULL,
    delivery_type ENUM('external_link', 'file_upload', 'none') NOT NULL DEFAULT 'external_link',
    external_url VARCHAR(500) NULL,
    file_path VARCHAR(500) NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    deleted_at DATETIME NULL,
    CONSTRAINT fk_products_reseller FOREIGN KEY (reseller_id) REFERENCES users(id)
        ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT ck_products_price_non_negative CHECK (price >= 0),
    INDEX idx_products_reseller_active (reseller_id, is_active),
    INDEX idx_products_currency (currency)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS payment_pages (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    product_id BIGINT UNSIGNED NOT NULL,
    reseller_id BIGINT UNSIGNED NOT NULL,
    slug VARCHAR(190) NOT NULL,
    title VARCHAR(190) NOT NULL,
    description TEXT NULL,
    require_customer_name TINYINT(1) NOT NULL DEFAULT 1,
    require_customer_email TINYINT(1) NOT NULL DEFAULT 1,
    require_customer_phone TINYINT(1) NOT NULL DEFAULT 1,
    collect_country TINYINT(1) NOT NULL DEFAULT 1,
    collect_city TINYINT(1) NOT NULL DEFAULT 1,
    collect_address TINYINT(1) NOT NULL DEFAULT 1,
    collect_notes TINYINT(1) NOT NULL DEFAULT 1,
    allow_mpesa TINYINT(1) NOT NULL DEFAULT 1,
    allow_emola TINYINT(1) NOT NULL DEFAULT 0,
    allow_visa TINYINT(1) NOT NULL DEFAULT 0,
    allow_paypal TINYINT(1) NOT NULL DEFAULT 0,
    status ENUM('active', 'inactive') NOT NULL DEFAULT 'active',
    view_count INT UNSIGNED NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT uq_payment_pages_slug UNIQUE (slug),
    CONSTRAINT fk_payment_pages_product FOREIGN KEY (product_id) REFERENCES products(id)
        ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT fk_payment_pages_reseller FOREIGN KEY (reseller_id) REFERENCES users(id)
        ON UPDATE CASCADE ON DELETE RESTRICT,
    INDEX idx_payment_pages_slug (slug),
    INDEX idx_payment_pages_reseller_status (reseller_id, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS orders (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    order_no VARCHAR(64) NOT NULL,
    payment_page_id BIGINT UNSIGNED NOT NULL,
    product_id BIGINT UNSIGNED NOT NULL,
    reseller_id BIGINT UNSIGNED NOT NULL,
    customer_name VARCHAR(160) NULL,
    customer_email VARCHAR(190) NULL,
    customer_phone VARCHAR(20) NOT NULL,
    customer_country VARCHAR(80) NULL,
    customer_city VARCHAR(120) NULL,
    customer_address VARCHAR(255) NULL,
    customer_notes VARCHAR(500) NULL,
    selected_gateway VARCHAR(40) NOT NULL DEFAULT 'mpesa',
    parent_order_id BIGINT UNSIGNED NULL,
    order_context ENUM('standard', 'funnel_base', 'funnel_upsell', 'funnel_downsell') NOT NULL DEFAULT 'standard',
    funnel_session_token CHAR(64) NULL,
    amount DECIMAL(14,2) NOT NULL,
    currency CHAR(3) NOT NULL DEFAULT 'MZN',
    status ENUM('pending', 'paid', 'failed', 'cancelled', 'expired') NOT NULL DEFAULT 'pending',
    idempotency_key CHAR(64) NOT NULL,
    expires_at DATETIME NULL,
    paid_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT uq_orders_order_no UNIQUE (order_no),
    CONSTRAINT uq_orders_idempotency_key UNIQUE (idempotency_key),
    CONSTRAINT fk_orders_payment_page FOREIGN KEY (payment_page_id) REFERENCES payment_pages(id)
        ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT fk_orders_product FOREIGN KEY (product_id) REFERENCES products(id)
        ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT fk_orders_reseller FOREIGN KEY (reseller_id) REFERENCES users(id)
        ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT fk_orders_parent FOREIGN KEY (parent_order_id) REFERENCES orders(id)
        ON UPDATE CASCADE ON DELETE SET NULL,
    CONSTRAINT ck_orders_amount_non_negative CHECK (amount >= 0),
    INDEX idx_orders_reseller_status_created (reseller_id, status, created_at),
    INDEX idx_orders_status_created (status, created_at),
    INDEX idx_orders_customer_phone (customer_phone),
    INDEX idx_orders_customer_email (customer_email),
    INDEX idx_orders_selected_gateway (selected_gateway),
    INDEX idx_orders_parent_order (parent_order_id),
    INDEX idx_orders_funnel_session (funnel_session_token)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS payments (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    order_id BIGINT UNSIGNED NOT NULL,
    provider VARCHAR(50) NOT NULL DEFAULT 'rozvitech',
    gateway_method VARCHAR(40) NOT NULL DEFAULT 'mpesa',
    provider_payment_id VARCHAR(120) NULL,
    provider_reference VARCHAR(120) NULL,
    amount DECIMAL(14,2) NOT NULL,
    currency CHAR(3) NOT NULL DEFAULT 'MZN',
    status ENUM('initiated', 'processing', 'confirmed', 'failed', 'timeout') NOT NULL DEFAULT 'initiated',
    idempotency_key CHAR(64) NOT NULL,
    request_payload JSON NOT NULL,
    response_payload JSON NOT NULL,
    callback_received_at DATETIME NULL,
    next_poll_at DATETIME NULL,
    retry_count INT UNSIGNED NOT NULL DEFAULT 0,
    last_error TEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT uq_payments_order_id UNIQUE (order_id),
    CONSTRAINT uq_payments_idempotency_key UNIQUE (idempotency_key),
    CONSTRAINT fk_payments_order FOREIGN KEY (order_id) REFERENCES orders(id)
        ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT ck_payments_amount_non_negative CHECK (amount >= 0),
    INDEX idx_payments_order_status (order_id, status),
    INDEX idx_payments_gateway_status (gateway_method, status),
    INDEX idx_payments_provider_reference (provider_reference),
    INDEX idx_payments_next_poll_at (next_poll_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS payment_gateways (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    code VARCHAR(40) NOT NULL,
    display_name VARCHAR(80) NOT NULL,
    description VARCHAR(255) NULL,
    icon_class VARCHAR(80) NOT NULL DEFAULT 'bi-credit-card',
    is_enabled TINYINT(1) NOT NULL DEFAULT 1,
    is_configured TINYINT(1) NOT NULL DEFAULT 0,
    is_live TINYINT(1) NOT NULL DEFAULT 0,
    sort_order INT UNSIGNED NOT NULL DEFAULT 0,
    settings_json JSON NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT uq_payment_gateways_code UNIQUE (code),
    INDEX idx_payment_gateways_enabled_sort (is_enabled, sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS commissions (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    order_id BIGINT UNSIGNED NOT NULL,
    reseller_id BIGINT UNSIGNED NOT NULL,
    gross_amount DECIMAL(14,2) NOT NULL,
    platform_fee DECIMAL(14,2) NOT NULL,
    reseller_earning DECIMAL(14,2) NOT NULL,
    currency CHAR(3) NOT NULL DEFAULT 'MZN',
    status ENUM('pending', 'paid', 'failed') NOT NULL DEFAULT 'pending',
    settlement_status ENUM('pending', 'settled') NOT NULL DEFAULT 'pending',
    settled_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT uq_commissions_order_id UNIQUE (order_id),
    CONSTRAINT fk_commissions_order FOREIGN KEY (order_id) REFERENCES orders(id)
        ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT fk_commissions_reseller FOREIGN KEY (reseller_id) REFERENCES users(id)
        ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT ck_commissions_non_negative CHECK (
        gross_amount >= 0 AND platform_fee >= 0 AND reseller_earning >= 0
    ),
    INDEX idx_commissions_reseller_settlement (reseller_id, settlement_status),
    INDEX idx_commissions_status_created (status, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS wallets (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    currency CHAR(3) NOT NULL DEFAULT 'MZN',
    balance_available DECIMAL(16,2) NOT NULL DEFAULT 0.00,
    balance_pending DECIMAL(16,2) NOT NULL DEFAULT 0.00,
    balance_total DECIMAL(16,2) NOT NULL DEFAULT 0.00,
    version INT UNSIGNED NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT uq_wallets_user_currency UNIQUE (user_id, currency),
    CONSTRAINT fk_wallets_user FOREIGN KEY (user_id) REFERENCES users(id)
        ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT ck_wallets_balances_non_negative CHECK (
        balance_available >= 0 AND balance_pending >= 0 AND balance_total >= 0
    ),
    INDEX idx_wallets_currency_available (currency, balance_available)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS wallet_transactions (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    wallet_id BIGINT UNSIGNED NOT NULL,
    user_id BIGINT UNSIGNED NOT NULL,
    type ENUM('credit', 'debit') NOT NULL,
    source ENUM('sale', 'adjustment', 'payout', 'reversal', 'fee') NOT NULL,
    amount DECIMAL(14,2) NOT NULL,
    currency CHAR(3) NOT NULL DEFAULT 'MZN',
    reference_type ENUM('order', 'commission', 'payout', 'manual', 'payment') NOT NULL,
    reference_id BIGINT UNSIGNED NOT NULL,
    status ENUM('pending', 'available', 'failed', 'reversed') NOT NULL DEFAULT 'pending',
    description VARCHAR(255) NULL,
    metadata JSON NULL,
    occurred_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_wallet_transactions_wallet FOREIGN KEY (wallet_id) REFERENCES wallets(id)
        ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT fk_wallet_transactions_user FOREIGN KEY (user_id) REFERENCES users(id)
        ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT ck_wallet_transactions_amount_positive CHECK (amount > 0),
    INDEX idx_wallet_transactions_user_status_occ (user_id, status, occurred_at),
    INDEX idx_wallet_transactions_wallet_occ (wallet_id, occurred_at),
    INDEX idx_wallet_transactions_reference (reference_type, reference_id),
    INDEX idx_wallet_transactions_user_reference (user_id, reference_type, reference_id, type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS downloads (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    order_id BIGINT UNSIGNED NOT NULL,
    product_id BIGINT UNSIGNED NOT NULL,
    token CHAR(64) NOT NULL,
    delivery_mode ENUM('file', 'redirect') NOT NULL DEFAULT 'redirect',
    target_path VARCHAR(600) NULL,
    target_url VARCHAR(2048) NULL,
    expires_at DATETIME NULL,
    max_downloads INT UNSIGNED NOT NULL DEFAULT 5,
    download_count INT UNSIGNED NOT NULL DEFAULT 0,
    status ENUM('active', 'expired', 'revoked') NOT NULL DEFAULT 'active',
    last_download_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT uq_downloads_token UNIQUE (token),
    CONSTRAINT fk_downloads_order FOREIGN KEY (order_id) REFERENCES orders(id)
        ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT fk_downloads_product FOREIGN KEY (product_id) REFERENCES products(id)
        ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT ck_downloads_limits CHECK (max_downloads >= 1 AND download_count >= 0),
    INDEX idx_downloads_token_status (token, status),
    INDEX idx_downloads_order_status (order_id, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS funnels (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    reseller_id BIGINT UNSIGNED NOT NULL,
    name VARCHAR(180) NOT NULL,
    slug VARCHAR(190) NOT NULL,
    description TEXT NULL,
    status ENUM('active', 'inactive') NOT NULL DEFAULT 'active',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT uq_funnels_slug UNIQUE (slug),
    CONSTRAINT fk_funnels_reseller FOREIGN KEY (reseller_id) REFERENCES users(id)
        ON UPDATE CASCADE ON DELETE CASCADE,
    INDEX idx_funnels_reseller_status (reseller_id, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS funnel_steps (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    funnel_id BIGINT UNSIGNED NOT NULL,
    step_type ENUM('landing', 'checkout', 'confirmation', 'upsell', 'downsell', 'thank_you') NOT NULL,
    title VARCHAR(190) NOT NULL,
    description TEXT NULL,
    payment_page_id BIGINT UNSIGNED NULL,
    product_id BIGINT UNSIGNED NULL,
    sequence_no INT UNSIGNED NOT NULL DEFAULT 1,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    accept_label VARCHAR(90) NULL,
    reject_label VARCHAR(90) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_funnel_steps_funnel FOREIGN KEY (funnel_id) REFERENCES funnels(id)
        ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT fk_funnel_steps_payment_page FOREIGN KEY (payment_page_id) REFERENCES payment_pages(id)
        ON UPDATE CASCADE ON DELETE SET NULL,
    CONSTRAINT fk_funnel_steps_product FOREIGN KEY (product_id) REFERENCES products(id)
        ON UPDATE CASCADE ON DELETE SET NULL,
    INDEX idx_funnel_steps_order (funnel_id, sequence_no, is_active),
    INDEX idx_funnel_steps_type (funnel_id, step_type, is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS funnel_sessions (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    token CHAR(64) NOT NULL,
    funnel_id BIGINT UNSIGNED NOT NULL,
    current_step_id BIGINT UNSIGNED NULL,
    customer_name VARCHAR(160) NULL,
    customer_email VARCHAR(190) NULL,
    customer_phone VARCHAR(20) NULL,
    base_order_id BIGINT UNSIGNED NULL,
    last_order_id BIGINT UNSIGNED NULL,
    status ENUM('active', 'completed', 'expired') NOT NULL DEFAULT 'active',
    metadata JSON NULL,
    expires_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT uq_funnel_sessions_token UNIQUE (token),
    CONSTRAINT fk_funnel_sessions_funnel FOREIGN KEY (funnel_id) REFERENCES funnels(id)
        ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT fk_funnel_sessions_step FOREIGN KEY (current_step_id) REFERENCES funnel_steps(id)
        ON UPDATE CASCADE ON DELETE SET NULL,
    CONSTRAINT fk_funnel_sessions_base_order FOREIGN KEY (base_order_id) REFERENCES orders(id)
        ON UPDATE CASCADE ON DELETE SET NULL,
    CONSTRAINT fk_funnel_sessions_last_order FOREIGN KEY (last_order_id) REFERENCES orders(id)
        ON UPDATE CASCADE ON DELETE SET NULL,
    INDEX idx_funnel_sessions_funnel_status (funnel_id, status),
    INDEX idx_funnel_sessions_expiry (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS funnel_orders (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    funnel_session_id BIGINT UNSIGNED NOT NULL,
    funnel_step_id BIGINT UNSIGNED NULL,
    order_id BIGINT UNSIGNED NOT NULL,
    offer_type ENUM('base', 'upsell', 'downsell') NOT NULL DEFAULT 'base',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_funnel_orders_session FOREIGN KEY (funnel_session_id) REFERENCES funnel_sessions(id)
        ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT fk_funnel_orders_step FOREIGN KEY (funnel_step_id) REFERENCES funnel_steps(id)
        ON UPDATE CASCADE ON DELETE SET NULL,
    CONSTRAINT fk_funnel_orders_order FOREIGN KEY (order_id) REFERENCES orders(id)
        ON UPDATE CASCADE ON DELETE CASCADE,
    INDEX idx_funnel_orders_session (funnel_session_id, created_at),
    INDEX idx_funnel_orders_order (order_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS email_templates (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    reseller_id BIGINT UNSIGNED NULL,
    template_type ENUM('purchase_confirmation', 'product_access', 'upsell_offer') NOT NULL,
    subject VARCHAR(255) NOT NULL,
    body_html LONGTEXT NOT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_email_templates_reseller FOREIGN KEY (reseller_id) REFERENCES users(id)
        ON UPDATE CASCADE ON DELETE CASCADE,
    INDEX idx_email_templates_scope (reseller_id, template_type, is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS email_logs (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    reseller_id BIGINT UNSIGNED NULL,
    order_id BIGINT UNSIGNED NULL,
    recipient_email VARCHAR(190) NOT NULL,
    template_type ENUM('purchase_confirmation', 'product_access', 'upsell_offer') NOT NULL,
    subject VARCHAR(255) NOT NULL,
    body_html LONGTEXT NOT NULL,
    status ENUM('sent', 'failed') NOT NULL DEFAULT 'failed',
    provider_message TEXT NULL,
    error_message TEXT NULL,
    attempt_count INT UNSIGNED NOT NULL DEFAULT 1,
    sent_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_email_logs_reseller FOREIGN KEY (reseller_id) REFERENCES users(id)
        ON UPDATE CASCADE ON DELETE SET NULL,
    CONSTRAINT fk_email_logs_order FOREIGN KEY (order_id) REFERENCES orders(id)
        ON UPDATE CASCADE ON DELETE SET NULL,
    INDEX idx_email_logs_reseller_created (reseller_id, created_at),
    INDEX idx_email_logs_order_created (order_id, created_at),
    INDEX idx_email_logs_status (status, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS user_settings (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    avatar_path VARCHAR(500) NULL,
    theme_preference ENUM('system', 'light', 'dark') NOT NULL DEFAULT 'system',
    language VARCHAR(12) NOT NULL DEFAULT 'pt-MZ',
    timezone VARCHAR(64) NOT NULL DEFAULT 'Africa/Maputo',
    notify_sales TINYINT(1) NOT NULL DEFAULT 1,
    notify_payment_errors TINYINT(1) NOT NULL DEFAULT 1,
    notify_security TINYINT(1) NOT NULL DEFAULT 1,
    notify_system TINYINT(1) NOT NULL DEFAULT 1,
    email_reports TINYINT(1) NOT NULL DEFAULT 1,
    email_marketing TINYINT(1) NOT NULL DEFAULT 0,
    dashboard_show_charts TINYINT(1) NOT NULL DEFAULT 1,
    dashboard_show_kpis TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT uq_user_settings_user UNIQUE (user_id),
    CONSTRAINT fk_user_settings_user FOREIGN KEY (user_id) REFERENCES users(id)
        ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS notification_reads (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    notification_key VARCHAR(120) NOT NULL,
    read_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT uq_notification_reads_key UNIQUE (user_id, notification_key),
    CONSTRAINT fk_notification_reads_user FOREIGN KEY (user_id) REFERENCES users(id)
        ON UPDATE CASCADE ON DELETE CASCADE,
    INDEX idx_notification_reads_user_read_at (user_id, read_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS api_logs (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    service VARCHAR(80) NOT NULL,
    endpoint VARCHAR(255) NOT NULL,
    method VARCHAR(10) NOT NULL,
    request_headers JSON NULL,
    request_body JSON NULL,
    response_status SMALLINT UNSIGNED NULL,
    response_body JSON NULL,
    latency_ms INT UNSIGNED NULL,
    correlation_id VARCHAR(120) NULL,
    idempotency_key CHAR(64) NULL,
    order_id BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_api_logs_order FOREIGN KEY (order_id) REFERENCES orders(id)
        ON UPDATE CASCADE ON DELETE SET NULL,
    INDEX idx_api_logs_service_created (service, created_at),
    INDEX idx_api_logs_correlation_id (correlation_id),
    INDEX idx_api_logs_idempotency_key (idempotency_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS audit_logs (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    actor_user_id BIGINT UNSIGNED NULL,
    actor_role VARCHAR(40) NULL,
    action VARCHAR(120) NOT NULL,
    entity_type VARCHAR(80) NOT NULL,
    entity_id BIGINT UNSIGNED NULL,
    old_values JSON NULL,
    new_values JSON NULL,
    ip_address VARCHAR(45) NULL,
    user_agent VARCHAR(255) NULL,
    request_id VARCHAR(64) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_audit_logs_actor_user FOREIGN KEY (actor_user_id) REFERENCES users(id)
        ON UPDATE CASCADE ON DELETE SET NULL,
    INDEX idx_audit_logs_actor_created (actor_user_id, created_at),
    INDEX idx_audit_logs_entity (entity_type, entity_id),
    INDEX idx_audit_logs_action (action)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

