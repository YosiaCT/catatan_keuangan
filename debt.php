<?php
$page_title='Hutang';
require_once __DIR__ . '/config/auth.php'; require_login();
$userId=(int)$_SESSION['user_id']; $category=selected_category();
$status=$_GET['status'] ?? ''; $month=$_GET['month'] ?? '';
$sql="SELECT * FROM transactions WHERE user_id=? AND category_type=? AND type='debt'";
$params=[$userId,$category];
if(in_array($status,['paid','unpaid'],true)){ $sql.=" AND status=?"; $params[]=$status; }
if(preg_match('/^\d{4}-\d{2}$/',$month)){ $sql.=" AND DATE_FORMAT(transaction_date,'%Y-%m')=?"; $params[]=$month; }
$sql.=" ORDER BY transaction_date DESC,id DESC";
$stmt=$pdo->prepare($sql); $stmt->execute($params); $rows=$stmt->fetchAll();

// Summary
$sTotal=$pdo->prepare("SELECT COALESCE(SUM(amount),0) t, COALESCE(SUM(CASE WHEN status='unpaid' THEN amount ELSE 0 END),0) u FROM transactions WHERE user_id=? AND category_type=? AND type='debt'");
$sTotal->execute([$userId,$category]); $summary=$sTotal->fetch();

include __DIR__ . '/includes/header.php'; include __DIR__ . '/includes/sidebar.php';
?>
<main class="main">
<div class="page-head">
    <div><h1>Hutang</h1><p>Daftar hutang yang harus dibayar.</p></div>
    <a class="btn btn-primary" href="transaction_form.php?preset=debt">+ Catat Hutang</a>
</div>
<?php if($msg=flash('success')): ?><div class="notice"><?= e($msg) ?></div><?php endif; ?>
<?php if($err=flash('error')): ?><div class="notice" style="background:#f8d7da;color:#842029;border:1px solid #f5c2c7;">
    <?= e($err) ?>
</div><?php endif; ?>

<div class="cards" style="margin-bottom:20px; grid-template-columns: repeat(2,1fr);">
    <div class="stat-card debt"><div class="stat-title">🔴 Total Hutang</div><div class="stat-value"><?= rupiah($summary['t']) ?></div></div>
    <div class="stat-card expense"><div class="stat-title">⏳ Belum Lunas</div><div class="stat-value"><?= rupiah($summary['u']) ?></div></div>
</div>

<div class="panel">
<form class="filters" method="get">
    <div class="field"><label>Status</label><select name="status">
        <option value="">Semua</option>
        <option value="unpaid" <?= $status==='unpaid'?'selected':'' ?>>Belum Lunas</option>
        <option value="paid" <?= $status==='paid'?'selected':'' ?>>Lunas</option>
    </select></div>
    <div class="field"><label>Bulan</label><input type="month" name="month" value="<?= e($month) ?>"></div>
    <button class="btn btn-primary">Filter</button>
    <a class="btn btn-light" href="debt.php">Reset</a>
</form>
</div>

<div class="panel"><div class="table-wrap"><table class="table">
<thead><tr><th>No</th><th>Tanggal</th><th>Keterangan</th><th>Kepada</th><th>Jatuh Tempo</th><th>Jumlah</th><th>Status</th><th>Aksi</th></tr></thead>
<tbody>
<?php foreach($rows as $i=>$r): ?>
<?php
$paidStmt=$pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM transactions WHERE user_id=? AND category_type=? AND type='expense' AND loan_id=?");
$paidStmt->execute([$userId,$r['category_type'],$r['id']]);
$paidAmount=(float)$paidStmt->fetchColumn();
$remaining=max(0,(float)$r['amount']-$paidAmount);
?>
<tr>
    <td><?= $i+1 ?></td>
    <td><?= e(date('d-m-Y',strtotime($r['transaction_date']))) ?></td>
    <td><?= e($r['description']) ?></td>
    <td><?= e($r['person_name'] ?: '-') ?></td>
    <td><?= $r['due_date'] ? e(date('d-m-Y',strtotime($r['due_date']))) : '-' ?></td>
    <td>
        <strong><?= rupiah($r['amount']) ?></strong>
        <?php if($r['status']==='unpaid'): ?>
            <?php $progress=$r['amount']>0?min(100,($paidAmount/(float)$r['amount'])*100):0; ?>
            <div class="debt-progress" aria-label="Terbayar <?= number_format($progress,0) ?> persen">
                <div class="debt-progress-track"><span style="width:<?= number_format($progress,2,'.','') ?>%"></span></div>
                <div class="debt-progress-label"><span>Terbayar <?= rupiah($paidAmount) ?></span><strong>Sisa <?= rupiah($remaining) ?></strong></div>
            </div>
        <?php endif; ?>
    </td>
    <td>
        <?php if($r['status']==='paid'): ?>
            <span class="badge" style="background:#198754;">✓ Lunas</span>
        <?php else: ?>
            <span class="badge" style="background:#dc3545;">Belum Lunas</span>
        <?php endif; ?>
    </td>
    <td><div class="debt-actions">
        <?php if($r['status']==='unpaid'): ?>
        <form method="post" action="transaction_action.php" onsubmit="return confirm('Tandai hutang ini lunas? Akan otomatis tercatat sebagai pengeluaran.')">
            <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="pay">
            <input type="hidden" name="id" value="<?= $r['id'] ?>">
            <input type="hidden" name="ref" value="debt">
            <button class="btn btn-success btn-small">✓ Lunasi</button>
        </form>
        <button class="btn btn-primary btn-small" type="button" data-debt-installment-trigger
            data-debt-id="<?= (int)$r['id'] ?>"
            data-debt-description="<?= e($r['description']) ?>"
            data-debt-remaining="<?= e((string)$remaining) ?>">Cicil</button>
        <?php endif; ?>
        <a class="btn btn-warning btn-small" href="transaction_form.php?id=<?= $r['id'] ?>">✎ Edit</a>
    </div></td>
</tr>
<?php endforeach; ?>
<?php if(!$rows): ?><tr><td colspan="8" class="empty">Tidak ada data hutang.</td></tr><?php endif; ?>
</tbody></table></div></div>

<dialog class="debt-installment-dialog" aria-labelledby="debt-installment-title" data-debt-installment-dialog>
    <form method="post" action="transaction_action.php" class="debt-installment-content" data-debt-installment-form>
        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="action" value="pay_installment">
        <input type="hidden" name="id" value="">
        <input type="hidden" name="ref" value="debt">
        <div class="debt-installment-heading">
            <div>
                <h2 id="debt-installment-title">Bayar cicilan hutang</h2>
                <p data-debt-installment-description></p>
            </div>
            <button class="debt-installment-close" type="button" aria-label="Tutup" data-debt-installment-close>&times;</button>
        </div>
        <div class="debt-installment-balance">
            <span>Sisa hutang</span>
            <strong data-debt-installment-remaining></strong>
        </div>
        <div class="field">
            <label for="debt-installment-amount">Jumlah cicilan</label>
            <div class="amount-input">
                <span>Rp</span>
                <input id="debt-installment-amount" type="text" name="amount" inputmode="decimal" data-currency-input required placeholder="Masukkan jumlah">
            </div>
            <small>Jumlah tidak boleh melebihi sisa hutang.</small>
        </div>
        <div class="debt-installment-footer">
            <button class="btn btn-light" type="button" data-debt-installment-close>Batal</button>
            <button class="btn btn-primary" type="submit">Simpan cicilan</button>
        </div>
    </form>
</dialog>
</main>
<?php include __DIR__ . '/includes/footer.php'; ?>
