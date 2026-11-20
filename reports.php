<?php
$page_title='Laporan Keuangan';
require_once __DIR__ . '/config/auth.php';
require_login();
$userId=(int)$_SESSION['user_id'];
$category=selected_category();
$month=$_GET['month']??date('Y-m');
if(!preg_match('/^\d{4}-\d{2}$/',$month))$month=date('Y-m');
$periodStart=DateTimeImmutable::createFromFormat('!Y-m-d',$month.'-01');
$periodEnd=$periodStart->modify('last day of this month');

$stmt=$pdo->prepare("SELECT
    COALESCE(SUM(CASE WHEN type IN ('income','debt') THEN amount ELSE 0 END),0) income_total,
    COUNT(CASE WHEN type IN ('income','debt') THEN 1 END) income_count,
    COALESCE(SUM(CASE WHEN type='expense' AND NOT (category_type='personal' AND COALESCE(transaction_category,'')='Tabungan') THEN amount ELSE 0 END),0) expense_total,
    COUNT(CASE WHEN type='expense' AND NOT (category_type='personal' AND COALESCE(transaction_category,'')='Tabungan') THEN 1 END) expense_count,
    COALESCE(SUM(CASE WHEN type='savings' OR (category_type='personal' AND type='expense' AND transaction_category='Tabungan') THEN amount ELSE 0 END),0) savings_total,
    COUNT(CASE WHEN type='savings' OR (category_type='personal' AND type='expense' AND transaction_category='Tabungan') THEN 1 END) savings_count,
    COALESCE(SUM(CASE WHEN type='debt' AND status='unpaid' THEN amount ELSE 0 END),0) debt_total,
    COUNT(CASE WHEN type='debt' AND status='unpaid' THEN 1 END) debt_count,
    COALESCE(SUM(CASE WHEN type='receivable' AND status='unpaid' THEN amount ELSE 0 END),0) receivable_total,
    COUNT(CASE WHEN type='receivable' AND status='unpaid' THEN 1 END) receivable_count
    FROM transactions WHERE user_id=? AND category_type=? AND DATE_FORMAT(transaction_date,'%Y-%m')=?");
$stmt->execute([$userId,$category,$month]);
$summary=$stmt->fetch();
$data=[
    'income'=>['total'=>$summary['income_total'],'jumlah'=>$summary['income_count']],
    'expense'=>['total'=>$summary['expense_total'],'jumlah'=>$summary['expense_count']],
    'savings'=>['total'=>$summary['savings_total'],'jumlah'=>$summary['savings_count']],
    'debt'=>['total'=>$summary['debt_total'],'jumlah'=>$summary['debt_count']],
    'receivable'=>['total'=>$summary['receivable_total'],'jumlah'=>$summary['receivable_count']],
];

$stmt=$pdo->prepare("SELECT * FROM transactions WHERE user_id=? AND category_type=? AND DATE_FORMAT(transaction_date,'%Y-%m')=? ORDER BY transaction_date DESC,id DESC");
$stmt->execute([$userId,$category,$month]);
$transactions=$stmt->fetchAll();
$income=(float)$data['income']['total'];
$expense=(float)$data['expense']['total'];
$savings=(float)$data['savings']['total'];
$balance=$income-$expense-$savings;
if(($_GET['export']??'')==='csv'){
    $filename=preg_replace('/[^a-z0-9_-]/i','-',$category).'-'.$month.'-laporan.csv';
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="'.$filename.'"');
    header('X-Content-Type-Options: nosniff');
    $output=fopen('php://output','wb');
    if($output===false){
        throw new RuntimeException('Tidak dapat menyiapkan berkas ekspor CSV.');
    }
    fwrite($output,"\xEF\xBB\xBF");
    $safeText=static function(string $value): string {
        return preg_match('/^[\x00-\x20]*[=+\-@]/',$value) ? "'".$value : $value;
    };
    fputcsv($output,['Laporan Keuangan',$safeText(category_label($category)),$month],',','"','');
    fputcsv($output,['Total Pemasukan',number_format($income,2,'.','')],',','"','');
    fputcsv($output,['Total Pengeluaran',number_format($expense,2,'.','')],',','"','');
    if($category==='personal'){
        fputcsv($output,['Total Tabungan',number_format($savings,2,'.','')],',','"','');
    }
    fputcsv($output,['Hutang Belum Lunas',number_format((float)$data['debt']['total'],2,'.','')],',','"','');
    fputcsv($output,['Piutang Belum Lunas',number_format((float)$data['receivable']['total'],2,'.','')],',','"','');
    fputcsv($output,['Saldo',number_format($balance,2,'.','')],',','"','');
    fputcsv($output,[],',','"','');
    fputcsv($output,['Tanggal','Keterangan','Pihak','Jenis','Kategori / Status','Jumlah'],',','"','');
    foreach($transactions as $transaction){
        $statusOrCategory=in_array($transaction['type'],['debt','receivable'],true)
            ? ($transaction['status']==='paid'?'Lunas':'Belum Lunas')
            : ($transaction['transaction_category']?:'Lainnya');
        fputcsv($output,[
            $transaction['transaction_date'],
            $safeText((string)$transaction['description']),
            $safeText((string)($transaction['person_name']??'')),
            $safeText(transaction_type_label($transaction['type'])),
            $safeText($statusOrCategory),
            number_format((float)$transaction['amount'],2,'.',''),
        ],',','"','');
    }
    fclose($output);
    exit;
}
include __DIR__ . '/includes/header.php';
include __DIR__ . '/includes/sidebar.php';
?>
<main class="main report-page">
	<div class="page-head">
		<div><h1>Laporan Keuangan - <?=e(category_label($category))?></h1><p>Periode <?=e($periodStart->format('d-m-Y'))?> s/d <?=e($periodEnd->format('d-m-Y'))?></p></div>
		<div class="actions no-print"><a class="btn btn-outline" href="grafik.php?month=<?=e($month)?>">Lihat Grafik</a><button class="btn btn-primary btn-print" type="button" data-print-page aria-label="Cetak laporan" title="Cetak laporan"><svg aria-hidden="true" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M6 9V3h12v6"/><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/><path d="M6 14h12v7H6z"/><path d="M18 12h.01"/></svg></button></div>
	</div>
	<div class="panel no-print">
		<form class="filters" method="get">
			<div class="field"><label for="report-month">Bulan Laporan</label><input id="report-month" type="month" name="month" value="<?=e($month)?>"></div>
			<button class="btn btn-primary">Tampilkan</button>
		</form>
		<div class="actions" style="margin-top:14px">
			<a class="btn btn-outline" href="reports.php?month=<?=e($month)?>&amp;export=csv">Unduh CSV / Excel</a>
			<button class="btn btn-outline" type="button" data-print-page>Simpan PDF / Cetak</button>
		</div>
	</div>
	<div class="cards report-summary">
		<?php $reportMetrics=['income'=>'Total Pemasukan','expense'=>'Total Pengeluaran']; if($category==='personal')$reportMetrics['savings']='Tabungan'; $reportMetrics+=['debt'=>'Total Hutang','receivable'=>'Total Piutang']; ?>
		<?php foreach($reportMetrics as $key=>$label): ?>
		<div class="report-metric"><h2><?=e($label)?></h2><strong><?=rupiah($data[$key]['total']??0)?></strong><small><?=(int)($data[$key]['jumlah']??0)?> transaksi</small></div>
		<?php endforeach; ?>
	</div>
	<div class="balance report-balance"><div><div class="balance-label">Saldo</div><div class="balance-value"><?=rupiah($balance)?></div></div></div>
	<section class="panel report-details">
		<div class="panel-head"><div><h2>Detail Transaksi</h2><p><?=count($transactions)?> transaksi pada periode ini</p></div></div>
		<div class="table-wrap"><table class="table">
			<thead><tr><th>No</th><th>Tanggal</th><th>Keterangan</th><th>Jenis</th><th>Kategori / Status</th><th>Jumlah</th></tr></thead>
			<tbody>
				<?php foreach($transactions as $index=>$transaction): ?>
				<tr>
					<td><?=$index+1?></td>
					<td><?=e(date('d-m-Y',strtotime($transaction['transaction_date'])))?></td>
					<td><?=e($transaction['description'])?><?=$transaction['person_name']?' - '.e($transaction['person_name']):''?></td>
					<td><span class="badge <?=type_class($transaction['type'])?>"><?=e(transaction_type_label($transaction['type']))?></span></td>
					<td><?=in_array($transaction['type'],['debt','receivable'],true)?'<span class="badge '.($transaction['status']==='paid'?'status-paid':'status-unpaid').'">'.($transaction['status']==='paid'?'Lunas':'Belum Lunas').'</span>':e($transaction['transaction_category']?:'Lainnya')?></td>
					<td><strong><?=rupiah($transaction['amount'])?></strong></td>
				</tr>
				<?php endforeach; ?>
				<?php if(!$transactions): ?><tr><td colspan="6" class="empty">Tidak ada transaksi pada periode ini.</td></tr><?php endif; ?>
			</tbody>
		</table></div>
	</section>
</main>
<?php include __DIR__ . '/includes/footer.php'; ?>
