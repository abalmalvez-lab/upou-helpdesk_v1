<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/ticket_repo.php';
require_once __DIR__ . '/../includes/user_repo.php';
Auth::requireLogin();

$ticketId = $_GET['id'] ?? '';
$flash = null;
$flashType = 'success';

// Handle POST updates
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Auth::csrfCheck($_POST['csrf'] ?? null)) {
        $flash = 'Invalid form submission.';
        $flashType = 'error';
    } else {
        $action = $_POST['action'] ?? '';

        if ($action === 'update') {
            $fields = [
                'status'           => $_POST['status'] ?? null,
                'assignee'         => $_POST['assignee'] ?? '',
                'resolution_notes' => $_POST['resolution_notes'] ?? '',
            ];
            $fields = array_filter($fields, fn($v) => $v !== null);
            if (TicketRepo::update($ticketId, $fields)) {
                Auth::log('update_ticket', $ticketId, json_encode($fields));
                $flash = 'Ticket updated.';
            } else {
                $flash = 'Failed to update ticket.';
                $flashType = 'error';
            }
        } elseif ($action === 'claim') {
            $username = Auth::user()['username'];
            if (TicketRepo::update($ticketId, ['assignee' => $username, 'status' => 'IN_PROGRESS'])) {
                Auth::log('claim_ticket', $ticketId);
                $flash = 'Ticket claimed and marked In Progress.';
            } else {
                $flash = 'Failed to claim ticket.';
                $flashType = 'error';
            }
        } elseif ($action === 'delete' && Auth::isAdmin()) {
            if (TicketRepo::delete($ticketId)) {
                Auth::log('delete_ticket', $ticketId);
                header('Location: /tickets.php?deleted=1');
                exit;
            } else {
                $flash = 'Failed to delete ticket.';
                $flashType = 'error';
            }
        }
    }
}

$ticket = TicketRepo::get($ticketId);
if (!$ticket) {
    http_response_code(404);
    $pageTitle = 'Not Found';
    require __DIR__ . '/../views/partials/header.php';
    echo '<div class="card"><h1>Ticket not found</h1><p><a href="/tickets.php">Back to tickets</a></p></div>';
    require __DIR__ . '/../views/partials/footer.php';
    exit;
}

$assignableUsers = UserRepo::assignableUsernames();
$pageTitle = 'Ticket ' . substr($ticketId, 0, 8);
require __DIR__ . '/../views/partials/header.php';
?>
<div class="card">
  <h1 style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:0.5rem;">
    <span><i class="fa-solid fa-ticket"></i> Ticket Detail</span>
    <span class="pill pill-<?= strtolower($ticket['status'] ?? 'open') ?>"><?= htmlspecialchars($ticket['status'] ?? 'OPEN') ?></span>
  </h1>
  <p class="muted">ID: <code><?= htmlspecialchars($ticketId) ?></code></p>
</div>

<?php if ($flash): ?>
  <div class="alert alert-<?= $flashType === 'success' ? 'success' : 'error' ?>">
    <i class="fa-solid <?= $flashType === 'success' ? 'fa-circle-check' : 'fa-circle-exclamation' ?>"></i>
    <span><?= htmlspecialchars($flash) ?></span>
  </div>
<?php endif; ?>

