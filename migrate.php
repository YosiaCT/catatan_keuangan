<?php
require_once __DIR__ . '/config/auth.php';

try {
    $pdo->exec("ALTER TABLE transactions MODIFY category_type VARCHAR(50) NOT NULL DEFAULT 'business'");
    $pdo->exec("ALTER TABLE transactions MODIFY type ENUM('income','expense','debt','receivable','savings') NOT NULL");
    $categoryColumn = $pdo->query("SHOW COLUMNS FROM transactions LIKE 'transaction_category'")->fetch();
    if (!$categoryColumn) {
        $pdo->exec('ALTER TABLE transactions ADD transaction_category VARCHAR(100) NULL AFTER type');
    }
    $loanColumn = $pdo->query("SHOW COLUMNS FROM transactions LIKE 'loan_id'")->fetch();
    if (!$loanColumn) {
        $pdo->exec('ALTER TABLE transactions ADD loan_id BIGINT UNSIGNED NULL AFTER transaction_category');
    }
    $loanIndex = $pdo->query("SHOW INDEX FROM transactions WHERE Key_name='idx_loan_id'")->fetch();
    if (!$loanIndex) {
        $pdo->exec('ALTER TABLE transactions ADD INDEX idx_loan_id(loan_id)');
    }
    $fundingSourceColumn = $pdo->query("SHOW COLUMNS FROM transactions LIKE 'funding_source'")->fetch();
    if (!$fundingSourceColumn) {
        $pdo->exec('ALTER TABLE transactions ADD funding_source VARCHAR(255) NULL AFTER person_name');
    }
    $pdo->exec("UPDATE transactions SET transaction_category=CASE WHEN type='expense' AND description LIKE 'Pembelian stok:%' THEN 'Pembelian' ELSE 'Lainnya' END WHERE transaction_category IS NULL AND type IN ('income','expense')");
    $pdo->exec("UPDATE transactions SET transaction_category=CASE WHEN type='expense' AND description LIKE 'Pembelian stok:%' THEN 'Pembelian' WHEN type='expense' AND description LIKE 'Pelunasan hutang:%' THEN 'Pembayaran Hutang' WHEN type='income' AND description LIKE 'Penerimaan piutang:%' THEN 'Penerimaan Piutang' END WHERE transaction_category='Lainnya' AND ((type='expense' AND (description LIKE 'Pembelian stok:%' OR description LIKE 'Pelunasan hutang:%')) OR (type='income' AND description LIKE 'Penerimaan piutang:%'))");
    echo "Modified transactions table.\n";
} catch (Exception $e) {
    echo "Error modifying transactions: " . $e->getMessage() . "\n";
}

try {
    $pdo->exec("ALTER TABLE stocks MODIFY category_type VARCHAR(50) NOT NULL DEFAULT 'business'");
    echo "Modified stocks table.\n";
} catch (Exception $e) {
    echo "Error modifying stocks: " . $e->getMessage() . "\n";
}

try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS user_categories (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        user_id INT UNSIGNED NOT NULL,
        slug VARCHAR(50) NOT NULL,
        name VARCHAR(100) NOT NULL,
        icon VARCHAR(50) NULL,
        color VARCHAR(20) NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY unique_user_category(user_id, slug),
        CONSTRAINT fk_categories_user FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE
    )");
    echo "Created user_categories table.\n";
} catch (Exception $e) {
    echo "Error creating user_categories: " . $e->getMessage() . "\n";
}

try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS personal_budgets (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        user_id INT UNSIGNED NOT NULL,
        month CHAR(7) NOT NULL,
        expense_category VARCHAR(100) NOT NULL,
        amount DECIMAL(15,2) NOT NULL DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY unique_personal_budget(user_id, month, expense_category),
        CONSTRAINT fk_personal_budgets_user FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE
    )");
    echo "Created personal_budgets table.\n";
} catch (Exception $e) {
    echo "Error creating personal_budgets: " . $e->getMessage() . "\n";
}

echo "Done.\n";
