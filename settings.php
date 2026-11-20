<?php
$page_title = 'Pengaturan';
require_once __DIR__ . '/config/auth.php';
require_login();

$user = current_user();
$userId = (int)$user['id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $name = trim($_POST['full_name'] ?? '');
    $newPassword = trim($_POST['password'] ?? '');
    if ($name) {
        $pdo->prepare('UPDATE users SET full_name=? WHERE id=?')->execute([$name, $userId]);
    }
    if ($newPassword) {
        $pdo->prepare('UPDATE users SET password=? WHERE id=?')->execute([password_hash($newPassword, PASSWORD_DEFAULT), $userId]);
    }
    flash('success', 'Pengaturan disimpan.');
    header('Location: settings.php');
    exit;
}

include __DIR__ . '/includes/header.php';
include __DIR__ . '/includes/sidebar.php';
?>
<main class="main">
    <div class="page-head"><div><h1>Pengaturan</h1><p>Kelola profil akun.</p></div></div>
    <?php if ($message = flash('success')): ?><div class="notice"><?= e($message) ?></div><?php endif; ?>
    <div class="panel">
        <form method="post">
            <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
            <div class="field" style="max-width:600px;margin-bottom:15px"><label>Username</label><input value="<?= e($user['username']) ?>" disabled></div>
            <div class="field" style="max-width:600px;margin-bottom:15px"><label>Nama</label><input name="full_name" value="<?= e($user['full_name']) ?>" required></div>
            <div class="field" style="max-width:600px;margin-bottom:15px"><label>Password Baru</label><input type="password" name="password" placeholder="Kosongkan jika tidak diubah"></div>
            <button class="btn btn-primary">Simpan Pengaturan</button>
        </form>
    </div>
</main>
<?php include __DIR__ . '/includes/footer.php'; ?>
