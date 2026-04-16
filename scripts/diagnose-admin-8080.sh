#!/usr/bin/env bash
#
# diagnose-admin-8080.sh
#
# Diagnostic and auto-fix for the UPOU admin console on port 8080.
# Runs the checks in order, and only applies fixes for the specific
# problem it detects. Safe to re-run — every fix is idempotent.

set -u   # no -e because we WANT to keep going after failures

ADMIN_PORT=8080
VHOST=/etc/httpd/conf.d/upou-admin.conf
ADMIN_ROOT=/var/www/upou-helpdesk/admin/public

# Colored output
ok()   { printf "\033[32m✓\033[0m %s\n" "$1"; }
warn() { printf "\033[33m!\033[0m %s\n" "$1"; }
err()  { printf "\033[31m✗\033[0m %s\n" "$1"; }
hdr()  { printf "\n\033[1;34m=== %s ===\033[0m\n" "$1"; }

# ---- 1. Diagnostics ---------------------------------------------------

hdr "1. Is Apache listening on $ADMIN_PORT?"
if sudo ss -tlnp | grep -q ":$ADMIN_PORT\b"; then
    ok "Apache is listening on $ADMIN_PORT"
    LISTENING=1
else
    err "Apache is NOT listening on $ADMIN_PORT"
    LISTENING=0
fi

hdr "2. Vhost file"
if [[ -f "$VHOST" ]]; then
    ok "Vhost exists: $VHOST"
    sudo ls -la "$VHOST"
    VHOST_EXISTS=1
else
    err "Vhost missing: $VHOST"
    VHOST_EXISTS=0
fi

hdr "3. Vhost contents"
if [[ "$VHOST_EXISTS" -eq 1 ]]; then
    sudo cat "$VHOST"
fi

hdr "4. Admin app files"
if [[ -d "$ADMIN_ROOT" ]]; then
    ok "Admin DocumentRoot exists: $ADMIN_ROOT"
    ls "$ADMIN_ROOT" | head
    ADMIN_FILES=1
else
    err "Admin DocumentRoot missing: $ADMIN_ROOT"
    ADMIN_FILES=0
fi

hdr "5. Apache config test"
CONFIG_OK=0
if sudo apachectl configtest 2>&1 | grep -q "Syntax OK"; then
    ok "Apache syntax OK"
    CONFIG_OK=1
else
    err "Apache syntax check FAILED:"
    sudo apachectl configtest
fi

hdr "6. What Apache thinks it's serving on $ADMIN_PORT"
sudo apachectl -S 2>&1 | grep -A 2 "$ADMIN_PORT\|upou-admin" || warn "No vhost matching 8080 or 'upou-admin' in Apache's config dump"

hdr "7. Local HTTP test"
if [[ "$LISTENING" -eq 1 ]]; then
    curl -sI "http://localhost:$ADMIN_PORT/" | head -5
else
    warn "Skipping (Apache not listening on $ADMIN_PORT)"
fi

# ---- 8. Apply fixes based on what we found ---------------------------

hdr "8. Applying fixes (if needed)"

# 8a. Fix DocumentRoot if the vhost points at the wrong path
if [[ "$VHOST_EXISTS" -eq 1 ]] && sudo grep -q '/var/www/upou-admin/public' "$VHOST"; then
    warn "Vhost has old DocumentRoot — correcting to $ADMIN_ROOT"
    sudo sed -i "s|/var/www/upou-admin/public|$ADMIN_ROOT|g" "$VHOST"
    ok "DocumentRoot fixed"
    NEEDS_RESTART=1
fi

# 8b. If Apache isn't listening on 8080, try SELinux fix
if [[ "$LISTENING" -eq 0 ]]; then
    warn "Attempting SELinux port registration for $ADMIN_PORT"

    if ! command -v semanage >/dev/null 2>&1; then
        warn "semanage missing, installing policycoreutils-python-utils..."
        sudo dnf install -y policycoreutils-python-utils
    fi

    if sudo semanage port -l 2>/dev/null | grep -qE "^http_port_t.*\b$ADMIN_PORT\b"; then
        ok "SELinux already has port $ADMIN_PORT in http_port_t"
    else
        if sudo semanage port -a -t http_port_t -p tcp "$ADMIN_PORT" 2>/dev/null; then
            ok "Registered port $ADMIN_PORT with SELinux"
        else
            # -a fails if already defined; try -m as fallback
            sudo semanage port -m -t http_port_t -p tcp "$ADMIN_PORT" 2>/dev/null && \
                ok "SELinux port $ADMIN_PORT set via -m" || \
                warn "SELinux port registration had no effect (may already be set)"
        fi
    fi
    NEEDS_RESTART=1
fi

# 8c. Restart Apache if we changed anything
if [[ "${NEEDS_RESTART:-0}" -eq 1 ]]; then
    hdr "9. Restarting httpd"
    if sudo systemctl restart httpd; then
        sleep 2
        if sudo ss -tlnp | grep -q ":$ADMIN_PORT\b"; then
            ok "Apache restarted and listening on $ADMIN_PORT"
        else
            err "Apache restarted but still not listening on $ADMIN_PORT"
            err "Check: sudo journalctl -u httpd -n 30"
        fi
    else
        err "Apache restart FAILED"
        sudo journalctl -u httpd -n 20 --no-pager
    fi
else
    hdr "No changes needed"
    ok "Admin site appears correctly configured"
fi