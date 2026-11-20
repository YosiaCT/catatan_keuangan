<?php
$page_title='Grafik Keuangan';
require_once __DIR__ . '/config/auth.php';
require_login();

$userId=(int)$_SESSION['user_id'];
$category=selected_category();
$month=$_GET['month']??date('Y-m');
if(!preg_match('/^\d{4}-\d{2}$/',$month))$month=date('Y-m');
$monthNamesLong=['Januari','Februari','Maret','April','Mei','Juni','Juli','Agustus','September','Oktober','November','Desember'];
$monthLabel=$monthNamesLong[(int)substr($month,5,2)-1].' '.substr($month,0,4);

$stmt=$pdo->prepare("SELECT
    COALESCE(SUM(CASE WHEN type IN ('income','debt') THEN amount ELSE 0 END),0) income,
    COALESCE(SUM(CASE WHEN type='expense' AND (?<>'personal' OR COALESCE(transaction_category,'')<>'Tabungan') THEN amount
        WHEN type='savings' AND ?<>'personal' THEN amount ELSE 0 END),0) expense,
    COALESCE(SUM(CASE WHEN ?='personal' AND (type='savings' OR (type='expense' AND transaction_category='Tabungan')) THEN amount ELSE 0 END),0) savings,
    COALESCE(SUM(CASE WHEN type='debt' AND status='unpaid' THEN amount ELSE 0 END),0) debt,
    COALESCE(SUM(CASE WHEN type='receivable' AND status='unpaid' THEN amount ELSE 0 END),0) receivable
    FROM transactions
    WHERE user_id=? AND category_type=? AND DATE_FORMAT(transaction_date,'%Y-%m')=?");
$stmt->execute([$category,$category,$category,$userId,$category,$month]);
$sum=$stmt->fetch();
$balance=(float)$sum['income']-(float)$sum['expense']-(float)$sum['savings'];
$selectedDate=new DateTimeImmutable($month.'-01');
$trendStart=$selectedDate->modify('-8 months')->format('Y-m-01');
$trendEnd=$selectedDate->modify('+1 month')->format('Y-m-01');
$stmt=$pdo->prepare("SELECT DATE_FORMAT(transaction_date,'%Y-%m') period,
    COALESCE(SUM(CASE WHEN type IN ('income','debt') THEN amount ELSE 0 END),0) income,
    COALESCE(SUM(CASE WHEN type='expense' AND (?<>'personal' OR COALESCE(transaction_category,'')<>'Tabungan') THEN amount
        WHEN type='savings' AND ?<>'personal' THEN amount ELSE 0 END),0) expense,
    COALESCE(SUM(CASE WHEN ?='personal' AND (type='savings' OR (type='expense' AND transaction_category='Tabungan')) THEN amount ELSE 0 END),0) savings
    FROM transactions WHERE user_id=? AND category_type=? AND transaction_date>=? AND transaction_date<? GROUP BY period ORDER BY period");
$stmt->execute([$category,$category,$category,$userId,$category,$trendStart,$trendEnd]);
$trendRows=[];
foreach($stmt as $trendRow)$trendRows[$trendRow['period']]=$trendRow;
$monthNames=['Jan','Feb','Mar','Apr','Mei','Jun','Jul','Agu','Sep','Okt','Nov','Des'];
$trendLabels=[];$trendIncome=[];$trendExpense=[];$trendSavings=[];$trendBalance=[];
for($offset=8;$offset>=0;$offset--){
    $trendMonth=$selectedDate->modify('-'.$offset.' months');
    $period=$trendMonth->format('Y-m');
    $monthData=$trendRows[$period]??['income'=>0,'expense'=>0,'savings'=>0];
    $income=(float)$monthData['income'];
    $expense=(float)$monthData['expense'];
    $savings=(float)$monthData['savings'];
    $trendLabels[]=$monthNames[(int)$trendMonth->format('n')-1];
    $trendIncome[]=$income;
    $trendExpense[]=$expense;
    $trendSavings[]=$savings;
    $trendBalance[]=$income-$expense-$savings;
}
$expenseTypeCondition=$category==='personal'
    ? "type='expense' AND COALESCE(transaction_category,'')<>'Tabungan'"
    : "type IN ('expense','savings')";
$stmt=$pdo->prepare("SELECT COALESCE(NULLIF(transaction_category,''),'Lainnya') category,COALESCE(SUM(amount),0) total FROM transactions WHERE user_id=? AND category_type=? AND $expenseTypeCondition AND DATE_FORMAT(transaction_date,'%Y-%m')=? GROUP BY category ORDER BY total DESC");
$stmt->execute([$userId,$category,$month]);
$expenseCategories=$stmt->fetchAll();
$businessReceivables=null;
if($category==='business'){
    $stmt=$pdo->prepare("SELECT COALESCE(NULLIF(TRIM(person_name),''),NULLIF(TRIM(description),''),'Tanpa nama') borrower,
        COALESCE(SUM(amount),0) outstanding
        FROM transactions
        WHERE user_id=? AND category_type='business' AND type='receivable' AND status='unpaid'
            AND DATE_FORMAT(transaction_date,'%Y-%m')=?
        GROUP BY borrower ORDER BY outstanding DESC LIMIT 8");
    $stmt->execute([$userId,$month]);
    $businessReceivables=$stmt->fetchAll();
}
$businessDebts=null;
if($category==='business'){
    $stmt=$pdo->prepare("SELECT COALESCE(NULLIF(TRIM(debt.person_name),''),NULLIF(TRIM(debt.description),''),'Tanpa nama') creditor,
        COALESCE(SUM(GREATEST(debt.amount-COALESCE((
            SELECT SUM(installment.amount) FROM transactions installment
            WHERE installment.user_id=debt.user_id AND installment.category_type=debt.category_type
                AND installment.type='expense' AND installment.loan_id=debt.id
        ),0),0)),0) outstanding
        FROM transactions debt
        WHERE debt.user_id=? AND debt.category_type='business' AND debt.type='debt' AND debt.status='unpaid'
            AND DATE_FORMAT(debt.transaction_date,'%Y-%m')=?
        GROUP BY creditor HAVING outstanding>0 ORDER BY outstanding DESC LIMIT 8");
    $stmt->execute([$userId,$month]);
    $businessDebts=$stmt->fetchAll();
}
$lenderChartData=null;
if($category==='lender'){
    $stmt=$pdo->prepare("SELECT
        COALESCE(SUM(CASE WHEN type='receivable' THEN amount ELSE 0 END),0) loans,
        COALESCE(SUM(CASE WHEN type='income' AND loan_id IS NOT NULL THEN amount ELSE 0 END),0) repayments
        FROM transactions
        WHERE user_id=? AND category_type='lender' AND DATE_FORMAT(transaction_date,'%Y-%m')=?");
    $stmt->execute([$userId,$month]);
    $lenderMonth=$stmt->fetch();

    $stmt=$pdo->prepare("SELECT COALESCE(SUM(
        CASE WHEN loan.status='paid' THEN 0
            ELSE GREATEST(loan.amount-COALESCE((
                SELECT SUM(repayment.amount) FROM transactions repayment
                WHERE repayment.user_id=loan.user_id AND repayment.category_type=loan.category_type
                    AND repayment.type='income' AND repayment.loan_id=loan.id
            ),0),0) END
        ),0)
        FROM transactions loan
        WHERE loan.user_id=? AND loan.category_type='lender' AND loan.type='receivable'");
    $stmt->execute([$userId]);
    $lenderOutstanding=(float)$stmt->fetchColumn();

    $stmt=$pdo->prepare("SELECT DATE_FORMAT(transaction_date,'%Y-%m') period,
        COALESCE(SUM(CASE WHEN type='receivable' THEN amount ELSE 0 END),0) loans,
        COALESCE(SUM(CASE WHEN type='income' AND loan_id IS NOT NULL THEN amount ELSE 0 END),0) repayments
        FROM transactions
        WHERE user_id=? AND category_type='lender' AND transaction_date>=? AND transaction_date<?
        GROUP BY period ORDER BY period");
    $stmt->execute([$userId,$trendStart,$trendEnd]);
    $lenderTrendRows=[];
    foreach($stmt as $trendRow)$lenderTrendRows[$trendRow['period']]=$trendRow;
    $lenderTrendLoans=[];$lenderTrendRepayments=[];
    for($offset=8;$offset>=0;$offset--){
        $trendMonth=$selectedDate->modify('-'.$offset.' months');
        $monthData=$lenderTrendRows[$trendMonth->format('Y-m')]??['loans'=>0,'repayments'=>0];
        $lenderTrendLoans[]=(float)$monthData['loans'];
        $lenderTrendRepayments[]=(float)$monthData['repayments'];
    }

    $stmt=$pdo->prepare("SELECT borrower, SUM(outstanding) outstanding FROM (
        SELECT COALESCE(NULLIF(TRIM(loan.person_name),''),loan.description) borrower,
            CASE WHEN loan.status='paid' THEN 0
                ELSE GREATEST(loan.amount-COALESCE(repayment.returned,0),0) END outstanding
        FROM transactions loan
        LEFT JOIN (
            SELECT user_id,category_type,loan_id,SUM(amount) returned
            FROM transactions WHERE type='income' AND loan_id IS NOT NULL
            GROUP BY user_id,category_type,loan_id
        ) repayment ON repayment.user_id=loan.user_id
            AND repayment.category_type=loan.category_type AND repayment.loan_id=loan.id
        WHERE loan.user_id=? AND loan.category_type='lender' AND loan.type='receivable'
    ) borrower_loans
    WHERE outstanding>0
    GROUP BY borrower ORDER BY outstanding DESC LIMIT 8");
    $stmt->execute([$userId]);
    $borrowerOutstanding=$stmt->fetchAll();

    $lenderChartData=[
        'monthLoans'=>(float)$lenderMonth['loans'],
        'monthRepayments'=>(float)$lenderMonth['repayments'],
        'trend'=>[
            'loans'=>$lenderTrendLoans,
            'repayments'=>$lenderTrendRepayments,
        ],
        'borrowers'=>[
            'labels'=>array_column($borrowerOutstanding,'borrower'),
            'values'=>array_map('floatval',array_column($borrowerOutstanding,'outstanding')),
        ],
    ];
}
$chartData=[
    'month'=>$month,
    'isLender'=>$category==='lender',
    'isPersonal'=>$category==='personal',
    'income'=>(float)$sum['income'],
    'expense'=>(float)$sum['expense'],
    'savings'=>(float)$sum['savings'],
    'balance'=>$balance,
    'trend'=>['labels'=>$trendLabels,'income'=>$trendIncome,'expense'=>$trendExpense,'savings'=>$trendSavings,'balance'=>$trendBalance],
    'expenseCategories'=>['labels'=>array_column($expenseCategories,'category'),'values'=>array_map('floatval',array_column($expenseCategories,'total'))],
    'businessDebts'=>$businessDebts===null?null:[
        'labels'=>array_column($businessDebts,'creditor'),
        'values'=>array_map('floatval',array_column($businessDebts,'outstanding')),
    ],
    'businessReceivables'=>$businessReceivables===null?null:[
        'labels'=>array_column($businessReceivables,'borrower'),
        'values'=>array_map('floatval',array_column($businessReceivables,'outstanding')),
    ],
    'lender'=>$lenderChartData,
];

include __DIR__.'/includes/header.php';
include __DIR__.'/includes/sidebar.php';
?>
<main class="main finance-chart-page">
    <div class="page-head">
        <div><h1><?= $category==='lender'?'Grafik Pemberi Pinjaman':'Grafik Keuangan - '.e(category_label($category)) ?></h1><p><?= $category==='lender'?'Pantau jumlah pinjaman, pengembalian, dan sisa pinjaman per peminjam.':'Visualisasi arus keuangan dan kategori transaksi.' ?></p></div>
        <form class="month-picker" method="get" data-month-picker>
            <input type="hidden" name="month" value="<?=e($month)?>" data-month-value>
            <button class="month-picker-trigger" type="button" aria-label="Pilih bulan" aria-haspopup="dialog" aria-expanded="false" aria-controls="finance-month-picker" data-month-trigger>
                <span data-month-label><?=e($monthLabel)?></span>
                <svg class="month-picker-chevron" aria-hidden="true" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m6 9 6 6 6-6"/></svg>
            </button>
            <div class="month-picker-popover" id="finance-month-picker" role="dialog" aria-label="Pilih bulan" hidden data-month-popover>
                <div class="month-picker-year">
                    <button type="button" aria-label="Tahun sebelumnya" data-month-prev><svg aria-hidden="true" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m15 18-6-6 6-6"/></svg></button>
                    <strong data-month-year></strong>
                    <button type="button" aria-label="Tahun berikutnya" data-month-next><svg aria-hidden="true" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m9 18 6-6-6-6"/></svg></button>
                </div>
                <div class="month-picker-grid" data-month-grid></div>
            </div>
        </form>
    </div>
    <div class="finance-chart-grid finance-primary-charts">
        <section class="panel finance-chart-card">
            <div class="panel-head"><div><h2><?= $category==='lender'?'Pinjaman & Pengembalian Bulan Ini':'Ringkasan Keuangan' ?></h2><p><?= $category==='lender'?'Periode '.e($monthLabel):'Tren 9 bulan hingga '.e($monthLabel) ?></p></div></div>
            <div class="chart-canvas-wrap"><canvas id="income-expense-chart" aria-label="<?= $category==='lender'?'Grafik pinjaman dan pengembalian bulan ini':($category==='personal'?'Grafik penghasilan, pengeluaran, dan tabungan catatan pribadi':'Grafik pemasukan dan pengeluaran bisnis') ?>" role="img"></canvas><p class="chart-fallback" hidden>Grafik tidak dapat dimuat. Ringkasan nilai tersedia di bagian bawah.</p></div>
        </section>
        <section class="panel finance-chart-card">
            <div class="panel-head"><div><h2><?= $category==='lender'?'Tren Pinjaman & Pengembalian':'Tren Keuangan' ?></h2><p>9 bulan terakhir hingga <?=e($monthLabel)?></p></div></div>
            <div class="chart-canvas-wrap"><canvas id="balance-trend-chart" aria-label="<?= $category==='lender'?'Grafik tren pinjaman dan pengembalian':'Grafik tren saldo' ?>" role="img"></canvas><p class="chart-fallback" hidden>Grafik tidak dapat dimuat. Ringkasan nilai tersedia di bagian bawah.</p></div>
        </section>
    </div>
    <section class="finance-summary-section" aria-label="Ringkasan bulan ini">
        <div class="finance-summary-heading">
            <div><h2>Ringkasan Bulan Ini</h2><p><?=e($monthLabel)?></p></div>
            <a class="btn btn-outline btn-small" href="reports.php?month=<?=e($month)?>">Lihat Laporan</a>
        </div>
        <div class="finance-summary-grid">
            <?php if($category==='lender'): ?>
            <article class="finance-summary-metric finance-summary-income">
                <span class="finance-summary-label"><span aria-hidden="true">↗</span> Total Dipinjamkan</span>
                <strong><?=rupiah($lenderChartData['monthLoans'])?></strong>
                <small><?=e($monthLabel)?></small>
            </article>
            <article class="finance-summary-metric finance-summary-expense">
                <span class="finance-summary-label"><span aria-hidden="true">↩</span> Total Pengembalian</span>
                <strong><?=rupiah($lenderChartData['monthRepayments'])?></strong>
                <small><?=e($monthLabel)?></small>
            </article>
            <article class="finance-summary-metric finance-summary-balance">
                <span class="finance-summary-label"><span aria-hidden="true">◷</span> Belum Dikembalikan</span>
                <strong><?=rupiah($lenderOutstanding)?></strong>
                <small>Semua waktu</small>
            </article>
            <?php else: ?>
            <article class="finance-summary-metric finance-summary-income">
                <span class="finance-summary-label"><span aria-hidden="true">↗</span> Total Pemasukan</span>
                <strong><?=rupiah($sum['income'])?></strong>
                <small><?=e($monthLabel)?></small>
            </article>
            <article class="finance-summary-metric finance-summary-expense">
                <span class="finance-summary-label"><span aria-hidden="true">↘</span> Total Pengeluaran</span>
                <strong><?=rupiah($sum['expense'])?></strong>
                <small><?=e($monthLabel)?></small>
            </article>
            <?php if($category==='personal'): ?>
            <article class="finance-summary-metric finance-summary-savings">
                <span class="finance-summary-label"><span aria-hidden="true">▣</span> Total Tabungan</span>
                <strong><?=rupiah($sum['savings'])?></strong>
                <small><?=e($monthLabel)?></small>
            </article>
            <?php endif; ?>
            <article class="finance-summary-metric finance-summary-balance">
                <span class="finance-summary-label"><span aria-hidden="true">◈</span> Saldo Akhir</span>
                <strong><?=rupiah($balance)?></strong>
                <small><?=e($monthLabel)?></small>
            </article>
            <?php endif; ?>
        </div>
    </section>
    <div class="finance-chart-grid finance-secondary-charts">
        <section class="panel finance-chart-card">
            <div class="panel-head"><div><h2><?= $category==='lender'?'Sisa Pinjaman per Peminjam':($category==='personal'?'Kategori Pengeluaran Pribadi':'Kategori Pengeluaran Bisnis') ?></h2><p><?= $category==='lender'?'Maksimal 8 peminjam dengan sisa pinjaman terbesar':'Rincian pengeluaran bulan ini' ?></p></div></div>
            <div class="chart-canvas-wrap"><canvas id="expense-category-chart" aria-label="<?= $category==='lender'?'Grafik sisa pinjaman per peminjam':'Grafik kategori pengeluaran' ?>" role="img"></canvas><p class="chart-fallback" hidden>Grafik tidak dapat dimuat. Ringkasan nilai tersedia di bagian bawah.</p></div>
        </section>
        <?php if($category==='business'): ?>
        <section class="panel finance-chart-card">
            <div class="panel-head"><div><h2>Hutang Belum Lunas per Pihak</h2><p>Maksimal 8 pihak pemberi pinjaman untuk <?=e($monthLabel)?></p></div></div>
            <div class="chart-canvas-wrap"><canvas id="business-debt-chart" aria-label="Grafik sisa hutang bisnis yang belum lunas per pihak" role="img"></canvas><p class="chart-fallback" hidden>Grafik tidak dapat dimuat. Ringkasan nilai tersedia di bagian bawah.</p></div>
        </section>
        <section class="panel finance-chart-card">
            <div class="panel-head"><div><h2>Piutang Belum Dilunasi per Pelanggan</h2><p>Maksimal 8 pelanggan untuk <?=e($monthLabel)?></p></div></div>
            <div class="chart-canvas-wrap"><canvas id="business-receivable-chart" aria-label="Grafik piutang bisnis belum dilunasi per pelanggan" role="img"></canvas><p class="chart-fallback" hidden>Grafik tidak dapat dimuat. Ringkasan nilai tersedia di bagian bawah.</p></div>
        </section>
        <?php endif; ?>
    </div>
    <script type="application/json" id="finance-chart-data"><?=json_encode($chartData,JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_AMP|JSON_HEX_QUOT)?></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.8/dist/chart.umd.min.js"></script>
</main>
<?php include __DIR__.'/includes/footer.php'; ?>