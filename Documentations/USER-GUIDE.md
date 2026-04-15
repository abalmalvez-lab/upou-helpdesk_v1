# Student User Guide

A simple guide to using the UPOU AI HelpDesk to get answers to your registration, enrollment, and academic calendar questions.

## What is the UPOU AI HelpDesk?

It's an AI assistant that knows the official UP Open University policies. You ask it a question in plain English, and it answers from the official documents — telling you exactly which policy it used. If it doesn't know the answer, your question is automatically sent to a human helpdesk agent who will follow up.

## Getting started

### Sign up

1. Open the helpdesk in your web browser. Your instructor will give you the URL.
2. Click **Sign up** in the top-right corner.
3. Fill in:
   - **Username** — a name to log in with (3–32 characters, letters/digits only)
   - **Email** — your real email
   - **Password** — at least 8 characters
4. Click **Create account**. You'll be logged in automatically.

### Log in next time

1. Click **Login** in the top-right corner.
2. Enter your username (or email) and password.
3. Click **Login**.

### Log out

Click **Logout** in the top-right corner. Your session ends and you'll need to log in again next time.

## Asking questions

1. Click **Chat** in the top menu (or you may land there automatically after login).
2. Type your question in the text box at the bottom of the page. Examples:
   - *"How do I reset my AIMS portal password?"*
   - *"When does 2nd semester 2025-2026 start?"*
   - *"What is the deadline to drop a subject?"*
   - *"How do I activate my AIMS account?"*
3. Click **Send** (or press Enter).
4. Wait a few seconds. The AI's answer will appear above the input box.

## Understanding the colored badges

Every answer comes with a colored badge that tells you **how the AI answered your question**. This is important — different sources have different reliability.

### 🟢 Official Policy

The AI found your question in the official UPOU policies and answered using them. **You can trust this answer** — it's based on the actual document. The answer also shows you exactly which policy chunks it used, with links to the original sources.

**Example:** Asking *"When does 2nd semester start?"* will return the date 26 January 2026 with a citation linking to the UPOU Academic Calendar PDF.

### 🟡 General Knowledge

The AI couldn't find your question in the UPOU policies, so it answered using its general knowledge. This is **less reliable** — the AI is doing its best from what it knows generally, but it might be wrong about UPOU-specific details. Treat these answers as a starting point, not as official guidance.

**Example:** Asking *"Who wrote Hamlet?"* would get a yellow General Knowledge answer because Shakespeare isn't in the UPOU policy documents.

### 🔴 Forwarded to Human Agent

The AI couldn't answer your question confidently. Instead of guessing, it **created a support ticket** and forwarded your question to a human helpdesk agent. You'll see a ticket ID — write it down or screenshot it. An agent will follow up.

**Example:** Asking *"What's my current GPA?"* would get a red Forwarded to Human Agent response because that's personal information the AI cannot know.

## Tips for getting good answers

- **Be specific.** "When does 2nd semester 2025-2026 start?" works better than "When does class start?"
- **Use UPOU terms.** If you're asking about AIMS, mention AIMS by name. The AI matches keywords in your question against UPOU policy keywords.
- **One question at a time.** Don't combine multiple questions in one message — ask each separately for the best results.
- **Ask in English.** The policy documents are in English, so English questions match best.
- **If you get a yellow or red badge, try rephrasing.** Sometimes the AI just needs different keywords. *"How can I add a course after the registration deadline?"* might work better than *"Late registration?"*.

## Looking at past questions

Click **History** in the top menu to see your last 50 questions and answers. Each entry shows:

- The question you asked
- The first 400 characters of the answer
- The colored badge for that answer
- The date and time
- A ticket ID if it was forwarded to a human agent

This is useful if you've already asked something and want to look back at the answer without asking again.

## What to do if the system is broken

If you see an error message like "Failed to execute" or "Connection refused":

1. **Refresh the page** (Ctrl+R or Cmd+R)
2. **Log out and log back in**
3. If neither works, **contact your instructor** with the time of day and what you were trying to do

If your question gets forwarded to a human agent (red badge) and nobody responds in a reasonable time, follow up via your normal class communication channel and reference the ticket ID.

## Privacy

- Your questions and answers are logged for support purposes
- Your email is recorded with each escalated ticket so the agent can contact you
- Your account password is stored encrypted (bcrypt) — even system administrators cannot read it
- Don't put confidential information (passwords, ID numbers, financial details) in your questions

## Frequently asked questions

**Q: Can I ask the AI about my grades?**
A: No. Personal information like grades, transcripts, payment history, etc. are not available to the AI. Those questions will be forwarded to a human agent who can look them up in the proper systems.

**Q: Why did I get a yellow badge instead of green?**
A: Your question didn't match any UPOU policy in the system's knowledge base. The AI tried to help anyway, but its answer isn't authoritative. Try rephrasing or contact a human agent if it's important.

**Q: How long until a human agent responds to my forwarded question?**
A: That depends on the helpdesk's hours and current workload. Check with your instructor for typical response times.

**Q: Can I delete my account?**
A: Not from the user interface. Contact your instructor or a system administrator if you need your account removed.

**Q: Does the AI remember my previous questions?**
A: Each question is answered independently. The AI doesn't see your previous chat history when answering — it starts fresh every time. (You can see your history yourself on the History page, but the AI can't.)
