# Fast Deployment — UPOU AI HelpDesk

**Time:** 5–10 minutes
**Prerequisites:** AWS Academy Learner Lab, OpenAI-compatible API key, Git repo with this project pushed

This is the all-CLI path. For step-by-step explanations, see [`DEPLOYMENT-LONG.md`](DEPLOYMENT-LONG.md).

---

## Step 1 — Start the Learner Lab and bootstrap AWS

1. Log into AWS Academy → **Start Lab** → wait for green dot → click **AWS**
2. Open **CloudShell** (the `>_` icon in the top toolbar)
3. Run:

```bash
git clone <YOUR_REPO_URL> upou-helpdesk
cd upou-helpdesk
chmod +x scripts/*.sh

export OPENAI_API_KEY='your-key'
export S3_BUCKET='upou-helpdesk-2026-yourinitials'   # globally unique, no s3:// prefix

./scripts/bootstrap_aws.sh
```

Creates: S3 bucket, DynamoDB `upou-helpdesk-tickets` table, Lambda `ai-webapp-handler` shell, env vars.

## Step 2 — Launch EC2

In the AWS console:

1. EC2 → **Launch instance**
2. Name: `upou-helpdesk`
3. AMI: **Amazon Linux 2023**
4. Type: **t3.small** ⚠️ not micro (Composer needs RAM)
5. Key pair: create `upou-helpdesk-key`, download .pem
6. Security group: new `upou-helpdesk-sg`, inbound rules:
   - SSH (22) — My IP
   - HTTP (80) — My IP
   - Custom TCP (8080) — My IP
7. **Advanced details → IAM instance profile → `LabInstanceProfile`**
8. Launch, copy the public IP

## Step 3 — Install LAMP and deploy on EC2

SSH in:
```bash
ssh -i ~/Downloads/upou-helpdesk-key.pem ec2-user@<EC2_PUBLIC_IP>
```

Install everything in one block:
```bash
sudo dnf update -y
sudo dnf install -y \
  httpd \
  php php-cli php-mysqlnd php-xml php-mbstring php-curl php-json \
  mariadb105-server \
  python3.11 python3.11-pip \
  git unzip zip policycoreutils-python-utils

curl -sS https://getcomposer.org/installer | php
sudo mv composer.phar /usr/local/bin/composer

sudo systemctl enable --now httpd mariadb

# Python deps system-wide
sudo pip3.11 install --quiet openai boto3

# SELinux: allow Apache on port 8080 (for admin console)
sudo semanage port -a -t http_port_t -p tcp 8080 2>/dev/null || true

# Set MariaDB root password (interactive)
sudo mysql_secure_installation
```

Clone the project:
```bash
sudo mkdir -p /var/www
sudo chown ec2-user:ec2-user /var/www
cd /var/www
git clone <YOUR_REPO_URL> upou-helpdesk
cd upou-helpdesk

# If git creates a nested upou-helpdesk/upou-helpdesk, flatten it
[[ -d upou-helpdesk ]] && { sudo mv upou-helpdesk/* . && sudo rmdir upou-helpdesk; }

ls    # should show: data docs lambda php admin scripts sql README.md ...
```

## Step 4 — Deploy the student-facing helpdesk

```bash
cd /var/www/upou-helpdesk/php
composer install --no-dev --optimize-autoloader

# Generate a strong DB password and import the schema
DB_PASS=$(openssl rand -base64 16 | tr -d '=+/')
echo "Helpdesk DB password: $DB_PASS"
sed -i "s/CHANGE_ME_STRONG_PASSWORD/$DB_PASS/" /var/www/upou-helpdesk/sql/schema.sql
sudo mysql -u root -p < /var/www/upou-helpdesk/sql/schema.sql

# Install Apache vhost
sudo cp /var/www/upou-helpdesk/docs/deploy/upou-helpdesk.conf /etc/httpd/conf.d/
sudo sed -i "s|your-bucket-name-here|$S3_BUCKET|" /etc/httpd/conf.d/upou-helpdesk.conf
sudo sed -i "s|CHANGE_ME_STRONG_PASSWORD|$DB_PASS|" /etc/httpd/conf.d/upou-helpdesk.conf
sudo rm -f /etc/httpd/conf.d/welcome.conf

# File permissions
sudo chown -R apache:apache /var/www/upou-helpdesk/php
sudo systemctl restart httpd
```

