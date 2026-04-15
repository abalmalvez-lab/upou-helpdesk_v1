<?php
require_once __DIR__ . '/../includes/auth.php';
Auth::logout();
header('Location: /index.php');
exit;
