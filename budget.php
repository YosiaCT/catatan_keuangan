<?php
declare(strict_types=1);

$page_title='Anggaran Bulanan';
require_once __DIR__ . '/config/auth.php';
require_login();

$userId=(int)$_SESSION['user_id'];
$category=selected_category();
if($category!=='personal'){
    header('Location: dashboard.php');
    exit;
}

$month=$_GET['month']??date('Y-m');
if(!preg_match('/^\d{4}-\d{2}$/',$month))$month=date('Y-m');
$expenseOptions=transaction_category_options('personal')['expense'];
$stmt=$pdo->prepare("SELECT DISTINCT transaction_category FROM transactions
    WHERE user_id=? AND category_type='personal' AND type='expense'
        AND transaction_category IS NOT NULL AND transaction_category<>'' AND transaction_category<>'Tabungan'");
$stmt->execute([$userId]);
foreach($stmt->fetchAll(PDO::FETCH_COLUMN) as $storedCategory){
    if(!in_array($storedCategory,$expenseOptions,true))$expenseOptions[]=$storedCategory;
}

$error=null;
if($_SERVER['REQUEST_METHOD']==='POST'){
    verify_csrf();
    $month=$_POST['month']??date('Y-m');
    if(!preg_match('/^\d{4}-\d{2}$/',$month))$month=date('Y-m');
    $expenseCategory=trim($_POST['expense_category']??'');
    $amount=parse_currency_amount($_POST['amount']??null);
    if(!in_array($expenseCategory,$expenseOptions,true) || $expenseCategory==='Tabungan' || $amount===null || $amount<=0){
        $error='Pilih kategori pengeluaran dan masukkan anggaran lebih besar dari Rp 0.';
    }else{
        $stmt=$pdo->prepare("INSERT INTO personal_budgets(user_id,month,expense_category,amount)
            VALUES(?,?,?,?) ON DUPLICATE KEY UPDATE amount=VALUES(amount)");
        $stmt->execute([$userId,$month,$expenseCategory,$amount]);
        flash('success','Anggaran berhasil disimpan.');
        header('Location: budget.php?month='.rawurlencode($month));
        exit;
    }
}

$stmt=$pdo->prepare("SELECT budget.expense_category,budget.amount,
        COALESCE(SUM(CASE WHEN txn.type='expense' THEN txn.amount ELSE 0 END),0) spent
    FROM personal_budgets budget
    LEFT JOIN transactions txn
        ON txn.user_id=budget.user_id AND txn.category_type='personal'
        AND txn.type='expense' AND txn.transaction_category=budget.expense_category
        AND DATE_FORMAT(txn.transaction_date,'%Y-%m')=budget.month
    WHERE budget.user_id=? AND budget.month=?
    GROUP BY budget.id ORDER BY budget.expense_category");
$stmt->execute([$userId,$month]);
$budgets=$stmt->fetchAll();
$monthParts=explode('-',$month);
$monthName=['Januari','Februari','Maret','April','Mei','Juni','Juli','Agustus','September','Oktober','November','Desember'][(int)$monthParts[1]-1].' '.$monthParts[0];

include __DIR__ . '/includes/header.php';
include __DIR__ . '/includes/sidebar.php';
?>
<main class="main">
    <div class="page-head">
        <div><h1>Anggaran Catatan Pribadi</h1><p>Atur batas pengeluaran bulanan per kategori.</p></div>
        <form class="filters" method="get">
            <div class="field"><label for="budget-month">Bulan</label><input id="budget-month" type="month" name="month" value="<?=e($month)?>"></div>
            <button class="btn btn-primary">Tampilkan</button>
        </form>
    </div>
    <?php if($message=flash('success')): ?><div class="notice"><?=e($message)?></div><?php endif; ?>
    <?php if($error): ?><div class="login-error"><?=e($error)?></div><?php endif; ?>
    <section class="panel">
        <div class="panel-head"><div><h2>Tambah atau Ubah Anggaran</h2><p>Anggaran yang sudah ada untuk kategori dan bulan yang sama akan diperbarui.</p></div></div>
        <form method="post" class="form-grid">
            <input type="hidden" name="csrf" value="<?=e(csrf_token())?>">
            <input type="hidden" name="month" value="<?=e($month)?>">
            <div class="field"><label for="expense-category">Kategori Pengeluaran</label>
                <select id="expense-category" name="expense_category" required>
                    <option value="">Pilih kategori</option>
                    <?php foreach($expenseOptions as $option): ?>
                    <?php if($option!=='Tabungan'): ?><option value="<?=e($option)?>" <?=($_POST['expense_category']??'')===$option?'selected':''?>><?=e($option)?></option><?php endif; ?>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="field"><label for="budget-amount">Batas Anggaran</label><div class="amount-input"><span>Rp</span><input id="budget-amount" type="text" name="amount" inputmode="decimal" required data-currency-input value="<?=e($_POST['amount']??'')?>"></div></div>
            <div class="field full"><button class="btn btn-primary">Simpan Anggaran</button></div>
        </form>
    </section>
    <section class="panel">
        <div class="panel-head"><div><h2>Anggaran <?=e($monthName)?></h2><p><?=count($budgets)?> kategori dianggarkan</p></div></div>
        <?php if($budgets): ?>
        <div class="budget-list">
            <?php foreach($budgets as $budget):
                $amount=(float)$budget['amount'];
                $spent=(float)$budget['spent'];
                $ratio=$amount>0?$spent/$amount:0;
                $progress=min(100,$ratio*100);
                $state=$ratio>=1?'over':($ratio>=0.8?'warning':'on-track');
            ?>
            <article class="budget-item">
                <div class="budget-item-heading"><strong><?=e($budget['expense_category'])?></strong><span><?=rupiah($spent)?> / <?=rupiah($amount)?></span></div>
                <div class="budget-progress"><span class="<?=$state?>" style="width:<?=number_format($progress,2,'.','')?>%"></span></div>
                <small><?=$ratio>=1?'Anggaran terlampaui':($ratio>=0.8?'Mendekati batas':'Masih dalam anggaran')?><?= $ratio>1?' sebesar '.rupiah($spent-$amount):'' ?></small>
            </article>
            <?php endforeach; ?>
        </div>
        <?php else: ?>
        <p class="empty">Belum ada anggaran untuk bulan ini.</p>
        <?php endif; ?>
    </section>
</main>
<?php include __DIR__ . '/includes/footer.php'; ?>
