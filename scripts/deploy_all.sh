#!/usr/bin/env bash
#
# deploy_all.sh - One-command deployment for UPOU HelpDesk.
#
# Orchestrates:
#   1. System prerequisites check
#   2. PHP app deploy (composer install, permissions, apache restart)
#   3. Lambda code deploy (build + upload + pin runtime + smoke test)
#   4. Policy embeddings index build + upload + cold start
#   5. End-to-end verification via curl
#
# Run on the EC2 instance from the project root:
#   cd /var/www/upou-helpdesk
#   sudo -E ./scripts/deploy_all.sh
#
# Required env vars:
#   OPENAI_API_KEY   - for building the embeddings index
#   S3_BUCKET        - your bucket name
#
# Optional:
#   LAMBDA_FUNCTION_NAME (default: ai-webapp-handler)
#   AWS_REGION           (default: us-east-1)
#   SKIP_PHP=1           to skip PHP redeploy
#   SKIP_LAMBDA=1        to skip Lambda code redeploy
#   SKIP_INDEX=1         to skip policy index rebuild

set -euo pipefail

PROJECT_DIR="$(cd "$(dirname "$0")/.." && pwd)"
cd "$PROJECT_DIR"

FUNCTION_NAME="${LAMBDA_FUNCTION_NAME:-ai-webapp-handler}"
REGION="${AWS_REGION:-us-east-1}"

color_ok()    { printf "\033[32m✓\033[0m %s\n" "$1"; }
color_warn()  { printf "\033[33m!\033[0m %s\n" "$1"; }
color_err()   { printf "\033[31m✗\033[0m %s\n" "$1" >&2; }
color_phase() { printf "\n\033[1;34m=== %s ===\033[0m\n" "$1"; }

START_TS=$(date +%s)

# ---- Phase 0: Prerequisites ----------------------------------------------
color_phase "Phase 0: Prerequisites"

: "${OPENAI_API_KEY:?OPENAI_API_KEY must be set}"
: "${S3_BUCKET:?S3_BUCKET must be set (no s3:// prefix)}"

