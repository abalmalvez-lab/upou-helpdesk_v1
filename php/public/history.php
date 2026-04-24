<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
Auth::requireLogin();
$user = Auth::user();

$pdo = Database::get();
$stmt = $pdo->prepare('SELECT * FROM chat_history WHERE user_id = ? ORDER BY created_at DESC LIMIT 50');
$stmt->execute([$user['id']]);
$rows = $stmt->fetchAll();

$pageTitle = 'History';
require __DIR__ . '/../views/partials/header.php';
?>
<div class="card">
  <h2><i class="fa-solid fa-clock-rotate-left"></i> Your Chat History</h2>
  <p class="lead">Your last 50 questions and answers.</p>
</div>

<?php if (!$rows): ?>
  <div class="alert alert-info">
    <i class="fa-solid fa-info-circle"></i>
    <span>No questions yet. <a href="/chat.php">Ask your first question</a>.</span>
  </div>
<?php endif; ?>

<?php foreach ($rows as $r): ?>
  <div class="history-item">
    <div class="h-q"><i class="fa-solid fa-circle-question"></i> <?= htmlspecialchars($r['question']) ?></div>
    <div class="h-a"><?= nl2br(htmlspecialchars(mb_strimwidth($r['answer'], 0, 400, '...'))) ?></div>
    <div class="h-meta">
      <?php
        $label = $r['source_label'] ?? 'General Knowledge';
        $cls = 'badge-general'; $icon = 'fa-brain';
        if ($label === 'Official Policy') { $cls = 'badge-policy'; $icon = 'fa-shield-halved'; }
        elseif ($label === 'Needs Human Review') { $cls = 'badge-human'; $icon = 'fa-headset'; }
      ?>
      <span class="source-badge <?= $cls ?>"><i class="fa-solid <?= $icon ?>"></i> <?= htmlspecialchars($label) ?></span>
      <span><?= htmlspecialchars($r['created_at']) ?></span>
      <?php if ($r['ticket_id']): ?>
        <span><i class="fa-solid fa-ticket"></i> <a href="/my_tickets.php"><?= htmlspecialchars($r['ticket_id']) ?></a></span>
      <?php endif; ?>
    </div>
  </div>
<?php endforeach; ?>

<?php require __DIR__ . '/../views/partials/footer.php'; ?>
