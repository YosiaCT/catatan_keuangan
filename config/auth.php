<?php
declare(strict_types=1);

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

require_once __DIR__ . '/database.php';
require_once __DIR__ . '/../includes/helpers.php';

function require_login(): void {
    if (empty($_SESSION['user_id'])) {
        header('Location: login.php');
        exit;
    }
}

function current_user(): ?array {
    global $pdo;
    if (empty($_SESSION['user_id'])) return null;

    static $user = false;
    if ($user !== false) return $user;

    $stmt = $pdo->prepare("SELECT id, username, full_name FROM users WHERE id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    $user = $stmt->fetch() ?: null;
    return $user;
}
