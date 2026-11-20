<?php
$page_title = 'Transaksi';
require_once __DIR__ . '/config/auth.php';
require_login();
$userId=(int)$_SESSION['user_id']; $category=selected_category();
$id=(int)($_GET['id'] ?? 0);
$requestedPreset=$_GET['preset']??'income';
$isCapitalPreset=$category==='lender' && $requestedPreset==='capital' && !$id;
$isSavingsPreset=$category==='personal' && $requestedPreset==='savings' && !$id;
$preset=$isCapitalPreset?'income':($isSavingsPreset?'savings':$requestedPreset);
if(!in_array($preset,['income','expense','debt','receivable','savings'],true))$preset='income';
$categoryOptions=transaction_category_options($category);
$defaultCategories=[
    'business'=>['income'=>'Penjualan','expense'=>'Pembelian'],
    'personal'=>['income'=>'Gaji','expense'=>'Makanan'],
    'lender'=>['income'=>LENDER_CAPITAL_CATEGORY,'expense'=>'Biaya Operasional'],
];
$defaultCategories=$defaultCategories[$category]??$defaultCategories['business'];
$defaultCategories+=['debt'=>'','receivable'=>'','savings'=>''];
$row=['type'=>$preset,'transaction_category'=>$isCapitalPreset?LENDER_CAPITAL_CATEGORY:$defaultCategories[$preset],'transaction_date'=>date('Y-m-d'),'description'=>'','amount'=>'','person_name'=>'','funding_source'=>'','due_date'=>'','status'=>in_array($preset,['debt','receivable'],true)?'unpaid':'paid'];