## Step 5 — Deploy the admin console (port 8080)

The `deploy_admin.sh` script handles everything: SELinux port registration, Composer install, schema import (with password sync), Apache vhost setup with the correct DocumentRoot, and verification that port 8080 actually serves.

```bash
cd /var/www/upou-helpdesk
sudo ./scripts/deploy_admin.sh
```

The script will prompt for your MariaDB root password once (for schema import) and print the generated admin DB password at the end. It's idempotent — safe to re-run if anything needs to change.

To skip the MariaDB prompt, set the root password as an env var first:
```bash
export MYSQL_ROOT_PASSWORD='your-mariadb-root-password'
sudo -E ./scripts/deploy_admin.sh
```

When done, verify both ports are listening:
```bash
sudo ss -tlnp | grep -E ':(80|8080)'
```

## Step 6 — Run the deploy orchestrator

This builds the Lambda, uploads the policy index, and runs end-to-end verification:

```bash
cd /var/www/upou-helpdesk
chmod +x scripts/*.sh
export OPENAI_API_KEY='your-key'
export S3_BUCKET='upou-helpdesk-2026-yourinitials'

./scripts/deploy_all.sh
```

The script prints `✓` for each step and ends with a green `=== Deploy complete ===` plus the elapsed time.

## Step 7 — Verify

### Student app (port 80)
1. Visit `http://<EC2_PUBLIC_IP>/`
2. Sign up
3. Ask: *"When does 2nd semester 2025-2026 start?"* → 🟢 **Official Policy** badge with date

### Admin app (port 8080)
1. Visit `http://<EC2_PUBLIC_IP>:8080/`
2. Sign up — **first signup becomes admin**
3. Dashboard shows ticket counts (zero unless someone has triggered escalation)

### Trigger an escalation
1. Back at the student app, ask: *"What is my GPA in BIO101?"*
2. Should return 🔴 **Forwarded to Human Agent** with a ticket ID
3. Reload the admin dashboard — ticket count goes up by 1
4. Click the ticket → claim it → mark resolved → confirm DynamoDB has the updates:
   ```bash
   aws dynamodb scan --table-name upou-helpdesk-tickets --region us-east-1 \
     --query 'Items[*].[ticket_id.S,status.S,assignee.S]' --output table
   ```

If all checks pass, you're done.

---

## Restarting after a Learner Lab session expires

Lab sessions expire ~4 hours. EC2 instance is stopped; everything else persists.

```bash
# 1. Start the lab → AWS console
# 2. EC2 → start the stopped upou-helpdesk instance, get new public IP
# 3. EC2 → Security Groups → upou-helpdesk-sg → update both rules' source to "My IP"
# 4. Visit http://<NEW_IP>/ — Apache + MariaDB auto-start
```

If you updated the source code in git, on the EC2:
```bash
cd /var/www/upou-helpdesk
git pull
./scripts/deploy_all.sh
```

The `deploy_all.sh` script is idempotent — re-running it just re-syncs everything.

---

## If anything fails

See [`KNOWN-ISSUES.md`](KNOWN-ISSUES.md) — every bug we encountered during development is documented with symptom, cause, and fix. The deploy scripts prevent most of them automatically, but check the catalog if you hit something unexpected.

Most common failures:
- `s3://` prefix in bucket name → scripts refuse to run with this
- Lambda runtime drifted to 3.14 → `deploy_lambda.sh` re-pins to 3.11 every run
- Cold start not picking up new index → `deploy_policy_index.sh` bumps `CACHE_BUST` env var
- PHP returns "Unexpected end of JSON input" → check `/var/log/php-fpm/www-error.log` (not Apache error log)
