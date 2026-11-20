<?php
declare(strict_types=1);

$page_title = 'Arsip Keuangan Lengkap';
require_once __DIR__ . '/config/auth.php';
require_login();

$userId = (int) $_SESSION['user_id'];
$user = current_user();

$categoryLabels = [
    'business' => 'Bisnis',
    'personal' => 'Catatan Pribadi',
    'lender' => 'Pemberi Pinjaman',
];
$stmt = $pdo->prepare('SELECT slug, name FROM user_categories WHERE user_id = ? ORDER BY id');
$stmt->execute([$userId]);
foreach ($stmt->fetchAll() as $userCategory) {
    $categoryLabels[$userCategory['slug']] = $userCategory['name'];
}

$stmt = $pdo->prepare('SELECT * FROM transactions WHERE user_id = ? ORDER BY category_type, transaction_date DESC, id DESC');
$stmt->execute([$userId]);
$transactions = $stmt->fetchAll();

$emptySummary = [
    'count' => 0,
    'income' => 0.0,
    'expense' => 0.0,
    'savings' => 0.0,
    'debt' => 0.0,
    'receivable' => 0.0,
];
$summaries = [];
foreach ($categoryLabels as $category => $_label) {
    $summaries[$category] = $emptySummary;
}

$repaymentsByLoan = [];
foreach ($transactions as $transaction) {
    if ($transaction['category_type'] === 'lender' && $transaction['type'] === 'income' && $transaction['loan_id'] !== null) {
        $loanId = (int) $transaction['loan_id'];
        $repaymentsByLoan[$loanId] = ($repaymentsByLoan[$loanId] ?? 0) + (float) $transaction['amount'];
    }
}

foreach ($transactions as $transaction) {
    $category = $transaction['category_type'];
    if (!isset($summaries[$category])) {
        $summaries[$category] = $emptySummary;
    }
    $summaries[$category]['count']++;

    if (in_array($transaction['type'], ['income', 'debt'], true)) {
        $summaries[$category]['income'] += (float) $transaction['amount'];
    }
    if ($transaction['type'] === 'expense' && !($category === 'personal' && $transaction['transaction_category'] === 'Tabungan')) {
        $summaries[$category]['expense'] += (float) $transaction['amount'];
    }
    if ($transaction['type'] === 'savings' || ($category === 'personal' && $transaction['type'] === 'expense' && $transaction['transaction_category'] === 'Tabungan')) {
        $summaries[$category]['savings'] += (float) $transaction['amount'];
    }
    if ($transaction['type'] === 'debt' && $transaction['status'] === 'unpaid') {
        $summaries[$category]['debt'] += (float) $transaction['amount'];
    }
    if ($transaction['type'] === 'receivable' && $transaction['status'] === 'unpaid') {
        $remaining = (float) $transaction['amount'];
        if ($category === 'lender') {
            $remaining -= $repaymentsByLoan[(int) $transaction['id']] ?? 0;
        }
        $summaries[$category]['receivable'] += max(0, $remaining);
    }
}

uksort($summaries, static function (string $left, string $right) use ($categoryLabels): int {
    return strcasecmp($categoryLabels[$left] ?? $left, $categoryLabels[$right] ?? $right);
});

$stmt = $pdo->prepare('SELECT * FROM stocks WHERE user_id = ? ORDER BY category_type, item_name');
$stmt->execute([$userId]);
$stocks = $stmt->fetchAll();

