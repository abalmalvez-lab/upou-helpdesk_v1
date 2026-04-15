<?php
require_once __DIR__ . '/../includes/auth.php';
Auth::requireLogin();
$pageTitle = 'Chat';
require __DIR__ . '/../views/partials/header.php';
?>
<div class="card">
  <h2><i class="fa-solid fa-comments"></i> Ask the HelpDesk</h2>
  <p class="lead">Questions are answered using official UPOU policies. If we can't answer, a human agent will follow up.</p>
</div>

<div class="chat-shell">
  <div id="thread" class="chat-thread"></div>

  <form id="ask-form" class="card">
    <div class="field" style="margin-bottom: 0.75rem;">
      <textarea id="question" rows="3" placeholder="e.g. How do I reset my AIMS portal password?" required></textarea>
    </div>
    <button id="ask-btn" class="btn btn-primary" type="submit">
      <i class="fa-solid fa-paper-plane"></i> Send
    </button>
  </form>
</div>

<script>
const thread = document.getElementById('thread');
const form = document.getElementById('ask-form');
const qInput = document.getElementById('question');
const btn = document.getElementById('ask-btn');

function escapeHtml(s) {
  return (s || '').replace(/[&<>"']/g, c => ({
    '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'
  }[c]));
}

function badgeFor(label) {
  if (label === 'Official Policy') {
    return '<span class="source-badge badge-policy"><i class="fa-solid fa-shield-halved"></i> Official Policy</span>';
  }
  if (label === 'General Knowledge') {
    return '<span class="source-badge badge-general"><i class="fa-solid fa-brain"></i> General Knowledge</span>';
  }
  return '<span class="source-badge badge-human"><i class="fa-solid fa-headset"></i> Forwarded to Human Agent</span>';
}

function renderUser(text) {
  const div = document.createElement('div');
  div.className = 'msg';
  div.innerHTML = '<div class="msg-user">' + escapeHtml(text) + '</div>';
  thread.appendChild(div);
  thread.scrollTop = thread.scrollHeight;
}

function renderBot(data) {
  const div = document.createElement('div');
  div.className = 'msg';
  let html = '<div class="msg-bot">';
  html += badgeFor(data.source_label);
  html += '<div>' + escapeHtml(data.answer) + '</div>';

  if (data.sources && data.sources.length) {
    html += '<div class="source-list"><strong><i class="fa-solid fa-link"></i> Sources:</strong><ul>';
    data.sources.forEach(s => {
      html += '<li>[' + escapeHtml(s.chunk_id) + '] ' + escapeHtml(s.section_title);
      if (s.source_url) {
        html += ' — <a href="' + encodeURI(s.source_url) + '" target="_blank" rel="noopener">' + escapeHtml(s.source_title || 'link') + '</a>';
      }
      html += ' <span class="muted">(similarity ' + s.similarity + ')</span></li>';
    });
    html += '</ul></div>';
  }

  if (data.ticket_id) {
    html += '<div class="ticket-note"><i class="fa-solid fa-ticket"></i> Ticket created: <code>' + escapeHtml(data.ticket_id) + '</code></div>';
  }

  html += '</div>';
  div.innerHTML = html;
  thread.appendChild(div);
  thread.scrollTop = thread.scrollHeight;
}

function renderError(msg) {
  const div = document.createElement('div');
  div.className = 'msg';
  div.innerHTML = '<div class="msg-bot"><span class="source-badge badge-human"><i class="fa-solid fa-triangle-exclamation"></i> Error</span><div>' + escapeHtml(msg) + '</div></div>';
  thread.appendChild(div);
  thread.scrollTop = thread.scrollHeight;
}

form.addEventListener('submit', async (e) => {
  e.preventDefault();
  const question = qInput.value.trim();
  if (!question) return;

  renderUser(question);
  qInput.value = '';
  btn.disabled = true;
  btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Thinking...';

  try {
    const res = await fetch('/api_ask.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ question })
    });
    const data = await res.json();
    if (!res.ok) throw new Error(data.error || 'Request failed');
    renderBot(data);
  } catch (err) {
    renderError(err.message);
  } finally {
    btn.disabled = false;
    btn.innerHTML = '<i class="fa-solid fa-paper-plane"></i> Send';
    qInput.focus();
  }
});

qInput.focus();
</script>
<?php require __DIR__ . '/../views/partials/footer.php'; ?>
