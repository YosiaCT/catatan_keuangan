<?php
declare(strict_types=1);

const LENDER_CAPITAL_CATEGORY = 'Tambahan Modal';

function e(?string $value): string {
    return htmlspecialchars($value ?? '', ENT_QUOTES, 'UTF-8');
}

function rupiah(float|int $value): string {
    return 'Rp ' . number_format((float)$value, 0, ',', '.');
}

function parse_currency_amount(mixed $value): ?float {
    if (!is_string($value) && !is_numeric($value)) {
        return null;
    }

    $value=(string)$value;
    $value = preg_replace('/[\sRp]/iu', '', trim($value));
    if ($value === '') {
        return null;
    }

    if (str_contains($value, ',')) {
        $value = str_replace('.', '', $value);
        $value = str_replace(',', '.', $value);
    } elseif (substr_count($value, '.') > 1 || !preg_match('/\.\d{1,2}$/', $value)) {
        $value = str_replace('.', '', $value);
    }

    if (!preg_match('/^\d+(?:\.\d{1,2})?$/', $value)) {
        return null;
    }

    return (float)$value;
}

function csrf_token(): string {
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function verify_csrf(): void {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $token = $_POST['csrf'] ?? '';
        if (!hash_equals($_SESSION['csrf'] ?? '', $token)) {
            http_response_code(419);
            exit('CSRF token tidak valid.');
        }
    }
}

function flash(string $key, ?string $message = null): ?string {
    if ($message !== null) {
        $_SESSION['_flash'][$key] = $message;
        return null;
    }
    $msg = $_SESSION['_flash'][$key] ?? null;
    unset($_SESSION['_flash'][$key]);
    return $msg;
}

function selected_category(): string {
    global $pdo;
    $allowed = ['business', 'personal', 'lender'];
    $category = $_SESSION['category'] ?? 'business';
    if (in_array($category, $allowed, true)) {
        return $category;
    }

    if (!empty($_SESSION['user_id'])) {
        $stmt = $pdo->prepare('SELECT slug FROM user_categories WHERE user_id = ? AND slug = ?');
        $stmt->execute([(int)$_SESSION['user_id'], $category]);
        if ($stmt->fetchColumn()) {
            return $category;
        }
    }

    return 'business';
}

function category_label(string $category): string {
    $labels = [
        'business' => 'Bisnis',
        'personal' => 'Catatan Pribadi',
        'lender' => 'Pemberi Pinjaman',
    ];
    if (isset($labels[$category])) {
        return $labels[$category];
    }

    global $pdo;
    if (!empty($_SESSION['user_id'])) {
        $stmt = $pdo->prepare('SELECT name FROM user_categories WHERE user_id = ? AND slug = ?');
        $stmt->execute([(int)$_SESSION['user_id'], $category]);
        $name = $stmt->fetchColumn();
        if ($name !== false) {
            return (string)$name;
        }
    }

    return 'Bisnis';
}

function transaction_category_options(string $category): array {
    $options = [
        'business' => [
            'income' => ['Penjualan','Jasa','Pendapatan Usaha','Penerimaan Piutang','Lainnya'],
            'expense' => ['Pembelian','Operasional','Gaji Karyawan','Transportasi','Tagihan','Pembayaran Hutang','Lainnya'],
        ],
        'personal' => [
            'income' => ['Gaji','Bonus','Usaha Sampingan','Investasi','Hadiah','Lainnya'],
            'expense' => ['Makanan','Transportasi','Tagihan','Belanja','Kesehatan','Pendidikan','Pembayaran Hutang','Lainnya'],
        ],
        'lender' => [
            'income' => [LENDER_CAPITAL_CATEGORY,'Pengembalian Pinjaman','Bunga Pinjaman','Penerimaan Piutang','Pendapatan Lainnya','Lainnya'],
            'expense' => ['Biaya Operasional','Biaya Administrasi','Pembayaran Hutang','Lainnya'],
        ],
    ];

    return $options[$category] ?? $options['business'];
}

function lender_available_capital(PDO $pdo, int $userId, ?int $excludeTransactionId = null): float {
    $sql = "SELECT COALESCE(SUM(CASE
        WHEN type='income' THEN amount
        WHEN type IN ('expense','receivable') THEN -amount
        ELSE 0
    END),0) FROM transactions WHERE user_id=? AND category_type='lender'";
    $params = [$userId];
    if ($excludeTransactionId !== null) {
        $sql .= ' AND id<>?';
        $params[] = $excludeTransactionId;
    }

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return (float)$stmt->fetchColumn();
}

function transaction_type_label(string $type): string {
    return [
        'income' => 'Pemasukan',
        'expense' => 'Pengeluaran',
        'debt' => 'Hutang',
        'receivable' => 'Piutang',
        'savings' => 'Tabungan',
    ][$type] ?? ucfirst($type);
}

function type_class(string $type): string {
    return [
        'income' => 'income',
        'expense' => 'expense',
        'debt' => 'debt',
        'receivable' => 'receivable',
        'savings' => 'income',
    ][$type] ?? '';
}
