-- Additive, re-runnable. Accepted coins the worker cannot bind to an eligible
-- hardware order (no active session, coin observed before the order existed,
-- unknown or non-hardware order). Value is retained in full and blocks the
-- machine until staff record a resolution. Nothing here is a refund record.
CREATE TABLE IF NOT EXISTS hardware_unassigned_coins (
    event_id CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL PRIMARY KEY,
    coin_centavos INT UNSIGNED NOT NULL,
    reason ENUM('no_active_order','before_order','order_not_found','not_hardware_order') NOT NULL,
    candidate_transaction_id BIGINT UNSIGNED NULL,
    observed_at DATETIME NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    resolved_at DATETIME NULL,
    resolved_by VARCHAR(80) NULL,
    resolution_note VARCHAR(240) NULL,
    KEY idx_unassigned_open (resolved_at, created_at),
    CONSTRAINT fk_unassigned_candidate FOREIGN KEY (candidate_transaction_id) REFERENCES transactions (id)
        ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT chk_unassigned_denomination CHECK (coin_centavos IN (100, 500, 1000, 2000))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