<div class="detail-grid">
  <!-- LEFT: question + AI attempt -->
  <div>
    <div class="card">
      <div class="qa-label">Student Question</div>
      <div class="qa-box q"><?= htmlspecialchars($ticket['question'] ?? '') ?></div>

      <div class="qa-label">AI's Attempted Answer</div>
      <div class="qa-box a"><?= htmlspecialchars($ticket['ai_attempt'] ?? '(none)') ?></div>

      <?php if (!empty($ticket['resolution_notes'])): ?>
        <div class="qa-label">Resolution Notes</div>
        <div class="qa-box"><?= htmlspecialchars($ticket['resolution_notes']) ?></div>
      <?php endif; ?>
    </div>

    <div class="card">
      <h2><i class="fa-solid fa-pen"></i> Update Ticket</h2>
      <form method="post">
        <input type="hidden" name="csrf" value="<?= htmlspecialchars(Auth::csrfToken()) ?>">
        <input type="hidden" name="action" value="update">

        <div class="field">
          <label>Status</label>
          <select name="status">
            <?php foreach (['OPEN','IN_PROGRESS','RESOLVED','CLOSED'] as $s): ?>
              <option value="<?= $s ?>" <?= ($ticket['status'] ?? 'OPEN') === $s ? 'selected' : '' ?>><?= $s ?></option>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="field">
          <label>Assignee</label>
          <select name="assignee">
            <option value="">— Unassigned —</option>
            <?php foreach ($assignableUsers as $u): ?>
              <option value="<?= htmlspecialchars($u) ?>" <?= ($ticket['assignee'] ?? '') === $u ? 'selected' : '' ?>><?= htmlspecialchars($u) ?></option>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="field">
          <label>Resolution notes</label>
          <textarea name="resolution_notes" rows="4" placeholder="What did you do to resolve this?"><?= htmlspecialchars($ticket['resolution_notes'] ?? '') ?></textarea>
        </div>

        <button class="btn btn-primary" type="submit">
          <i class="fa-solid fa-floppy-disk"></i> Save changes
        </button>
      </form>
    </div>
  </div>

  <!-- RIGHT: metadata + actions -->
  <div>
    <div class="card">
      <h2><i class="fa-solid fa-info-circle"></i> Metadata</h2>
      <div class="kv"><div class="k">Created</div><div class="v"><?= htmlspecialchars($ticket['created_at'] ?? '') ?></div></div>
      <?php if (!empty($ticket['updated_at'])): ?>
        <div class="kv"><div class="k">Last update</div><div class="v"><?= htmlspecialchars($ticket['updated_at']) ?></div></div>
      <?php endif; ?>
      <?php if (!empty($ticket['resolved_at'])): ?>
        <div class="kv"><div class="k">Resolved</div><div class="v"><?= htmlspecialchars($ticket['resolved_at']) ?></div></div>
      <?php endif; ?>
      <div class="kv"><div class="k">Student email</div><div class="v"><?= htmlspecialchars($ticket['user_email'] ?? '(unknown)') ?></div></div>
      <div class="kv"><div class="k">Top similarity</div><div class="v"><?= isset($ticket['top_similarity']) ? number_format((float)$ticket['top_similarity'], 4) : '—' ?></div></div>
      <div class="kv"><div class="k">Assignee</div><div class="v"><?= !empty($ticket['assignee']) ? htmlspecialchars($ticket['assignee']) : '<em class="muted">unassigned</em>' ?></div></div>
    </div>

    <?php if (empty($ticket['assignee']) || $ticket['assignee'] !== Auth::user()['username']): ?>
      <div class="card">
        <h2><i class="fa-solid fa-hand"></i> Quick actions</h2>
        <form method="post" style="margin-bottom: 0.5rem;">
          <input type="hidden" name="csrf" value="<?= htmlspecialchars(Auth::csrfToken()) ?>">
          <input type="hidden" name="action" value="claim">
          <button class="btn btn-success btn-block" type="submit">
            <i class="fa-solid fa-hand-holding"></i> Claim this ticket
          </button>
        </form>
        <p class="muted">Claiming will assign the ticket to you and set status to In Progress.</p>
      </div>
    <?php endif; ?>

    <?php if (Auth::isAdmin()): ?>
      <div class="card">
        <h2><i class="fa-solid fa-triangle-exclamation"></i> Admin actions</h2>
        <form method="post" onsubmit="return confirm('Permanently delete this ticket? This cannot be undone.');">
          <input type="hidden" name="csrf" value="<?= htmlspecialchars(Auth::csrfToken()) ?>">
          <input type="hidden" name="action" value="delete">
          <button class="btn btn-danger btn-block" type="submit">
            <i class="fa-solid fa-trash"></i> Delete ticket
          </button>
        </form>
      </div>
    <?php endif; ?>

    <a class="btn btn-outline" href="/tickets.php">
      <i class="fa-solid fa-arrow-left"></i> Back to tickets
    </a>
  </div>
</div>
<?php require __DIR__ . '/../views/partials/footer.php'; ?>
