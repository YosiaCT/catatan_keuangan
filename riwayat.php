<?php
$page_title='Riwayat Transaksi';
require_once __DIR__ . '/config/auth.php';
require_login();

$userId=(int)$_SESSION['user_id'];
$category=selected_category();
$type=$_GET['type']??'';
$month=$_GET['month']??'';
$allowedTypes=['income','expense','debt','receivable'];
if($category==='personal')$allowedTypes[]='savings';
if(!in_array($type,$allowedTypes,true))$type='';
if(!preg_match('/^\d{4}-\d{2}$/',$month))$month='';

$where='user_id=? AND category_type=?';
$params=[$userId,$category];
if($type!==''){
    $where.=' AND type=?';
    $params[]=$type;
}
if($month!==''){
    $where.=" AND DATE_FORMAT(transaction_date,'%Y-%m')=?";
    $params[]=$month;
}

$stmt=$pdo->prepare("SELECT COUNT(*) FROM transactions WHERE $where");
$stmt->execute($params);
$totalRows=(int)$stmt->fetchColumn();
$perPage=20;
$totalPages=max(1,(int)ceil($totalRows/$perPage));
$page=min($totalPages,max(1,(int)($_GET['page']??1)));
$offset=($page-1)*$perPage;

$stmt=$pdo->prepare("SELECT * FROM transactions WHERE $where ORDER BY transaction_date DESC,id DESC LIMIT $perPage OFFSET $offset");
$stmt->execute($params);
$transactions=$stmt->fetchAll();

include __DIR__ . '/includes/header.php';
include __DIR__ . '/includes/sidebar.php';
?>
<main class="main">
    <div class="page-head"><div><h1>Riwayat Transaksi</h1><p>Semua transaksi pada kategori <?=e(category_label($category))?>.</p></div><a class="btn btn-primary" href="transaction_form.php">+ Transaksi</a></div>
    <?php if($message=flash('success')): ?><div class="notice"><?=e($message)?></div><?php endif; ?>
    <div class="panel">
        <form class="filters" method="get">
            <div class="field"><label>Jenis</label><select name="type">
                <option value="">Semua</option>
                <?php foreach($allowedTypes as $option): ?><option value="<?=e($option)?>" <?=$type===$option?'selected':''?>><?=e(transaction_type_label($option))?></option><?php endforeach; ?>
            </select></div>
            <div class="field"><label>Bulan</label><input type="month" name="month" value="<?=e($month)?>"></div>
            <button class="btn btn-primary">Filter</button>
            <a class="btn btn-light" href="riwayat.php">Reset</a>
        </form>
    </div>
    <div class="panel">
        <div class="panel-head"><div><h2>Daftar Transaksi</h2><p><?=number_format($totalRows,0,',','.')?> transaksi</p></div></div>
        <div class="table-wrap"><table class="table">
            <thead><tr><th>No</th><th>Tanggal</th><th>Keterangan</th><th>Jenis</th><th>Kategori / Status</th><th>Jumlah</th><th>Aksi</th></tr></thead>
            <tbody>
                <?php foreach($transactions as $index=>$row): ?>
                <tr>
                    <td><?=$offset+$index+1?></td>
                    <td><?=e(date('d-m-Y',strtotime($row['transaction_date'])))?></td>
                    <td><?=e($row['description'])?><?=$row['person_name']?' - '.e($row['person_name']):''?></td>
                    <td><span class="badge <?=type_class($row['type'])?>"><?=e(transaction_type_label($row['type']))?></span></td>
                    <td><?=in_array($row['type'],['debt','receivable'],true)?'<span class="badge status">'.($row['status']==='paid'?($row['type']==='receivable'?'Diterima':'Lunas'):($row['type']==='receivable'?'Belum Dilunaskan':'Belum Lunas')).'</span>':e($row['transaction_category']?:'Lainnya')?></td>
                    <td><strong><?=rupiah($row['amount'])?></strong></td>
                    <td><div class="actions">
                        <a class="btn btn-warning" href="transaction_form.php?id=<?=(int)$row['id']?>">✎ Edit</a>
                        <?php if($row['type']==='debt' && $row['status']==='unpaid'): ?>
                        <form method="post" action="transaction_action.php"><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="action" value="pay"><input type="hidden" name="id" value="<?=(int)$row['id']?>"><input type="hidden" name="ref" value="riwayat"><button class="btn btn-success">✓ Lunasi Hutang</button></form>
                        <?php endif; ?>
                    </div></td>
                </tr>
                <?php endforeach; ?>
                <?php if(!$transactions): ?><tr><td colspan="7" class="empty">Belum ada transaksi.</td></tr><?php endif; ?>
            </tbody>
        </table></div>
        <?php if($totalPages>1): ?>
        <nav class="pagination" aria-label="Navigasi halaman riwayat">
            <?php for($pageNumber=1;$pageNumber<=$totalPages;$pageNumber++): ?>
            <a class="<?=$pageNumber===$page?'active':''?>" href="?<?=e(http_build_query(array_filter(['type'=>$type,'month'=>$month,'page'=>$pageNumber],static fn($value)=>$value!=='')))?>"><?=$pageNumber?></a>
            <?php endfor; ?>
        </nav>
        <?php endif; ?>
    </div>
</main>
<?php include __DIR__ . '/includes/footer.php'; ?>