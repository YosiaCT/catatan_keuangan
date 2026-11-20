<?php
$page_title='Stok Barang';
require_once __DIR__ . '/config/auth.php';require_login();
$userId=(int)$_SESSION['user_id'];$category=selected_category();
if($category!=='business'){
 header('Location: dashboard.php');
 exit;
}
if($_SERVER['REQUEST_METHOD']==='POST'){
 verify_csrf();
 if(($_POST['action']??'')==='delete'){
  http_response_code(405);
  exit('Fitur hapus stok tidak tersedia.');
 }
}
$stmt=$pdo->prepare("SELECT * FROM stocks WHERE user_id=? AND category_type=? ORDER BY item_name");$stmt->execute([$userId,$category]);$stocks=$stmt->fetchAll();
include __DIR__.'/includes/header.php';include __DIR__.'/includes/sidebar.php';
?>
<main class="main">
<div class="page-head"><div><h1>Stok Barang</h1><p>Kelola stok usaha dan harga barang.</p></div><a class="btn btn-primary" href="stock_form.php">+ Tambah Stok</a></div>
<div class="panel"><div class="panel-head"><div><h2>Daftar Stok</h2><p><?= count($stocks) ?> jenis barang</p></div></div>
<div class="table-wrap"><table class="table"><thead><tr><th>Barang</th><th>Stok</th><th>Harga Beli/Unit</th><th>Harga Jual</th><th>Aksi</th></tr></thead><tbody><?php foreach($stocks as $s): ?><tr><td><?=e($s['item_name'])?></td><td><?=e((string)$s['stock_qty'])?> <?=e($s['unit'])?></td><td><?=rupiah($s['buy_price'])?></td><td><?=rupiah($s['sell_price'])?></td><td><div class="actions"><a class="btn btn-warning" href="stock_form.php?id=<?=(int)$s['id']?>">✎ Edit</a></div></td></tr><?php endforeach;?><?php if(!$stocks): ?><tr><td colspan="5" class="empty">Belum ada stok.</td></tr><?php endif;?></tbody></table></div></div>
</main><?php include __DIR__.'/includes/footer.php'; ?>
