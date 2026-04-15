# API Reference

Every HTTP endpoint and Lambda invocation contract in the UPOU AI HelpDesk.

## Student app endpoints (port 80)

All paths are relative to `http://<EC2_HOST>/`.

### `GET /` — Landing page

Returns the public landing HTML. No auth required.

### `GET /register.php`, `POST /register.php` — Sign up

Form-based registration.

**POST body (form-encoded):**
- `csrf` (string, required) — CSRF token from the form
- `username` (string, required) — 3–32 chars, `[A-Za-z0-9_.-]`
- `email` (string, required) — valid email
- `password` (string, required) — 8+ chars

**Side effects:**
- Inserts a row into `upou_helpdesk.users` with bcrypt-hashed password
- Auto-logs in the user
- Redirects to `/chat.php`

### `GET /login.php`, `POST /login.php` — Log in

**POST body (form-encoded):**
- `csrf` (string, required)
- `identifier` (string, required) — username or email
- `password` (string, required)

**Side effects:**
- Sets `PHPSESSID` cookie (HttpOnly, SameSite=Lax)
- Regenerates session ID
- Redirects to `/chat.php`

### `GET /logout.php` — Log out

Destroys the session and redirects to `/index.php`.

### `GET /chat.php` — Chat UI (auth required)

Returns the chat HTML with thread, input form, and JavaScript that calls `/api_ask.php`.

### `POST /api_ask.php` — Submit a question (auth required)

The main interaction endpoint. Called by the chat page's JavaScript.

**Request:**
- `Content-Type: application/json`
- Body:
  ```json
  { "question": "When does 2nd semester 2025-2026 start?" }
  ```
- Cookie: `PHPSESSID=<session>` (set by login)

**Response (success, 200):**
```json
{
  "id": "uuid",
  "answer": "The 2nd semester for AY 2025-2026 starts on 26 January 2026 (Monday) [CAL002].",
  "source_label": "Official Policy",
  "sources": [
    {
      "chunk_id": "CAL002",
      "section_title": "Start of classes by term",
      "source_title": "UPOU Academic Calendar AY 2025-2026",
      "source_url": "https://registrar.upou.edu.ph/...",
      "similarity": 0.933
    }
  ],
  "ticket_id": null,
  "top_similarity": 0.933,
  "s3_key": "logs/2026-04-14/uuid.json"
}
```

**Response (escalated, 200):**
```json
{
  "id": "uuid",
  "answer": "I couldn't find a confident answer to your question in the official UPOU policies...",
  "source_label": "Needs Human Review",
  "sources": [],
  "ticket_id": "ticket-uuid",
  "top_similarity": 0.45,
  "s3_key": "logs/2026-04-14/uuid.json"
}
```

**Response (general knowledge, 200):**
```json
{
  "id": "uuid",
  "answer": "...",
  "source_label": "General Knowledge",
  "sources": [],
  "ticket_id": null,
  "top_similarity": 0.04
}
```

**Errors:**
- `400` `{"error":"Question is required"}` — empty question
- `401` `{"error":"Not authenticated"}` — session missing or expired
- `500` `{"error":"Lambda invoke failed","detail":"..."}` — boto3 exception
- `500` `{"error":"PHP fatal: ...","file":"...","line":N}` — caught by shutdown handler

**Side effects:**
- Invokes `ai-webapp-handler` Lambda via `boto3.invoke()`
- Inserts a row into `upou_helpdesk.chat_history` (best-effort, does not fail the request if it errors)

### `GET /history.php` — Chat history (auth required)

Returns the user's last 50 questions and answers as HTML.

## Admin app endpoints (port 8080)

All paths are relative to `http://<EC2_HOST>:8080/`.

### `GET /` — Auth-aware redirect

Redirects to `/dashboard.php` if logged in, `/login.php` otherwise.

### `GET /register.php`, `POST /register.php` — Sign up

Same as student app but writes to `upou_admin.admin_users`.

