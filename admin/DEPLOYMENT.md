# UPOU HelpDesk Admin — Deployment Guide

Step-by-step instructions to deploy the admin console alongside the existing UPOU HelpDesk app on the same EC2 instance. Assumes the main helpdesk app is already deployed and working.

**Total time:** ~10 minutes
**Requires:** an existing EC2 instance running the main helpdesk, `LabInstanceProfile` attached, AWS Academy Learner Lab session active.

---

## 1. Prerequisites

- ✅ Main helpdesk app is running at `http://<EC2_IP>/`
- ✅ DynamoDB table `upou-helpdesk-tickets` exists (created during main app deployment)
- ✅ At least one ticket in DynamoDB to test with (ask the main app an unanswerable question first — something like *"What's my current GPA?"*)
- ✅ You can SSH into the EC2 instance

If DynamoDB is empty, that's fine — the dashboard will just show zero counts until the main Lambda escalates something.

---

## 2. Open port 8080 in the EC2 security group

This admin app runs on port 8080 (the main app uses port 80).

1. AWS Console → **EC2** → **Security Groups** → select `upou-helpdesk-sg`
2. **Inbound rules** → **Edit inbound rules** → **Add rule**
3. Settings:
   - Type: **Custom TCP**
   - Port: **8080**
   - Source: **My IP** (or `0.0.0.0/0` to share with classmates)
4. **Save rules**

---

## 3. Clone the admin app onto EC2

SSH in:
```bash
ssh -i ~/Downloads/upou-helpdesk-key.pem ec2-user@<EC2_PUBLIC_IP>
```

Clone:
```bash
cd /var/www
sudo chown ec2-user:ec2-user /var/www
git clone <YOUR_REPO_URL> upou-admin
cd upou-admin
```

Verify the layout (should see `composer.json`, `public/`, `includes/`, `sql/`, `docs/`):
```bash
ls
```

If git cloned into a nested `upou-admin/upou-admin` folder (a known git quirk), flatten it:
```bash
cd /var/www
sudo mv upou-admin/upou-admin /var/www/upou-admin-tmp
sudo rm -rf upou-admin
sudo mv upou-admin-tmp upou-admin
cd upou-admin
ls  # should now show files at the root
```

---

## 4. Install PHP dependencies via Composer

```bash
cd /var/www/upou-admin
composer install --no-dev --optimize-autoloader
```

If `composer` is not installed (it was installed when you deployed the main app, so it should be), install it:
```bash
curl -sS https://getcomposer.org/installer | php
sudo mv composer.phar /usr/local/bin/composer
composer install --no-dev --optimize-autoloader
```

If the install OOMs, your EC2 is a t3.micro — either resize to t3.small or temporarily add swap:
```bash
sudo dd if=/dev/zero of=/swapfile bs=1M count=1024
sudo chmod 600 /swapfile
sudo mkswap /swapfile
sudo swapon /swapfile
composer install --no-dev --optimize-autoloader
```

---

## 5. Create the MySQL database

Generate a strong password and substitute it into the schema:

```bash
DB_PASS=$(openssl rand -base64 16 | tr -d '=+/' | head -c 20)
echo "Database password: $DB_PASS"
sed -i "s/CHANGE_ME_STRONG_PASSWORD/$DB_PASS/" sql/schema.sql
```

**Write that password down** — you need it in the next step.

Import the schema:
```bash
sudo mysql -u root -p < sql/schema.sql
```

Enter the MariaDB root password you set when deploying the main app.

Verify the database was created:
```bash
mysql -u upou_admin_app -p upou_admin -e "SHOW TABLES;"
```
Enter the new `$DB_PASS`. Should list `admin_users` and `audit_log`.

---

## 6. Configure Apache

Copy the vhost:
```bash
sudo cp /var/www/upou-admin/docs/upou-admin.conf /etc/httpd/conf.d/upou-admin.conf
```

Substitute the database password:
```bash
sudo sed -i "s|CHANGE_ME_STRONG_PASSWORD|$DB_PASS|" /etc/httpd/conf.d/upou-admin.conf
```

Fix file permissions for Apache:
```bash
sudo chown -R apache:apache /var/www/upou-admin
sudo chmod -R 755 /var/www/upou-admin
```

Test Apache config:
```bash
sudo apachectl configtest
```

