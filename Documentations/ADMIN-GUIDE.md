# Admin & Agent Guide

A guide for HelpDesk admins and agents who handle escalated tickets in the UPOU AI HelpDesk admin console.

## What is the admin console?

When the AI HelpDesk can't answer a student's question, it forwards the question to a human as a "ticket." The admin console is where you handle those tickets — claim them, resolve them, and document what you did.

The admin console runs on **port 8080** of the same server as the student helpdesk. If the student site is at `http://example.com/`, the admin console is at `http://example.com:8080/`. Your instructor will give you the exact URL.

## Roles: admin vs agent

There are two roles in the admin console:

| Role | Can do |
|---|---|
| **Admin** | Everything an agent can do, PLUS manage other users (promote, demote, deactivate) and delete tickets |
| **Agent** | View tickets, claim tickets, update status, add notes, mark as resolved |

**The first person to register becomes the admin automatically.** Everyone who registers after that becomes an agent. An admin can promote any agent to admin from the Users page.

## Getting started

### Sign up

1. Open the admin console URL in your browser
2. Click **Sign up**
3. If you're the first person to register, you'll see a yellow notice: *"You will be the first admin"*
4. Fill in username, email, password (8+ characters)
5. Click **Create Account** — you'll be logged in automatically and land on the dashboard

### Log in next time

1. Open the admin console URL
2. Click **Login**
3. Enter your username (or email) and password

### Log out

Click **Logout** in the top-right corner. Your session ends.

## The dashboard

When you log in, you land on the dashboard. It shows six stat cards at a glance:

- **Total tickets** — all tickets ever created
- **Open** — newly created, nobody has claimed them yet
- **In progress** — someone is working on them
- **Resolved** — completed (waiting to be closed)
- **Closed** — fully done
- **Unassigned** — no agent assigned (this is usually the same as "Open" but can include older tickets that were unclaimed)

Below the cards is a **Recent tickets** table showing the 5 newest tickets. Click **View** on any of them to see the details.

## Working with tickets

### See all tickets

Click **Tickets** in the top menu. You'll see a filterable list of every ticket in the system.

You can filter by:

- **Status** — Open, In progress, Resolved, Closed
- **Assignee** — All, Mine only, Unassigned

For example, to see only your own active work, set Status = "IN_PROGRESS" and Assignee = "Mine only."

### Open a ticket

Click **View** on any row in the table. You'll see the ticket detail page with:

**Left column:**
- The student's original question
- The AI's attempted answer (this is what the AI tried to say before realizing it couldn't help)
- An update form with status, assignee, and resolution notes fields

**Right column:**
- Metadata: when the ticket was created, last updated, who's assigned, the AI's confidence score, the student's email
- Quick action buttons (Claim, etc.)
- Admin-only actions (Delete, if you're an admin)

### Claim a ticket

When you're ready to work on a ticket:

1. Open the ticket
2. Click **Claim this ticket** in the right column
3. The ticket's status changes to **IN_PROGRESS** and the assignee field gets your username

You only need to do this once per ticket. From then on, the ticket appears under "Mine only" in the filter.

### Update a ticket

Use the form in the left column to change:

- **Status:**
  - **OPEN** — newly created, nobody has touched it
  - **IN_PROGRESS** — someone is actively working on it
  - **RESOLVED** — done; the student has been helped (the system records the resolved time automatically)
  - **CLOSED** — fully finalized; usually only set after follow-up confirmation
- **Assignee:** reassign to a different agent (or yourself, or leave unassigned)
- **Resolution notes:** free-text field — write what you did to handle the ticket. This is important for accountability and for whoever has to look at the ticket later.

Click **Save changes** when done. The ticket history is updated and other admins/agents can see who changed what (in the audit log).

### Delete a ticket (admin only)

If a ticket is spam, a duplicate, or otherwise shouldn't exist:

1. Open the ticket
2. Click **Delete ticket** in the right column (admin-only button)
3. Confirm the popup

This permanently removes the ticket from DynamoDB. **Cannot be undone.** Use sparingly — for accountability reasons it's usually better to mark a ticket as CLOSED with notes than to delete it.

## Recommended workflow

### Routine ticket handling

1. **Start of shift:** Open the dashboard. Note how many Open tickets exist.
2. **Pick a ticket:** Filter to "Status: OPEN" → click View → read the question and the AI's attempted answer
3. **Decide if you can handle it:** if yes, claim it (one click). If you need to ask someone else, leave it unassigned and ping them.
4. **Investigate and respond:** look up whatever the student asked about — usually in your normal helpdesk tools (AIMS, finance system, registrar records, etc.)
5. **Reply to the student:** the system doesn't email students directly, so contact them via your normal channels (email, class platform, Slack)
6. **Document the resolution:** open the ticket → set status to RESOLVED → write what you did in resolution notes → save
7. **Close it later:** after a day or two, if the student hasn't followed up, set status to CLOSED

### Escalating between agents

If you've claimed a ticket but realized someone else should handle it:

1. Open the ticket
2. Change the **Assignee** dropdown to the right person
3. Add a note in **Resolution notes**: "Reassigned to X because Y"
4. Save

The other agent will see the ticket appear under their "Mine only" filter.

## User management (admin only)

If you're an admin, you'll see a **Users** link in the top menu. Click it to see all admin/agent accounts.

For each user you can:

- **Promote agent to admin** (shield icon) — gives them user management permissions
- **Demote admin to agent** (user icon) — removes user management permissions. **Cannot demote yourself or the last remaining admin.**
- **Deactivate** (X icon) — disables the account; the user can no longer log in but their data is preserved
- **Activate** (check icon) — re-enables a deactivated account

A few safety rules built in:

- You can't modify your own row from this page (prevents accidental lockout)
- You can't deactivate or demote the last remaining admin (prevents permanent lockout)
- Audit log records every promote/demote/activate/deactivate action

## Reading the audit log

Every action in the admin console — claiming a ticket, changing status, promoting a user — is recorded in the `audit_log` table. There's no UI for browsing this yet (you'd need MySQL access to query it directly), but the data is there for accountability.

