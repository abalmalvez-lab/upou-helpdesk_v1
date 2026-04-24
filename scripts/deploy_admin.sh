#!/usr/bin/env bash
#
# deploy_admin.sh - Deploy the UPOU HelpDesk Admin console (port 8080).
#
# Idempotent: safe to re-run. Detects existing state and adapts.
#
# Handles every issue we hit during initial admin-app deployment:
#   - SELinux port 8080 registration (semanage)
#   - Apache vhost DocumentRoot path correction (when nested under /var/www/upou-helpdesk/)
#   - Listen 8080 directive deduplication
#   - DB password generation and consistent substitution into schema + vhost
#   - Composer install + correct apache:apache ownership
#   - MariaDB schema import (skips if already imported)
#   - Verifies port 8080 is actually listening after restart
#   - Verifies the landing page returns 200 before exiting
#
# Run from EC2 project root:
#   cd /var/www/upou-helpdesk
#   sudo -E ./scripts/deploy_admin.sh
#
# The -E preserves env vars; the script needs sudo for system files.

set -euo pipefail

# ---- Auto-detect project root regardless of where script is invoked ----
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="$(cd "$SCRIPT_DIR/.." && pwd)"
ADMIN_DIR="$PROJECT_ROOT/admin"
DOC_ROOT="$ADMIN_DIR/public"
VHOST_SRC="$ADMIN_DIR/docs/upou-admin.conf"
SCHEMA_SRC="$ADMIN_DIR/sql/schema.sql"

VHOST_DEST="/etc/httpd/conf.d/upou-admin.conf"
ADMIN_PORT="8080"
DB_NAME="upou_admin"
DB_USER="upou_admin_app"

color_ok()    { printf "\033[32m✓\033[0m %s\n" "$1"; }
color_warn()  { printf "\033[33m!\033[0m %s\n" "$1"; }
color_err()   { printf "\033[31m✗\033[0m %s\n" "$1" >&2; }
color_phase() { printf "\n\033[1;34m=== %s ===\033[0m\n" "$1"; }

START_TS=$(date +%s)

color_phase "UPOU HelpDesk Admin Deploy"
echo "Project root:  $PROJECT_ROOT"
echo "Admin app dir: $ADMIN_DIR"
echo "DocumentRoot:  $DOC_ROOT"
echo "Vhost:         $VHOST_DEST (port $ADMIN_PORT)"

# ---- 0. Sanity checks --------------------------------------------------
if [[ $EUID -ne 0 ]]; then
    color_err "This script must be run as root (use sudo). It modifies /etc/httpd/ and runs systemctl."
    exit 1
fi

for cmd in mysql apachectl systemctl; do
    if ! command -v "$cmd" >/dev/null 2>&1; then
        color_err "Required command not found: $cmd"
        exit 1
    fi
done

if [[ ! -d "$DOC_ROOT" ]]; then
    color_err "Admin DocumentRoot not found: $DOC_ROOT"
    color_err "Make sure the project is cloned to $PROJECT_ROOT"
    exit 1
fi

if [[ ! -f "$VHOST_SRC" ]]; then
    color_err "Vhost template not found: $VHOST_SRC"
    exit 1
fi

if [[ ! -f "$SCHEMA_SRC" ]]; then
    color_err "Schema not found: $SCHEMA_SRC"
    exit 1
fi

color_ok "All required files present"

# ---- 1. Composer install -----------------------------------------------
color_phase "1. Installing PHP dependencies"

if [[ ! -f "$ADMIN_DIR/vendor/autoload.php" ]]; then
    if ! command -v composer >/dev/null 2>&1; then
        color_err "Composer not installed. Install with:"
        color_err "  curl -sS https://getcomposer.org/installer | php"
        color_err "  sudo mv composer.phar /usr/local/bin/composer"
        exit 1
    fi
    cd "$ADMIN_DIR"
    sudo -u ec2-user composer install --no-dev --optimize-autoloader --quiet
    color_ok "Composer dependencies installed"
else
    color_ok "vendor/ already present (skipping composer install)"
fi

# ---- 2. SELinux: allow Apache on port 8080 -----------------------------
color_phase "2. SELinux port registration"

if command -v semanage >/dev/null 2>&1; then
    if semanage port -l 2>/dev/null | grep -q "^http_port_t.*\b${ADMIN_PORT}\b"; then
        color_ok "Port $ADMIN_PORT already in http_port_t"
    else
        if semanage port -a -t http_port_t -p tcp "$ADMIN_PORT" 2>/dev/null; then
            color_ok "Registered port $ADMIN_PORT with SELinux"
        else
            # -a fails if already exists; try -m (modify) as fallback
            semanage port -m -t http_port_t -p tcp "$ADMIN_PORT" 2>/dev/null || true
            color_ok "SELinux port $ADMIN_PORT configured"
        fi
    fi