Should print `Syntax OK`. If it complains about `Listen 8080` being duplicate (because the vhost file has it too), remove that line from the vhost:
```bash
sudo sed -i '/^Listen 8080$/d' /etc/httpd/conf.d/upou-admin.conf
```

Reload Apache:
```bash
sudo systemctl restart httpd
```

Verify it's now listening on both ports:
```bash
sudo ss -tlnp | grep -E ':(80|8080)'
```
Should show `httpd` on both 80 and 8080.

---

## 7. SELinux consideration (Amazon Linux 2023)

Amazon Linux 2023 has SELinux enabled by default, and it blocks Apache from listening on non-standard ports. If you get a "Permission denied" error on `systemctl restart httpd`, run:

```bash
sudo semanage port -a -t http_port_t -p tcp 8080
```

If `semanage` isn't installed:
```bash
sudo dnf install -y policycoreutils-python-utils
sudo semanage port -a -t http_port_t -p tcp 8080
sudo systemctl restart httpd
```

If `semanage` already has 8080 registered, it'll print an error you can ignore.

---

## 8. First login — become the admin

Visit:
```
http://<EC2_PUBLIC_IP>:8080/
```

You should see the admin login page. Click **Sign up** and register:
- Username: `admin` (or whatever you like)
- Email: your email
- Password: at least 8 characters

On the registration page, you should see a **yellow notice**: *"You will be the first admin."* Since `admin_users` is empty, this first signup automatically becomes an admin. You'll be redirected to the dashboard.

If you see *"You will be registered as an agent"* instead, it means `admin_users` already has rows from a previous test — that's not a problem, but you'll need to log in with the existing admin account, or truncate the table and re-register:

```bash
mysql -u upou_admin_app -p upou_admin -e "TRUNCATE TABLE admin_users;"
```

---

## 9. Verify the ticket dashboard works

### 9a. If you don't have any tickets yet

Go to the **main helpdesk** at `http://<EC2_IP>/`, log in, and ask an unanswerable question:
- *"What is my GPA in IS215?"*
- *"What grade did I get on my midterm?"*

The AI should return the red **Forwarded to Human Agent** badge and a ticket ID. That creates a row in DynamoDB.

### 9b. Check the admin dashboard

Back at `http://<EC2_IP>:8080/dashboard.php`, refresh. You should see:
- Total tickets: 1 (or whatever you have)
- Open: 1
- Unassigned: 1
- Recent tickets table showing the ticket

### 9c. Open the ticket

Click **View** on the ticket. You should see:
- The student's question
- The AI's attempted answer
- The top similarity score
- An update form with Status / Assignee / Resolution notes

### 9d. Test the full lifecycle

1. Click **Claim this ticket** → status changes to `IN_PROGRESS`, assignee becomes your username
2. Add some resolution notes → click **Save changes**
3. Change status to `RESOLVED` → click **Save changes**
4. Go back to the dashboard — counts should update (Resolved: 1, Open: 0)

### 9e. Verify the updates landed in DynamoDB

From any shell with AWS CLI:
```bash
aws dynamodb scan --table-name upou-helpdesk-tickets --region us-east-1 \
  --query 'Items[*].{id:ticket_id.S,status:status.S,assignee:assignee.S,notes:resolution_notes.S,resolved:resolved_at.S}' \
  --output table
```

You should see your ticket with the status, assignee, notes, and `resolved_at` timestamp all populated — proving the admin app wrote back to DynamoDB, not just local MySQL.

---

## 10. Test the role system

### 10a. Create a second user (agent)

