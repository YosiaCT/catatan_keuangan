<?php
$page_title = 'Dashboard';
require_once __DIR__ . '/config/auth.php';
require_login();

$userId = (int)$_SESSION['user_id'];

$category = selected_category();
$month = $_GET['month'] ?? date('Y-m');
if (!preg_match('/^\d{4}-\d{2}$/', $month)) $month = date('Y-m');
$monthNames = ['Januari','Februari','Maret','April','Mei','Juni','Juli','Agustus','September','Oktober','November','Desember'];
$monthLabel = $monthNames[(int)substr($month, 5, 2) - 1] . ' ' . substr($month, 0, 4);
$previousMonth = (new DateTimeImmutable($month . '-01'))->modify('-1 month')->format('Y-m');

$stmt = $pdo->prepare("
    SELECT
      COALESCE(SUM(CASE WHEN type IN ('income','debt') THEN amount ELSE 0 END),0) income,
      COALESCE(SUM(CASE WHEN type='expense' THEN amount ELSE 0 END),0) expense,
      COALESCE(SUM(CASE WHEN type='savings' THEN amount ELSE 0 END),0) savings,
      COALESCE(SUM(CASE WHEN type='debt' AND status='unpaid' THEN amount ELSE 0 END),0) debt,
      COALESCE(SUM(CASE WHEN type='receivable' AND status='unpaid' THEN amount ELSE 0 END),0) receivable
    FROM transactions
    WHERE user_id=? AND category_type=? AND DATE_FORMAT(transaction_date,'%Y-%m')=?
");
$stmt->execute([$userId,$category,$month]);
$sum = $stmt->fetch();
$balance = (float)$sum['income'] - (float)$sum['expense'] - (float)$sum['savings'];

$stmt=$pdo->prepare("SELECT DATE_FORMAT(transaction_date,'%Y-%m') period,
    COALESCE(SUM(CASE WHEN type IN ('income','debt') THEN amount ELSE 0 END),0) income,
    COALESCE(SUM(CASE WHEN type='expense' AND NOT (?='personal' AND COALESCE(transaction_category,'')='Tabungan') THEN amount ELSE 0 END),0) expense,
    COALESCE(SUM(CASE WHEN ?='personal' AND (type='savings' OR (type='expense' AND transaction_category='Tabungan')) THEN amount ELSE 0 END),0) savings,
    COALESCE(SUM(CASE WHEN type='receivable' THEN amount ELSE 0 END),0) loans,
    COALESCE(SUM(CASE WHEN type='income' AND loan_id IS NOT NULL THEN amount ELSE 0 END),0) repayments
    FROM transactions
    WHERE user_id=? AND category_type=? AND DATE_FORMAT(transaction_date,'%Y-%m') IN (?,?)
    GROUP BY period");
$stmt->execute([$category,$category,$userId,$category,$month,$previousMonth]);
$comparisonRows=[];
foreach($stmt->fetchAll() as $comparisonRow){
    $comparisonRows[$comparisonRow['period']]=$comparisonRow;
}
$comparisonCurrent=$comparisonRows[$month]??['income'=>0,'expense'=>0,'savings'=>0,'loans'=>0,'repayments'=>0];
$comparisonPrevious=$comparisonRows[$previousMonth]??['income'=>0,'expense'=>0,'savings'=>0,'loans'=>0,'repayments'=>0];
$comparisonMetrics=$category==='lender'
    ? ['Dipinjamkan'=>['loans','loans'],'Pengembalian'=>['repayments','repayments']]
    : ['Pemasukan'=>['income','income'],'Pengeluaran'=>['expense','expense']];

$stmt = $pdo->prepare("SELECT * FROM transactions WHERE user_id=? AND category_type=? ORDER BY transaction_date DESC,id DESC LIMIT 10");
$stmt->execute([$userId,$category]);
$transactions = $stmt->fetchAll();

$personalSavings = 0.0;
$lenderSummary = ['loans'=>0,'repayments'=>0,'outstanding'=>0,'available'=>0];
$loans = [];
$openDebts = [];
$openReceivables = [];
$dueReminders = [];
$recentRepayments = [];
$personalBudgets = [];
if ($category === 'personal') {
    $stmt=$pdo->prepare("SELECT
        COALESCE(SUM(CASE WHEN type='savings' THEN amount ELSE 0 END),0) savings,
        COALESCE(SUM(CASE WHEN type='expense' AND transaction_category='Tabungan' THEN amount ELSE 0 END),0) legacy_savings
        FROM transactions WHERE user_id=? AND category_type=? AND DATE_FORMAT(transaction_date,'%Y-%m')=?");
    $stmt->execute([$userId,$category,$month]);
    $savingsTotals=$stmt->fetch();
    $personalSavings=(float)$savingsTotals['savings']+(float)$savingsTotals['legacy_savings'];
    $stmt=$pdo->prepare("SELECT budget.expense_category,budget.amount,
            COALESCE(SUM(CASE WHEN txn.id IS NOT NULL THEN txn.amount ELSE 0 END),0) spent
        FROM personal_budgets budget
            LEFT JOIN transactions txn ON txn.user_id=budget.user_id
                AND txn.category_type='personal' AND txn.type='expense'
                AND txn.transaction_category=budget.expense_category
                AND DATE_FORMAT(txn.transaction_date,'%Y-%m')=budget.month
        WHERE budget.user_id=? AND budget.month=?
        GROUP BY budget.id ORDER BY budget.expense_category");
    $stmt->execute([$userId,$month]);
    $personalBudgets=$stmt->fetchAll();
}
if ($category === 'lender') {
    $lenderSummary['available']=lender_available_capital($pdo,$userId);
    $stmt=$pdo->prepare("SELECT
        COALESCE(SUM(CASE WHEN type='receivable' THEN amount ELSE 0 END),0) loans,
        COALESCE(SUM(CASE WHEN type='income' AND loan_id IS NOT NULL THEN amount ELSE 0 END),0) repayments
        FROM transactions WHERE user_id=? AND category_type='lender'");
    $stmt->execute([$userId]);
    $allTimeLender=$stmt->fetch();
    $lenderSummary['loans']=(float)$allTimeLender['loans'];
    $lenderSummary['repayments']=(float)$allTimeLender['repayments'];
    $stmt=$pdo->prepare("SELECT repayment.*,loan.person_name borrower_name,loan.description loan_description
        FROM transactions repayment LEFT JOIN transactions loan
            ON loan.id=repayment.loan_id AND loan.user_id=repayment.user_id
        WHERE repayment.user_id=? AND repayment.category_type='lender'
            AND repayment.type='income' AND repayment.loan_id IS NOT NULL
        ORDER BY repayment.transaction_date DESC,repayment.id DESC LIMIT 5");
    $stmt->execute([$userId]);
    $recentRepayments=$stmt->fetchAll();
}
if (in_array($category, ['business','personal'], true)) {
    $stmt=$pdo->prepare("SELECT debt.*,
        GREATEST(debt.amount-COALESCE((
            SELECT SUM(installment.amount) FROM transactions installment
            WHERE installment.user_id=debt.user_id AND installment.category_type=debt.category_type
                AND installment.type='expense' AND installment.loan_id=debt.id
        ),0),0) outstanding
        FROM transactions debt
        WHERE debt.user_id=? AND debt.category_type=? AND debt.type='debt' AND debt.status='unpaid'
            AND GREATEST(debt.amount-COALESCE((
                SELECT SUM(installment.amount) FROM transactions installment
                WHERE installment.user_id=debt.user_id AND installment.category_type=debt.category_type
                    AND installment.type='expense' AND installment.loan_id=debt.id
            ),0),0)>0
        ORDER BY debt.transaction_date DESC,debt.id DESC");
    $stmt->execute([$userId,$category]);
    $openDebts=$stmt->fetchAll();

    $stmt=$pdo->prepare("SELECT * FROM transactions
        WHERE user_id=? AND category_type=? AND type='receivable' AND status='unpaid'
        ORDER BY transaction_date DESC,id DESC");
    $stmt->execute([$userId,$category]);
    $openReceivables=$stmt->fetchAll();
}
if ($category === 'lender') {
    $stmt=$pdo->prepare("SELECT loan.person_name,loan.description,loan.due_date,
        GREATEST(loan.amount-COALESCE(SUM(repayment.amount),0),0) outstanding,
        'Pinjaman' reminder_type
        FROM transactions loan
        LEFT JOIN transactions repayment ON repayment.user_id=loan.user_id
            AND repayment.category_type=loan.category_type AND repayment.type='income' AND repayment.loan_id=loan.id
        WHERE loan.user_id=? AND loan.category_type='lender' AND loan.type='receivable'
            AND loan.status='unpaid' AND loan.due_date IS NOT NULL
            AND loan.due_date<DATE_ADD(CURDATE(),INTERVAL 8 DAY)
        GROUP BY loan.id HAVING outstanding>0
        ORDER BY loan.due_date ASC,loan.id DESC LIMIT 20");
    $stmt->execute([$userId]);
    $dueReminders=$stmt->fetchAll();
} else {
    $stmt=$pdo->prepare("SELECT due_items.* FROM (
        SELECT item.id,item.person_name,item.description,item.due_date,
            CASE WHEN item.type='debt' THEN
                GREATEST(item.amount-COALESCE((
                    SELECT SUM(installment.amount) FROM transactions installment
                    WHERE installment.user_id=item.user_id AND installment.category_type=item.category_type
                        AND installment.type='expense' AND installment.loan_id=item.id
                ),0),0)
            ELSE item.amount END outstanding,
            CASE WHEN item.type='debt' THEN 'Hutang' ELSE 'Piutang' END reminder_type
        FROM transactions item
        WHERE item.user_id=? AND item.category_type=? AND item.type IN ('debt','receivable')
            AND item.status='unpaid' AND item.due_date IS NOT NULL
            AND item.due_date<DATE_ADD(CURDATE(),INTERVAL 8 DAY)
    ) due_items
    WHERE due_items.outstanding>0
    ORDER BY due_items.due_date ASC,due_items.id DESC LIMIT 20");
    $stmt->execute([$userId,$category]);
    $dueReminders=$stmt->fetchAll();
}
if ($category === 'lender') {
    $stmt=$pdo->prepare("SELECT COALESCE(SUM(CASE WHEN loan.status='paid' THEN 0 ELSE GREATEST(loan.amount-COALESCE((SELECT SUM(repayment.amount) FROM transactions repayment WHERE repayment.user_id=loan.user_id AND repayment.category_type=loan.category_type AND repayment.type='income' AND repayment.loan_id=loan.id),0),0) END),0) FROM transactions loan WHERE loan.user_id=? AND loan.category_type=? AND loan.type='receivable'");
    $stmt->execute([$userId,$category]);
    $lenderSummary['outstanding']=(float)$stmt->fetchColumn();
    $stmt=$pdo->prepare("SELECT loan.*,
        COALESCE((SELECT SUM(repayment.amount) FROM transactions repayment WHERE repayment.user_id=loan.user_id AND repayment.category_type=loan.category_type AND repayment.type='income' AND repayment.loan_id=loan.id),0) returned,
        CASE WHEN loan.status='paid' THEN 0 ELSE GREATEST(loan.amount-COALESCE((SELECT SUM(repayment.amount) FROM transactions repayment WHERE repayment.user_id=loan.user_id AND repayment.category_type=loan.category_type AND repayment.type='income' AND repayment.loan_id=loan.id),0),0) END outstanding
        FROM transactions loan WHERE loan.user_id=? AND loan.category_type=? AND loan.type='receivable' AND loan.status='unpaid' ORDER BY loan.transaction_date DESC,loan.id DESC");
    $stmt->execute([$userId,$category]);
    foreach ($stmt->fetchAll() as $loan) {
        if ((float)$loan['outstanding'] > 0) {
            $loans[]=$loan;
        }
    }
}

$categoryName = category_label($category);

include __DIR__ . '/includes/header.php';
include __DIR__ . '/includes/sidebar.php';
?>
<main class="main">
<div class="page-head">
    <div><h1>Dashboard <?= e($categoryName) ?></h1>
        <p><?php if ($category === 'business'): ?>Pantau arus keuangan dan persediaan usaha kamu.
        <?php elseif ($category === 'personal'): ?>Pantau penghasilan, pengeluaran, dan saldo keuangan pribadi kamu.
        <?php elseif ($category === 'lender'): ?>Pantau modal tersedia, dana yang dipinjamkan, dan pengembalian dari peminjam.
        <?php else: ?>Ringkasan transaksi untuk kategori <?= e($categoryName) ?>.<?php endif; ?></p>
    </div>
    <form class="month-picker" method="get" data-month-picker>
        <input type="hidden" name="month" value="<?= e($month) ?>" data-month-value>
        <button class="month-picker-trigger" type="button" aria-label="Pilih bulan" aria-haspopup="dialog" aria-expanded="false" aria-controls="dashboard-month-picker" data-month-trigger>
            <span data-month-label><?= e($monthLabel) ?></span>
            <svg class="month-picker-chevron" aria-hidden="true" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m6 9 6 6 6-6"/></svg>
        </button>
        <div class="month-picker-popover" id="dashboard-month-picker" role="dialog" aria-label="Pilih bulan" hidden data-month-popover>
            <div class="month-picker-year">
                <button type="button" aria-label="Tahun sebelumnya" data-month-prev><svg aria-hidden="true" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m15 18-6-6 6-6"/></svg></button>
                <strong data-month-year></strong>
                <button type="button" aria-label="Tahun berikutnya" data-month-next><svg aria-hidden="true" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m9 18 6-6-6-6"/></svg></button>
            </div>
            <div class="month-picker-grid" data-month-grid></div>
        </div>
    </form>
</div>

<?php if ($category === 'business'): ?>
<div class="cards" style="margin-top:16px">
    <div class="stat-card income"><div class="stat-title">💰 Total Pemasukan</div><div class="stat-value"><?= rupiah($sum['income']) ?></div></div>
    <div class="stat-card expense"><div class="stat-title">💸 Total Pengeluaran</div><div class="stat-value"><?= rupiah($sum['expense']) ?></div></div>
    <div class="stat-card debt"><div class="stat-title">🔴 Total Hutang</div><div class="stat-value"><?= rupiah($sum['debt']) ?></div></div>
    <div class="stat-card receivable"><div class="stat-title">🔵 Piutang Belum Dilunaskan</div><div class="stat-value"><?= rupiah($sum['receivable']) ?></div></div>
</div>

<div class="balance">
    <div><div class="balance-label">💳 Saldo</div><div class="balance-value"><?= rupiah($balance) ?></div></div>
    <a class="btn btn-outline" href="grafik.php?month=<?= e($month) ?>">📊 Grafik Bisnis</a>
</div>
<?php elseif ($category === 'personal'): ?>
<div class="cards" style="margin-top:16px">
    <div class="stat-card income"><div class="stat-title">💰 Uang Masuk</div><div class="stat-value"><?= rupiah($sum['income']) ?></div></div>
    <div class="stat-card expense"><div class="stat-title">💸 Uang Keluar</div><div class="stat-value"><?= rupiah(max(0,(float)$sum['expense']-(float)$savingsTotals['legacy_savings'])) ?></div></div>
    <div class="stat-card personal-savings"><div class="stat-title">🏦 Tabungan</div><div class="stat-value"><?= rupiah($personalSavings) ?></div></div>
</div>
<div class="balance">
    <div><div class="balance-label">💳 Saldo</div><div class="balance-value"><?= rupiah($balance) ?></div></div>
    <div class="actions"><a class="btn btn-outline" href="grafik.php?month=<?= e($month) ?>">📊 Grafik Catatan Pribadi</a></div>
</div>
<?php if($personalBudgets): ?>
<div class="panel">
    <div class="panel-head"><div><h2>🎯 Anggaran Bulan Ini</h2><p>Pemakaian anggaran pengeluaran <?=e($monthLabel)?></p></div><a class="btn btn-outline btn-small" href="budget.php?month=<?=e($month)?>">Kelola Anggaran</a></div>
    <div class="budget-list budget-list-dashboard">
        <?php foreach($personalBudgets as $budget):
            $budgetAmount=(float)$budget['amount'];
            $budgetSpent=(float)$budget['spent'];
            $budgetRatio=$budgetAmount>0?$budgetSpent/$budgetAmount:0;
            $budgetProgress=min(100,$budgetRatio*100);
            $budgetState=$budgetRatio>=1?'over':($budgetRatio>=0.8?'warning':'on-track');
        ?>
        <article class="budget-item">
            <div class="budget-item-heading"><strong><?=e($budget['expense_category'])?></strong><span><?=rupiah($budgetSpent)?> / <?=rupiah($budgetAmount)?></span></div>
            <div class="budget-progress"><span class="<?=$budgetState?>" style="width:<?=number_format($budgetProgress,2,'.','')?>%"></span></div>
            <small><?=$budgetRatio>=1?'Anggaran terlampaui':($budgetRatio>=0.8?'Mendekati batas':'Masih dalam anggaran')?></small>
        </article>
        <?php endforeach; ?>
    </div>
</div>
<?php else: ?>
<div class="balance"><div><div class="balance-label">🎯 Anggaran Bulanan</div><div>Belum ada anggaran pengeluaran untuk <?=e($monthLabel)?>.</div></div><a class="btn btn-outline" href="budget.php?month=<?=e($month)?>">Atur Anggaran</a></div>
<?php endif; ?>
<?php elseif ($category === 'lender'): ?>
<div class="cards" style="margin-top:16px">
    <div class="stat-card lender-loans"><div class="stat-title">💜 Total Dipinjamkan (Semua Waktu)</div><div class="stat-value"><?= rupiah($lenderSummary['loans']) ?></div></div>
    <div class="stat-card income"><div class="stat-title">↩ Total Pengembalian (Semua Waktu)</div><div class="stat-value"><?= rupiah($lenderSummary['repayments']) ?></div></div>
    <div class="stat-card debt"><div class="stat-title">⏳ Belum Lunas</div><div class="stat-value"><?= rupiah($lenderSummary['outstanding']) ?></div></div>
    <div class="stat-card personal-savings"><div class="stat-title">💰 Modal Tersedia</div><div class="stat-value"><?= rupiah($lenderSummary['available']) ?></div></div>
</div>
<div class="balance">
    <div><div class="balance-label">💳 Belum Dikembalikan</div><div class="balance-value"><?= rupiah($lenderSummary['outstanding']) ?></div></div>
    <div class="actions"><a class="btn btn-outline" href="grafik.php?month=<?= e($month) ?>">📊 Lihat Grafik</a><a class="btn btn-primary" href="transaction_form.php?preset=capital">+ Tambah Modal</a><a class="btn btn-outline" href="transaction_form.php?preset=receivable">+ Tambah Peminjam</a><a class="btn btn-outline" href="pengembalian.php">+ Pengembalian</a></div>
</div>
<?php else: ?>
<div class="cards" style="margin-top:16px">
    <div class="stat-card income"><div class="stat-title">💰 Pemasukan</div><div class="stat-value"><?= rupiah($sum['income']) ?></div></div>
    <div class="stat-card expense"><div class="stat-title">💸 Pengeluaran</div><div class="stat-value"><?= rupiah($sum['expense']) ?></div></div>
    <div class="stat-card debt"><div class="stat-title">🔴 Hutang Belum Lunas</div><div class="stat-value"><?= rupiah($sum['debt']) ?></div></div>
    <div class="stat-card receivable"><div class="stat-title">🔵 Piutang Belum Lunas</div><div class="stat-value"><?= rupiah($sum['receivable']) ?></div></div>
</div>
<div class="balance">
    <div><div class="balance-label">💳 Saldo Bulan Ini</div><div class="balance-value"><?= rupiah($balance) ?></div></div>
    <a class="btn btn-outline" href="grafik.php?month=<?= e($month) ?>">📊 Lihat Grafik</a>
</div>
<?php endif; ?>

<section class="panel">
    <div class="panel-head"><div><h2>Perbandingan Bulanan</h2><p><?=e($monthLabel)?> dibanding <?=e($monthNames[(int)substr($previousMonth,5,2)-1].' '.substr($previousMonth,0,4))?></p></div></div>
    <div class="comparison-grid">
        <?php foreach($comparisonMetrics as $metricLabel=>$metricConfig):
            $metricKey=$metricConfig[0];
            $currentValue=(float)$comparisonCurrent[$metricKey];
            $previousValue=(float)$comparisonPrevious[$metricKey];
            $difference=$currentValue-$previousValue;
            $changePercent=$previousValue!=0?($difference/abs($previousValue))*100:null;
        ?>
        <article class="comparison-item">
            <span><?=e($metricLabel)?></span>
            <strong><?=rupiah($currentValue)?></strong>
            <small>Sebelumnya <?=rupiah($previousValue)?> ·
                <?php if($changePercent===null): ?><?= $currentValue>0?'Baru':'Tidak berubah' ?>
                <?php else: ?><?= $difference>0?'+':'' ?><?=number_format($changePercent,1,',','.')?>%<?php endif; ?>
            </small>
        </article>
        <?php endforeach; ?>
    </div>
</section>

<section class="panel">
    <div class="panel-head"><div><h2>Pengingat Jatuh Tempo</h2><p>Hutang, piutang, atau pinjaman yang sudah terlambat atau jatuh tempo dalam 7 hari ke depan.</p></div></div>
    <?php if($dueReminders): ?>
    <div class="table-wrap"><table class="table">
        <thead><tr><th>Jenis</th><th>Keterangan</th><th>Pihak</th><th>Jatuh Tempo</th><th>Sisa</th><th>Status</th></tr></thead>
        <tbody>
            <?php foreach($dueReminders as $reminder):
                $reminderDate=DateTimeImmutable::createFromFormat('!Y-m-d',$reminder['due_date']);
                $daysUntil=(int)(new DateTimeImmutable('today'))->diff($reminderDate)->format('%r%a');
                $isOverdue=$daysUntil<0;
            ?>
            <tr>
                <td><?=e($reminder['reminder_type'])?></td>
                <td><?=e($reminder['description'])?></td>
                <td><?=e($reminder['person_name']?:'-')?></td>
                <td><?=e(date('d-m-Y',strtotime($reminder['due_date'])))?></td>
                <td><strong><?=rupiah($reminder['outstanding'])?></strong></td>
                <td><span class="badge <?=$isOverdue?'status-unpaid':'status'?>"><?=$isOverdue?'Terlambat '.abs($daysUntil).' hari':($daysUntil===0?'Jatuh tempo hari ini':'Dalam '.$daysUntil.' hari')?></span></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table></div>
    <?php else: ?>
    <p class="empty">Tidak ada hutang, piutang, atau pinjaman yang jatuh tempo dalam 7 hari ke depan.</p>
    <?php endif; ?>
</section>

<?php if($category==='lender'): ?>
<div class="panel">
    <div class="panel-head"><div><h2>📋 Peminjam Belum Lunas</h2><p>Semua pinjaman yang masih menunggu pengembalian</p></div><div class="actions"><a class="btn btn-outline btn-small" href="riwayat.php">Arsip Transaksi</a><a class="btn btn-outline btn-small" href="receivable.php">Lihat Semua</a></div></div>
    <div class="table-wrap"><table class="table">
        <thead><tr><th>Nama Peminjam</th><th>Tanggal Dipinjamkan</th><th>Jatuh Tempo</th><th>Jumlah Dipinjamkan</th><th>Sisa</th><th>Status</th><th>Aksi</th></tr></thead>
        <tbody>
            <?php foreach($loans as $loan): ?>
            <tr>
                <td><?=e($loan['person_name']?:$loan['description'])?></td>
                <td><?=e(date('d-m-Y',strtotime($loan['transaction_date'])))?></td>
                <td><?=$loan['due_date']?e(date('d-m-Y',strtotime($loan['due_date']))):'-'?></td>
                <td><strong><?=rupiah($loan['amount'])?></strong></td>
                <td><strong><?=rupiah($loan['outstanding'])?></strong></td>
                <td><span class="badge status-unpaid">Belum Lunas</span></td>
                <td><div class="actions">
                    <a class="btn btn-warning" href="transaction_form.php?id=<?=(int)$loan['id']?>">✎ Edit</a>
                    <a class="btn btn-success" href="pengembalian.php?loan_id=<?=(int)$loan['id']?>">Catat Kembali</a>
                </div></td>
            </tr>
            <?php endforeach; ?>
            <?php if(!$loans): ?><tr><td colspan="7" class="empty">Tidak ada pinjaman yang belum lunas.</td></tr><?php endif; ?>
        </tbody>
    </table></div>
</div>
<div class="panel">
    <div class="panel-head"><div><h2>↩ Pengembalian Terbaru</h2><p>Maksimal 5 pengembalian terakhir</p></div><a class="btn btn-outline btn-small" href="pengembalian.php">Semua Pengembalian</a></div>
    <div class="table-wrap"><table class="table">
        <thead><tr><th>Tanggal</th><th>Peminjam</th><th>Keterangan</th><th>Jumlah Kembali</th></tr></thead>
        <tbody>
            <?php foreach($recentRepayments as $repayment): ?>
            <tr><td><?=e(date('d-m-Y',strtotime($repayment['transaction_date'])))?></td><td><?=e($repayment['borrower_name']?:$repayment['loan_description']?:'-')?></td><td><?=e($repayment['description'])?></td><td><strong><?=rupiah($repayment['amount'])?></strong></td></tr>
            <?php endforeach; ?>
            <?php if(!$recentRepayments): ?><tr><td colspan="4" class="empty">Belum ada pengembalian yang tercatat.</td></tr><?php endif; ?>
        </tbody>
    </table></div>
</div>
<?php else: ?>
<?php if(in_array($category,['business','personal'],true)): ?>
<div class="panel">
    <div class="panel-head"><div><h2>Hutang Belum Lunas</h2><p>Semua hutang yang masih memiliki sisa pembayaran</p></div><a class="btn btn-outline btn-small" href="debt.php">Kelola Hutang</a></div>
    <div class="table-wrap"><table class="table">
        <thead><tr><th>Tanggal</th><th>Keterangan</th><th>Kepada</th><th>Jatuh Tempo</th><th>Jumlah Hutang</th><th>Sisa Hutang</th></tr></thead>
        <tbody>
            <?php foreach($openDebts as $debt): ?>
            <tr>
                <td><?=e(date('d-m-Y',strtotime($debt['transaction_date'])))?></td>
                <td><?=e($debt['description'])?></td>
                <td><?=e($debt['person_name']?:'-')?></td>
                <td><?=$debt['due_date']?e(date('d-m-Y',strtotime($debt['due_date']))):'-'?></td>
                <td><?=rupiah($debt['amount'])?></td>
                <td><strong><?=rupiah($debt['outstanding'])?></strong></td>
            </tr>
            <?php endforeach; ?>
            <?php if(!$openDebts): ?><tr><td colspan="6" class="empty">Tidak ada hutang yang belum lunas.</td></tr><?php endif; ?>
        </tbody>
    </table></div>
</div>
<div class="panel">
    <div class="panel-head"><div><h2>Piutang Belum Diterima</h2><p>Semua piutang yang masih menunggu pembayaran</p></div><a class="btn btn-outline btn-small" href="receivable.php">Kelola Piutang</a></div>
    <div class="table-wrap"><table class="table">
        <thead><tr><th>Tanggal</th><th>Keterangan</th><th>Dari</th><th>Jatuh Tempo</th><th>Jumlah</th><th>Status</th></tr></thead>
        <tbody>
            <?php foreach($openReceivables as $receivable): ?>
            <tr>
                <td><?=e(date('d-m-Y',strtotime($receivable['transaction_date'])))?></td>
                <td><?=e($receivable['description'])?></td>
                <td><?=e($receivable['person_name']?:'-')?></td>
                <td><?=$receivable['due_date']?e(date('d-m-Y',strtotime($receivable['due_date']))):'-'?></td>
                <td><strong><?=rupiah($receivable['amount'])?></strong></td>
                <td><span class="badge status-unpaid">Belum Diterima</span></td>
            </tr>
            <?php endforeach; ?>
            <?php if(!$openReceivables): ?><tr><td colspan="6" class="empty">Tidak ada piutang yang belum diterima.</td></tr><?php endif; ?>
        </tbody>
    </table></div>
</div>
<?php endif; ?>
<div class="panel">
    <div class="panel-head"><div><h2>📜 Transaksi Terbaru</h2><p>Maksimal 10 transaksi terbaru. Transaksi lainnya tersimpan di arsip.</p></div><div class="actions">
        <a class="btn btn-outline btn-small" href="riwayat.php">Arsip Semua Transaksi</a><a class="btn btn-outline btn-small" href="reports.php">Lihat Laporan</a><a class="btn btn-primary btn-small" href="transaction_form.php">+ Tambah</a>
    </div></div>
    <div class="table-wrap">
    <table class="table">
        <thead><tr><th>No</th><th>Tanggal</th><th>Keterangan</th><th>Jenis</th><th>Kategori / Status</th><th>Jumlah</th><th>Aksi</th></tr></thead>
        <tbody>
        <?php foreach($transactions as $i=>$row): ?>
        <tr>
            <td><?= $i+1 ?></td><td><?= e(date('d-m-Y',strtotime($row['transaction_date']))) ?></td>
            <td><?= e($row['description']) ?><?= $row['person_name'] ? ' - '.e($row['person_name']) : '' ?></td>
            <td><span class="badge <?= type_class($row['type']) ?>"><?= e(transaction_type_label($row['type'])) ?></span></td>
            <td><?= in_array($row['type'],['debt','receivable'],true) ? '<span class="badge status">'.($row['status']==='paid'?($row['type']==='receivable'?'Diterima':'Lunas'):($row['type']==='receivable'?'Belum Dilunaskan':'Belum Lunas')).'</span>' : e($row['transaction_category'] ?: 'Lainnya') ?></td>
            <td><strong><?= rupiah($row['amount']) ?></strong></td>
            <td><div class="actions">
                <a class="btn btn-warning" href="transaction_form.php?id=<?= $row['id'] ?>">✎ Edit</a>
                <?php if($row['type']==='debt' && $row['status']==='unpaid'): ?>
                    <form method="post" action="transaction_action.php"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="pay"><input type="hidden" name="id" value="<?= $row['id'] ?>"><button class="btn btn-success">✓ Lunasi</button></form>
                <?php endif; ?>
            </div></td>
        </tr>
        <?php endforeach; ?>
        <?php if(!$transactions): ?><tr><td colspan="7" class="empty">Belum ada transaksi.</td></tr><?php endif; ?>
        </tbody>
    </table>
    </div>
</div>
<?php endif; ?>
</main>
<?php include __DIR__ . '/includes/footer.php'; ?>
