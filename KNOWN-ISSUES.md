# Known Issues Catalog

Every bug we hit during initial development of the UPOU AI HelpDesk, with symptom, root cause, and the prevention mechanism baked into the project.

If you hit a problem, find it in this list. If it's not here, check the deploy script output — they print colored `✓` / `!` / `✗` lines that pinpoint the failing step.

---

## I1 — Lambda runtime drifts to Python 3.14 on create

**Symptom:** `Runtime.ImportModuleError: No module named 'pydantic_core._pydantic_core'`

**Root cause:** When you click "Create function" in the Lambda console, AWS defaults the runtime to the newest Python (currently 3.14). Every pre-built `pydantic_core` wheel targets specific Python versions. Your zip has `_pydantic_core.cpython-311-x86_64-linux-gnu.so`, and Python 3.14 cannot load that file — the version is encoded in the filename.

**Prevention:**
- `bootstrap_aws.sh` creates the Lambda with `--runtime python3.11`
- `deploy_lambda.sh` checks the runtime before every code upload and re-pins it to 3.11 if drifted
- The deploy guide documents this in bold

**Manual fix:** Lambda console → ai-webapp-handler → Code tab → Runtime settings → Edit → Python 3.11 → Save.

---

## I2 — `s3://` prefix in the bucket name env var

**Symptom:** `botocore.exceptions.ParamValidationError: Invalid bucket name "s3://..."`

**Root cause:** The bucket appears in the S3 console as `s3://bucket-name`, and it's natural to copy that whole URL. boto3's bucket parameter wants only the name.

**Prevention:**
- All deploy scripts check `if [[ "$S3_BUCKET" == s3://* ]]` and refuse to run
- The pre-flight checklist says "bucket name only, NO `s3://` prefix" in bold
- The `.env.example` has a comment explaining this

---

## I3 — Lambda zip has `lambda_function.py` inside a subfolder

**Symptom:** `No module named 'lambda_function'`

**Root cause:** `zip -r function.zip build/` creates `build/lambda_function.py` inside the zip. Lambda's handler `lambda_function.lambda_handler` expects the file at the root.

**Prevention:** `deploy_lambda.sh` uses `cd $BUILD_DIR && zip -rq function.zip .` so paths inside the zip start at the root. It then greps the zip listing to verify `lambda_function.py` has no folder prefix.

---

## I4 — `openai` package missing from the zip

**Symptom:** `No module named 'openai'`

**Root cause:** `pip install` ran but installed packages somewhere other than the build directory (e.g. `/home/ec2-user`, the project root, etc.).

**Prevention:** `deploy_lambda.sh` `cd`s into a fresh `/tmp/lambda-build-$$` directory and runs `pip3.11 install --target .` from there.

---

## I5 — CSV has `…` (ellipsis) placeholders instead of real chunk IDs

**Symptom:** Lambda retrieves matches but all chunk IDs are `…`, the AI returns weird results, and the rebuilt index has fewer rows than expected.

**Root cause:** The source CSV (`WebScrapingSourceCode.csv`) was scraped with Excel in display-truncated mode. 34 of its 39 rows had `…` in the chunk_id column, but the `chunk_text` was still real content.

**Prevention:**
- `scripts/clean_csv.py` renumbers all ENR rows sequentially and preserves content regardless of the original chunk_id
- `deploy_policy_index.sh` refuses to build an index if the CSV has any `…` chunk_ids
- The shipped `data/policies.csv` is the cleaned 53-row version

---

## I6 — Lambda keeps serving the OLD embeddings/keyword index

**Symptom:** You rebuild `policy_index.json` to 53 chunks, but CloudWatch DEBUG output shows `index count: 34`.

**Root cause:** The Lambda caches the index in module-level `_policy_index` on cold start. Warm containers reuse the stale copy. Uploading a new file to S3 doesn't invalidate Lambda's in-memory cache.

**Prevention:** `deploy_policy_index.sh` always bumps a `CACHE_BUST` env var on the Lambda after uploading the new index. Changing any env var forces all containers to die, so the next invocation cold-starts and reloads from S3.

---

## I7 — `update-function-configuration --environment` wipes other env vars

**Symptom:** After running a "force cold start" command, `OPENAI_API_KEY` becomes the literal placeholder `sk-YOUR-KEY` and the Lambda fails with 401 from the OpenAI API.