if($id){
    $stmt=$pdo->prepare("SELECT * FROM transactions WHERE id=? AND user_id=? AND category_type=?");
    $stmt->execute([$id,$userId,$category]); $found=$stmt->fetch();
    if($found) $row=array_merge($row,$found); else exit('Transaksi tidak ditemukan.');
    if(in_array($row['type'],['income','expense'],true) && $row['transaction_category'] && !in_array($row['transaction_category'],$categoryOptions[$row['type']],true)){
        array_unshift($categoryOptions[$row['type']],$row['transaction_category']);
    }
}
$isDebtForm=in_array($row['type'],['debt','receivable'],true);
$isCapitalForm=$category==='lender' && $row['type']==='income' && $row['transaction_category']===LENDER_CAPITAL_CATEGORY;
$isLenderIncomeForm=$category==='lender' && $row['type']==='income' && empty($row['loan_id']);
$page_title=$isCapitalPreset || $isCapitalForm?($id?'Edit Modal':'Tambah Modal'):($isSavingsPreset?'Tambah Tabungan':($row['type']==='savings'?($id?'Edit Tabungan':'Tambah Tabungan'):($isDebtForm?($row['type']==='debt'?'Hutang':($category==='lender'?'Peminjam':'Piutang')):'Transaksi')));
$returnPage=$isCapitalForm?'transactions.php?type=income&transaction_category='.rawurlencode(LENDER_CAPITAL_CATEGORY):($row['type']==='debt'?'debt.php':($row['type']==='receivable'?'receivable.php':($row['type']==='savings'?'transactions.php?type=savings':'dashboard.php')));
$error=null;
if($_SERVER['REQUEST_METHOD']==='POST'){
    verify_csrf();
    $type=$isDebtForm?$row['type']:($_POST['type'] ?? 'income');
    $allowed=['income','expense','debt','receivable','savings'];
    $date=$_POST['transaction_date'] ?? date('Y-m-d');
    $description=trim($_POST['description'] ?? '');
    $amount=parse_currency_amount($_POST['amount'] ?? null) ?? 0;
    $person=trim($_POST['person_name'] ?? '');
    $fundingSource=trim($_POST['funding_source'] ?? '');
    $due=$_POST['due_date'] ?: null;
    $submittedStatus=$_POST['status']??'unpaid';
    $transactionCategory=trim($_POST['transaction_category']??'');
    $needsTransactionCategory=in_array($type,['income','expense'],true);
    $validTransactionCategory=!$needsTransactionCategory || in_array($transactionCategory,$categoryOptions[$type]??[],true);
    $validType=in_array($type,$allowed,true) && ($type!=='savings' || $category==='personal');
    $isCapitalAddition=$category==='lender' && $type==='income' && $transactionCategory===LENDER_CAPITAL_CATEGORY;
    $needsFundingSource=$category==='lender' && $type==='income' && empty($row['loan_id']);
    $validFundingSource=!$needsFundingSource || ($fundingSource!=='' && preg_match('/\A.{1,255}\z/us',$fundingSource)===1);
    if(!$validType || !$description || $amount<=0 || !$validTransactionCategory || !$validFundingSource) {
        $error=$validFundingSource?'Lengkapi data transaksi dengan benar.':'Isi asal modal (maksimal 255 karakter).';
        $row=array_merge($row,['type'=>$type,'transaction_category'=>$transactionCategory,'transaction_date'=>$date,'description'=>$description,'amount'=>$amount,'person_name'=>$person,'funding_source'=>$fundingSource,'due_date'=>$due]);
    }
    else{
        $canEditLenderRepaymentStatus=false;
        if($canEditLenderRepaymentStatus && !in_array($submittedStatus,['paid','unpaid'],true)){
            $error='Pilih status pengembalian yang valid.';
        }
        $status=in_array($type,['debt','receivable'],true)
            ?($canEditLenderRepaymentStatus?$submittedStatus:($id && in_array($row['type'],['debt','receivable'],true)?$row['status']:'unpaid'))
            :'paid';
        $isSavings=$category==='personal' && $type==='savings';
        $transactionCategory=$type==='savings'?'Tabungan':($needsTransactionCategory?$transactionCategory:null);
        $fundingSourceToSave=$needsFundingSource
            ?$fundingSource
            :($id && $row['type']==='receivable' && $type==='receivable'?($row['funding_source']??null):null);
        $saveTransaction=static function() use ($pdo,$id,$type,$transactionCategory,$date,$description,$amount,$person,$fundingSourceToSave,$due,$status,$userId,$category): void {
            if($id){
                $stmt=$pdo->prepare("UPDATE transactions SET type=?,transaction_category=?,transaction_date=?,description=?,amount=?,person_name=?,funding_source=?,due_date=?,status=? WHERE id=? AND user_id=? AND category_type=?");
                $stmt->execute([$type,$transactionCategory,$date,$description,$amount,$person?:null,$fundingSourceToSave,$due,$status,$id,$userId,$category]);
            }else{
                $stmt=$pdo->prepare("INSERT INTO transactions(user_id,category_type,type,transaction_category,transaction_date,description,amount,person_name,funding_source,due_date,status) VALUES(?,?,?,?,?,?,?,?,?,?,?)");
                $stmt->execute([$userId,$category,$type,$transactionCategory,$date,$description,$amount,$person?:null,$fundingSourceToSave,$due,$status]);
            }
        };
        $needsCapitalLock=$category==='lender' && in_array($type,['income','expense','receivable'],true);
        if($error===null && $needsCapitalLock){
            $pdo->beginTransaction();
            try{
                $lock=$pdo->prepare('SELECT id FROM users WHERE id=? FOR UPDATE');
                $lock->execute([$userId]);
                if($type==='receivable'){
                    $excludeLoanId=$id && $row['type']==='receivable'?$id:null;
                    $availableCapital=lender_available_capital($pdo,$userId,$excludeLoanId);
                    if($amount>$availableCapital){
                        $error='Modal tersedia hanya '.rupiah(max(0,$availableCapital)).'. Tambahkan modal sebelum mencatat pinjaman ini.';
                        $row=array_merge($row,['type'=>$type,'transaction_category'=>$transactionCategory,'transaction_date'=>$date,'description'=>$description,'amount'=>$amount,'person_name'=>$person,'due_date'=>$due]);
                    }
                }
                if($error===null){
                    $saveTransaction();
                    $pdo->commit();
                }else{
                    $pdo->rollBack();
                }
            }catch(Throwable $exception){
                if($pdo->inTransaction())$pdo->rollBack();
                throw $exception;
            }
        }elseif($error===null){
            $saveTransaction();
        }
        if($error===null){
            flash('success',$isCapitalAddition?'Modal berhasil ditambahkan.':($isSavings?'Tabungan berhasil dicatat.':($isDebtForm?($type==='debt'?'Hutang berhasil disimpan.':($category==='lender'?'Data peminjam berhasil diperbarui.':'Piutang berhasil disimpan.')):'Transaksi berhasil disimpan.')));
            $redirect=$isCapitalAddition?'transactions.php?type=income&transaction_category='.rawurlencode(LENDER_CAPITAL_CATEGORY):($type==='debt'?'debt.php':($type==='receivable'?'receivable.php':($isSavings?'transactions.php?type=savings':'transactions.php')));
            header('Location: '.$redirect);exit;
        }
    }
}
$isCapitalForm=$category==='lender' && $row['type']==='income' && $row['transaction_category']===LENDER_CAPITAL_CATEGORY;
$isLenderIncomeForm=$category==='lender' && $row['type']==='income' && empty($row['loan_id']);
$page_title=$isCapitalForm?($id?'Edit Modal':'Tambah Modal'):($isSavingsPreset?'Tambah Tabungan':($row['type']==='savings'?($id?'Edit Tabungan':'Tambah Tabungan'):($isDebtForm?($row['type']==='debt'?'Hutang':($category==='lender'?'Peminjam':'Piutang')):'Transaksi')));
$returnPage=$isCapitalForm?'transactions.php?type=income&transaction_category='.rawurlencode(LENDER_CAPITAL_CATEGORY):($row['type']==='debt'?'debt.php':($row['type']==='receivable'?'receivable.php':($row['type']==='savings'?'transactions.php?type=savings':'dashboard.php')));
include __DIR__ . '/includes/header.php'; include __DIR__ . '/includes/sidebar.php';
?>
<main class="main transaction-main">
<div class="transaction-overlay">
    <section class="transaction-dialog" role="dialog" aria-modal="true" aria-labelledby="transaction-title">
        <div class="transaction-dialog-head">
            <h1 id="transaction-title"><?= $isCapitalForm?($id?'Edit Modal':'Tambah Modal'):($isSavingsPreset?'Tambah Tabungan':($row['type']==='savings'?($id?'Edit Tabungan':'Tambah Tabungan'):($id?'Edit ': 'Tambah ').($isDebtForm?($row['type']==='debt'?'Hutang':($category==='lender'?'Peminjam':'Piutang')):'Transaksi'))) ?></h1>
            <a class="transaction-close" href="<?=e($returnPage)?>" aria-label="Tutup">×</a>
        </div>
        <?php if($error): ?><div class="login-error"><?= e($error) ?></div><?php endif; ?>
        <?php if($category==='lender'): ?><div class="notice">Modal tersedia untuk dipinjamkan: <strong><?=rupiah(lender_available_capital($pdo,$userId))?></strong></div><?php endif; ?>
        <form method="post">
            <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
            <div class="transaction-fields">
                <div class="field full"><label for="transaction_date">Tanggal</label><input id="transaction_date" type="date" name="transaction_date" value="<?= e($row['transaction_date']) ?>" required></div>
                <?php if(!$isDebtForm): ?><div class="field full" id="transaction_category_wrap"><label for="transaction_category">Kategori</label><select id="transaction_category" name="transaction_category" required>
                    <?php foreach($categoryOptions as $transactionType=>$options): ?><optgroup label="<?= $transactionType==='income'?'Pemasukan':'Pengeluaran' ?>" data-transaction-type="<?= e($transactionType) ?>"><?php foreach($options as $option): ?><option value="<?= e($option) ?>" <?= ($row['transaction_category']??'')===$option?'selected':'' ?>><?= e($option) ?></option><?php endforeach; ?></optgroup><?php endforeach; ?>
                </select></div><?php endif; ?>
                <div class="field full"><label for="description">Keterangan</label><input id="description" name="description" value="<?= e($row['description']) ?>" placeholder="<?= ($isSavingsPreset || $row['type']==='savings')?'Contoh: Tabungan bulanan':($isDebtForm?($row['type']==='debt'?'Contoh: Pinjam uang untuk biaya darurat':'Contoh: Pinjaman untuk Budi'):($isCapitalForm?'Contoh: Tambahan modal untuk pinjaman':'Contoh: Beli bahan baku')) ?>" required></div>
                <?php if($isDebtForm): ?>
                    <input type="hidden" name="type" value="<?=e($row['type'])?>">
                <?php else: ?>
                    <fieldset class="field full transaction-type-field"><legend>Jenis</legend><div class="transaction-type-options" id="transaction_type">
                        <?php $transactionTypes=['income'=>'Pemasukan','expense'=>'Pengeluaran']; if($category==='personal')$transactionTypes['savings']='Tabungan'; ?>
                        <?php foreach($transactionTypes as $k=>$v): ?><label class="transaction-type-option <?= $row['type']===$k?'selected':'' ?>"><input type="radio" name="type" value="<?= e($k) ?>" <?= $row['type']===$k?'checked':'' ?>><span><?= e($v) ?></span></label><?php endforeach; ?>
                    </div></fieldset>
                <?php endif; ?>
                <div class="field full"><label for="amount">Jumlah</label><div class="amount-input"><span>Rp</span><input id="amount" type="text" name="amount" inputmode="decimal" data-currency-input value="<?= e((string)$row['amount']) ?>" placeholder="0" required></div></div>
                <?php if($isLenderIncomeForm): ?>
                    <div class="field full" id="lender_income_source_wrap"><label for="funding_source">Asal Dana</label><input id="funding_source" name="funding_source" value="<?=e($row['funding_source']?:$row['description'])?>" maxlength="255" placeholder="Contoh: tabungan pribadi atau pinjaman bank" required><small>Pemasukan menambah modal tersedia. Catat asal dana yang diterima.</small></div>
                <?php endif; ?>
                <?php if($isDebtForm): ?>
                    <div class="field full"><label for="person_name"><?=$row['type']==='debt'?'Nama Pemberi Hutang':'Nama Peminjam'?></label><input id="person_name" name="person_name" value="<?= e($row['person_name']) ?>" placeholder="Nama orang" ></div>
                    <div class="field full"><label for="due_date">Jatuh Tempo</label><input id="due_date" type="date" name="due_date" value="<?= e($row['due_date']) ?>"></div>
                    <div class="field full"><label>Status Pengembalian</label><div><span class="badge <?=($row['status']??'unpaid')==='paid'?'status-paid':'status-unpaid'?>"><?= ($row['status']??'unpaid')==='paid'?'Lunas':'Belum Lunas' ?></span></div><?php if($category==='lender'): ?><small>Status berubah otomatis berdasarkan nominal yang dicatat di halaman Pengembalian.</small><?php endif; ?></div>
                <?php endif; ?>
            </div>
            <div class="transaction-dialog-actions"><a class="btn btn-light" href="<?=e($returnPage)?>">Batal</a><button class="btn btn-primary"><?= $id?'Simpan Perubahan':($isDebtForm?'Simpan':'Simpan') ?></button></div>
        </form>
    </section>
</div>
</main>
<?php include __DIR__ . '/includes/footer.php'; ?>
