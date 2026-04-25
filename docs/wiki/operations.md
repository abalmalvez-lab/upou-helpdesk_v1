# Operations Guide

For operators maintaining the UPOU AI HelpDesk in production. Covers monitoring, logs, common tasks, and incident response.

## Where things log

Different layers log to different places. When debugging, check the right log for the right component.

| Component | Log location |
|---|---|
| Apache (HTTP layer) | `/var/log/httpd/upou-helpdesk-error.log` (helpdesk vhost) |
| Apache (HTTP layer, admin) | `/var/log/httpd/upou-admin-error.log` |
| Apache access | `/var/log/httpd/upou-helpdesk-access.log`, `upou-admin-access.log` |
| **PHP fatal errors** | `/var/log/php-fpm/www-error.log` ⚠️ NOT in the Apache logs |
| PHP `error_log()` calls | same as PHP fatals |
| MariaDB | `/var/log/mariadb/mariadb.log` |
| Lambda (any `print()` and exceptions) | CloudWatch Logs → `/aws/lambda/ai-webapp-handler` |
| systemd service status | `journalctl -u httpd`, `journalctl -u mariadb` |

**The single most important thing to know:** PHP fatal errors do NOT appear in `/var/log/httpd/upou-helpdesk-error.log`. They go to `/var/log/php-fpm/www-error.log`. We hit this trap multiple times during development.

## Monitoring health

### Quick health check (10 seconds)

```bash
# Apache running and listening on both ports
sudo ss -tlnp | grep -E ':(80|8080)' && echo "ports OK"

# MariaDB running
sudo systemctl is-active mariadb

# Helpdesk DB reachable
mysql -u upou_app -p upou_helpdesk -e "SELECT 1;" 2>/dev/null && echo "helpdesk DB OK"

# Admin DB reachable
mysql -u upou_admin_app -p upou_admin -e "SELECT 1;" 2>/dev/null && echo "admin DB OK"

# Lambda exists and is in the right runtime
aws lambda get-function-configuration \
  --function-name ai-webapp-handler \
  --region us-east-1 \
  --query '{Runtime:Runtime,LastModified:LastModified}'

# S3 has the policy index
aws s3 ls s3://$S3_BUCKET/policy_index.json && echo "index OK"

# DynamoDB table exists
aws dynamodb describe-table --table-name upou-helpdesk-tickets --region us-east-1 \
  --query 'Table.TableStatus'
```

If all six checks pass, the system is healthy.

### End-to-end smoke test

```bash
# Test the Lambda directly (bypasses PHP entirely)
aws lambda invoke \
  --function-name ai-webapp-handler \
  --payload '{"question":"When does 2nd semester 2025-2026 start?"}' \
  --cli-binary-format raw-in-base64-out \
  --region us-east-1 \
  /tmp/smoke.json

cat /tmp/smoke.json | python3 -c '
import json, sys
data = json.load(sys.stdin)
body = json.loads(data["body"])
print("source_label:", body.get("source_label"))
print("top_similarity:", body.get("top_similarity"))
print("answer (first 100):", body.get("answer", "")[:100])
'
```

Should print `source_label: Official Policy` and a date.

## Common operational tasks

### Manage SSL certificates

The system supports HTTPS for both the student portal (port 443) and admin console (port 8443).

**Generate a placeholder SSL certificate:**
```bash
sudo openssl req -x509 -nodes -days 365 \
  -newkey rsa:2048 \
  -keyout /etc/pki/tls/private/localhost.key \
  -out /etc/pki/tls/certs/localhost.crt \
  -subj "/CN=localhost"
```

**Obtain new SSL certificates from Let's Encrypt:**
```bash
cd /var/www/upou-helpdesk
sudo ./scripts/ssl.sh setup
```

This installs certbot, obtains certificates for your domains, configures Apache HTTPS vhosts, and sets up auto-renewal.

**Export SSL certificates (for backup or migration):**
```bash
cd /var/www/upou-helpdesk
sudo ./scripts/ssl.sh export
```

Creates `upou-ssl-backup.tar.gz` with certificates and vhost configurations.

**Import SSL certificates (restore from backup):**
```bash
# Upload the backup to /tmp/ first
sudo ./scripts/ssl.sh import
```

**Verify SSL is working:**
```bash
curl -sk https://upouaihelp.duckdns.org/
curl -sk https://upouaihelp.duckdns.org:8443/
```

