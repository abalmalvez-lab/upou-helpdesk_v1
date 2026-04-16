# UPOU AI HelpDesk

A full-stack AI helpdesk for UP Open University students, grounded in official policy data via keyword search, with human-agent escalation for unanswered questions.

Built for AWS Academy Learner Lab. Deploys in 5–10 minutes via the included CLI scripts.

## What's in the box

- **AWS Lambda** (Python 3.11) — answers questions using a chat completion grounded in keyword search over a CSV policy knowledge base
- **PHP/MariaDB student frontend** — bcrypt auth, chat UI with three colored answer badges, history page (Apache port 80)
- **PHP/MariaDB admin console** — separate vhost for managing escalated tickets in DynamoDB, role-based access (admin/agent), first-signup-becomes-admin (Apache port 8080)
- **DynamoDB** — single source of truth for escalated tickets
- **S3** — stores the policy index and per-interaction logs
- **CLI deploy scripts** — bootstrap AWS resources, build/deploy Lambda, build/upload policy index, full orchestration

## Three answer modes

| Badge | When | What happens |
|---|---|---|
| 🟢 **Official Policy** | Keyword score ≥ threshold | AI answers from matched UPOU chunks, citations shown |
| 🟡 **General Knowledge** | No good policy match | AI answers from training, marked accordingly |
| 🔴 **Forwarded to Human Agent** | AI explicitly cannot answer | Ticket created in DynamoDB, picked up by admin console |

## Project File Structure Layout

```
upou-final/
├── data/
│   └── policies.csv               # 53 UPOU policy chunks (ENR + CAL)
├── lambda/
│   └── lambda_function.py         # Keyword search + chat completion + escalation
├── scripts/
│   ├── bootstrap_aws.sh           # One-time: create S3 bucket, DynamoDB, Lambda shell
│   ├── build_policy_index.py      # CSV → tokenized index → S3
│   ├── deploy_policy_index.sh     # Validate CSV, build, upload, force cold start
│   ├── deploy_lambda.sh           # Build zip, pin runtime, upload
│   ├── deploy_all.sh              # Orchestrator: PHP + Lambda + index + verify
│   └── clean_csv.py               # Repair tool for broken CSV (ellipsis chunk_ids)
├── php/                           # Student-facing app (port 80)
│   ├── composer.json
│   ├── includes/                  # config, db, auth, aws_client
│   ├── views/partials/            # header, footer
│   ├── public/                    # Apache document root
│   │   ├── index.php login.php register.php logout.php
│   │   ├── chat.php api_ask.php history.php
│   │   └── assets/style.css
│   └── sql/schema.sql             # Helpdesk users + chat_history (in /sql at top level too)
├── admin/                         # Admin console (port 8080)
│   ├── composer.json
│   ├── includes/                  # config, db, auth, ticket_repo, user_repo
│   ├── views/partials/
│   ├── public/                    # dashboard, tickets, ticket detail, users, login, register
│   ├── sql/schema.sql             # admin_users + audit_log
│   └── docs/upou-admin.conf       # Apache vhost (port 8080)
├── sql/
│   └── schema.sql                 # Helpdesk DB schema (top-level copy)
├── docs/
│   ├── deploy/
│   │   └── upou-helpdesk.conf     # Apache vhost for the student app
│   └── wiki/
│       ├── README.md              # Wiki index
│       ├── user-guide.md          # Non-technical user docs
│       ├── admin-guide.md         # Non-technical admin docs
│       ├── architecture.md        # Technical: how the system works
│       ├── api-reference.md       # Technical: every endpoint
│       └── operations.md          # Technical: monitoring, logs, troubleshooting
├── .env.example
├── README.md                      # this file
├── DEPLOYMENT-FAST.md             # 10-minute CLI deploy
├── DEPLOYMENT-LONG.md             # Full step-by-step manual deploy with explanations
└── KNOWN-ISSUES.md                # Catalog of every bug we hit and how the project prevents it
```

## Quick start

**Two paths, same destination:**

| Path | Time | When to use |
|---|---|---|
| **[`DEPLOYMENT-FAST.md`](DEPLOYMENT-FAST.md)** | 5–10 min | You have the prereqs, just want it deployed |
| **[`DEPLOYMENT-LONG.md`](DEPLOYMENT-LONG.md)** | 30–45 min | First-time deploy, want explanations and manual control |

Both paths produce the identical working system.

## Documentation

| Audience | Document |
|---|---|
| **Student / end-user** | [`docs/wiki/user-guide.md`](docs/wiki/user-guide.md) |
| **HelpDesk admin / agent** | [`docs/wiki/admin-guide.md`](docs/wiki/admin-guide.md) |
| **Developer / DevOps** | [`docs/wiki/architecture.md`](docs/wiki/architecture.md), [`docs/wiki/operations.md`](docs/wiki/operations.md), [`docs/wiki/api-reference.md`](docs/wiki/api-reference.md) |
| **Anyone hitting a bug** | [`KNOWN-ISSUES.md`](KNOWN-ISSUES.md) |

## Stack

- **Lambda runtime:** Python 3.11 (x86_64) — pinned by the deploy scripts
- **EC2:** Amazon Linux 2023, t3.small
- **Web server:** Apache 2.4 with PHP 8 (mod_php or PHP-FPM)
- **Database:** MariaDB 10.5
- **AWS region:** us-east-1
- **OpenAI-compatible endpoint:** UPOU class proxy (`https://is215-openai.upou.io/v1`) or real OpenAI

## License

For educational use in UPOU IS215 coursework.
