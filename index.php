<?php
require_once __DIR__ . '/config/auth.php';
if (!empty($_SESSION['user_id'])) {
    header('Location: category.php');
} else {
    header('Location: login.php');
}
exit;
