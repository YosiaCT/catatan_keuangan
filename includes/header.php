<?php
require_once __DIR__ . '/../config/auth.php';
require_login();
$user = current_user();
?>
<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($page_title ?? 'Catatan Keuangan') ?></title>
    <link rel="stylesheet" href="assets/css/style.css?v=<?= filemtime(__DIR__ . '/../assets/css/style.css') ?>">
</head>
<body class="theme-<?= e(selected_category()) ?> page-<?= e(pathinfo(basename($_SERVER['PHP_SELF']), PATHINFO_FILENAME)) ?>">
<header class="topbar">
    <a class="brand" href="dashboard.php">
        <span class="brand-icon"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 12V7H5a2 2 0 0 1 0-4h14v4"/><path d="M3 5v14a2 2 0 0 0 2 2h16v-5"/><path d="M18 12a2 2 0 0 0 0 4h4v-4Z"/></svg></span>
        <span>Catatan Keuangan</span>
    </a>
    <div class="top-actions" style="margin-left:auto">
        <span style="font-weight:600;">Halo, <?= e($user['full_name'] ?? $user['username'] ?? '') ?></span>
        <a class="btn btn-light btn-small" href="logout.php" style="color:#475569;font-weight:600;">Logout</a>
    </div>
</header>
<div class="app-shell">