**Special behavior:** if `admin_users` is empty when the registration completes, the new user is assigned role `admin`. Otherwise role `agent`.

### `GET /login.php`, `POST /login.php` — Log in

Sets the `UPOU_ADMIN_SID` cookie (distinct from the student app's `PHPSESSID`).

### `GET /logout.php` — Log out

### `GET /dashboard.php` — Dashboard (auth required)

Returns 6 stat cards (TOTAL, OPEN, IN_PROGRESS, RESOLVED, CLOSED, UNASSIGNED) and the 5 most recent tickets.

### `GET /tickets.php` — Filterable ticket list (auth required)

**Query parameters:**
- `status` (optional) — filter by status: `OPEN`, `IN_PROGRESS`, `RESOLVED`, `CLOSED`, or empty for all
- `assignee` (optional) — username, `__unassigned__`, or empty for all

### `GET /ticket.php?id=<ticket_id>` — Ticket detail (auth required)

Returns the ticket detail page with question, AI attempt, metadata, and update form.

### `POST /ticket.php?id=<ticket_id>` — Update a ticket (auth required)

**Request body (form-encoded):**
- `csrf` (required)
- `action` (required) — `update`, `claim`, or `delete` (admin-only)
- `status` (for update) — one of OPEN, IN_PROGRESS, RESOLVED, CLOSED
- `assignee` (for update) — username or empty for unassigned
- `resolution_notes` (for update) — free text

**Side effects:**
- Calls `TicketRepo::update()` which issues a DynamoDB `UpdateItem`
- Adds `updated_at` and (if status=RESOLVED) `resolved_at` automatically
- Writes an audit_log row

### `GET /users.php`, `POST /users.php` — User management (admin only)

**Side effects:**
- `promote` → `admin_users.role = 'admin'`
- `demote` → `admin_users.role = 'agent'` (refuses if it would leave 0 admins)
- `activate` / `deactivate` → toggles `is_active`

## Lambda invocation contract

The Lambda is invoked by both PHP apps via `boto3.invoke()` (or `aws-sdk-php`'s `invoke()`).

### Request payload

```json
{
  "question": "When does 2nd semester 2025-2026 start?",
  "user_email": "student@example.com"
}
```

The Lambda also accepts an API-Gateway-style event with the payload nested in `body`:
```json
{
  "body": "{\"question\":\"...\"}"
}
```

`_parse_body()` handles both shapes.

### Response

API-Gateway-shaped:
```json
{
  "statusCode": 200,
  "headers": {
    "Content-Type": "application/json",
    "Access-Control-Allow-Origin": "*"
  },
  "body": "{\"id\":\"...\",\"answer\":\"...\",...}"
}
```

The PHP `AwsClient::invokeAi()` unwraps the body before returning to callers.

### Lambda environment variables (required)

| Name | Purpose |
|---|---|
| `OPENAI_API_KEY` | API key for the chat completion endpoint |
| `OPENAI_BASE_URL` | Base URL — must end in `/v1`, NOT `/chat/completions` |
| `OPENAI_MODEL` | Model name passed in the chat request, e.g. `gpt-4o-mini` |
| `S3_BUCKET` | Bucket holding `policy_index.json` and logs |
| `S3_PREFIX` | Log key prefix, e.g. `logs/` |
| `POLICY_INDEX_KEY` | Default `policy_index.json` |
| `DDB_TICKETS_TABLE` | Default `upou-helpdesk-tickets` |
| `KEYWORD_THRESHOLD` | 0..1, default `0.15` |

### Required IAM permissions

The Lambda execution role (`LabRole` in Learner Lab) needs:

- `s3:GetObject` on `<bucket>/policy_index.json`
- `s3:PutObject` on `<bucket>/logs/*`
- `dynamodb:PutItem`, `dynamodb:UpdateItem` on `upou-helpdesk-tickets`
- `logs:CreateLogGroup`, `logs:CreateLogStream`, `logs:PutLogEvents` on `*` (CloudWatch)

The EC2 instance role (`LabInstanceProfile`) needs:

- `lambda:InvokeFunction` on `ai-webapp-handler`
- `dynamodb:Scan`, `dynamodb:GetItem`, `dynamodb:UpdateItem`, `dynamodb:DeleteItem` on `upou-helpdesk-tickets` (admin app only)

Both `LabRole` and `LabInstanceProfile` already have all of these in Learner Lab.

## DynamoDB schema reference

### Table: `upou-helpdesk-tickets`

| Field | Type | Set by | Purpose |
|---|---|---|---|
| `ticket_id` | S | Lambda | Partition key, UUID |
| `created_at` | S | Lambda | ISO timestamp UTC |
| `status` | S | Lambda → admin | OPEN, IN_PROGRESS, RESOLVED, CLOSED |
| `question` | S | Lambda | Original student question (immutable) |
| `ai_attempt` | S | Lambda | What the AI tried before giving up (immutable) |
| `top_similarity` | N | Lambda | Best policy chunk score (immutable) |
| `user_email` | S | Lambda | Student's email (immutable) |
| `assignee` | S | admin app | Username of agent |
| `resolution_notes` | S | admin app | Free text |
| `updated_at` | S | admin app | ISO timestamp on every update |
| `resolved_at` | S | admin app | Set automatically when status → RESOLVED |

The admin app's update whitelist is enforced in `TicketRepo::update()`:
```php
$allowed = ['status', 'assignee', 'resolution_notes'];
```

Any other fields in the request payload are silently ignored.

## MySQL schema reference

### `upou_helpdesk.users`
```sql
CREATE TABLE users (
  id            INT PRIMARY KEY AUTO_INCREMENT,
  username      VARCHAR(32) UNIQUE NOT NULL,
  email         VARCHAR(190) UNIQUE NOT NULL,
  password_hash VARCHAR(255) NOT NULL,    -- bcrypt
  created_at    DATETIME NOT NULL
);
```

### `upou_helpdesk.chat_history`
```sql
CREATE TABLE chat_history (
  id              BIGINT PRIMARY KEY AUTO_INCREMENT,
  user_id         INT NOT NULL,
  question        TEXT NOT NULL,
  answer          MEDIUMTEXT NOT NULL,
  source_label    VARCHAR(40),
  top_similarity  DECIMAL(6,4),
  ticket_id       VARCHAR(64),
  created_at      DATETIME NOT NULL,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);
```

### `upou_admin.admin_users`
```sql
CREATE TABLE admin_users (
  id            INT PRIMARY KEY AUTO_INCREMENT,
  username      VARCHAR(32) UNIQUE NOT NULL,
  email         VARCHAR(190) UNIQUE NOT NULL,
  password_hash VARCHAR(255) NOT NULL,
  role          ENUM('admin', 'agent') NOT NULL DEFAULT 'agent',
  is_active     TINYINT(1) NOT NULL DEFAULT 1,
  created_at    DATETIME NOT NULL,
  last_login_at DATETIME
);
```

### `upou_admin.audit_log`
```sql
CREATE TABLE audit_log (
  id         BIGINT PRIMARY KEY AUTO_INCREMENT,
  user_id    INT NOT NULL,
  username   VARCHAR(32) NOT NULL,
  action     VARCHAR(64) NOT NULL,
  ticket_id  VARCHAR(64),
  details    TEXT,
  created_at DATETIME NOT NULL
);
```

## Audit log action vocabulary

The admin app writes these action strings to `audit_log.action`:

| Action | Triggered by | `ticket_id` set? | `details` content |
|---|---|---|---|
| `update_ticket` | POST ticket.php update | yes | JSON of changed fields |
| `claim_ticket` | POST ticket.php claim | yes | (none) |
| `delete_ticket` | POST ticket.php delete | yes | (none) |
| `promote_user` | POST users.php | no | `user_id=N` |
| `demote_user` | POST users.php | no | `user_id=N` |
| `activate_user` | POST users.php | no | `user_id=N` |
| `deactivate_user` | POST users.php | no | `user_id=N` |
