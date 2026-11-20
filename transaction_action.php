<?php
require_once __DIR__ . '/config/auth.php'; require_login(); verify_csrf();
$userId=(int)$_SESSION['user_id'];
$id=(int)($_POST['id'] ?? 0);
$action=$_POST['action'] ?? '';
$category=selected_category();

function business_total_income(PDO $pdo, int $userId): float
{
    $stmt=$pdo->prepare("SELECT COALESCE(SUM(CASE WHEN type='income' THEN amount ELSE 0 END),0) FROM transactions WHERE user_id=? AND category_type='business'");
    $stmt->execute([$userId]);
    return (float)$stmt->fetchColumn();
}

if($action==='delete'){
    http_response_code(405);
    exit('Fitur hapus transaksi tidak tersedia.');
}elseif(in_array($action,['pay','pay_installment'],true)){
    $stmt=$pdo->prepare("SELECT * FROM transactions WHERE id=? AND user_id=? AND type IN ('debt','receivable') AND status='unpaid'");
    $stmt->execute([$id,$userId]);
    $orig=$stmt->fetch();

    if(!$orig){
        flash('error','Hutang tidak ditemukan atau sudah lunas.');
        $ref=$_POST['ref'] ?? 'debt';
        header("Location: {$ref}.php"); exit;
    }

    if($action==='pay_installment' && $orig['type']!=='debt'){
        flash('error','Cicilan hanya dapat dicatat untuk hutang.');
        header('Location: debt.php'); exit;
    }

    if($orig['type']==='receivable' && $orig['category_type']==='lender'){
        flash('error','Catat pengembalian pinjaman melalui menu Pengembalian.');
        header('Location: pengembalian.php'); exit;
    }

    if($orig['type']==='debt' && $orig['category_type']==='business'){
        $availableIncome=business_total_income($pdo,$userId);
        if($availableIncome <= 0){
            flash('error','Hutang tidak dapat dibayar/dilunasi karena total pemasukan masih kosong atau hanya berasal dari penerimaan hutang.');
            header('Location: debt.php'); exit;
        }
    }

    if($action==='pay_installment'){
        $amount=parse_currency_amount($_POST['amount'] ?? null) ?? 0;
        if($amount <= 0){
            flash('error','Nominal cicilan hutang harus lebih besar dari 0.');
            header('Location: debt.php'); exit;
        }

        $paidStmt=$pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM transactions WHERE user_id=? AND category_type=? AND type='expense' AND loan_id=?");
        $paidStmt->execute([$userId,$orig['category_type'],$orig['id']]);
        $paidAmount=(float)$paidStmt->fetchColumn();
        $remaining=max(0,(float)$orig['amount']-$paidAmount);

        if($amount > $remaining){
            flash('error','Jumlah cicilan melebihi sisa hutang '.rupiah($remaining).'.');
            header('Location: debt.php'); exit;
        }

        $pdo->prepare("INSERT INTO transactions(user_id,category_type,type,transaction_category,loan_id,transaction_date,description,amount,person_name,status) VALUES(?,?,?,?,?,?,?,?,?,'paid')")
            ->execute([
                $userId,
                $orig['category_type'],
                'expense',
                'Pembayaran Hutang',
                $orig['id'],
                date('Y-m-d'),
                'Cicilan hutang: ' . ($orig['description'] ?: 'Hutang'),
                $amount,
                $orig['person_name'],
            ]);

        $newPaidAmount=$paidAmount + $amount;
        if($newPaidAmount >= (float)$orig['amount'] - 0.01){
            $pdo->prepare("UPDATE transactions SET status='paid' WHERE id=? AND user_id=?")->execute([$orig['id'],$userId]);
            flash('success','Cicilan hutang berhasil dicatat dan hutang telah lunas.');
        }else{
            flash('success','Cicilan hutang berhasil dicatat. Sisa hutang: '.rupiah(max(0,(float)$orig['amount']-$newPaidAmount)).'.');
        }

        header('Location: debt.php'); exit;
    }

    $pdo->beginTransaction();
    try{
        $update=$pdo->prepare("UPDATE transactions SET status='paid' WHERE id=? AND user_id=? AND status='unpaid'");
        $update->execute([$id,$userId]);
        if($update->rowCount()!==1){
            $pdo->rollBack();
            flash('error','Piutang atau hutang sudah diproses sebelumnya.');
            header('Location: '.($orig['type']==='debt'?'debt.php':'receivable.php')); exit;
        }

        $isDebt=$orig['type']==='debt';
        $autoType=$isDebt?'expense':'income';
        $transactionCategory=$isDebt
            ?'Pembayaran Hutang'
            :($orig['category_type']==='personal'?'Lainnya':'Penerimaan Piutang');
        $description=($isDebt?'Pelunasan hutang: ':'Penerimaan piutang: ')
            .$orig['description']
            .($orig['person_name']?' - '.$orig['person_name']:'');

        $stmt2=$pdo->prepare("INSERT INTO transactions(user_id,category_type,type,transaction_category,loan_id,transaction_date,description,amount,person_name,status) VALUES(?,?,?,?,?,?,?,?,?,'paid')");
        $stmt2->execute([
            $userId,
            $orig['category_type'],
            $autoType,
            $transactionCategory,
            $orig['type']==='receivable'?$orig['id']:null,
            date('Y-m-d'),
            $description,
            $orig['amount'],
            $orig['person_name'],
        ]);
        $pdo->commit();
    }catch(Throwable $exception){
        if($pdo->inTransaction())$pdo->rollBack();
        throw $exception;
    }

    if($orig['type']==='debt'){
        flash('success','Berhasil dilunasi! Otomatis tercatat sebagai pengeluaran.');
        $ref=$_POST['ref'] ?? 'debt';
        header("Location: {$ref}.php"); exit;
    }
    flash('success','Piutang berhasil diterima dan tercatat sebagai pemasukan.');
    $ref=$_POST['ref'] ?? 'receivable';
    header("Location: {$ref}.php"); exit;
}
header('Location: transactions.php'); exit;