**Root cause:** The `--environment` flag **replaces** the entire env var block. Any vars not included in your command are deleted.

**Prevention:** `deploy_policy_index.sh` reads the existing env vars first (via `get-function-configuration`), modifies only `CACHE_BUST`, and writes them all back. It also separately preserves `MemorySize` and `Timeout`.

---

## I8 — Lambda memory reset to 128 MB

**Symptom:** OOM errors or very slow cold starts.

**Root cause:** Default memory for new functions is 128 MB. Some CLI calls reset it.

**Prevention:** `deploy_lambda.sh` checks memory on every run and bumps to 512 MB if lower.

---

## I9 — EC2 running a different Python than Lambda

**Symptom:** Zip builds successfully but still fails with `pydantic_core` import error at invocation.

**Root cause:** `pip` on EC2 targeted its own Python version (e.g. 3.9 or 3.12) instead of 3.11. The wheel downloaded was for the wrong ABI.

**Prevention:** `deploy_lambda.sh` uses `pip3.11` explicitly and passes the full cross-build flags: `--platform manylinux2014_x86_64 --target . --implementation cp --python-version 3.11 --only-binary=:all:`. This forces pip to download wheels matching the Lambda runtime regardless of the host Python.

---

## I10 — `env()` function redeclared in `config.php`

**Symptom:** `PHP Fatal error: Cannot redeclare function env() (previously declared in config.php:7)`

**Root cause:** `config.php` is loaded multiple times per request (once via `db.php`, once via `aws_client.php`). PHP redeclares the function each time, which is a fatal error.

**Prevention:** The function is wrapped in `if (!function_exists('env'))`. Same fix applied to the admin app's `admin_env()`.

This bug came back twice during development — both times because an older config.php from git overwrote the fixed version. **The shipped version has the guard. Do not remove it.**

---

## I11 — `Auth::require()` uses a PHP reserved word

**Symptom:** Parse error on PHP 8.1+: `unexpected 'require'`

**Root cause:** `require` is a reserved word in newer PHP versions and cannot be a method name.

**Prevention:** Method renamed to `Auth::requireLogin()`. All callers use the new name.

---

## I12 — PHP returns empty body, browser shows "Unexpected end of JSON input"

**Symptom:** Browser console shows `Failed to execute 'json' on 'Response': Unexpected end of JSON input`. Apache access log shows `POST /api_ask.php 500`. Apache error log is empty.

**Root cause:** PHP fatal error before any output is written. The error message goes to PHP-FPM's log (`/var/log/php-fpm/www-error.log`) NOT Apache's error log (`upou-helpdesk-error.log`). The browser sees an empty 500 response.

**Prevention:** `api_ask.php` has a `register_shutdown_function` that catches fatal errors and returns them as JSON 500 responses. The browser now sees `{"error":"PHP fatal: ..."}` instead of an empty body, and you can debug from the Network tab without needing server logs.

**Where to find PHP errors when this happens anyway:**
- `/var/log/php-fpm/www-error.log` (most likely)
- `/var/log/php-fpm/error.log`
- `sudo journalctl -u php-fpm -n 50`

NOT in `/var/log/httpd/upou-helpdesk-error.log` — Apache only logs Apache-level errors there.

---

## I13 — Git clones into a nested `upou-helpdesk/upou-helpdesk` folder

**Symptom:** `cd: /var/www/upou-helpdesk/php: No such file or directory`

**Root cause:** Sometimes `git clone` creates an extra directory layer. The fix is to flatten:

```bash
cd /var/www
sudo mv upou-helpdesk/upou-helpdesk /tmp/helpdesk-tmp
sudo rm -rf upou-helpdesk
sudo mv /tmp/helpdesk-tmp upou-helpdesk
```

**Prevention:** The deployment guides include this fix as an `if [[ -d nested-folder ]]; then flatten` check in the install commands.

---

## I14 — EC2 `pip` is for the wrong user or missing entirely

**Symptom:** `bash: pip: command not found` or `No module named 'boto3'` after install.

**Root cause:** `sudo pip3.11 install` puts packages in system site-packages; `pip3.11 install --user` puts them in the user's home. If you switch between `sudo` and `ec2-user`, the packages may be invisible to whoever runs the script.

