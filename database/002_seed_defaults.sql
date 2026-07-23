-- ============================================================
-- OURCR ONLINE - Default Seed Data
-- ============================================================

USE `ourcr_online`;

-- ─── Default Site Settings ────────────────────────────────────────────────────
INSERT INTO `site_settings` (`setting_key`, `setting_value`, `setting_group`, `label`) VALUES
('site_name',           'OURCR ONLINE',         'general',  'Site Name'),
('site_tagline',        'Powering Digital Transactions Across Nigeria', 'general', 'Tagline'),
('site_email',          'support@ourcr.online', 'general',  'Support Email'),
('site_phone',          '+234 800 000 0000',    'general',  'Support Phone'),
('site_color',          '#DC2626',              'general',  'Brand Color'),
('maintenance_mode',    '0',                    'general',  'Maintenance Mode'),
('allow_registration',  '1',                    'general',  'Allow Registration'),
('email_verification',  '1',                    'general',  'Email Verification Required'),
('phone_verification',  '0',                    'general',  'Phone Verification Required'),
('referral_bonus',      '200',                  'referral', 'Referral Bonus Amount'),
('referral_enabled',    '1',                    'referral', 'Referrals Enabled'),
('min_deposit',         '100',                  'wallet',   'Minimum Deposit'),
('max_deposit',         '5000000',              'wallet',   'Maximum Deposit'),
('min_withdrawal',      '500',                  'wallet',   'Minimum Withdrawal'),
('max_withdrawal',      '500000',               'wallet',   'Maximum Withdrawal'),
('withdrawal_fee',      '1.5',                  'wallet',   'Withdrawal Fee'),
('transfer_fee',        '0',                    'wallet',   'Transfer Fee'),
('min_transfer',        '100',                  'wallet',   'Minimum Transfer'),
('max_transfer',        '1000000',              'wallet',   'Maximum Transfer'),
('company_bank_name',   'Accelerex Bank',       'payment',  'Company Bank Name'),
('company_account_name','OURCR ONLINE',         'payment',  'Company Account Name'),
('company_account_no',  '0000000000',           'payment',  'Company Account Number'),
('vtpass_enabled',      '1',                    'api',      'VTpass Enabled'),
('gaps_enabled',        '1',                    'api',      'GAPS Enabled'),
('airtime_enabled',     '1',                    'services', 'Airtime Service'),
('data_enabled',        '1',                    'services', 'Data Service'),
('cable_enabled',       '1',                    'services', 'Cable TV Service'),
('electricity_enabled', '1',                    'services', 'Electricity Service'),
('betting_enabled',     '1',                    'services', 'Betting Service'),
('exam_enabled',        '1',                    'services', 'Exam Pins Service')
ON DUPLICATE KEY UPDATE `setting_key` = `setting_key`;

-- ─── Default Admin Roles ─────────────────────────────────────────────────────
INSERT INTO `admin_roles` (`name`, `slug`, `description`) VALUES
('Super Admin', 'superadmin', 'Full unrestricted access'),
('Admin',       'admin',      'Standard admin access'),
('Support',     'support',    'Support team access')
ON DUPLICATE KEY UPDATE `name` = VALUES(`name`);

-- ─── Default Permissions ─────────────────────────────────────────────────────
INSERT INTO `admin_permissions` (`name`, `slug`, `group`) VALUES
('View Dashboard',      'dashboard.view',       'dashboard'),
('View Users',          'users.view',           'users'),
('Edit Users',          'users.edit',           'users'),
('Delete Users',        'users.delete',         'users'),
('Suspend Users',       'users.suspend',        'users'),
('View Transactions',   'transactions.view',    'transactions'),
('Reverse Transaction', 'transactions.reverse', 'transactions'),
('View Wallets',        'wallets.view',         'wallets'),
('Credit Wallet',       'wallets.credit',       'wallets'),
('Debit Wallet',        'wallets.debit',        'wallets'),
('View Deposits',       'deposits.view',        'deposits'),
('Reconcile Deposits',  'deposits.reconcile',   'deposits'),
('View Withdrawals',    'withdrawals.view',     'withdrawals'),
('Process Withdrawals', 'withdrawals.process',  'withdrawals'),
('View Reports',        'reports.view',         'reports'),
('Export Reports',      'reports.export',       'reports'),
('Manage Settings',     'settings.manage',      'settings'),
('Manage API Keys',     'api.manage',           'settings'),
('View Logs',           'logs.view',            'logs'),
('Manage Announcements','announcements.manage', 'announcements'),
('Manage Coupons',      'coupons.manage',       'coupons'),
('View Support',        'support.view',         'support'),
('Reply Support',       'support.reply',        'support')
ON DUPLICATE KEY UPDATE `name` = VALUES(`name`);

-- ─── Super Admin gets all permissions (via role slug, handled in code) ────────

-- ─── Default Cron Jobs ───────────────────────────────────────────────────────
INSERT INTO `cron_jobs` (`name`, `status`) VALUES
('gaps_statement_fetch',    'idle'),
('deposit_reconciliation',  'idle'),
('vtpass_retry',            'idle'),
('notification_dispatch',   'idle'),
('log_cleanup',             'idle')
ON DUPLICATE KEY UPDATE `status` = VALUES(`status`);

-- ─── Default Superadmin User ─────────────────────────────────────────────────
-- Password: 6202@rcruonimda
INSERT INTO `users` (
    `uuid`, `username`, `email`, `phone`,
    `password_hash`, `first_name`, `last_name`,
    `role`, `status`, `email_verified_at`, `referral_code`, `wallet_balance`
) VALUES (
    UUID(), 'superadmin', 'admin@ourcr.online', '08000000000',
    '$2y$12$pEgO2O423GIHy.xuK7xpPO4QQSJsaOgg/C6il2mbTq5hK8iX3AuSS',
    'Super', 'Admin',
    'superadmin', 'active', NOW(), 'SADMIN00', 0.00
) ON DUPLICATE KEY UPDATE `role` = 'superadmin';

-- ─── Admin User: Collins ─────────────────────────────────────────────────────
-- Password: @Seaner22  |  Role: admin  |  Full access except managing admins
INSERT INTO `users` (
    `uuid`, `username`, `email`, `phone`,
    `password_hash`, `first_name`, `last_name`,
    `role`, `status`, `email_verified_at`, `referral_code`, `wallet_balance`
) VALUES (
    UUID(), 'collins', 'collins@ourcr.online', '08000000001',
    '$2y$12$pTiC3OpLM00opsyL9nunUuuwLCkn6HcmRW6zw0Z/pQUNblFTewLe.',
    'Collins', 'Admin',
    'admin', 'active', NOW(), 'CADMIN01', 0.00
) ON DUPLICATE KEY UPDATE
    `password_hash` = VALUES(`password_hash`),
    `role`          = 'admin',
    `status`        = 'active';

-- ─── Referral Bonus Percentage setting ───────────────────────────────────────
-- Percentage of the referred user's service discount that goes to the referrer
INSERT INTO `site_settings` (`setting_key`, `setting_value`, `setting_group`, `label`) VALUES
('referral_bonus_percentage', '10', 'referral', 'Ongoing Discount Bonus (%)'),
('vtu_commission_admin_share', '34', 'wallet', 'Admin VTU Commission Share (%)'),
('vtu_commission_user_share', '66', 'wallet', 'User VTU Commission Share (%)')
ON DUPLICATE KEY UPDATE `setting_key` = `setting_key`;