if [[ "$S3_BUCKET" == s3://* ]]; then
    color_err "S3_BUCKET must NOT start with 's3://' — use just the bucket name"
    exit 1
fi

for cmd in aws pip3.11 python3.11 zip unzip; do
    if ! command -v "$cmd" >/dev/null 2>&1; then
        color_err "$cmd not found — install with: sudo dnf install -y python3.11 python3.11-pip"
        exit 1
    fi
done

# Verify AWS credentials work
if ! aws sts get-caller-identity --region "$REGION" >/dev/null 2>&1; then
    color_err "AWS credentials not working. Check LabInstanceProfile is attached."
    exit 1
fi

color_ok "All prerequisites met"

# ---- Phase 1: PHP app ----------------------------------------------------
if [[ "${SKIP_PHP:-0}" != "1" ]]; then
    color_phase "Phase 1: PHP app deploy"

    if [[ ! -f "$PROJECT_DIR/php/composer.json" ]]; then
        color_err "composer.json not found at $PROJECT_DIR/php/"
        exit 1
    fi

    cd "$PROJECT_DIR/php"
    if command -v composer >/dev/null 2>&1; then
        composer install --no-dev --optimize-autoloader --quiet
        color_ok "Composer dependencies installed"
    else
        color_warn "composer not installed; skipping PHP dep install"
    fi

    # Fix permissions for Apache
    if [[ -d /var/www/upou-helpdesk ]] && getent passwd apache >/dev/null; then
        chown -R apache:apache "$PROJECT_DIR/php" 2>/dev/null || \
            sudo chown -R apache:apache "$PROJECT_DIR/php"
        color_ok "File ownership fixed (apache:apache)"

        if systemctl is-active httpd >/dev/null 2>&1; then
            sudo systemctl reload httpd || sudo systemctl restart httpd
            color_ok "Apache reloaded"
        fi
    fi

    cd "$PROJECT_DIR"
fi

# ---- Phase 2: Lambda code ------------------------------------------------
if [[ "${SKIP_LAMBDA:-0}" != "1" ]]; then
    color_phase "Phase 2: Lambda code deploy"

    if [[ -x "$PROJECT_DIR/scripts/deploy_lambda.sh" ]]; then
        LAMBDA_SRC="$PROJECT_DIR/lambda/lambda_function.py" \
        LAMBDA_FUNCTION_NAME="$FUNCTION_NAME" \
        AWS_REGION="$REGION" \
        "$PROJECT_DIR/scripts/deploy_lambda.sh"
    else
        color_err "scripts/deploy_lambda.sh not found or not executable"
        exit 1
    fi
fi

# ---- Phase 3: Policy embeddings index ------------------------------------
if [[ "${SKIP_INDEX:-0}" != "1" ]]; then
    color_phase "Phase 3: Policy index deploy"

    if [[ -x "$PROJECT_DIR/scripts/deploy_policy_index.sh" ]]; then
        POLICY_CSV="$PROJECT_DIR/data/policies.csv" \
        OPENAI_API_KEY="$OPENAI_API_KEY" \
        S3_BUCKET="$S3_BUCKET" \
        LAMBDA_FUNCTION_NAME="$FUNCTION_NAME" \
        AWS_REGION="$REGION" \
        "$PROJECT_DIR/scripts/deploy_policy_index.sh"
    else
        color_err "scripts/deploy_policy_index.sh not found or not executable"
        exit 1
    fi
fi

# ---- Phase 4: End-to-end verification ------------------------------------
color_phase "Phase 4: End-to-end verification"

# 1. Invoke Lambda directly with a policy question
echo "Testing Lambda with policy question..."
aws lambda invoke \
    --function-name "$FUNCTION_NAME" \
    --payload '{"question":"When does 2nd semester 2025-2026 start?"}' \
    --cli-binary-format raw-in-base64-out \
    --region "$REGION" \
    /tmp/e2e-$$.json >/dev/null 2>&1

RESULT=$(cat /tmp/e2e-$$.json)
rm -f /tmp/e2e-$$.json

# Parse the response
LABEL=$(echo "$RESULT" | python3.11 -c "
import json, sys
try:
    data = json.load(sys.stdin)
    if 'body' in data:
        body = json.loads(data['body'])
        print(body.get('source_label', 'UNKNOWN'))
    else:
        print('NO_BODY')
except Exception as e:
    print(f'PARSE_ERROR: {e}')
")

if [[ "$LABEL" == "Official Policy" ]]; then
    color_ok "Lambda returned 'Official Policy' for policy question"
elif [[ "$LABEL" == "General Knowledge" ]]; then
    color_warn "Lambda returned 'General Knowledge' — consider lowering SIMILARITY_THRESHOLD"
elif [[ "$LABEL" == "Needs Human Review" ]]; then
    color_err "Lambda is escalating everything — check CloudWatch DEBUG logs"
    echo "Raw response:"
    echo "$RESULT" | head -20
    exit 1
else
    color_err "Unexpected label: $LABEL"
    echo "Raw response:"
    echo "$RESULT" | head -20
    exit 1
fi

# 2. Check PHP frontend (if Apache is running)
if systemctl is-active httpd >/dev/null 2>&1; then
    STATUS=$(curl -s -o /dev/null -w "%{http_code}" http://localhost/index.php || echo "fail")
    if [[ "$STATUS" == "200" ]]; then
        color_ok "PHP frontend responding (http://localhost/index.php)"
    else
        color_warn "PHP frontend returned $STATUS (check /var/log/httpd/upou-helpdesk-error.log)"
    fi
fi

# ---- Done ----------------------------------------------------------------
ELAPSED=$(( $(date +%s) - START_TS ))
color_phase "Deploy complete in ${ELAPSED}s"

cat <<EOF

Next steps:
  1. Visit http://<EC2_PUBLIC_IP>/ in your browser
  2. Sign up, log in, ask: "When does 2nd semester 2025-2026 start?"
  3. You should see a green "Official Policy" badge with the date

If anything is off:
  - Tail logs:          sudo tail -f /var/log/httpd/upou-helpdesk-error.log
  - CloudWatch:         Lambda console → $FUNCTION_NAME → Monitor → View logs
  - Lambda test event:  {"question":"When does 2nd semester 2025-2026 start?"}

EOF