**Prevention:** Deploy guide and scripts always use `sudo pip3.11 install` (system-wide). The pre-flight checklist verifies `python3.11 -c "import boto3, openai"` succeeds before continuing.

**On Amazon Linux 2023, `pip` and `pip3` don't exist by default — use `pip3.11`.**

---

## I15 — `OPENAI_BASE_URL` includes `/chat/completions`

**Symptom:** `Invalid URL (POST /v1/chat/completions/chat/completions)` — doubled URL path.

**Root cause:** The OpenAI Python SDK appends `/chat/completions` to the base URL automatically. If your base URL already ends with `/chat/completions`, you get `.../chat/completions/chat/completions`.

**Prevention:** The `.env.example` and deployment guides specify `OPENAI_BASE_URL=https://is215-openai.upou.io/v1` — no `/chat/completions` suffix.

---

## I16 — UPOU proxy doesn't support embeddings

**Symptom:** `You are not allowed to generate embeddings from this model` (HTTP 200 with error body)

**Root cause:** The UPOU class proxy at `https://is215-openai.upou.io/v1` only supports chat completions. The embeddings endpoint exists but is restricted.

**Prevention:** The shipped Lambda uses **keyword search**, not vector embeddings, so it never calls the embeddings endpoint. Works with chat-only proxies.

---

## I17 — `OPENAI_API_KEY` has whitespace in the middle

**Symptom:** `Incorrect API key provided: sk-proj-************      ***********IPsS` (note the gap)

**Root cause:** Pasting the key into a `.env` file or `export` statement included a line break or trailing whitespace.

**Prevention:** Always paste API keys with single quotes and no trailing newline:
```bash
export OPENAI_API_KEY='sk-proj-...'   # not export OPENAI_API_KEY=sk-proj-...
```

To verify: `echo -n "$OPENAI_API_KEY" | wc -c` should be one number around 100-180 with no embedded whitespace.

---

## I18 — Lambda response missing `usage` field

**Symptom:** `TypeError: 'NoneType' object is not subscriptable`

**Root cause:** The Lambda code tried `completion.usage.model_dump()` but the UPOU proxy returned `usage = None`. Strict OpenAI clients assume usage is always present.

**Prevention:** The shipped `lambda_function.py` has a `call_chat()` helper that defensively extracts the answer text and usage dict, handling all edge cases:
- `completion = None`
- `completion.choices = None or empty`
- `completion.usage = None`
- `completion.usage` returned as a dict instead of a Pydantic object

---

## I19 — Apache welcome page intercepts `/`

**Symptom:** Browser shows the default Apache test page instead of the helpdesk landing page.

**Root cause:** Amazon Linux's `httpd` package ships with `/etc/httpd/conf.d/welcome.conf` which serves a placeholder for `/var/www/html/`.

**Prevention:** The deployment guide includes `sudo rm -f /etc/httpd/conf.d/welcome.conf` after copying the helpdesk vhost.

---

## I20 — SELinux blocks Apache on port 8080

**Symptom:** `Permission denied` when starting httpd after adding the admin vhost on port 8080.

**Root cause:** SELinux on Amazon Linux 2023 only allows httpd on a fixed list of ports (80, 443, etc.). Port 8080 is not in the default list.

**Prevention:** `scripts/deploy_admin.sh` automatically registers port 8080 with SELinux on every run, installing `policycoreutils-python-utils` first if `semanage` is missing. The script is idempotent — re-running just verifies the port is registered.

For manual deploy without the script: `sudo semanage port -a -t http_port_t -p tcp 8080`

---

## I21 — Composer OOMs on t3.micro

**Symptom:** `composer install` fails with "Cannot allocate memory".

**Root cause:** Composer needs ~1.5 GB RAM. t3.micro only has 1 GB.

**Prevention:** Deployment guide specifies **t3.small** (2 GB) for the EC2 instance. If you're stuck on t3.micro, add swap:
```bash
sudo dd if=/dev/zero of=/swapfile bs=1M count=1024
sudo chmod 600 /swapfile
sudo mkswap /swapfile
sudo swapon /swapfile
```

---

## I22 — DocumentRoot doesn't exist after EC2 reboot