In a different browser (or an incognito window, so you don't lose your admin session):

1. Visit `http://<EC2_IP>:8080/register.php`
2. Register with a different username (e.g. `jane`, `jane@example.com`, password)
3. The registration page should now say *"You will be registered as an agent"*
4. You'll be logged in as `jane`

As `jane`:
- Dashboard: visible ✅
- Tickets: visible ✅
- **Users menu in top nav: HIDDEN** (admins only)
- Visit `/users.php` directly: **403 Forbidden** page ✅
- Open a ticket → there's **no Delete button** (admin only) ✅

### 10b. Promote jane to admin

In your admin browser tab:
1. Click **Users** in the top nav
2. Find `jane` in the table
3. Click the **promote** button (shield icon)
4. Alert: *"Promoted jane to admin."*

In jane's browser, refresh — she should now see the Users link and the role badge should show `admin`. (She may need to log out and back in for the session role to update.)

### 10c. Try to demote the last admin

If you're the only admin, try to demote yourself — the button is disabled on your own row. Promote jane first, then try to demote jane — should work. Try to demote the last remaining admin (either jane or you, depending on order) — should show *"Can't demote the last remaining admin."*

---

## 11. Restarting after a Learner Lab session

Same procedure as the main app. Everything persists except the EC2 instance state and public IP.

1. Start the lab → open console
2. EC2 → start the stopped instance
3. Copy new public IP
4. EC2 → Security Groups → `upou-helpdesk-sg` → update both port 80 AND port 8080 rules' source to "My IP"
5. Visit `http://<NEW_IP>:8080/` — the app is auto-started by Apache

Your admin accounts, audit log, and all tickets survive.

---

## 12. Troubleshooting

### "403 Forbidden" when visiting http://EC2_IP:8080/
- Security group doesn't allow port 8080 from your IP → Step 2
- OR Apache isn't listening on 8080 → `sudo ss -tlnp | grep 8080` and check `/etc/httpd/conf.d/upou-admin.conf`

### "500 Internal Server Error"
```bash
sudo tail -50 /var/log/httpd/upou-admin-error.log
```
Common causes:
- `vendor/` missing → `cd /var/www/upou-admin && composer install`
- DB password mismatch → check vhost `DB_PASS` matches what you imported in schema.sql
- File permissions → `sudo chown -R apache:apache /var/www/upou-admin`

### Dashboard shows 0 tickets even though DynamoDB has them
- Wrong `DDB_TICKETS_TABLE` in vhost → check `/etc/httpd/conf.d/upou-admin.conf`, then `sudo systemctl restart httpd`
- Wrong region → should be `us-east-1` in the vhost
- EC2 role has no DynamoDB permissions → `LabInstanceProfile` grants this by default in Learner Lab; verify it's attached (EC2 → Instances → Actions → Security → Modify IAM role)

### "Unknown database 'upou_admin'"
You didn't import the schema → Step 5.

### Login form says "Invalid username or password"
- Double-check the password (it's case-sensitive)
- If you forgot it, truncate and re-register:
  ```bash
  mysql -u upou_admin_app -p upou_admin -e "TRUNCATE TABLE admin_users;"
  ```

### Ticket update succeeds in UI but DynamoDB doesn't change
- Check `/var/log/httpd/upou-admin-error.log` for `DynamoDB update failed: ...` lines
- `LabInstanceProfile` normally includes `dynamodb:UpdateItem`; verify your IAM role if this fails

### Apache fails to restart after copying the vhost
```bash
sudo apachectl configtest
```
Read the error message. Most common: duplicate `Listen 8080` (the line exists both in the vhost and in `/etc/httpd/conf/httpd.conf` from a previous edit). Remove one of them.

### Port 8080 "Permission denied"
SELinux is blocking. See Step 7.

### Both the admin app and the main app see each other's sessions (or auto-logout on switch)
Shouldn't happen — the admin app uses `UPOU_ADMIN_SID` as its session cookie name, separate from PHP's default `PHPSESSID`. If it IS happening, check `includes/auth.php` → `Auth::start()` has the `session_name('UPOU_ADMIN_SID')` call before `session_start()`.

---

## 13. Uninstalling

If you want to remove the admin app:

```bash
sudo rm /etc/httpd/conf.d/upou-admin.conf
sudo systemctl restart httpd
sudo rm -rf /var/www/upou-admin
mysql -u root -p -e "DROP DATABASE upou_admin; DROP USER 'upou_admin_app'@'localhost';"
```

The main helpdesk app and its DynamoDB tickets are untouched.

---

## Appendix — What the admin app adds to each DynamoDB item

The Lambda creates tickets with these fields:
```
ticket_id, created_at, status, question, ai_attempt, top_similarity, user_email
```

This admin app adds these fields via `UpdateItem`:
```
assignee         - string, username of the assigned agent/admin
resolution_notes - string, free-form text
updated_at       - ISO timestamp, set on every update
resolved_at      - ISO timestamp, set when status transitions to RESOLVED
```

The update whitelist in `includes/ticket_repo.php` is **strict** — only `status`, `assignee`, and `resolution_notes` can be modified via the web UI. `question`, `ai_attempt`, `top_similarity`, etc. are immutable from the admin side, so you can always see what the student originally asked and how the AI originally responded.

---

**End of guide.**