### Diagnose and fix admin console port 8080 issues

If the admin console is not accessible on port 8080:

```bash
cd /var/www/upou-helpdesk
sudo ./scripts/diagnose-admin-8080.sh
```

This script automatically:
- Verifies Apache is listening on 8080
- Checks vhost file exists and has correct contents
- Verifies admin app files exist at DocumentRoot
- Runs Apache config test
- Fixes DocumentRoot path mismatches
- Applies SELinux port registration if needed
- Restarts Apache if changes were made

### Update the policy CSV and rebuild the index

When UPOU publishes new policies (e.g. a new academic calendar):

```bash
# 1. Edit data/policies.csv on your laptop or directly on EC2
sudo nano /var/www/upou-helpdesk/data/policies.csv

# 2. Validate the CSV (no ellipsis chunk_ids, no blanks)
python3 /var/www/upou-helpdesk/scripts/clean_csv.py /var/www/upou-helpdesk/data/policies.csv

# 3. Rebuild the index AND force the Lambda to pick it up
cd /var/www/upou-helpdesk
export S3_BUCKET=your-bucket-name
./scripts/deploy_policy_index.sh
```

The script bumps the Lambda's `CACHE_BUST` env var, which forces all warm containers to drop their in-memory cache. Next invocation will reload the new index from S3.

### Update the Lambda code

```bash
# 1. Edit lambda/lambda_function.py
sudo nano /var/www/upou-helpdesk/lambda/lambda_function.py

# 2. Rebuild and redeploy
cd /var/www/upou-helpdesk
./scripts/deploy_lambda.sh
```

The script verifies syntax, builds the zip with the right Python version + architecture, pins the Lambda runtime to 3.11, uploads, and runs a smoke test.

### Update PHP code

```bash
# 1. Edit files under php/ or admin/
# 2. PHP picks up changes per request — no restart needed
# 3. (Optional) clear OPcache if enabled:
sudo systemctl reload php-fpm
```

If you changed `composer.json`:

```bash
cd /var/www/upou-helpdesk/php   # or admin
composer install --no-dev --optimize-autoloader
sudo chown -R apache:apache vendor/
```

If you added a new PHP file that Apache should serve, make sure it has the right ownership:

```bash
sudo chown -R apache:apache /var/www/upou-helpdesk/php
sudo chown -R apache:apache /var/www/upou-helpdesk/admin
```

### Add a new admin user without going through the UI

If the first admin gets locked out and there's no other admin to promote someone else:

```bash
# Create a new account via SQL
mysql -u root -p upou_admin <<EOF
INSERT INTO admin_users (username, email, password_hash, role, is_active, created_at)
VALUES (
  'recovery_admin',
  'admin@example.com',
  '$(php -r "echo password_hash('temporary-password', PASSWORD_BCRYPT);")',
  'admin',
  1,
  NOW()
);
EOF
```

Then log in with `recovery_admin` / `temporary-password`, change the password through the (currently nonexistent) password-change UI, or just keep using it for emergencies.

### Reset a user's password

The system has no self-service password reset. To reset a user's password manually:

```bash
NEW_HASH=$(php -r "echo password_hash('new-password-here', PASSWORD_BCRYPT);")

# For a student user
mysql -u root -p upou_helpdesk -e \
  "UPDATE users SET password_hash='$NEW_HASH' WHERE username='john'"

# For an admin user
mysql -u root -p upou_admin -e \
  "UPDATE admin_users SET password_hash='$NEW_HASH' WHERE username='jane'"
```

### Inspect tickets in DynamoDB

```bash
# All tickets, table-formatted
aws dynamodb scan --table-name upou-helpdesk-tickets --region us-east-1 \
  --query 'Items[*].{id:ticket_id.S,status:status.S,assignee:assignee.S,created:created_at.S}' \
  --output table

# One specific ticket, full detail
aws dynamodb get-item --table-name upou-helpdesk-tickets --region us-east-1 \
  --key '{"ticket_id":{"S":"PUT_TICKET_ID_HERE"}}'

# Count tickets by status
aws dynamodb scan --table-name upou-helpdesk-tickets --region us-east-1 \
  --query 'Items[].status.S' --output text | tr '\t' '\n' | sort | uniq -c
```

### Inspect Lambda interaction logs

