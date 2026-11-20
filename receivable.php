<?php
$page_title='Piutang';
require_once __DIR__ . '/config/auth.php'; require_login();
$userId=(int)$_SESSION['user_id']; $category=selected_category();
$isLender=$category==='lender';
if($isLender)$page_title='Peminjam';
$status=$_GET['status'] ?? ''; $month=$_GET['month'] ?? '';
$remainingSql="GREATEST(loan.amount-COALESCE((SELECT SUM(repayment.amount) FROM transactions repayment WHERE repayment.user_id=loan.user_id AND repayment.category_type=loan.category_type AND repayment.type='income' AND repayment.loan_id=loan.id),0),0)";
$statusSql="CASE WHEN loan.status='paid' OR ($remainingSql)<=0 THEN 'paid' ELSE 'unpaid' END";
$sql=$isLender
    ?"SELECT loan.*,($remainingSql) outstanding,($statusSql) display_status FROM transactions loan WHERE loan.user_id=? AND loan.category_type=? AND loan.type='receivable'"
    :"SELECT * FROM transactions WHERE user_id=? AND category_type=? AND type='receivable'";
$params=[$userId,$category];
if(in_array($status,['paid','unpaid'],true)){ $sql.=' AND '.($isLender?'('.$statusSql.')':'status').'=?'; $params[]=$status; }
if(preg_match('/^\d{4}-\d{2}$/',$month)){ $sql.=" AND DATE_FORMAT(transaction_date,'%Y-%m')=?"; $params[]=$month; }
$sql.=" ORDER BY transaction_date DESC,id DESC";
$stmt=$pdo->prepare($sql); $stmt->execute($params); $rows=$stmt->fetchAll();

// Summary
$sTotal=$pdo->prepare($isLender
    ?"SELECT COALESCE(SUM(amount),0) t,COALESCE(SUM(CASE WHEN status='paid' THEN 0 ELSE ($remainingSql) END),0) u FROM transactions loan WHERE user_id=? AND category_type=? AND type='receivable'"
    :"SELECT COALESCE(SUM(amount),0) t, COALESCE(SUM(CASE WHEN status='unpaid' THEN amount ELSE 0 END),0) u FROM transactions WHERE user_id=? AND category_type=? AND type='receivable'");
$sTotal->execute([$userId,$category]); $summary=$sTotal->fetch();

include __DIR__ . '/includes/header.php'; include __DIR__ . '/includes/sidebar.php';
?>
<main class="main">
<div class="page-head">
    <div><h1><?=$isLender?'Peminjam':'Piutang'?></h1><p><?=$isLender?'Daftar orang yang menerima pinjaman dari kamu.':'Daftar piutang yang belum diterima.'?></p></div>
    <a class="btn btn-primary" href="transaction_form.php?preset=receivable"><?=$isLender?'+ Tambah Peminjam':'+ Catat Piutang'?></a>
</div>
<?php if($msg=flash('success')): ?><div class="notice"><?= e($msg) ?></div><?php endif; ?>
<?php if($err=flash('error')): ?><div class="notice" style="background:#f8d7da;color:#842029;border:1px solid #f5c2c7;"><?= e($err) ?></div><?php endif; ?>

<div class="cards" style="margin-bottom:20px; grid-template-columns: repeat(2,1fr);">
    <div class="stat-card receivable"><div class="stat-title">🔵 <?=$isLender?'Total Dipinjamkan':'Total Piutang'?></div><div class="stat-value"><?= rupiah($summary['t']) ?></div></div>
    <div class="stat-card income"><div class="stat-title">⏳ <?=$isLender?'Belum Dikembalikan':'Belum Diterima'?></div><div class="stat-value"><?= rupiah($summary['u']) ?></div></div>
</div>

<div class="panel">
<form class="filters" method="get">
    <div class="field"><label>Status</label><select name="status">
        <option value="">Semua</option>
        <option value="unpaid" <?= $status==='unpaid'?'selected':'' ?>><?=$isLender?'Belum Lunas':'Belum Diterima'?></option>
        <option value="paid" <?= $status==='paid'?'selected':'' ?>><?=$isLender?'Lunas':'Sudah Diterima'?></option>
    </select></div>
    <div class="field"><label>Bulan</label><input type="month" name="month" value="<?= e($month) ?>"></div>
    <button class="btn btn-primary">Filter</button>
    <a class="btn btn-light" href="receivable.php">Reset</a>
</form>
</div>

<div class="panel"><div class="table-wrap"><table class="table">
<thead><tr><th>No</th><th><?= $isLender?'Tanggal Dipinjamkan':'Tanggal' ?></th><th>Keterangan</th><th><?= $isLender?'Nama Peminjam':'Dari' ?></th><th>Jatuh Tempo</th><th><?= $isLender?'Jumlah Dipinjamkan':'Jumlah' ?></th><?php if($isLender): ?><th>Sisa</th><?php endif; ?><th>Status</th><th>Aksi</th></tr></thead>
<tbody>
<?php foreach($rows as $i=>$r): ?>
<tr>
    <td><?= $i+1 ?></td>
    <td><?= e(date('d-m-Y',strtotime($r['transaction_date']))) ?></td>
    <td><?= e($r['description']) ?></td>
    <td><?= e($r['person_name'] ?: '-') ?></td>
    <td><?= $r['due_date'] ? e(date('d-m-Y',strtotime($r['due_date']))) : '-' ?></td>
    <td><strong><?= rupiah($r['amount']) ?></strong></td>
    <?php if($isLender): ?><td><?=rupiah($r['outstanding'])?></td><?php endif; ?>
    <td>
        <?php if(($isLender?$r['display_status']:$r['status'])==='paid'): ?>
            <span class="badge status-paid">✓ <?=$isLender?'Lunas':'Diterima'?></span>
        <?php else: ?>
            <span class="badge status-unpaid"><?=$isLender?'Belum Lunas':'Belum Diterima'?></span>
        <?php endif; ?>
    </td>
    <td><div class="actions">
        <?php if(!$isLender && $r['status']==='unpaid'): ?>
        <form method="post" action="transaction_action.php" onsubmit="return confirm('<?=$isLender?'Tandai pinjaman ini lunas?':'Tandai piutang ini diterima?'?> Akan otomatis tercatat sebagai pemasukan.')">
            <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="pay">
            <input type="hidden" name="id" value="<?= $r['id'] ?>">
            <input type="hidden" name="ref" value="receivable">
            <button class="btn btn-success btn-small">✓ Terima</button>
        </form>
        <?php endif; ?>
        <?php if($isLender && $r['outstanding']>0): ?><a class="btn btn-success btn-small" href="pengembalian.php?loan_id=<?=(int)$r['id']?>">Catat Kembali</a><?php endif; ?>
        <a class="btn btn-warning btn-small" href="transaction_form.php?id=<?= $r['id'] ?>">✎ Edit</a>
    </div></td>
</tr>
<?php endforeach; ?>
<?php if(!$rows): ?><tr><td colspan="<?=$isLender?9:8?>" class="empty">Tidak ada data <?=$isLender?'pinjaman':'piutang'?>.</td></tr><?php endif; ?>
</tbody></table></div></div>
</main>
<?php include __DIR__ . '/includes/footer.php'; ?>
