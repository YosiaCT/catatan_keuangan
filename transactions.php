<?php
$page_title='Transaksi';
require_once __DIR__ . '/config/auth.php'; require_login();
$userId=(int)$_SESSION['user_id']; $category=selected_category();
$type=$_GET['type'] ?? ''; $month=$_GET['month'] ?? '';
$transactionCategory=$_GET['transaction_category']??'';
$categoryOptions=transaction_category_options($category);
$allowedCategories=array_values(array_unique(array_merge($categoryOptions['income'],$categoryOptions['expense'])));
$stmt=$pdo->prepare("SELECT DISTINCT transaction_category FROM transactions WHERE user_id=? AND category_type=? AND type IN ('income','expense','savings') AND transaction_category IS NOT NULL AND transaction_category<>''");
$stmt->execute([$userId,$category]);
foreach($stmt->fetchAll(PDO::FETCH_COLUMN) as $storedCategory){
    if(!in_array($storedCategory,$allowedCategories,true))$allowedCategories[]=$storedCategory;
}
if(!in_array($transactionCategory,$allowedCategories,true))$transactionCategory='';
$isSavingsView=$category==='personal' && ($type==='savings' || ($type==='expense' && $transactionCategory==='Tabungan'));
$isRepaymentView=$category==='lender' && $type==='income' && in_array($transactionCategory,['Pengembalian Pinjaman','Penerimaan Piutang'],true);
$isCapitalView=$category==='lender' && $type==='income' && $transactionCategory===LENDER_CAPITAL_CATEGORY;
$page_title=$isSavingsView?'Tabungan':($isCapitalView?'Modal':($isRepaymentView?'Pengembalian':($type==='income'?'Penghasilan':'Transaksi')));
$sql="SELECT * FROM transactions WHERE user_id=? AND category_type=? AND type IN ('income','expense','savings')"; 
$params=[$userId,$category];
if(in_array($type,['income','expense'],true)){ $sql.=" AND type=?"; $params[]=$type; }
elseif($type==='savings' && $category==='personal'){ $sql.=" AND (type='savings' OR (type='expense' AND transaction_category='Tabungan'))"; }
if($transactionCategory!==''){ $sql.=" AND transaction_category=?"; $params[]=$transactionCategory; }
if(preg_match('/^\d{4}-\d{2}$/',$month)){ $sql.=" AND DATE_FORMAT(transaction_date,'%Y-%m')=?"; $params[]=$month; }
$sql.=" ORDER BY transaction_date DESC,id DESC";
$stmt=$pdo->prepare($sql); $stmt->execute($params); $rows=$stmt->fetchAll();
include __DIR__ . '/includes/header.php'; include __DIR__ . '/includes/sidebar.php';
?>
<main class="main">
<div class="page-head">
    <div><h1><?=e($page_title)?></h1><p><?= $isSavingsView?'Daftar dana yang dialokasikan sebagai tabungan.':($isCapitalView?'Daftar setoran modal dan asal dananya.':($isRepaymentView?'Daftar pengembalian pinjaman yang diterima.':($type==='income'?'Daftar pemasukan dan gaji yang tercatat.':'Kelola pemasukan dan pengeluaran.'))) ?></p></div>
    <div class="actions">
        <?php if($category==='lender'): ?><a class="btn btn-primary" href="transaction_form.php?preset=capital">+ Tambah Modal</a><a class="btn btn-outline" href="transaction_form.php">+ Transaksi Lain</a>
        <?php else: ?><a class="btn btn-primary" href="<?= $isSavingsView?'transaction_form.php?preset=savings':'transaction_form.php' ?>"><?= $isSavingsView?'🏦 Tambah Tabungan':'+ Tambah Transaksi' ?></a><?php endif; ?>
    </div>
</div>
<?php if($msg=flash('success')): ?><div class="notice"><?= e($msg) ?></div><?php endif; ?>
<div class="panel">
<form class="filters" method="get">
    <div class="field"><label>Jenis</label><select name="type">
        <option value="">Semua</option>
        <option value="income" <?= $type==='income'?'selected':'' ?>>Pemasukan</option>
        <option value="expense" <?= $type==='expense'?'selected':'' ?>>Pengeluaran</option>
        <?php if($category==='personal'): ?><option value="savings" <?= $type==='savings'?'selected':'' ?>>Tabungan</option><?php endif; ?>
    </select></div>
    <div class="field"><label>Kategori</label><select name="transaction_category">
        <option value="">Semua kategori</option>
        <?php foreach($allowedCategories as $option): ?><option value="<?=e($option)?>" <?=$transactionCategory===$option?'selected':''?>><?=e($option)?></option><?php endforeach; ?>
    </select></div>
    <div class="field"><label>Bulan</label><input type="month" name="month" value="<?= e($month) ?>"></div>
    <button class="btn btn-primary">Filter</button>
    <a class="btn btn-light" href="transactions.php">Reset</a>
</form>
</div>
<div class="panel"><div class="table-wrap"><table class="table">
<thead><tr><th>No</th><th>Tanggal</th><th>Keterangan</th><th>Jenis</th><th>Kategori</th><?php if($category==='lender'): ?><th>Asal Dana</th><?php endif; ?><th>Jumlah</th><th>Aksi</th></tr></thead>
<tbody>
<?php foreach($rows as $i=>$r): ?>
<tr>
    <td><?= $i+1 ?></td>
    <td><?= e(date('d-m-Y',strtotime($r['transaction_date']))) ?></td>
    <td><?= e($r['description']) ?></td>
    <td><span class="badge <?= type_class($r['type']) ?>"><?= e(transaction_type_label($r['type'])) ?></span></td>
    <td><?= e($r['transaction_category'] ?: 'Lainnya') ?></td>
    <?php if($category==='lender'): ?><td><?=e($r['funding_source']?:($r['type']==='income' && empty($r['loan_id'])?$r['description']:'-'))?></td><?php endif; ?>
    <td><strong><?= rupiah($r['amount']) ?></strong></td>
    <td><div class="actions">
        <a class="btn btn-warning btn-small" href="transaction_form.php?id=<?= $r['id'] ?>">✎ Edit</a>
    </div></td>
</tr>
<?php endforeach; ?>
<?php if(!$rows): ?><tr><td colspan="<?=$category==='lender'?8:7?>" class="empty">Tidak ada data transaksi.</td></tr><?php endif; ?>
</tbody></table></div></div>
</main>
<?php include __DIR__ . '/includes/footer.php'; ?>
