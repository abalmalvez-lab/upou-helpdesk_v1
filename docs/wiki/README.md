# UPOU AI HelpDesk Wiki

Welcome. This wiki has documentation for everyone who interacts with the UPOU AI HelpDesk — students asking questions, agents handling tickets, administrators managing the system, and developers maintaining the code.

## Pick your role

### 🎓 I'm a student wanting to use the helpdesk
Read [`user-guide.md`](user-guide.md). No technical background needed — it covers how to sign up, ask questions, and what the colored badges on answers mean.

### 🎧 I'm an agent or administrator handling escalated tickets
Read [`admin-guide.md`](admin-guide.md). Walks you through claiming tickets, updating status, adding notes, and managing other agent accounts.

### 🛠 I'm a developer or DevOps engineer
You probably want to understand the architecture before changing anything:
- [`architecture.md`](architecture.md) — how the pieces fit together
- [`api-reference.md`](api-reference.md) — every HTTP endpoint and Lambda invocation contract
- [`operations.md`](operations.md) — monitoring, logs, common operational tasks

For deployment instructions see [`../../DEPLOYMENT-FAST.md`](../../DEPLOYMENT-FAST.md) (10 min) or [`../../DEPLOYMENT-LONG.md`](../../DEPLOYMENT-LONG.md) (with explanations).

For bug references see [`../../KNOWN-ISSUES.md`](../../KNOWN-ISSUES.md).

## What is the UPOU AI HelpDesk?

It's a web application that answers UP Open University students' questions about registration, enrollment, and the academic calendar. An AI assistant is grounded in the official UPOU policy documents — when it can answer from the policies, it cites the source. When it can't, the question is forwarded to a human helpdesk agent.

The system has two web interfaces:

- **Student helpdesk** (port 80): the chat UI students use to ask questions
- **Admin console** (port 8080): the dashboard agents use to handle escalated tickets

Both run on the same EC2 instance but have separate logins, separate databases, and separate sessions.
