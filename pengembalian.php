<?php
$page_title='Pengembalian Pinjaman';
require_once __DIR__ . '/config/auth.php';
require_login();

$userId=(int)$_SESSION['user_id'];
$category=selected_category();
if($category!=='lender'){
    header('Location: dashboard.php');
    exit;
}

$error=null;
$selectedLoanId=(int)($_POST['loan_id']??$_GET['loan_id']??0);
if($_SERVER['REQUEST_METHOD']==='POST'){
    verify_csrf();
    $repaymentDate=$_POST['repayment_date']??date('Y-m-d');
    $amount=parse_currency_amount($_POST['amount']??null) ?? 0;
    $description=trim($_POST['description']??'');
    $date=DateTimeImmutable::createFromFormat('!Y-m-d',$repaymentDate);

    $stmt=$pdo->prepare("SELECT * FROM transactions WHERE id=? AND user_id=? AND category_type='lender' AND type='receivable' AND status='unpaid'");
    $stmt->execute([$selectedLoanId,$userId]);
    $loan=$stmt->fetch();
    if(!$loan){
        $error='Peminjam tidak ditemukan atau pinjamannya sudah lunas.';
    }elseif(!$date || $date->format('Y-m-d')!==$repaymentDate || $amount<=0){
        $error='Isi tanggal dan jumlah pengembalian dengan benar.';
    }else{
        $stmt=$pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM transactions WHERE user_id=? AND category_type='lender' AND type='income' AND loan_id=?");
        $stmt->execute([$userId,$selectedLoanId]);
        $previouslyReturned=(float)$stmt->fetchColumn();
        $outstanding=max(0,(float)$loan['amount']-$previouslyReturned);

        if($amount>$outstanding){
            $error='Jumlah pengembalian melebihi sisa pinjaman '.rupiah($outstanding).'.';
        }else{
            $description=$description?:'Pengembalian pinjaman - '.($loan['person_name']?:$loan['description']);
            $fullyReturned=$previouslyReturned+$amount>=(float)$loan['amount']-0.01;
            $pdo->beginTransaction();
            try{
                $stmt=$pdo->prepare("INSERT INTO transactions(user_id,category_type,type,transaction_category,loan_id,transaction_date,description,amount,person_name,status) VALUES(?,'lender','income','Pengembalian Pinjaman',?,?,?,?,?,'paid')");
                $stmt->execute([$userId,$selectedLoanId,$repaymentDate,$description,$amount,$loan['person_name']]);
                $stmt=$pdo->prepare("UPDATE transactions SET status=? WHERE id=? AND user_id=? AND category_type='lender' AND type='receivable'");
                $stmt->execute([$fullyReturned?'paid':'unpaid',$selectedLoanId,$userId]);
                $pdo->commit();
            }catch(Throwable $exception){
                if($pdo->inTransaction())$pdo->rollBack();
                throw $exception;
            }
            flash('success','Pengembalian '.rupiah($amount).' berhasil dicatat.');
            header('Location: pengembalian.php');
            exit;
        }
    }
}

$stmt=$pdo->prepare("SELECT loan.id,loan.person_name,loan.description,loan.amount,loan.due_date,COALESCE((SELECT SUM(repayment.amount) FROM transactions repayment WHERE repayment.user_id=loan.user_id AND repayment.category_type=loan.category_type AND repayment.type='income' AND repayment.loan_id=loan.id),0) returned FROM transactions loan WHERE loan.user_id=? AND loan.category_type='lender' AND loan.type='receivable' AND loan.status='unpaid' ORDER BY loan.transaction_date DESC,loan.id DESC");
$stmt->execute([$userId]);
$openLoans=[];
foreach($stmt->fetchAll() as $loan){
    $loan['outstanding']=max(0,(float)$loan['amount']-(float)$loan['returned']);
    if($loan['outstanding']>0)$openLoans[]=$loan;
}