**Symptom:** Apache log shows `AH00112: Warning: DocumentRoot [/var/www/upou-helpdesk/php/public] does not exist`. Browser gets 404 or the default Apache page.

**Root cause:** Either:
1. The EC2 instance was launched fresh from an AMI without your project files, or
2. A redeploy moved the files but Apache still has the old vhost cached, or
3. SELinux denied the initial directory access (different bug than I20)

**Prevention:** Always run `ls /var/www/upou-helpdesk/php/public/` to verify the directory exists before restarting Apache. The deployment guide includes this verification step.

If files are missing entirely (lab session lost the disk), re-clone from git and re-run `./scripts/deploy_all.sh`.

---

## I23 — EC2 IAM profile not attached after restart

**Symptom:** `Aws\Exception\CredentialsException` from PHP, or `NoCredentialsError` from boto3.

**Root cause:** Sometimes a new EC2 instance gets launched without `LabInstanceProfile` attached, or the role detaches during a Learner Lab session rotation.

**Prevention:** The deployment guide specifies `LabInstanceProfile` in the Launch wizard. To verify after the fact:
```bash
curl -s http://169.254.169.254/latest/meta-data/iam/info
```

If it returns nothing or an error, attach the role: EC2 → Instances → select instance → Actions → Security → Modify IAM role → `LabInstanceProfile` → Update.

---

## I24 — Browser is logged out after lab session restart but session cookie is still present

**Symptom:** Login attempts work but immediately redirect back to login.

**Root cause:** PHP sessions are stored in `/var/lib/php/session/`. After a fresh EC2 reboot the directory may not have correct ownership for the apache user.

**Prevention:**
```bash
sudo chown -R apache:apache /var/lib/php/session
sudo systemctl restart httpd
```

---

## I25 — Admin app returns 404 because vhost DocumentRoot doesn't match

**Symptom:** `Not Found - The requested URL was not found on this server.` when visiting port 8080. Apache is listening on 8080, the vhost is loaded, but every URL returns 404.

**Root cause:** The shipped `admin/docs/upou-admin.conf` template has `DocumentRoot /var/www/upou-admin/public` (assuming admin is a separate top-level project). When the admin app is nested inside the helpdesk repo at `/var/www/upou-helpdesk/admin/public`, that DocumentRoot points nowhere.

**Prevention:** `scripts/deploy_admin.sh` auto-detects the actual project location and rewrites the vhost's DocumentRoot to match. Specifically, it does:
```bash
sed -i "s|/var/www/upou-admin/|$ADMIN_DIR/|g" "$VHOST_DEST"
```
where `$ADMIN_DIR` is the script's auto-detected path to the admin folder. Works whether the project is at `/var/www/upou-helpdesk/admin/` or anywhere else.

**Manual fix:** Edit `/etc/httpd/conf.d/upou-admin.conf` and change every `/var/www/upou-admin/` path to wherever your admin app actually lives, then `sudo systemctl restart httpd`.

---

## I26 — Admin DB password mismatch between MySQL user and Apache vhost

**Symptom:** Admin login or signup returns 500. `php-fpm` log shows `SQLSTATE[HY000] [1045] Access denied for user 'upou_admin_app'@'localhost'`.

**Root cause:** The MySQL user was created with one password, but the vhost's `SetEnv DB_PASS` says a different one. Easy to do when re-running schema imports manually.

**Prevention:** `scripts/deploy_admin.sh` reads any existing password from the vhost first, tests it against MySQL, and either reuses it (if working) or generates a new one and runs `ALTER USER ... IDENTIFIED BY` to keep them in sync. The schema import always ends with the password sync, so re-runs cannot drift out of sync.

---

Every issue above is either:

1. **Prevented at deploy time** — the script refuses to run in a known-broken state (e.g. wrong runtime, wrong env vars, wrong CSV)
2. **Auto-corrected at deploy time** — the script fixes the state if it's wrong (e.g. runtime drifted, memory too low)
3. **Caught at runtime** — defensive code handles edge cases without crashing (e.g. PHP fatal handler, defensive Lambda response parsing)
4. **Documented in the deploy guide** — with the exact fix steps if it ever happens

The scripts are designed so that running `./scripts/deploy_all.sh` on a freshly broken system will fix it back to a working state. They're idempotent — re-running just re-syncs everything.