If you have shell access to the server:

```bash
mysql -u upou_admin_app -p upou_admin -e \
  "SELECT created_at, username, action, ticket_id, details FROM audit_log ORDER BY id DESC LIMIT 20;"
```

This shows the most recent 20 admin actions.

## Common scenarios

### "I can't see the Users link"
You're an agent, not an admin. Only admins see Users. Ask an existing admin to promote you.

### "I claimed a ticket by mistake"
Open it, change Assignee back to "Unassigned," and change Status back to OPEN. Save.

### "Two of us claimed the same ticket"
DynamoDB doesn't lock tickets — last write wins. Whoever updated the ticket second is the current assignee. Coordinate verbally and reassign as needed.

### "I forgot my password"
There's no self-service password reset. Ask an admin to deactivate your account, then create a new one with the same email. (You'll lose your audit history.)

Or have an admin connect to the database and reset your hash directly.

### "I see 'Forbidden' when visiting the Users page"
You're not an admin. Only admins can access user management. The link is hidden in the menu but visiting the URL directly returns 403.

### "A ticket says 'similarity 1.0' but it was still escalated"
The "similarity" is how well the question matched a UPOU policy. A high score with an escalation usually means: the policy was found, but the AI realized the policy doesn't actually answer the specific question. For example, the student asked "What was my grade on the midterm exam?" — the policy mentions midterm exams (high keyword match) but doesn't have grades for individual students.

### "I want to see the full ticket data including raw fields"
The ticket detail page shows the human-friendly view. For raw DynamoDB inspection:
```bash
aws dynamodb get-item --table-name upou-helpdesk-tickets \
  --key '{"ticket_id":{"S":"PUT_TICKET_ID_HERE"}}'
```

## Privacy and data handling

- Student questions and emails are visible to all admins/agents — handle them with appropriate confidentiality
- The audit log records who did what and when, but not the content of resolution notes (those are part of the ticket itself)
- Deleted tickets cannot be recovered from the admin console — they're permanently removed from DynamoDB
- Closed tickets are kept indefinitely unless an admin deletes them

## Tips

- **Claim before investigating.** Other agents see the ticket as unclaimed otherwise, and might duplicate work.
- **Write detailed resolution notes.** "Helped student" is useless. "Resent activation email to <email>, confirmed receipt at 14:30" is useful.
- **Use status RESOLVED then CLOSED, not just CLOSED.** RESOLVED = "I think this is done"; CLOSED = "and the student confirms / no follow-up needed." Two-step closure makes audit trails clearer.
- **If you're not sure what the AI's policy match was**, look at the "Top similarity" field on the ticket detail page. A low number (< 0.2) means the AI didn't find good policy context — your judgment is needed. A high number (> 0.7) means the AI had context but couldn't answer specifically — usually because the student is asking about personal data.
