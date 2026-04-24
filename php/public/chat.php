<?php
require_once __DIR__ . '/../includes/auth.php';
Auth::requireLogin();
$pageTitle = 'Chat';
require __DIR__ . '/../views/partials/header.php';
?>
<div class="card">
  <h2><i class="fa-solid fa-comments"></i> Ask the HelpDesk</h2>
  <p class="lead">Questions are answered using official UPOU policies. If we can't answer, you can choose to forward your question to a human agent.</p>
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

function renderEscalationPrompt(data) {
  const div = document.createElement('div');
  div.className = 'msg';
  let html = '<div class="msg-bot">';
  html += '<span class="source-badge badge-human"><i class="fa-solid fa-headset"></i> Needs Human Review</span>';
  html += '<div>' + escapeHtml(data.answer) + '</div>';
  html += '<div class="escalation-prompt" style="margin-top: 1rem; padding: 1rem; border: 1px solid var(--border, #555); border-radius: 8px; background: rgba(255,255,255,0.03);">';
  html += '<p style="margin: 0 0 0.75rem 0;"><i class="fa-solid fa-circle-question"></i> <strong>Would you like to forward this question to a human agent?</strong></p>';
  html += '<div style="display: flex; gap: 0.75rem;">';
  html += '<button class="btn btn-primary escalate-yes" style="padding: 0.5rem 1.5rem;"><i class="fa-solid fa-check"></i> Yes, forward it</button>';
  html += '<button class="btn escalate-no" style="padding: 0.5rem 1.5rem; background: #666; color: #fff;"><i class="fa-solid fa-xmark"></i> No thanks</button>';
  html += '</div></div>';
  html += '</div>';
  div.innerHTML = html;
  thread.appendChild(div);
  thread.scrollTop = thread.scrollHeight;

  // Yes button — create the ticket
  div.querySelector('.escalate-yes').addEventListener('click', async function() {
    const promptDiv = div.querySelector('.escalation-prompt');
    promptDiv.innerHTML = '<p><i class="fa-solid fa-spinner fa-spin"></i> Creating ticket...</p>';

    try {
      const res = await fetch('/api_escalate.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
          question: data._original_question,
          ai_attempt: data.answer,
          top_similarity: data.top_similarity || 0,
        })
      });
      const result = await res.json();
      if (!res.ok) throw new Error(result.error || 'Escalation failed');

      promptDiv.innerHTML =
        '<div class="ticket-note" style="margin: 0;">' +
        '<i class="fa-solid fa-check-circle" style="color: #4caf50;"></i> ' +
        '<strong>Ticket created!</strong> A human agent will follow up.' +
        '<br/><i class="fa-solid fa-ticket"></i> Ticket ID: <code>' + escapeHtml(result.ticket_id) + '</code>' +
        '<br/><small>You can track your ticket status on the <a href="/my_tickets.php">My Tickets</a> page.</small>' +
        '</div>';
    } catch (err) {
      promptDiv.innerHTML =
        '<div style="color: #f44336;">' +
        '<i class="fa-solid fa-triangle-exclamation"></i> ' +
        escapeHtml(err.message) +
        '</div>';
    }
  });

  // No button — dismiss the prompt
  div.querySelector('.escalate-no').addEventListener('click', function() {
    const promptDiv = div.querySelector('.escalation-prompt');
    promptDiv.innerHTML =
      '<div style="color: #aaa;">' +
      '<i class="fa-solid fa-info-circle"></i> ' +
      'No ticket created. Feel free to rephrase your question or try again later.' +
      '</div>';
  });
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

    if (data.source_label === 'Needs Human Review') {
      // Show Yes/No confirmation instead of auto-creating a ticket
      data._original_question = question;
      renderEscalationPrompt(data);
    } else {
      renderBot(data);
    }
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
