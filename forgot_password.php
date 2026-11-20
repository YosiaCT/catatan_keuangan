<?php
require_once __DIR__ . '/config/auth.php';

if (!empty($_SESSION['user_id'])) {
    header('Location: category.php');
    exit;
}

$error = null;
$success = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $usernameValue = $_POST['username'] ?? '';
    $passwordValue = $_POST['password'] ?? '';
    $confirmationValue = $_POST['password_confirmation'] ?? '';
    $username = is_string($usernameValue) ? trim($usernameValue) : '';
    $password = is_string($passwordValue) ? $passwordValue : '';
    $confirmation = is_string($confirmationValue) ? $confirmationValue : '';

    if ($username === '' || strlen($password) < 8 || $password !== $confirmation) {
        $error = 'Isi username dengan benar. Kata sandi baru minimal 8 karakter dan harus sama dengan konfirmasi.';
    } else {
        $stmt = $pdo->prepare('UPDATE users SET password=? WHERE username=?');
        $stmt->execute([password_hash($password, PASSWORD_DEFAULT), $username]);
        if ($stmt->rowCount() === 1) {
            $success = 'Kata sandi berhasil diubah. Silakan login dengan kata sandi baru.';
        } else {
            $stmt = $pdo->prepare('SELECT id FROM users WHERE username=?');
            $stmt->execute([$username]);
            $success = $stmt->fetchColumn()
                ? 'Kata sandi baru sama dengan kata sandi sebelumnya. Silakan coba kata sandi yang berbeda.'
                : null;
            if ($success === null) {
                $error = 'Username tidak ditemukan.';
            }
        }
    }
}
?>
<!doctype html>
<html lang="id">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Lupa Kata Sandi - Catatan Keuangan</title><link rel="stylesheet" href="assets/css/style.css">
</head>
<body class="login-page">
<div class="login-wrapper">
    <div class="login-card-new">
        <div class="login-left">
            <div class="login-logo-img">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="wallet-icon"><path d="M21 12V7H5a2 2 0 0 1 0-4h14v4"/><path d="M3 5v14a2 2 0 0 0 2 2h16v-5"/><path d="M18 12a2 2 0 0 0 0 4h4v-4Z"/></svg>
            </div>
            <h1>Lupa Kata Sandi</h1>
            <div class="subtitle">Masukkan username dan kata sandi baru.</div>
            <?php if ($error): ?><div class="login-error"><?= e($error) ?></div><?php endif; ?>
            <?php if ($success): ?><div class="notice"><?= e($success) ?></div><?php endif; ?>
            <?php if (!$success): ?>
            <form method="post">
                <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                <div class="field-icon"><input name="username" placeholder="Username" required autocomplete="username"></div>
                <div class="field-icon"><input type="password" name="password" placeholder="Kata sandi baru (minimal 8 karakter)" required minlength="8" autocomplete="new-password"></div>
                <div class="field-icon"><input type="password" name="password_confirmation" placeholder="Ulangi kata sandi baru" required minlength="8" autocomplete="new-password"></div>
                <button class="btn btn-primary btn-login">Simpan Kata Sandi Baru</button>
            </form>
            <?php endif; ?>
            <a class="register" href="login.php">Kembali ke Login</a>
        </div>
        <div class="login-right">
            <div class="illustration-bg">
                <img src="assets/img/illustration.jpg" alt="Ilustrasi" style="max-width:90%;height:auto;border-radius:20px;box-shadow:0 10px 30px rgba(0,0,0,0.1)" onerror="this.style.display='none'">
            </div>
        </div>
    </div>
</div>
</body>
</html>
