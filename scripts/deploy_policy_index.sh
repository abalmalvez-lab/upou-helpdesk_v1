#!/usr/bin/env bash
#
# deploy_policy_index.sh - Rebuild and upload the policy embeddings index.
#
# Handles:
#   - CSV validation (catches the "…" placeholder bug and empty rows)
#   - Embedding generation via OpenAI
#   - Upload to S3
#   - Verification that S3 has the correct count
#   - Force Lambda cold start via CACHE_BUST env var
#
# Usage:
#   export OPENAI_API_KEY=sk-...
#   export S3_BUCKET=your-bucket
#   ./scripts/deploy_policy_index.sh

set -euo pipefail

FUNCTION_NAME="${LAMBDA_FUNCTION_NAME:-ai-webapp-handler}"
REGION="${AWS_REGION:-us-east-1}"
CSV_PATH="${POLICY_CSV:-data/policies.csv}"

color_ok()   { printf "\033[32m✓\033[0m %s\n" "$1"; }
color_warn() { printf "\033[33m!\033[0m %s\n" "$1"; }
color_err()  { printf "\033[31m✗\033[0m %s\n" "$1" >&2; }

echo "=== UPOU HelpDesk Policy Index Deploy ==="
echo

# ---- 1. Check env vars ----------------------------------------------------
: "${OPENAI_API_KEY:?OPENAI_API_KEY env var is required}"
: "${S3_BUCKET:?S3_BUCKET env var is required}"

# Guard against the classic "s3://" prefix bug
if [[ "$S3_BUCKET" == s3://* ]]; then
    color_err "S3_BUCKET must NOT start with 's3://' — use just the bucket name"
    exit 1
fi

color_ok "Env vars OK (bucket: $S3_BUCKET)"

# ---- 2. Validate CSV ------------------------------------------------------
if [[ ! -f "$CSV_PATH" ]]; then
    color_err "CSV not found at $CSV_PATH"
    exit 1
fi

CSV_ROWS=$(tail -n +2 "$CSV_PATH" | grep -c . || true)
CSV_ELLIPSIS=$(awk -F, '$1 == "…"' "$CSV_PATH" | wc -l || true)
CSV_CAL=$(grep -c "^CAL" "$CSV_PATH" || true)
CSV_ENR=$(grep -c "^ENR" "$CSV_PATH" || true)

echo
echo "CSV: $CSV_PATH"
echo "  Total data rows: $CSV_ROWS"
echo "  ENR rows: $CSV_ENR"
echo "  CAL rows: $CSV_CAL"

if [[ "$CSV_ELLIPSIS" -gt 0 ]]; then
    color_err "CSV has $CSV_ELLIPSIS rows with '…' as chunk_id — run the CSV cleanup script first"
    exit 1
fi

if [[ "$CSV_ROWS" -lt 10 ]]; then
    color_err "CSV has fewer than 10 rows — likely corrupted"
    exit 1
fi

color_ok "CSV validation passed"

# ---- 3. Build and upload the index ---------------------------------------
echo
echo "Running build_policy_index.py..."
python3.11 scripts/build_policy_index.py

# ---- 4. Verify on S3 ------------------------------------------------------
echo
echo "Verifying index on S3..."
S3_COUNT=$(python3.11 -c "
import boto3, json
try:
    obj = boto3.client('s3').get_object(Bucket='$S3_BUCKET', Key='policy_index.json')
    idx = json.loads(obj['Body'].read())
    print(idx['count'])
except Exception as e:
    print('ERROR:', e)
    exit(1)
")

if [[ "$S3_COUNT" == "$CSV_ROWS" ]]; then
    color_ok "S3 index has $S3_COUNT chunks (matches CSV)"
else
    color_err "S3 index count ($S3_COUNT) does not match CSV rows ($CSV_ROWS)"
    exit 1
fi

# ---- 5. Force Lambda cold start via CACHE_BUST ---------------------------
# This is the ONLY reliable way to force warm containers to drop their
# cached _policy_index. Changing any env var triggers a full reload.
echo
echo "Forcing Lambda cold start..."
TS=$(date +%s)

# Fetch existing env vars, update CACHE_BUST, push them back
# (aws lambda update-function-configuration --environment overwrites, so we
#  have to include everything)
EXISTING=$(aws lambda get-function-configuration \
    --function-name "$FUNCTION_NAME" \
    --region "$REGION" \
    --query 'Environment.Variables' --output json)

# Use python to manipulate the JSON safely
NEW_ENV=$(python3.11 -c "
import json, sys
env = json.loads('''$EXISTING''')
env['CACHE_BUST'] = '$TS'
print('Variables={' + ','.join(f'{k}={v}' for k,v in env.items()) + '}')
")

aws lambda update-function-configuration \
    --function-name "$FUNCTION_NAME" \
    --environment "$NEW_ENV" \
    --region "$REGION" \
    --query 'LastUpdateStatus' --output text > /dev/null

aws lambda wait function-updated \
    --function-name "$FUNCTION_NAME" \
    --region "$REGION"

color_ok "Cold start forced (CACHE_BUST=$TS)"

echo
echo "=== Index deploy complete ==="
echo "Next Lambda invocation will reload the new index from S3."
