<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/ticket_repo.php';
Auth::requireLogin();

$status   = $_GET['status']   ?? '';
$assignee = $_GET['assignee'] ?? '';
$tickets  = TicketRepo::listAll($status ?: null, $assignee ?: null);

$pageTitle = 'Tickets';
require __DIR__ . '/../views/partials/header.php';
?>
<div class="card">
  <h1><i class="fa-solid fa-ticket"></i> Tickets</h1>
  <p class="lead">All escalated tickets in DynamoDB. Click a ticket to update.</p>
</div>

<form method="get" class="filter-bar">
  <div>
    <label>Status</label>
    <select name="status" onchange="this.form.submit()">
      <option value="">All</option>
      <?php foreach (['OPEN','IN_PROGRESS','RESOLVED','CLOSED'] as $s): ?>
        <option value="<?= $s ?>" <?= $status === $s ? 'selected' : '' ?>><?= $s ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <div>
    <label>Assignee</label>
    <select name="assignee" onchange="this.form.submit()">
      <option value="">All</option>
      <option value="__unassigned__" <?= $assignee === '__unassigned__' ? 'selected' : '' ?>>Unassigned</option>
      <option value="<?= htmlspecialchars(Auth::user()['username']) ?>" <?= $assignee === Auth::user()['username'] ? 'selected' : '' ?>>Mine only</option>
    </select>
  </div>
  <?php if ($status || $assignee): ?>
    <a class="btn btn-outline btn-sm" href="/tickets.php"><i class="fa-solid fa-xmark"></i> Clear</a>
  <?php endif; ?>
  <div style="margin-left:auto;color:var(--text-dim);align-self:center;font-size:0.85rem;">
    <?= count($tickets) ?> ticket<?= count($tickets) === 1 ? '' : 's' ?>
  </div>
</form>

<?php if (!$tickets): ?>
  <div class="card empty">
    <i class="fa-solid fa-inbox"></i>
    <p>No tickets match these filters.</p>
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
          <th>Similarity</th>
          <th class="col-actions"></th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($tickets as $t): ?>
        <tr>
          <td><?= htmlspecialchars(substr($t['created_at'] ?? '', 0, 19)) ?></td>
          <td><?= htmlspecialchars(mb_strimwidth($t['question'] ?? '', 0, 90, '...')) ?></td>
          <td><span class="pill pill-<?= strtolower($t['status'] ?? 'open') ?>"><?= htmlspecialchars($t['status'] ?? 'OPEN') ?></span></td>
          <td>
            <?= !empty($t['assignee']) ? htmlspecialchars($t['assignee']) : '<span class="pill pill-unassigned">unassigned</span>' ?>
          </td>
          <td><?= isset($t['top_similarity']) ? number_format((float)$t['top_similarity'], 3) : '—' ?></td>
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
<?php require __DIR__ . '/../views/partials/footer.php'; ?>
