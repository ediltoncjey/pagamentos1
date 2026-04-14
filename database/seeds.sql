USE sistem_pay;

INSERT INTO roles (name, created_at, updated_at)
VALUES
    ('admin', CURRENT_TIMESTAMP, CURRENT_TIMESTAMP),
    ('reseller', CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
ON DUPLICATE KEY UPDATE
    updated_at = VALUES(updated_at);

INSERT INTO payment_gateways (
    code, display_name, description, icon_class,
    is_enabled, is_configured, is_live, sort_order, settings_json
)
VALUES
    ('mpesa', 'M-Pesa', 'Pagamento M-Pesa via gateway Rozvitech', 'bi-phone', 1, 1, 1, 1, JSON_OBJECT('provider', 'rozvitech')),
    ('emola', 'e-Mola', 'Gateway preparado para integracao futura', 'bi-wallet2', 0, 0, 0, 2, JSON_OBJECT('provider', 'emola')),
    ('visa', 'Visa', 'Gateway preparado para integracao futura', 'bi-credit-card-2-front', 0, 0, 0, 3, JSON_OBJECT('provider', 'visa')),
    ('paypal', 'PayPal', 'Gateway preparado para integracao futura', 'bi-paypal', 0, 0, 0, 4, JSON_OBJECT('provider', 'paypal'))
ON DUPLICATE KEY UPDATE
    display_name = VALUES(display_name),
    description = VALUES(description),
    icon_class = VALUES(icon_class),
    is_enabled = VALUES(is_enabled),
    is_configured = VALUES(is_configured),
    is_live = VALUES(is_live),
    sort_order = VALUES(sort_order),
    settings_json = VALUES(settings_json),
    updated_at = CURRENT_TIMESTAMP;

-- Default admin user (temporary password: ChangeMe@123)
-- Alterar password imediatamente em produção.
INSERT INTO users (uuid, role_id, name, email, phone, password_hash, status, created_at, updated_at)
SELECT
    '00000000-0000-4000-8000-000000000001',
    r.id,
    'System Admin',
    'admin@sistempay.local',
    NULL,
    '$2y$12$q0ktcv9c2490CuCSIS1n2OMJbOLhG/QpQ7CdlIJUAdarkcHOBCWzC',
    'active',
    CURRENT_TIMESTAMP,
    CURRENT_TIMESTAMP
FROM roles r
WHERE r.name = 'admin'
ON DUPLICATE KEY UPDATE
    updated_at = CURRENT_TIMESTAMP;

INSERT INTO user_settings (
    user_id, theme_preference, language, timezone,
    notify_sales, notify_payment_errors, notify_security, notify_system,
    email_reports, email_marketing, dashboard_show_charts, dashboard_show_kpis,
    created_at, updated_at
)
SELECT
    u.id, 'system', 'pt-MZ', 'Africa/Maputo',
    1, 1, 1, 1,
    1, 0, 1, 1,
    CURRENT_TIMESTAMP, CURRENT_TIMESTAMP
FROM users u
ON DUPLICATE KEY UPDATE
    updated_at = CURRENT_TIMESTAMP;