$stmt = $pdo->prepare("SELECT budget.month, budget.expense_category, budget.amount,
        COALESCE(SUM(CASE WHEN txn.type = 'expense' THEN txn.amount ELSE 0 END), 0) spent
    FROM personal_budgets budget
    LEFT JOIN transactions txn
        ON txn.user_id = budget.user_id
        AND txn.category_type = 'personal'
        AND txn.type = 'expense'
        AND txn.transaction_category = budget.expense_category
        AND DATE_FORMAT(txn.transaction_date, '%Y-%m') = budget.month
    WHERE budget.user_id = ?
    GROUP BY budget.id
    ORDER BY budget.month DESC, budget.expense_category");
$stmt->execute([$userId]);
$budgets = $stmt->fetchAll();

$formatAmount = static fn ($value): string => rupiah((float) $value);
$transactionStatus = static function (array $transaction): string {
    if (in_array($transaction['type'], ['debt', 'receivable'], true)) {
        return $transaction['status'] === 'paid' ? 'Lunas' : 'Belum lunas';
    }
    return $transaction['transaction_category'] ?: 'Lainnya';
};
?>
<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($page_title) ?></title>
    <link rel="stylesheet" href="assets/css/style.css?v=<?= filemtime(__DIR__ . '/assets/css/style.css') ?>">
</head>
<body class="archive-page">
<main class="main archive-document">
    <div class="page-head">
        <div>
            <h1>Arsip Keuangan Lengkap</h1>
            <p><?= e($user['full_name'] ?? $user['username'] ?? '') ?> · Dibuat <?= e(date('d-m-Y H:i')) ?></p>
        </div>
        <div class="actions no-print">
            <a class="btn btn-outline" href="dashboard.php">Kembali</a>
            <button class="btn btn-primary" type="button" data-print-page>Simpan PDF / Cetak</button>
        </div>
    </div>

    <p class="archive-note">Arsip ini mencakup semua transaksi, kategori, stok, dan anggaran yang tersimpan untuk akun Anda.</p>

    <section class="panel archive-section">
        <div class="panel-head"><div><h2>Ringkasan per Kategori</h2></div></div>
        <div class="table-wrap">
            <table class="table">
                <thead><tr><th>Kategori</th><th>Transaksi</th><th>Pemasukan</th><th>Pengeluaran</th><th>Tabungan</th><th>Hutang belum lunas</th><th>Piutang belum lunas</th><th>Saldo</th></tr></thead>
                <tbody>
                    <?php foreach ($summaries as $category => $summary): ?>
                    <tr>
                        <td><?= e($categoryLabels[$category] ?? $category) ?></td>
                        <td><?= (int) $summary['count'] ?></td>
                        <td><?= e($formatAmount($summary['income'])) ?></td>
                        <td><?= e($formatAmount($summary['expense'])) ?></td>
                        <td><?= e($formatAmount($summary['savings'])) ?></td>
                        <td><?= e($formatAmount($summary['debt'])) ?></td>
                        <td><?= e($formatAmount($summary['receivable'])) ?></td>
                        <td><strong><?= e($formatAmount($summary['income'] - $summary['expense'] - $summary['savings'])) ?></strong></td>
                    </tr>
                    <?php endforeach; ?>
                    <?php if (!$summaries): ?><tr><td colspan="8" class="empty">Belum ada transaksi.</td></tr><?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>

    <section class="panel archive-section">
        <div class="panel-head"><div><h2>Seluruh Transaksi</h2><p><?= count($transactions) ?> transaksi · semua tanggal</p></div></div>
        <div class="table-wrap">
            <table class="table">
                <thead><tr><th>No</th><th>Kategori</th><th>Tanggal</th><th>Keterangan</th><th>Jenis</th><th>Kategori / Status</th><th>Jatuh tempo</th><th>Jumlah</th></tr></thead>
                <tbody>
                    <?php foreach ($transactions as $index => $transaction): ?>
                    <tr>
                        <td><?= $index + 1 ?></td>
                        <td><?= e($categoryLabels[$transaction['category_type']] ?? $transaction['category_type']) ?></td>
                        <td><?= e(date('d-m-Y', strtotime($transaction['transaction_date']))) ?></td>
                        <td><?= e($transaction['description']) ?><?= $transaction['person_name'] ? ' · ' . e($transaction['person_name']) : '' ?></td>
                        <td><?= e(transaction_type_label($transaction['type'])) ?></td>
                        <td><?= e($transactionStatus($transaction)) ?></td>
                        <td><?= $transaction['due_date'] ? e(date('d-m-Y', strtotime($transaction['due_date']))) : '—' ?></td>
                        <td><strong><?= e($formatAmount($transaction['amount'])) ?></strong></td>
                    </tr>
                    <?php endforeach; ?>
                    <?php if (!$transactions): ?><tr><td colspan="8" class="empty">Belum ada transaksi.</td></tr><?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>

    <section class="panel archive-section">
        <div class="panel-head"><div><h2>Seluruh Stok</h2><p><?= count($stocks) ?> jenis barang</p></div></div>
        <div class="table-wrap">
            <table class="table">
                <thead><tr><th>Kategori</th><th>Barang</th><th>Stok</th><th>Harga beli / unit</th><th>Harga jual / unit</th></tr></thead>
                <tbody>
                    <?php foreach ($stocks as $stock): ?>
                    <tr>
                        <td><?= e($categoryLabels[$stock['category_type']] ?? $stock['category_type']) ?></td>
                        <td><?= e($stock['item_name']) ?></td>
                        <td><?= e((string) $stock['stock_qty']) ?> <?= e($stock['unit']) ?></td>
                        <td><?= e($formatAmount($stock['buy_price'])) ?></td>
                        <td><?= e($formatAmount($stock['sell_price'])) ?></td>
                    </tr>
                    <?php endforeach; ?>
                    <?php if (!$stocks): ?><tr><td colspan="5" class="empty">Belum ada stok.</td></tr><?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>

    <section class="panel archive-section">
        <div class="panel-head"><div><h2>Seluruh Anggaran Pribadi</h2><p><?= count($budgets) ?> anggaran · semua bulan</p></div></div>
        <div class="table-wrap">
            <table class="table">
                <thead><tr><th>Bulan</th><th>Kategori pengeluaran</th><th>Anggaran</th><th>Terpakai</th><th>Sisa / Lebih</th></tr></thead>
                <tbody>
                    <?php foreach ($budgets as $budget): ?>
                    <?php $remaining = (float) $budget['amount'] - (float) $budget['spent']; ?>
                    <tr>
                        <td><?= e($budget['month']) ?></td>
                        <td><?= e($budget['expense_category']) ?></td>
                        <td><?= e($formatAmount($budget['amount'])) ?></td>
                        <td><?= e($formatAmount($budget['spent'])) ?></td>
                        <td><?= $remaining < 0 ? 'Lebih ' . e($formatAmount(abs($remaining))) : e($formatAmount($remaining)) ?></td>
                    </tr>
                    <?php endforeach; ?>
                    <?php if (!$budgets): ?><tr><td colspan="5" class="empty">Belum ada anggaran.</td></tr><?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>
</main>
<script src="assets/js/app.js?v=<?= filemtime(__DIR__ . '/assets/js/app.js') ?>"></script>
</body>
</html>
