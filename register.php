<?php
require_once __DIR__ . '/config/auth.php';

if (!empty($_SESSION['user_id'])) {
    header('Location: category.php'); exit;
}

$error = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $username = trim($_POST['username'] ?? '');
    $fullname = trim($_POST['fullname'] ?? '');
    $password = $_POST['password'] ?? '';

    if (empty($username) || empty($fullname) || empty($password)) {
        $error = 'Semua field harus diisi.';
    } else {
        $stmt = $pdo->prepare("SELECT id FROM users WHERE username = ?");
        $stmt->execute([$username]);
        if ($stmt->fetch()) {
            $error = 'Username sudah digunakan.';
        } else {
            $hash = password_hash($password, PASSWORD_DEFAULT);
            $stmt = $pdo->prepare("INSERT INTO users (username, full_name, password) VALUES (?, ?, ?)");
            if ($stmt->execute([$username, $fullname, $hash])) {
                $userId = $pdo->lastInsertId();
                session_regenerate_id(true);
                $_SESSION['user_id'] = (int)$userId;
                header('Location: category.php');
                exit;
            } else {
                $error = 'Gagal mendaftar. Silakan coba lagi.';
            }
        }
    }
}
?>
<!doctype html>
<html lang="id">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Register - Catatan Keuangan</title><link rel="stylesheet" href="assets/css/style.css?v=<?= filemtime(__DIR__ . '/assets/css/style.css') ?>">
</head>
<body class="login-page">
<div class="login-wrapper">
    <div class="login-card-new">
        <div class="login-left">
            <div class="login-logo-img">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="wallet-icon"><path d="M21 12V7H5a2 2 0 0 1 0-4h14v4"/><path d="M3 5v14a2 2 0 0 0 2 2h16v-5"/><path d="M18 12a2 2 0 0 0 0 4h4v-4Z"/></svg>
            </div>
            <h1>Daftar Akun</h1>
            <div class="subtitle">Mulai kelola uangmu sekarang</div>
            <?php if ($error): ?><div class="login-error"><?= e($error) ?></div><?php endif; ?>
            <form method="post">
                <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                
                <div class="field-icon">
                    <svg class="icon-left" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                    <input name="fullname" placeholder="Nama Lengkap" required autocomplete="name">
                </div>

                <div class="field-icon">
                    <svg class="icon-left" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M19 21v-2a4 4 0 0 0-4-4H9a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                    <input name="username" placeholder="Username" required autocomplete="username">
                </div>
                
                <div class="field-icon">
                    <svg class="icon-left" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
                    <input type="password" name="password" placeholder="Password" required autocomplete="new-password" id="pwd">
                    <svg class="icon-right" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" onclick="togglePwd()"><path d="M2 12s3-7 10-7 10 7 10 7-3 7-10 7-10-7-10-7Z"/><circle cx="12" cy="12" r="3"/></svg>
                </div>
                
                <button class="btn btn-primary btn-login">Daftar</button>
            </form>
            <a class="register" href="login.php">Sudah punya akun? Login</a>
        </div>
        <div class="login-right">
            <div class="illustration-bg">
                <img src="assets/img/illustration.jpg" alt="Illustration" style="max-width: 90%; height: auto; border-radius: 20px; box-shadow: 0 10px 30px rgba(0,0,0,0.1);" onerror="this.style.display='none'">
            </div>
        </div>
    </div>
</div>
<script>
function togglePwd() {
    var x = document.getElementById("pwd");
    if (x.type === "password") {
        x.type = "text";
    } else {
        x.type = "password";
    }
}
</script>
</body>
</html>
