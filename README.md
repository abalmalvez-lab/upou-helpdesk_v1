# UPOU AI HelpDesk

An intelligent helpdesk system for UP Open University students that provides instant answers to policy questions using AI, with seamless escalation to human agents when needed.

The system combines smart AI technology with official university policies to give students accurate, trustworthy answers. When the AI can't answer, questions are automatically routed to staff for personal assistance.

**Student Portal:** https://upouaihelp.duckdns.org/ <br>
**Admin Portal:** https://upouaihelp.duckdns.org:8443

## System Overview

The UPOU AI HelpDesk is a complete solution that includes:

- **Smart AI Assistant** — Answers student questions by searching through official UPOU policies, providing accurate answers with source citations
- **Student Portal** — Easy-to-use chat interface where students can ask questions, view their history, and track support tickets
- **Admin Console** — Staff dashboard for managing escalated questions, assigning agents, and monitoring support performance
- **Secure Database** — Stores all support tickets and interactions for tracking and reporting
- **Cloud Storage** — Maintains the policy knowledge base and conversation logs
- **Automated Setup** — Quick deployment tools that get the system running in minutes

## How It Works

The AI helpdesk provides three types of responses:

| Response Type | When It Happens | What Students See |
|---|---|---|
| 🟢 **Official Policy Answer** | The question matches official UPOU policies | AI provides an answer with direct citations to university policies |
| 🟡 **General Knowledge Answer** | The question doesn't match specific policies but is answerable | AI provides a helpful answer based on general knowledge, clearly labeled as such |
| 🔴 **Human Agent Needed** | The AI cannot confidently answer the question | Student is asked if they want to forward to a staff member for personal assistance |

## Student Experience

When students use the helpdesk:

- **Ask Questions** — Type any question about UPOU policies in the chat interface
- **Get Instant Answers** — Receive immediate responses with source citations when available
- **View History** — Access past questions and answers anytime
- **Track Support Tickets** — Monitor the status of questions forwarded to staff
- **Receive Updates** — Get notified when staff respond to escalated questions

## Staff Experience

The admin console provides staff with:

- **Dashboard Overview** — See all support tickets at a glance with status summaries
- **Ticket Management** — Assign, update, and resolve student questions
- **Agent Coordination** — Multiple staff can work together with role-based permissions
- **Activity Tracking** — Complete audit log of all actions taken
- **Performance Insights** — Monitor response times and resolution rates

## Getting Started

Two deployment options are available:

| Option | Time | Best For |
|---|---|---|
| **Quick Deploy** | 5–10 minutes | Experienced users who want fast setup |
| **Detailed Deploy** | 30–45 minutes | First-time setup with step-by-step guidance |

Both options produce the same fully functional system.

## Documentation

| For This Audience | Read This |
|---|---|
| **Students** | User Guide - how to use the helpdesk |
| **HelpDesk Staff** | Admin Guide - managing tickets and users |
| **Technical Team** | Architecture, Operations, and API Reference documents |
| **Troubleshooting** | Known Issues catalog |

## Technology Stack

The system is built on modern, reliable technologies:

- **Cloud Platform** — AWS (Amazon Web Services) for scalable infrastructure
- **AI Engine** — OpenAI-compatible AI for intelligent question answering
- **Web Server** — Apache with PHP for responsive web interfaces
- **Database** — MariaDB for user accounts and conversation history
- **Cloud Database** — DynamoDB for ticket management
- **Storage** — S3 for policy knowledge base and logs

## Key Features

**For Students:**
- 24/7 availability for policy questions
- Instant answers with source citations
- Conversation history for reference
- Easy ticket tracking for escalated questions

**For Staff:**
- Centralized ticket management dashboard
- Role-based access control (Admin/Agent)
- Real-time status updates
- Complete activity audit trail
- Performance monitoring capabilities

**For Administrators:**
- Automated deployment tools
- SSL certificate management
- Diagnostic and troubleshooting utilities
- Secure user authentication
- Comprehensive logging

## License

This system is developed for educational use in UPOU IS215 coursework.
