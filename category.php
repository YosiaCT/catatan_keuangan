<?php
require_once __DIR__ . '/config/auth.php';
require_login();

$userId = $_SESSION['user_id'];
$stmt = $pdo->prepare("SELECT * FROM user_categories WHERE user_id = ? ORDER BY id ASC");
$stmt->execute([$userId]);
$userCategories = $stmt->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $allowed = ['business','personal','lender'];
    foreach ($userCategories as $uc) {
        $allowed[] = $uc['slug'];
    }
    
    $category = $_POST['category'] ?? 'business';
    if (in_array($category, $allowed, true)) {
        $_SESSION['category'] = $category;
    }
    header('Location: dashboard.php'); exit;
}
?>
<!doctype html>
<html lang="id">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Pilih Kategori</title><link rel="stylesheet" href="assets/css/style.css?v=<?= filemtime(__DIR__ . '/assets/css/style.css') ?>"></head>
<body class="category-page">
<nav class="cat-navbar">
    <div class="cat-brand">
        <div class="cat-logo-icon">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M21 12V7H5a2 2 0 0 1 0-4h14v4"/><path d="M3 5v14a2 2 0 0 0 2 2h16v-5"/><path d="M18 12a2 2 0 0 0 0 4h4v-4Z"/></svg>
        </div>
        <span>Catatan Keuangan</span>
    </div>
    <a class="cat-logout" href="logout.php">Logout</a>
</nav>
<div class="category-wrap">
    <h1>Pilih Kategori Keuangan</h1>
    <p>Pilih kategori yang ingin kamu kelola</p>
    <div class="category-grid">
        <form method="post"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="category" value="business"><button class="category-card blue"><div class="category-icon">
            <svg width="55" height="55" viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
                <path d="M3 9l1.5-4.5A2 2 0 0 1 6.4 3h11.2a2 2 0 0 1 1.9 1.5L21 9" />
                <path d="M3 9v10a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V9" />
                <path d="M3 9c0 1.7 1.3 3 3 3s3-1.3 3-3 1.3 3 3 3 3-1.3 3-3 1.3 3 3 3 3-1.3 3-3" />
                <path d="M10 21v-5h4v5" />
            </svg>
        </div><h2>Bisnis</h2><p>Kelola keuangan usaha dengan mudah</p></button></form>
        <form method="post"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="category" value="personal"><button class="category-card green"><div class="category-icon">
            <svg width="55" height="55" viewBox="0 0 24 24" fill="white">
                <path d="M12 11c2.76 0 5-2.24 5-5s-2.24-5-5-5-5 2.24-5 5 2.24 5 5 5zm0 3c-3.33 0-10 1.67-10 5v3h20v-3c0-3.33-6.67-5-10-5z"/>
            </svg>
        </div><h2>Catatan Pribadi</h2><p>Atur keuangan pribadi kamu</p></button></form>
        <form method="post"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="category" value="lender"><button class="category-card purple"><div class="category-icon">
            <svg width="55" height="55" viewBox="0 0 24 24" fill="none">
                <path d="M21.5 17.5c-1-1-3-1-4-1l-3 1L12 15c-1-1-3-1-4 0l-4 4v3h12c3 0 5-2 5-5z" fill="white" stroke="white" stroke-width="1" stroke-linejoin="round"/>
                <circle cx="12" cy="6" r="4.5" fill="white"/>
                <path d="M12 3.5v5M10.5 4.5L12 3l1.5 1.5M10.5 7.5L12 9l1.5-1.5" stroke="#8050d9" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
            </svg>
        </div><h2>Pemberi Pinjaman</h2><p>Kelola pinjaman dan pengembalian</p></button></form>
        
        <?php foreach($userCategories as $uc): ?>
        <form method="post"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="category" value="<?= e($uc['slug']) ?>">
            <button class="category-card" style="background: <?= e($uc['color'] ?: '#2276df') ?>;">
                <div class="category-icon"><?= e($uc['icon'] ?: '★') ?></div>
                <h2><?= e($uc['name']) ?></h2>
                <p>Kategori Kustom</p>
            </button>
        </form>
        <?php endforeach; ?>
    </div>
</div>
</body>
</html>
