<?php
$pageTitle = 'Welcome';
require __DIR__ . '/../views/partials/header.php';
?>
<section class="hero">
  <h1><i class="fa-solid fa-graduation-cap"></i> UPOU AI HelpDesk</h1>
  <p class="lead">Get instant answers to your registration and enrollment questions, grounded in official UP Open University policies.</p>
  <div class="cta">
    <?php if (Auth::check()): ?>
      <a class="btn btn-primary" href="/chat.php"><i class="fa-solid fa-comments"></i> Open Chat</a>
    <?php else: ?>
      <a class="btn btn-primary" href="/register.php"><i class="fa-solid fa-user-plus"></i> Get Started</a>
      <a class="btn btn-outline" href="/login.php"><i class="fa-solid fa-right-to-bracket"></i> Login</a>
    <?php endif; ?>
  </div>
</section>

<div class="feature-grid">
  <div class="feature">
    <i class="fa-solid fa-book-bookmark"></i>
    <h3>Official Policy</h3>
    <p>Answers cite the exact UPOU policy chunk and source URL.</p>
  </div>
  <div class="feature">
    <i class="fa-solid fa-brain"></i>
    <h3>AI-Powered</h3>
    <p>Backed by GPT and semantic search over the UPOU knowledge base.</p>
  </div>
  <div class="feature">
    <i class="fa-solid fa-headset"></i>
    <h3>Human Handoff</h3>
    <p>Tough questions are forwarded to a human agent automatically.</p>
  </div>
  <div class="feature">
    <i class="fa-solid fa-cloud"></i>
    <h3>AWS Powered</h3>
    <p>Lambda, S3, DynamoDB working behind the scenes.</p>
  </div>
</div>
<?php require __DIR__ . '/../views/partials/footer.php'; ?>
