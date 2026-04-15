<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/user_repo.php';
Auth::start();

$error = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Auth::csrfCheck($_POST['csrf'] ?? null)) {
        $error = 'Invalid form submission. Please try again.';
    } else {
        $result = Auth::login($_POST['identifier'] ?? '', $_POST['password'] ?? '');
        if ($result['ok']) {
            header('Location: /dashboard.php');
            exit;
        }
        $error = $result['error'];
    }
}

$pageTitle = 'Login';
require __DIR__ . '/../views/partials/header.php';
?>
<div class="card auth-card">
  <h1><i class="fa-solid fa-right-to-bracket"></i> Admin Login</h1>
  <p class="lead">Access the UPOU HelpDesk admin console.</p>

  <?php if ($error): ?>
    <div class="alert alert-error">
      <i class="fa-solid fa-circle-exclamation"></i>
      <span><?= htmlspecialchars($error) ?></span>
    </div>
  <?php endif; ?>

  <form method="post">
    <input type="hidden" name="csrf" value="<?= htmlspecialchars(Auth::csrfToken()) ?>">

    <div class="field">
      <label>Username or Email</label>
      <div class="input-wrap">
        <i class="fa-solid fa-user"></i>
        <input name="identifier" required autofocus value="<?= htmlspecialchars($_POST['identifier'] ?? '') ?>">
      </div>
    </div>

    <div class="field">
      <label>Password</label>
      <div class="input-wrap">
        <i class="fa-solid fa-lock"></i>
        <input name="password" type="password" required>
      </div>
    </div>

    <button class="btn btn-primary btn-block" type="submit">
      <i class="fa-solid fa-right-to-bracket"></i> Login
    </button>
  </form>

  <p class="muted" style="margin-top: 1rem; text-align: center;">
    No account yet? <a href="/register.php">Sign up</a>
  </p>
</div>
<?php require __DIR__ . '/../views/partials/footer.php'; ?>