$stmt=$pdo->prepare("SELECT repayment.*,loan.person_name borrower_name,loan.description loan_description FROM transactions repayment LEFT JOIN transactions loan ON loan.id=repayment.loan_id AND loan.user_id=repayment.user_id WHERE repayment.user_id=? AND repayment.category_type='lender' AND repayment.type='income' AND repayment.transaction_category='Pengembalian Pinjaman' ORDER BY repayment.transaction_date DESC,repayment.id DESC LIMIT 20");
$stmt->execute([$userId]);
$repayments=$stmt->fetchAll();

include __DIR__.'/includes/header.php';
include __DIR__.'/includes/sidebar.php';
?>
<main class="main">
    <div class="page-head"><div><h1>Pengembalian</h1><p>Catat uang yang dikembalikan peminjam.</p></div></div>
    <?php if($error): ?><div class="login-error"><?=e($error)?></div><?php endif; ?>
    <?php if($message=flash('success')): ?><div class="notice"><?=e($message)?></div><?php endif; ?>
    <section class="panel repayment-form-panel">
        <div class="panel-head"><div><h2>Catat Pengembalian</h2><p>Pilih peminjam, lalu masukkan nominal yang diterima.</p></div></div>
        <?php if($openLoans): ?>
        <form method="post" class="form-grid" data-repayment-form>
            <input type="hidden" name="csrf" value="<?=e(csrf_token())?>">
            <div class="field full"><label for="loan_id">Peminjam</label><select id="loan_id" name="loan_id" required data-repayment-loan>
                <?php foreach($openLoans as $loan): ?><option value="<?=(int)$loan['id']?>" data-remaining="<?=e((string)$loan['outstanding'])?>" <?=$selectedLoanId===(int)$loan['id']?'selected':''?>><?=e($loan['person_name']?:$loan['description'])?> · Sisa <?=rupiah($loan['outstanding'])?></option><?php endforeach; ?>
            </select><small class="repayment-hint">Sisa pinjaman: <strong data-repayment-remaining></strong></small></div>
            <div class="field"><label for="repayment_date">Tanggal Pengembalian</label><input id="repayment_date" type="date" name="repayment_date" value="<?=e($_POST['repayment_date']??date('Y-m-d'))?>" required></div>
            <div class="field"><label for="repayment_amount">Jumlah Dikembalikan</label><div class="amount-input"><span>Rp</span><input id="repayment_amount" type="text" name="amount" inputmode="decimal" value="<?=e($_POST['amount']??'')?>" required data-repayment-amount data-currency-input></div></div>
            <div class="field full"><label for="repayment_description">Keterangan</label><input id="repayment_description" name="description" value="<?=e($_POST['description']??'')?>" placeholder="Contoh: Cicilan pertama"></div>
            <div class="field full"><button class="btn btn-primary">Simpan Pengembalian</button></div>
        </form>
        <?php else: ?>
        <p class="empty repayment-empty">Belum ada pinjaman yang menunggu pengembalian.</p>
        <?php endif; ?>
    </section>
    <section class="panel">
        <div class="panel-head"><div><h2>Riwayat Pengembalian</h2><p>20 pengembalian terakhir</p></div></div>
        <div class="table-wrap"><table class="table">
            <thead><tr><th>Tanggal</th><th>Peminjam</th><th>Keterangan</th><th>Jumlah</th></tr></thead>
            <tbody>
                <?php foreach($repayments as $repayment): ?>
                <tr><td><?=e(date('d-m-Y',strtotime($repayment['transaction_date'])))?></td><td><?=e($repayment['borrower_name']?:$repayment['loan_description']?:'-')?></td><td><?=e($repayment['description'])?></td><td><strong><?=rupiah($repayment['amount'])?></strong></td></tr>
                <?php endforeach; ?>
                <?php if(!$repayments): ?><tr><td colspan="4" class="empty">Belum ada pengembalian.</td></tr><?php endif; ?>
            </tbody>
        </table></div>
    </section>
</main>
<?php include __DIR__.'/includes/footer.php'; ?>