else
    # SELinux tools not installed - try to install
    color_warn "semanage not found, installing policycoreutils-python-utils..."
    if dnf install -y policycoreutils-python-utils >/dev/null 2>&1; then
        semanage port -a -t http_port_t -p tcp "$ADMIN_PORT" 2>/dev/null || true
        color_ok "Installed semanage and registered port $ADMIN_PORT"
    else
        color_warn "Could not install semanage; SELinux may block port $ADMIN_PORT"
        color_warn "If Apache fails to bind 8080, run: sudo setenforce 0  (temporary)"
    fi
fi

# ---- 3. Database password handling ------------------------------------
color_phase "3. Database setup"

# Detect existing password if vhost is already deployed
EXISTING_DB_PASS=""
if [[ -f "$VHOST_DEST" ]]; then
    EXISTING_DB_PASS=$(grep -oP 'SetEnv\s+DB_PASS\s+\K\S+' "$VHOST_DEST" 2>/dev/null || true)
    if [[ -n "$EXISTING_DB_PASS" && "$EXISTING_DB_PASS" != "CHANGE_ME_STRONG_PASSWORD" ]]; then
        # Verify it actually works against MySQL
        if mysql -u "$DB_USER" -p"$EXISTING_DB_PASS" "$DB_NAME" -e "SELECT 1" >/dev/null 2>&1; then
            DB_PASS="$EXISTING_DB_PASS"
            color_ok "Reusing existing DB password from $VHOST_DEST (verified)"
        else
            color_warn "Existing vhost password doesn't match MySQL. Generating new password and recreating user."
            DB_PASS=""
        fi
    fi
fi

# Generate a new password if we don't have a working one
if [[ -z "${DB_PASS:-}" ]]; then
    DB_PASS=$(openssl rand -base64 16 | tr -d '=+/' | head -c 20)
    color_ok "Generated new DB password: $DB_PASS"
    echo
    echo "  >>> WRITE THIS DOWN <<<"
    echo "  Admin DB password: $DB_PASS"
    echo
fi

# ---- 4. Import schema (idempotent) ------------------------------------
color_phase "4. MySQL schema"

# We need MariaDB root to create the database. If the user has set MYSQL_ROOT_PASSWORD,
# we use it non-interactively; otherwise we prompt.
import_schema() {
    local pass=$1
    # Substitute the password into a temp copy of the schema
    local tmp_schema
    tmp_schema=$(mktemp /tmp/schema.XXXXXX.sql)
    sed "s/CHANGE_ME_STRONG_PASSWORD/$pass/g" "$SCHEMA_SRC" > "$tmp_schema"

    # Add ALTER USER so re-runs sync the password if it changed
    cat >> "$tmp_schema" <<EOF

-- Ensure the user password matches whatever the vhost is using
ALTER USER '$DB_USER'@'localhost' IDENTIFIED BY '$pass';
FLUSH PRIVILEGES;
EOF

    if [[ -n "${MYSQL_ROOT_PASSWORD:-}" ]]; then
        mysql -u root -p"$MYSQL_ROOT_PASSWORD" < "$tmp_schema"
    else
        echo "Enter MariaDB root password when prompted:"
        mysql -u root -p < "$tmp_schema"
    fi
    local rc=$?
    rm -f "$tmp_schema"
    return $rc
}

if mysql -u "$DB_USER" -p"$DB_PASS" "$DB_NAME" -e "SELECT 1" >/dev/null 2>&1; then
    color_ok "Database '$DB_NAME' exists and credentials work"
    # Still run a quick check that the tables exist
    table_count=$(mysql -u "$DB_USER" -p"$DB_PASS" "$DB_NAME" -e "SHOW TABLES" 2>/dev/null | tail -n +2 | wc -l)
    if [[ "$table_count" -lt 2 ]]; then
        color_warn "Database exists but tables missing; re-importing schema"
        import_schema "$DB_PASS"
    fi
else
    echo "Importing schema and creating user (will prompt for MariaDB root password)..."
    import_schema "$DB_PASS"

    # Verify
    if mysql -u "$DB_USER" -p"$DB_PASS" "$DB_NAME" -e "SELECT 1" >/dev/null 2>&1; then
        color_ok "Schema imported, user can connect"
    else
        color_err "Schema import succeeded but user '$DB_USER' still cannot connect"
        color_err "Run manually: mysql -u root -p < $SCHEMA_SRC"
        exit 1
    fi
fi

# ---- 5. Apache vhost ---------------------------------------------------
color_phase "5. Apache vhost"

# Build the vhost from the template, with all path corrections baked in:
#   - DocumentRoot replaced with actual project path
#   - DB_PASS substituted
#   - Listen 8080 included exactly once

cp "$VHOST_SRC" "$VHOST_DEST"

