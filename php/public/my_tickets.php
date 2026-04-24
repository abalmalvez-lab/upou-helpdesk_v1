<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/aws_client.php';
Auth::requireLogin();
$user = Auth::user();
$pageTitle = 'My Tickets';
require __DIR__ . '/../views/partials/header.php';
?>
<div class="card">
  <h2><i class="fa-solid fa-ticket"></i> My Tickets</h2>
  <p class="lead">Track the status of questions you forwarded to a human agent. Updates appear here when an agent responds.</p>
</div>

<div id="tickets-loading" class="alert alert-info">
  <i class="fa-solid fa-spinner fa-spin"></i> Loading your tickets...
</div>

<div id="tickets-empty" class="alert alert-info" style="display:none;">
  <i class="fa-solid fa-info-circle"></i>
  <span>No tickets yet. When you forward a question to a human agent from the <a href="/chat.php">Chat</a> page, it will appear here.</span>
</div>

<div id="tickets-error" class="alert" style="display:none; background: #5c2020; border-color: #a33;">
</div>

<div id="tickets-list"></div>

<script>
function escapeHtml(s) {
  return (s || '').replace(/[&<>"']/g, c => ({
    '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'
  }[c]));
}

function statusBadge(status) {
  const map = {
    'OPEN':        { cls: 'badge-human',   icon: 'fa-clock',           label: 'Open' },
    'IN_PROGRESS': { cls: 'badge-general', icon: 'fa-spinner',         label: 'In Progress' },
    'RESOLVED':    { cls: 'badge-policy',  icon: 'fa-check-circle',    label: 'Resolved' },
    'CLOSED':      { cls: 'badge-closed',  icon: 'fa-lock',            label: 'Closed' },
  };
  const m = map[status] || { cls: 'badge-general', icon: 'fa-question', label: status || 'Unknown' };
  return '<span class="source-badge ' + m.cls + '"><i class="fa-solid ' + m.icon + '"></i> ' + m.label + '</span>';
}

async function loadTickets() {
  const loading = document.getElementById('tickets-loading');
  const empty   = document.getElementById('tickets-empty');
  const errorEl = document.getElementById('tickets-error');
  const list    = document.getElementById('tickets-list');

  try {
    const res = await fetch('/api_ticket_status.php');
    const data = await res.json();

    loading.style.display = 'none';

    if (!res.ok) throw new Error(data.error || 'Failed to load tickets');

    const tickets = data.tickets || [];
    if (tickets.length === 0) {
      empty.style.display = '';
      return;
    }

    let html = '';
    tickets.forEach(t => {
      html += '<div class="history-item" style="margin-bottom: 1rem;">';

      // Status badge and date
      html += '<div class="h-meta" style="margin-bottom: 0.5rem;">';
      html += statusBadge(t.status);
      if (t.assignee) {
        html += ' <span style="margin-left: 0.5rem;"><i class="fa-solid fa-user"></i> Agent: <strong>' + escapeHtml(t.assignee) + '</strong></span>';
      }
      html += '<span style="margin-left: 0.5rem;"><i class="fa-solid fa-clock"></i> ' + escapeHtml(t.created_at || '') + '</span>';
      html += '</div>';

      // Question
      html += '<div class="h-q"><i class="fa-solid fa-circle-question"></i> ' + escapeHtml(t.question || '') + '</div>';

      // AI attempt (collapsed)
      if (t.ai_attempt) {
        html += '<div class="h-a" style="font-size: 0.85rem; color: #999;">AI attempted: ' + escapeHtml(t.ai_attempt).substring(0, 200) + '</div>';
      }

      // Resolution notes (if agent has responded)
      if (t.resolution_notes) {
        html += '<div style="margin-top: 0.75rem; padding: 0.75rem; border-left: 3px solid #4caf50; background: rgba(76,175,80,0.08); border-radius: 4px;">';
        html += '<strong><i class="fa-solid fa-comment-dots" style="color: #4caf50;"></i> Agent Response:</strong>';
        html += '<div style="margin-top: 0.4rem;">' + escapeHtml(t.resolution_notes) + '</div>';
        if (t.resolved_at) {
          html += '<div style="margin-top: 0.3rem; font-size: 0.8rem; color: #999;">Resolved: ' + escapeHtml(t.resolved_at) + '</div>';
        }
        html += '</div>';
      } else if (t.status === 'OPEN' || t.status === 'IN_PROGRESS') {
        html += '<div style="margin-top: 0.5rem; padding: 0.5rem; color: #aaa; font-size: 0.85rem;">';
        html += '<i class="fa-solid fa-hourglass-half"></i> Waiting for agent response...';
        html += '</div>';
      }

      // Ticket ID
      html += '<div style="margin-top: 0.5rem; font-size: 0.8rem; color: #888;">';
      html += '<i class="fa-solid fa-ticket"></i> ' + escapeHtml(t.ticket_id || '');
      if (t.updated_at) {
        html += ' &middot; Last updated: ' + escapeHtml(t.updated_at);
      }
      html += '</div>';

      html += '</div>';
    });

    list.innerHTML = html;
  } catch (err) {
    loading.style.display = 'none';
    errorEl.style.display = '';
    errorEl.innerHTML = '<i class="fa-solid fa-triangle-exclamation"></i> ' + escapeHtml(err.message);
  }
}

loadTickets();
</script>
<?php require __DIR__ . '/../views/partials/footer.php'; ?>
