# UPOU HelpDesk Admin Console

A standalone PHP/MVC/MySQL web app for managing escalated tickets from the UPOU AI HelpDesk. Tickets live in **DynamoDB** (single source of truth); this app reads, updates, and optionally deletes them while keeping local MySQL tables only for admin user accounts and an audit log.

Runs as a separate Apache vhost on **port 8080** alongside the main helpdesk app on port 80. Distinct session cookie (`UPOU_ADMIN_SID`) so logging into one doesn't log you into the other.

## Features

### Authentication
- Username + email + bcrypt password
- **First signup becomes admin**, all subsequent signups are agents
- Role-based access: `admin` (full access) and `agent` (tickets only, no user management)
- CSRF protection on all forms
- Auto-deactivates accounts (admin can restore)
- Last-login timestamp tracking

### Ticket management
- **Dashboard** with counts: total / open / in-progress / resolved / closed / unassigned
- **Ticket list** with filters: status, assignee (all / mine / unassigned)
- **Ticket detail** showing original question, AI's attempted answer, similarity score, student email, source of escalation
- **Update status**: OPEN → IN_PROGRESS → RESOLVED → CLOSED (or any combination)
- **Assign/reassign** to any active user
- **Resolution notes** field for documenting how the ticket was handled
- **One-click claim** action (sets assignee to current user and status to IN_PROGRESS)
- **Admin-only delete** with confirmation
- Automatic `updated_at` / `resolved_at` timestamps written back to DynamoDB

### User management (admin-only)
- View all admin/agent accounts
- Promote agents to admin / demote admins to agent
- Activate / deactivate accounts (prevents login without losing data)
- **Safety guard**: cannot demote or deactivate the last remaining admin
- Cannot modify your own account from this page (prevents lockout)

### Audit log
- Every admin action writes a row to `audit_log` (user, action, ticket_id, details, timestamp)
- Includes ticket updates, claims, deletes, user role changes, activation/deactivation

## Stack

- **PHP 8.1+** on Apache (Amazon Linux 2023 `httpd`)
- **MySQL / MariaDB** for local auth + audit (separate database `upou_admin`)
- **DynamoDB** for tickets (shared with main helpdesk Lambda)
- **AWS SDK for PHP** via Composer
- **Font Awesome 6.5.1** for icons (via CDN)
- Custom dark-theme CSS (no framework)

## Project layout

```
upou-admin/
├── composer.json              # aws-sdk-php
├── includes/
│   ├── config.php             # env var loader (namespaced admin_env())
│   ├── db.php                 # PDO singleton
│   ├── auth.php               # register/login/CSRF/roles
│   ├── user_repo.php          # admin_users CRUD
│   └── ticket_repo.php        # DynamoDB scan/get/update/delete
├── views/partials/
│   ├── header.php             # topbar with role badge
│   └── footer.php
├── public/                    # Apache document root
│   ├── index.php              # auth-aware redirect
│   ├── login.php
│   ├── register.php
│   ├── logout.php
│   ├── dashboard.php          # stats + recent tickets
│   ├── tickets.php            # filterable list
│   ├── ticket.php             # detail + update form
│   ├── users.php              # admin-only user management
│   └── assets/style.css
├── sql/
│   └── schema.sql             # admin_users + audit_log tables
├── docs/
│   └── upou-admin.conf        # Apache vhost (port 8080)
├── .env.example
├── README.md
└── DEPLOYMENT.md
```

## Quick start

See [`DEPLOYMENT.md`](DEPLOYMENT.md) for the full step-by-step. High-level:

1. Clone into `/var/www/upou-admin` alongside the main helpdesk app
2. `cd /var/www/upou-admin && composer install`
3. Import `sql/schema.sql` with your DB password substituted
4. Copy `docs/upou-admin.conf` to `/etc/httpd/conf.d/` and fill in the DB password
5. Open port **8080** in the EC2 security group (My IP)
6. `sudo systemctl restart httpd`
7. Visit `http://<EC2_IP>:8080/` → first signup becomes admin

## How it coexists with the main app

| | Main helpdesk | Admin console |
|---|---|---|
| Port | 80 | 8080 |
| Document root | `/var/www/upou-helpdesk/php/public` | `/var/www/upou-admin/public` |
| Database | `upou_helpdesk` | `upou_admin` |
| Session cookie | `PHPSESSID` | `UPOU_ADMIN_SID` |
| MySQL user | `upou_app` | `upou_admin_app` |
| AWS access | invokes Lambda, reads S3 | reads/writes DynamoDB |
| Who logs in | end-user students | admins and agents |

They share the same EC2 instance, the same Apache daemon, the same MariaDB instance, and the same `LabInstanceProfile` IAM role — but otherwise they're fully isolated.

## Ticket lifecycle

```
  Student asks question
        │
        ▼
  Main helpdesk Lambda tries to answer
        │
        ├──  Official Policy / General Knowledge → return to student
        │
        └──  CANNOT_ANSWER_FROM_POLICY
                │
                ▼
        DynamoDB: PutItem { ticket_id, status: OPEN, question, ai_attempt, ... }
                │
                ▼
        Admin console: admin/agent claims ticket
                │
                ▼
        DynamoDB: UpdateItem { status: IN_PROGRESS, assignee: jane, updated_at: ... }
                │
                ▼
        Agent resolves, adds notes
                │
                ▼
        DynamoDB: UpdateItem { status: RESOLVED, resolution_notes: ..., resolved_at: ... }
```

The student-facing side of the app doesn't need to change — the Lambda writes tickets, and the admin console reads/updates the same records.

## Security notes

- All forms use CSRF tokens
- Passwords are bcrypt (never reversible)
- `Auth::requireAdmin()` guards user management and destructive actions
- Session cookies are `HttpOnly` + `SameSite=Lax`
- First-admin logic runs in a single transaction — no race condition between concurrent signups (MySQL's `COUNT(*)` + `INSERT` are serialized by the bcrypt delay anyway)
- DynamoDB updates use a **whitelist** of editable fields (`status`, `assignee`, `resolution_notes`) — the admin app cannot overwrite `question`, `ai_attempt`, or other fields the Lambda wrote

## License

For educational use in UPOU IS215 coursework.
