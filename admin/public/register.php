<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/user_repo.php';
Auth::start();

$error = null;
$adminCount = UserRepo::adminCount();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Auth::csrfCheck($_POST['csrf'] ?? null)) {
        $error = 'Invalid form submission. Please try again.';
    } else {
        $result = Auth::register(
            $_POST['username'] ?? '',
            $_POST['email'] ?? '',
            $_POST['password'] ?? ''
        );
        if ($result['ok']) {
            Auth::login($_POST['username'], $_POST['password']);
            header('Location: /dashboard.php');
            exit;
        }
        $error = $result['error'];
    }
}

$pageTitle = 'Sign Up';
require __DIR__ . '/../views/partials/header.php';
?>
<div class="card auth-card">
  <h1><i class="fa-solid fa-user-plus"></i> Create Admin Account</h1>
  <p class="lead">
    <?php if ($adminCount === 0): ?>
      <strong style="color:#fcd34d;">You will be the first admin.</strong> Subsequent signups will be agents.
    <?php else: ?>
      You will be registered as an <strong>agent</strong>. An admin can promote you later.
    <?php endif; ?>
  </p>

  <?php if ($error): ?>
    <div class="alert alert-error">
      <i class="fa-solid fa-circle-exclamation"></i>
      <span><?= htmlspecialchars($error) ?></span>
    </div>
  <?php endif; ?>

  <form method="post">
    <input type="hidden" name="csrf" value="<?= htmlspecialchars(Auth::csrfToken()) ?>">

    <div class="field">
      <label>Username</label>
      <div class="input-wrap">
        <i class="fa-solid fa-user"></i>
        <input name="username" required autofocus value="<?= htmlspecialchars($_POST['username'] ?? '') ?>">
      </div>
    </div>

    <div class="field">
      <label>Email</label>
      <div class="input-wrap">
        <i class="fa-solid fa-envelope"></i>
        <input name="email" type="email" required value="<?= htmlspecialchars($_POST['email'] ?? '') ?>">
      </div>
    </div>

    <div class="field">
      <label>Password (8+ characters)</label>
      <div class="input-wrap">
        <i class="fa-solid fa-lock"></i>
        <input name="password" type="password" required minlength="8">
      </div>
    </div>

    <button class="btn btn-primary btn-block" type="submit">
      <i class="fa-solid fa-user-plus"></i> Create Account
    </button>
  </form>

  <p class="muted" style="margin-top: 1rem; text-align: center;">
    Already have an account? <a href="/login.php">Log in</a>
  </p>
</div>
<?php require __DIR__ . '/../views/partials/footer.php'; ?>