```bash
# Today's logs
aws s3 ls s3://$S3_BUCKET/logs/$(date -u +%Y-%m-%d)/

# Download all of today's logs
aws s3 sync s3://$S3_BUCKET/logs/$(date -u +%Y-%m-%d)/ /tmp/today-logs/

# Pretty-print one log
aws s3 cp s3://$S3_BUCKET/logs/2026-04-14/abc-uuid.json - | python3 -m json.tool
```

### View Lambda CloudWatch logs

In the AWS Console: Lambda → ai-webapp-handler → Monitor → View CloudWatch logs → click the latest stream.

Or via CLI:

```bash
# Find the latest log stream
LOG_STREAM=$(aws logs describe-log-streams \
  --log-group-name /aws/lambda/ai-webapp-handler \
  --order-by LastEventTime --descending --limit 1 \
  --query 'logStreams[0].logStreamName' --output text)

# Tail it
aws logs get-log-events \
  --log-group-name /aws/lambda/ai-webapp-handler \
  --log-stream-name "$LOG_STREAM" \
  --query 'events[*].[timestamp,message]' --output text | tail -50
```

The Lambda emits `DEBUG` lines on every invocation showing the question, top 5 chunk matches, and threshold decision. Useful for tuning `KEYWORD_THRESHOLD`.

### Tune the keyword threshold

If too many policy questions fall through to "General Knowledge" or "Human Agent":

```bash
aws lambda update-function-configuration \
  --function-name ai-webapp-handler \
  --environment "Variables={KEYWORD_THRESHOLD=0.10,OPENAI_API_KEY=...,OPENAI_BASE_URL=...,...}" \
  --region us-east-1
```

⚠️ Remember: `--environment` REPLACES all env vars. You must include all the existing ones or they'll be deleted. Use this safer Python helper:

```bash
aws lambda get-function-configuration \
  --function-name ai-webapp-handler --region us-east-1 \
  --query 'Environment.Variables' --output json > /tmp/env.json

python3 -c "
import json
with open('/tmp/env.json') as f:
    e = json.load(f)
e['KEYWORD_THRESHOLD'] = '0.10'
print('Variables={' + ','.join(f'{k}={v}' for k,v in e.items()) + '}')
" > /tmp/env.txt

aws lambda update-function-configuration \
  --function-name ai-webapp-handler \
  --environment "$(cat /tmp/env.txt)" \
  --region us-east-1
```

This reads existing env vars, modifies only `KEYWORD_THRESHOLD`, and writes them all back.

### Force Lambda cold start

Any env var change forces all warm containers to die. Easiest:

```bash
# Bump memory by 1 MB and back (forces cold start)
CURRENT=$(aws lambda get-function-configuration \
  --function-name ai-webapp-handler --region us-east-1 \
  --query 'MemorySize' --output text)
NEW=$((CURRENT + 1))
aws lambda update-function-configuration \
  --function-name ai-webapp-handler --memory-size $NEW --region us-east-1
aws lambda wait function-updated --function-name ai-webapp-handler --region us-east-1
aws lambda update-function-configuration \
  --function-name ai-webapp-handler --memory-size $CURRENT --region us-east-1
```

Or just use `./scripts/deploy_policy_index.sh` which handles cold starts via `CACHE_BUST` automatically.

## Backups

The Learner Lab environment doesn't include automated backups, but everything important is stored outside the EC2 instance:

| Data | Location | Persistence |
|---|---|---|
| Policy index | S3 | Permanent |
| Lambda interaction logs | S3 | Permanent |
| Tickets | DynamoDB | Permanent |
| Lambda code | Lambda + your git repo | Permanent |
| Helpdesk users + chat history | MariaDB on EC2 | **Lost if EC2 disk lost** |
| Admin users + audit log | MariaDB on EC2 | **Lost if EC2 disk lost** |

To back up the MariaDB data manually:

```bash
mysqldump -u root -p --databases upou_helpdesk upou_admin > /tmp/backup.sql
aws s3 cp /tmp/backup.sql s3://$S3_BUCKET/backups/$(date -u +%Y-%m-%d).sql
```

Restore:
```bash
aws s3 cp s3://$S3_BUCKET/backups/2026-04-14.sql /tmp/restore.sql
mysql -u root -p < /tmp/restore.sql
```

## Restarting after a Learner Lab session