# Fix DocumentRoot if the template uses /var/www/upou-admin/ (separate top-level)
# but we're nested under /var/www/upou-helpdesk/admin/
if grep -q "/var/www/upou-admin/" "$VHOST_DEST"; then
    sed -i "s|/var/www/upou-admin/|$ADMIN_DIR/|g" "$VHOST_DEST"
    color_ok "Corrected DocumentRoot to $DOC_ROOT"
fi

# Substitute DB password
sed -i "s/CHANGE_ME_STRONG_PASSWORD/$DB_PASS/g" "$VHOST_DEST"

# Handle Listen 8080: must exist exactly once across all of /etc/httpd
listen_count_in_vhost=$(grep -c "^Listen $ADMIN_PORT$" "$VHOST_DEST" 2>/dev/null || echo 0)
listen_count_elsewhere=$(grep -rh "^Listen $ADMIN_PORT$" /etc/httpd/conf*/ 2>/dev/null | grep -v "^$VHOST_DEST" | wc -l || echo 0)

if [[ "$listen_count_elsewhere" -gt 0 && "$listen_count_in_vhost" -gt 0 ]]; then
    # Listen 8080 is in BOTH our vhost AND another file; remove from our vhost to avoid duplicate
    sed -i "/^Listen $ADMIN_PORT$/d" "$VHOST_DEST"
    color_ok "Removed duplicate 'Listen $ADMIN_PORT' from vhost (already declared elsewhere)"
elif [[ "$listen_count_elsewhere" -eq 0 && "$listen_count_in_vhost" -eq 0 ]]; then
    # Not declared anywhere; add to top of our vhost
    sed -i "1iListen $ADMIN_PORT\n" "$VHOST_DEST"
    color_ok "Added 'Listen $ADMIN_PORT' to vhost"
else
    color_ok "Listen $ADMIN_PORT correctly declared exactly once"
fi

# ---- 6. File permissions ----------------------------------------------
color_phase "6. File permissions"

chown -R apache:apache "$ADMIN_DIR"
chmod -R 755 "$ADMIN_DIR"
color_ok "Set $ADMIN_DIR ownership to apache:apache"

# Session directory (PHP needs write access)
if [[ -d /var/lib/php/session ]]; then
    chown -R apache:apache /var/lib/php/session
    chmod 1733 /var/lib/php/session
    color_ok "Fixed PHP session directory permissions"
fi

# ---- 7. Apache config test + restart ----------------------------------
color_phase "7. Apache restart"

if ! apachectl configtest 2>&1 | grep -q "Syntax OK"; then
    color_err "Apache config test FAILED:"
    apachectl configtest
    exit 1
fi
color_ok "Apache config syntax OK"

systemctl restart httpd
sleep 2

if ! systemctl is-active httpd >/dev/null; then
    color_err "Apache failed to start. Last 20 log lines:"
    journalctl -u httpd -n 20 --no-pager
    exit 1
fi
color_ok "Apache restarted"

# ---- 8. Verify port 8080 is listening ---------------------------------
color_phase "8. Verification"

if ss -tlnp 2>/dev/null | grep -q ":$ADMIN_PORT\b"; then
    color_ok "Port $ADMIN_PORT is listening"
else
    color_err "Port $ADMIN_PORT is NOT listening after Apache restart"
    color_err "Check logs: sudo journalctl -u httpd -n 30"
    color_err "If you see 'Permission denied', SELinux may still be blocking. Try:"
    color_err "  sudo semanage port -a -t http_port_t -p tcp $ADMIN_PORT"
    exit 1
fi

# Test the landing page
http_code=$(curl -s -o /dev/null -w "%{http_code}" "http://localhost:$ADMIN_PORT/" || echo "fail")
if [[ "$http_code" == "200" || "$http_code" == "302" ]]; then
    color_ok "Admin app responds (HTTP $http_code) at http://localhost:$ADMIN_PORT/"
else
    color_warn "Admin app returned HTTP $http_code (expected 200 or 302)"
    color_warn "Check Apache error log: sudo tail -20 /var/log/httpd/upou-admin-error.log"
fi

# ---- Done -------------------------------------------------------------
ELAPSED=$(( $(date +%s) - START_TS ))
color_phase "Deploy complete in ${ELAPSED}s"

cat <<EOF

Next steps:
  1. Open http://<EC2_PUBLIC_IP>:$ADMIN_PORT/ in your browser
  2. Click "Sign up" - the FIRST signup automatically becomes admin
  3. Subsequent signups become agents (admins can promote them later)

Reminders:
  - Make sure port $ADMIN_PORT is open in your EC2 security group (source: My IP)
  - DB password used: $DB_PASS
  - Vhost: $VHOST_DEST
  - Logs: sudo tail -f /var/log/httpd/upou-admin-error.log

EOF
