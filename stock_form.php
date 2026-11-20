<?php
require_once __DIR__ . '/config/auth.php';
require_login();

$userId=(int)$_SESSION['user_id'];
$category=selected_category();
if($category!=='business'){
    header('Location: dashboard.php');
    exit;
}
$id=(int)($_GET['id']??0);
$row=['item_name'=>'','stock_qty'=>0,'unit'=>'pcs','buy_price'=>0,'sell_price'=>0];
$initialStockQty=0;
$buyInputValue='0';
if($id){
    $stmt=$pdo->prepare('SELECT * FROM stocks WHERE id=? AND user_id=? AND category_type=?');
    $stmt->execute([$id,$userId,$category]);
    $found=$stmt->fetch();
    if(!$found){
        http_response_code(404);
        exit('Barang stok tidak ditemukan.');
    }
    $row=$found;
    $initialStockQty=(int)$row['stock_qty'];
}
$page_title=$id?'Edit Stok':'Tambah Stok';
$error=null;

if($_SERVER['REQUEST_METHOD']==='POST'){
    verify_csrf();
    $id=(int)($_POST['id']??0);
    $name=trim($_POST['item_name']??'');
    $qty=(int)($_POST['stock_qty']??0);
    $unit=trim($_POST['unit']??'pcs');
    $buy=parse_currency_amount($_POST['buy_price']??null);
    $sell=parse_currency_amount($_POST['sell_price']??null);
    $buyInputValue=(string)($_POST['buy_price']??'0');

    if($id){
        $stmt=$pdo->prepare('SELECT * FROM stocks WHERE id=? AND user_id=? AND category_type=?');
        $stmt->execute([$id,$userId,$category]);
        $row=$stmt->fetch();
        if(!$row){
            http_response_code(404);
            exit('Barang stok tidak ditemukan.');
        }
        $initialStockQty=(int)$row['stock_qty'];
    }

    if(!$name || !$unit || $qty<0 || $buy===null || $sell===null || $buy<0 || $sell<0){
        $error='Lengkapi data barang dengan benar.';
        $row=array_merge($row,['item_name'=>$name,'stock_qty'=>$qty,'unit'=>$unit,'buy_price'=>$buy,'sell_price'=>$sell]);
    }else{
        $stmt=$pdo->prepare('SELECT id FROM stocks WHERE user_id=? AND category_type=? AND item_name=? AND id<>?');
        $stmt->execute([$userId,$category,$name,$id]);
        if($stmt->fetchColumn()){
            $error='Nama barang tersebut sudah ada di daftar stok.';
            $row=array_merge($row,['item_name'=>$name,'stock_qty'=>$qty,'unit'=>$unit,'buy_price'=>$buy,'sell_price'=>$sell]);
        }else{
            $previousQty=$id?(int)$row['stock_qty']:0;
            $addedQty=max(0,$qty-$previousQty);
            $purchaseExpense=$addedQty>0?$buy:0;
            $buyPricePerUnit=$addedQty>0?round($buy/$addedQty,2):(float)$row['buy_price'];

            $pdo->beginTransaction();
            try{
                if($id){
                    $stmt=$pdo->prepare('UPDATE stocks SET item_name=?,stock_qty=?,unit=?,buy_price=?,sell_price=? WHERE id=? AND user_id=? AND category_type=?');
                    $stmt->execute([$name,$qty,$unit,$buyPricePerUnit,$sell,$id,$userId,$category]);
                }else{
                    $stmt=$pdo->prepare('INSERT INTO stocks(user_id,category_type,item_name,stock_qty,unit,buy_price,sell_price) VALUES(?,?,?,?,?,?,?)');
                    $stmt->execute([$userId,$category,$name,$qty,$unit,$buyPricePerUnit,$sell]);
                }

                if($purchaseExpense>0){
                    $description='Pembelian stok: '.$name.' ('.$addedQty.' '.$unit.')';
                    $stmt=$pdo->prepare("INSERT INTO transactions(user_id,category_type,type,transaction_category,transaction_date,description,amount,status) VALUES(?,?,'expense','Pembelian',?,?,?,'paid')");
                    $stmt->execute([$userId,$category,date('Y-m-d'),$description,$purchaseExpense]);
                }
                $pdo->commit();
            }catch(Throwable $e){
                if($pdo->inTransaction())$pdo->rollBack();
                throw $e;
            }

            $message=$id?'Stok berhasil diperbarui.':'Stok berhasil ditambahkan.';
            if($purchaseExpense>0){
                $message.=' Pengeluaran pembelian '.rupiah($purchaseExpense).' tercatat otomatis.';
            }
            flash('success',$message);
            header('Location: stock.php');
            exit;
        }
    }
}

include __DIR__.'/includes/header.php';
include __DIR__.'/includes/sidebar.php';
?>
<main class="main">
<div class="page-head"><div><h1><?=$id?'Edit':'Tambah'?> Stok</h1><p>Catat barang dan harga untuk kategori <?=e(category_label($category))?>. Penambahan jumlah stok otomatis dicatat sebagai pengeluaran.</p></div></div>
<div class="panel">
    <?php if($error): ?><div class="login-error"><?=e($error)?></div><?php endif; ?>
    <form method="post">
        <input type="hidden" name="csrf" value="<?=e(csrf_token())?>">
        <input type="hidden" name="id" value="<?=$id?>">
        <div class="form-grid">
            <div class="field full"><label>Nama Barang</label><input name="item_name" value="<?=e($row['item_name'])?>" required autofocus></div>
            <div class="field"><label>Jumlah</label><input type="number" name="stock_qty" min="0" value="<?=e((string)$row['stock_qty'])?>" required data-clear-zero data-stock-quantity></div>
            <div class="field"><label>Satuan</label><input name="unit" value="<?=e($row['unit'])?>" required></div>
            <div class="field"><label>Total Harga Beli untuk Stok yang Ditambahkan</label><div class="amount-input"><span>Rp</span><input type="text" name="buy_price" inputmode="decimal" data-currency-input data-currency-zero-allowed data-stock-total-price value="<?=e($buyInputValue)?>" required></div><small>Jumlah inilah yang dicatat sebagai pengeluaran saat stok bertambah. <strong data-stock-cost-preview data-initial-quantity="<?=$initialStockQty?>">Rp 0</strong></small></div>
            <div class="field"><label>Harga Jual</label><div class="amount-input"><span>Rp</span><input type="text" name="sell_price" inputmode="decimal" data-currency-input data-currency-zero-allowed value="<?=e((string)$row['sell_price'])?>" required></div></div>
            <div class="field full" style="display:flex;gap:10px;flex-wrap:wrap">
                <button class="btn btn-primary"><?=$id?'Simpan Perubahan':'Simpan Stok'?></button>
                <a class="btn btn-outline" href="stock.php">Batal</a>
            </div>
        </div>
    </form>
</div>
</main>
<?php include __DIR__.'/includes/footer.php'; ?>