When the lab session expires, the EC2 instance stops. On the next session:

1. Start the lab → open AWS Console
2. EC2 → start the stopped `upou-helpdesk` instance
3. Note the new public IP
4. EC2 → Security Groups → `upou-helpdesk-sg` → update both port 22, 80, and 8080 inbound rules to "My IP"
5. Visit `http://<NEW_IP>/` and `http://<NEW_IP>:8080/` — both apps auto-start via systemd

If anything was changed in your git repo since the last session:

```bash
ssh -i upou-helpdesk-key.pem ec2-user@<NEW_IP>
cd /var/www/upou-helpdesk
git pull
./scripts/deploy_all.sh
```

The deploy_all.sh script is idempotent — it re-syncs PHP dependencies, redeploys the Lambda, and rebuilds the policy index in one command.

## Incident response

### "Everything is broken"

1. Check the [health check](#quick-health-check-10-seconds) above
2. The first thing that fails tells you the layer to debug
3. If Apache is down: `sudo systemctl start httpd; sudo journalctl -u httpd -n 30`
4. If MariaDB is down: `sudo systemctl start mariadb; sudo journalctl -u mariadb -n 30`
5. If Lambda errors: CloudWatch Logs → latest stream → look for `[ERROR]` and `Traceback`
6. If everything looks OK but the chat doesn't work: check browser dev tools Network tab → `api_ask.php` → Response

### "Students report Unexpected end of JSON input"

PHP fatal error somewhere in the chat path. Check:
```bash
sudo tail -50 /var/log/php-fpm/www-error.log
```

Most likely causes (see [`../../KNOWN-ISSUES.md`](../../KNOWN-ISSUES.md) for full list):
- `Cannot redeclare function env()` → `config.php` is missing the `function_exists` guard
- `Class 'Aws\...' not found` → vendor/ missing, run `composer install`
- `PDO connection failed` → MariaDB down or wrong credentials in vhost

The shipped `api_ask.php` has a shutdown handler that returns fatals as JSON, so you should see the actual error in the browser's Network tab now instead of an empty body.

### "Lambda returns Runtime.ImportModuleError"

Almost always means the Lambda runtime drifted to a Python version that doesn't match the wheels in your zip. Fix:

```bash
aws lambda update-function-configuration \
  --function-name ai-webapp-handler \
  --runtime python3.11 \
  --region us-east-1
```

Or just re-run `./scripts/deploy_lambda.sh` which pins the runtime automatically.

### "All questions are escalated to human agents"

The Lambda is loading an empty or wrong index. Check:

```bash
# Verify the index size matches your CSV
python3 -c "
import boto3, json
bucket='YOUR_BUCKET'
idx = json.loads(boto3.client('s3').get_object(Bucket=bucket, Key='policy_index.json')['Body'].read())
print('Count:', idx['count'])
print('First 3 chunks:', [c['chunk_id'] for c in idx['chunks'][:3]])
"
```

Should show `Count: 53` and `['ENR001', 'ENR002', 'ENR003']`. If not, rebuild:

```bash
./scripts/deploy_policy_index.sh
```

If that still doesn't help, the threshold may be too high. Lower it:

```bash
# Use the Python helper from the "Tune the keyword threshold" section above
# to safely change KEYWORD_THRESHOLD from 0.15 to 0.10
```

### "Admin app shows 0 tickets but DynamoDB has them"

Probably an IAM issue. Check the EC2 instance has `LabInstanceProfile`:

```bash
curl -s http://169.254.169.254/latest/meta-data/iam/info
```

Should return JSON. If empty or error: EC2 → Instances → select instance → Actions → Security → Modify IAM role → `LabInstanceProfile`.

Or check the Apache error log for `Aws\Exception\CredentialsException` or `AccessDenied`.

## Costs (approximate, Learner Lab)

At ~50 students with light usage:

| Service | Cost |
|---|---|
| EC2 t3.small (4 hours/day) | covered by Learner Lab credit |
| S3 storage (~50 MB total) | < $0.001/month |
| S3 PUT/GET requests | < $0.01/month |
| Lambda invocations (~1000/month) | free tier covers this |
| DynamoDB on-demand | < $0.01/month |
| OpenAI API (chat completions) | depends on key — UPOU proxy may have fixed quota |

The dominant cost is the OpenAI API calls if you're using a real OpenAI key.
