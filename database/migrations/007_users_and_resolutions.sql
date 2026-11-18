-- Additive, re-runnable. Staff/admin accounts and staff resolution of orders
-- that need review. Existing orders, payments and stock rows are not changed.
CREATE TABLE IF NOT EXISTS staff_users (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(80) NOT NULL,
    display_name VARCHAR(120) NOT NULL,
    role ENUM('admin', 'staff') NOT NULL DEFAULT 'staff',
    password_hash VARCHAR(255) NOT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    -- One active sign-in per user: a new sign-in replaces this hash and ends
    -- the previous session. Password resets/deactivation clear it.
    session_token_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
    created_by VARCHAR(80) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    last_login_at DATETIME NULL,
    UNIQUE KEY uq_staff_users_username (username),
    CONSTRAINT chk_staff_users_active CHECK (is_active IN (0, 1))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- What staff physically did about an order that stopped for review. One per order.
CREATE TABLE IF NOT EXISTS order_resolutions (
    event_id CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL PRIMARY KEY,
    transaction_id BIGINT UNSIGNED NOT NULL,
    action ENUM('refunded', 'handed_over', 'closed') NOT NULL,
    amount_paid_centavos INT UNSIGNED NOT NULL,
    refunded_centavos INT UNSIGNED NOT NULL DEFAULT 0,
    units_handed_over INT UNSIGNED NOT NULL DEFAULT 0,
    previous_order_status VARCHAR(24) NOT NULL,
    previous_payment_status VARCHAR(24) NOT NULL,
    previous_dispensing_status VARCHAR(24) NOT NULL,
    note VARCHAR(240) NOT NULL,
    staff_user VARCHAR(80) NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_order_resolutions_transaction (transaction_id),
    KEY idx_order_resolutions_created (created_at),
    CONSTRAINT fk_order_resolutions_transaction FOREIGN KEY (transaction_id) REFERENCES transactions (id)
        ON UPDATE RESTRICT ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE hardware_unassigned_coins
    ADD COLUMN IF NOT EXISTS resolution ENUM('refunded', 'closed') NULL AFTER resolved_by;
