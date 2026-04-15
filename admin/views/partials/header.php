<?php
require_once __DIR__ . '/../../includes/auth.php';
$currentUser = Auth::user();
$pageTitle = $pageTitle ?? 'Admin';
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= htmlspecialchars($pageTitle) ?> · UPOU Admin</title>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
  <link rel="stylesheet" href="/assets/style.css">
</head>
<body>
<header class="topbar">
  <div class="topbar-inner">
    <a class="brand" href="/dashboard.php">
      <i class="fa-solid fa-shield-halved"></i>
      <span>UPOU Admin <strong>Console</strong></span>
    </a>
    <?php if ($currentUser): ?>
      <nav class="nav">
        <a href="/dashboard.php"><i class="fa-solid fa-gauge-high"></i> Dashboard</a>
        <a href="/tickets.php"><i class="fa-solid fa-ticket"></i> Tickets</a>
        <?php if (Auth::isAdmin()): ?>
          <a href="/users.php"><i class="fa-solid fa-users-gear"></i> Users</a>
        <?php endif; ?>
        <span class="user-pill">
          <i class="fa-solid <?= $currentUser['role'] === 'admin' ? 'fa-user-shield' : 'fa-headset' ?>"></i>
          <?= htmlspecialchars($currentUser['username']) ?>
          <span class="role-badge role-<?= htmlspecialchars($currentUser['role']) ?>"><?= htmlspecialchars($currentUser['role']) ?></span>
        </span>
        <a class="btn btn-outline" href="/logout.php"><i class="fa-solid fa-arrow-right-from-bracket"></i> Logout</a>
      </nav>
    <?php endif; ?>
  </div>
</header>
<main class="container">
