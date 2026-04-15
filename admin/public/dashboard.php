<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/ticket_repo.php';
Auth::requireLogin();

$counts = TicketRepo::counts();
$recent = array_slice(TicketRepo::listAll(), 0, 5);

$pageTitle = 'Dashboard';
require __DIR__ . '/../views/partials/header.php';
?>
<div class="card">
  <h1><i class="fa-solid fa-gauge-high"></i> Dashboard</h1>
  <p class="lead">Overview of escalated tickets from the AI HelpDesk.</p>
</div>

<div class="stats-grid">
  <div class="stat stat-total">
    <div class="stat-icon"><i class="fa-solid fa-ticket"></i></div>
    <div>
      <div class="stat-num"><?= (int) $counts['TOTAL'] ?></div>
      <div class="stat-label">Total tickets</div>
    </div>
  </div>
  <div class="stat stat-open">
    <div class="stat-icon"><i class="fa-solid fa-circle-exclamation"></i></div>
    <div>
      <div class="stat-num"><?= (int) $counts['OPEN'] ?></div>
      <div class="stat-label">Open</div>
    </div>
  </div>
  <div class="stat stat-progress">
    <div class="stat-icon"><i class="fa-solid fa-spinner"></i></div>
    <div>
      <div class="stat-num"><?= (int) $counts['IN_PROGRESS'] ?></div>
      <div class="stat-label">In progress</div>
    </div>
  </div>
  <div class="stat stat-resolved">
    <div class="stat-icon"><i class="fa-solid fa-circle-check"></i></div>
    <div>
      <div class="stat-num"><?= (int) $counts['RESOLVED'] ?></div>
      <div class="stat-label">Resolved</div>
    </div>
  </div>
  <div class="stat stat-closed">
    <div class="stat-icon"><i class="fa-solid fa-lock"></i></div>
    <div>
      <div class="stat-num"><?= (int) $counts['CLOSED'] ?></div>
      <div class="stat-label">Closed</div>
    </div>
  </div>
  <div class="stat stat-unassigned">
    <div class="stat-icon"><i class="fa-solid fa-user-slash"></i></div>
    <div>
      <div class="stat-num"><?= (int) $counts['UNASSIGNED'] ?></div>
      <div class="stat-label">Unassigned</div>
    </div>
  </div>
</div>

<div class="card">
  <h2 style="display:flex;align-items:center;justify-content:space-between;">
    <span><i class="fa-solid fa-clock-rotate-left"></i> Recent tickets</span>
    <a class="btn btn-outline btn-sm" href="/tickets.php"><i class="fa-solid fa-list"></i> View all</a>
  </h2>

  <?php if (!$recent): ?>
    <div class="empty">
      <i class="fa-solid fa-inbox"></i>
      <p>No tickets yet. When the AI escalates a question, it will appear here.</p>
    </div>
  <?php else: ?>
    <div class="table-wrap">
      <table class="table">
        <thead>
          <tr>
            <th>Created</th>
            <th>Question</th>
            <th>Status</th>
            <th>Assignee</th>
            <th class="col-actions"></th>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($recent as $t): ?>
          <tr>
            <td><?= htmlspecialchars(substr($t['created_at'] ?? '', 0, 19)) ?></td>
            <td><?= htmlspecialchars(mb_strimwidth($t['question'] ?? '', 0, 80, '...')) ?></td>
            <td><span class="pill pill-<?= strtolower($t['status'] ?? 'open') ?>"><?= htmlspecialchars($t['status'] ?? 'OPEN') ?></span></td>
            <td><?= !empty($t['assignee']) ? htmlspecialchars($t['assignee']) : '<span class="pill pill-unassigned">unassigned</span>' ?></td>
            <td class="col-actions">
              <a class="btn btn-sm btn-outline" href="/ticket.php?id=<?= urlencode($t['ticket_id']) ?>">
                <i class="fa-solid fa-eye"></i> View
              </a>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</div>
<?php require __DIR__ . '/../views/partials/footer.php'; ?>
