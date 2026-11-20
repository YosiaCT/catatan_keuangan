CREATE DATABASE IF NOT EXISTS catatan_keuangan CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE catatan_keuangan;

CREATE TABLE IF NOT EXISTS users (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50) NOT NULL UNIQUE,
    password VARCHAR(255) NOT NULL,
    full_name VARCHAR(100) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS transactions (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    category_type VARCHAR(50) NOT NULL DEFAULT 'business',
    type ENUM('income','expense','debt','receivable','savings') NOT NULL,
    transaction_category VARCHAR(100) NULL,
    loan_id BIGINT UNSIGNED NULL,
    transaction_date DATE NOT NULL,
    description VARCHAR(255) NOT NULL,
    amount DECIMAL(15,2) NOT NULL DEFAULT 0,
    person_name VARCHAR(100) NULL,
    funding_source VARCHAR(255) NULL,
    due_date DATE NULL,
    status ENUM('unpaid','paid') NOT NULL DEFAULT 'paid',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_user_category_date(user_id, category_type, transaction_date),
    INDEX idx_type_status(type,status),
    INDEX idx_loan_id(loan_id),
    CONSTRAINT fk_transactions_user FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS stocks (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    category_type VARCHAR(50) NOT NULL DEFAULT 'business',
    item_name VARCHAR(120) NOT NULL,
    stock_qty INT NOT NULL DEFAULT 0,
    unit VARCHAR(30) NOT NULL DEFAULT 'pcs',
    buy_price DECIMAL(15,2) NOT NULL DEFAULT 0,
    sell_price DECIMAL(15,2) NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY unique_stock(user_id,category_type,item_name),
    CONSTRAINT fk_stocks_user FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS user_categories (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    slug VARCHAR(50) NOT NULL,
    name VARCHAR(100) NOT NULL,
    icon VARCHAR(50) NULL,
    color VARCHAR(20) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY unique_user_category(user_id, slug),
    CONSTRAINT fk_categories_user FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS settings (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL UNIQUE,
    monthly_target DECIMAL(15,2) NOT NULL DEFAULT 0,
    currency VARCHAR(10) NOT NULL DEFAULT 'IDR',
    CONSTRAINT fk_settings_user FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS personal_budgets (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    month CHAR(7) NOT NULL,
    expense_category VARCHAR(100) NOT NULL,
    amount DECIMAL(15,2) NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY unique_personal_budget(user_id, month, expense_category),
    CONSTRAINT fk_personal_budgets_user FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE
);
