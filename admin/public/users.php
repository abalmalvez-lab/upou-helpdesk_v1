<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/user_repo.php';
Auth::requireAdmin();

$flash = null;
$flashType = 'success';
$current = Auth::user();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Auth::csrfCheck($_POST['csrf'] ?? null)) {
        $flash = 'Invalid form submission.';
        $flashType = 'error';
    } else {
        $action = $_POST['action'] ?? '';
        $userId = (int) ($_POST['user_id'] ?? 0);
        $target = UserRepo::find($userId);

        if (!$target) {
            $flash = 'User not found.';
            $flashType = 'error';
        } elseif ($target['id'] === $current['id']) {
            $flash = "You can't modify your own account from this page.";
            $flashType = 'error';
        } else {
            switch ($action) {
                case 'promote':
                    UserRepo::setRole($userId, 'admin');
                    Auth::log('promote_user', null, "user_id=$userId");
                    $flash = "Promoted {$target['username']} to admin.";
                    break;
                case 'demote':
                    if (UserRepo::adminCount() <= 1) {
                        $flash = "Can't demote the last remaining admin.";
                        $flashType = 'error';
                    } else {
                        UserRepo::setRole($userId, 'agent');
                        Auth::log('demote_user', null, "user_id=$userId");
                        $flash = "Demoted {$target['username']} to agent.";
                    }
                    break;
                case 'deactivate':
                    UserRepo::setActive($userId, false);
                    Auth::log('deactivate_user', null, "user_id=$userId");
                    $flash = "Deactivated {$target['username']}.";
                    break;
                case 'activate':
                    UserRepo::setActive($userId, true);
                    Auth::log('activate_user', null, "user_id=$userId");
                    $flash = "Activated {$target['username']}.";
                    break;
            }
        }
    }
}

$users = UserRepo::all();
$pageTitle = 'Users';
require __DIR__ . '/../views/partials/header.php';
?>
<div class="card">
  <h1><i class="fa-solid fa-users-gear"></i> User Management</h1>
  <p class="lead">Promote, demote, or deactivate admin/agent accounts.</p>
</div>

<?php if ($flash): ?>
  <div class="alert alert-<?= $flashType === 'success' ? 'success' : 'error' ?>">
    <i class="fa-solid <?= $flashType === 'success' ? 'fa-circle-check' : 'fa-circle-exclamation' ?>"></i>
    <span><?= htmlspecialchars($flash) ?></span>
  </div>
<?php endif; ?>

<div class="table-wrap">
  <table class="table">
    <thead>
      <tr>
        <th>Username</th>
        <th>Email</th>
        <th>Role</th>
        <th>Status</th>
        <th>Created</th>
        <th>Last login</th>
        <th class="col-actions">Actions</th>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($users as $u): $isMe = $u['id'] === $current['id']; ?>
      <tr>
        <td>
          <?= htmlspecialchars($u['username']) ?>
          <?php if ($isMe): ?><span class="muted">(you)</span><?php endif; ?>
        </td>
        <td><?= htmlspecialchars($u['email']) ?></td>
        <td>
          <span class="role-badge role-<?= htmlspecialchars($u['role']) ?>"><?= htmlspecialchars($u['role']) ?></span>
        </td>
        <td>
          <?php if ((int)$u['is_active'] === 1): ?>
            <span class="pill pill-resolved">active</span>
          <?php else: ?>
            <span class="pill pill-closed">disabled</span>
          <?php endif; ?>
        </td>
        <td><?= htmlspecialchars(substr($u['created_at'] ?? '', 0, 19)) ?></td>
        <td><?= htmlspecialchars(substr($u['last_login_at'] ?? '—', 0, 19) ?: '—') ?></td>
        <td class="col-actions">
          <?php if (!$isMe): ?>
            <form method="post" style="display:inline">
              <input type="hidden" name="csrf" value="<?= htmlspecialchars(Auth::csrfToken()) ?>">
              <input type="hidden" name="user_id" value="<?= (int)$u['id'] ?>">
              <?php if ($u['role'] === 'agent'): ?>
                <button class="btn btn-sm btn-outline" name="action" value="promote" title="Make admin">
                  <i class="fa-solid fa-user-shield"></i>
                </button>
              <?php else: ?>
                <button class="btn btn-sm btn-outline" name="action" value="demote" title="Demote to agent">
                  <i class="fa-solid fa-user"></i>
                </button>
              <?php endif; ?>
              <?php if ((int)$u['is_active'] === 1): ?>
                <button class="btn btn-sm btn-danger" name="action" value="deactivate" title="Deactivate"
                  onclick="return confirm('Deactivate <?= htmlspecialchars($u['username']) ?>?');">
                  <i class="fa-solid fa-ban"></i>
                </button>
              <?php else: ?>
                <button class="btn btn-sm btn-success" name="action" value="activate" title="Activate">
                  <i class="fa-solid fa-check"></i>
                </button>
              <?php endif; ?>
            </form>
          <?php else: ?>
            <span class="muted">—</span>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php require __DIR__ . '/../views/partials/footer.php'; ?>
