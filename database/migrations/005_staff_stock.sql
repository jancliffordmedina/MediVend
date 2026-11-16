-- Additive restocking history. Existing stock/order/payment rows are retained.
CREATE TABLE IF NOT EXISTS staff_stock_events (
    event_id CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL PRIMARY KEY,
    product_id INT UNSIGNED NOT NULL,
    product_name VARCHAR(120) NOT NULL,
    sku VARCHAR(40) NOT NULL,
    compartment_number SMALLINT UNSIGNED NOT NULL,
    mode ENUM('simulation', 'hardware') NOT NULL,
    quantity INT UNSIGNED NOT NULL,
    stock_before INT UNSIGNED NOT NULL,
    stock_after INT UNSIGNED NOT NULL,
    note VARCHAR(240) NOT NULL,
    staff_user VARCHAR(80) NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_staff_stock_history (mode, created_at),
    KEY idx_staff_stock_product (product_id, created_at),
    CONSTRAINT fk_staff_stock_product FOREIGN KEY (product_id) REFERENCES products(id)
        ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT chk_staff_stock_quantity CHECK (quantity BETWEEN 1 AND 10000),
    CONSTRAINT chk_staff_stock_amount CHECK (stock_after = stock_before + quantity)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
