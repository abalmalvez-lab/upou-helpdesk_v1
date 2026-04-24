<?php
require_once __DIR__ . '/../../includes/auth.php';
$currentUser = Auth::user();
$pageTitle = $pageTitle ?? 'UPOU AI HelpDesk';
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= htmlspecialchars($pageTitle) ?> · UPOU HelpDesk</title>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
  <link rel="stylesheet" href="/assets/style.css">
</head>
<body>
<header class="topbar">
  <div class="topbar-inner">
    <a class="brand" href="/index.php">
      <i class="fa-solid fa-graduation-cap"></i>
      <span>UPOU AI <strong>HelpDesk</strong></span>
    </a>
    <nav class="nav">
      <?php if ($currentUser): ?>
        <a href="/chat.php"><i class="fa-solid fa-comments"></i> Chat</a>
        <a href="/my_tickets.php"><i class="fa-solid fa-ticket"></i> My Tickets</a>
        <a href="/history.php"><i class="fa-solid fa-clock-rotate-left"></i> History</a>
        <span class="user-pill"><i class="fa-solid fa-user"></i> <?= htmlspecialchars($currentUser['username']) ?></span>
        <a class="btn btn-outline" href="/logout.php"><i class="fa-solid fa-arrow-right-from-bracket"></i> Logout</a>
      <?php else: ?>
        <a href="/login.php"><i class="fa-solid fa-right-to-bracket"></i> Login</a>
        <a class="btn btn-primary" href="/register.php"><i class="fa-solid fa-user-plus"></i> Sign up</a>
      <?php endif; ?>
    </nav>
  </div>
</header>
<main class="container">
