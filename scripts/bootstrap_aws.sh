#!/usr/bin/env bash
#
# bootstrap_aws.sh - Create all AWS resources needed for UPOU HelpDesk.
#
# Creates (idempotent — safe to re-run):
#   - S3 bucket
#   - DynamoDB table for tickets
#   - Lambda function shell (empty, to be populated by deploy_lambda.sh)
#   - Lambda environment variables
#
# Run from CloudShell or anywhere with AWS CLI + credentials:
#   export OPENAI_API_KEY=sk-...
#   export S3_BUCKET=upou-helpdesk-2026-yourinitials
#   ./scripts/bootstrap_aws.sh

set -euo pipefail

REGION="${AWS_REGION:-us-east-1}"
FUNCTION_NAME="${LAMBDA_FUNCTION_NAME:-ai-webapp-handler}"
DDB_TABLE="${DDB_TICKETS_TABLE:-upou-helpdesk-tickets}"

color_ok()    { printf "\033[32m✓\033[0m %s\n" "$1"; }
color_warn()  { printf "\033[33m!\033[0m %s\n" "$1"; }
color_err()   { printf "\033[31m✗\033[0m %s\n" "$1" >&2; }
color_phase() { printf "\n\033[1;34m=== %s ===\033[0m\n" "$1"; }

: "${OPENAI_API_KEY:?OPENAI_API_KEY must be set}"
: "${S3_BUCKET:?S3_BUCKET must be set (no s3:// prefix)}"

if [[ "$S3_BUCKET" == s3://* ]]; then
    color_err "S3_BUCKET must NOT start with 's3://'"
    exit 1
fi

# Discover the LabRole ARN (Learner Lab pre-made role)
ACCOUNT_ID=$(aws sts get-caller-identity --query Account --output text)
LAB_ROLE_ARN="arn:aws:iam::${ACCOUNT_ID}:role/LabRole"

if ! aws iam get-role --role-name LabRole >/dev/null 2>&1; then
    color_err "LabRole not found — are you in a Learner Lab session?"
    exit 1
fi
color_ok "Found LabRole: $LAB_ROLE_ARN"

# ---- 1. S3 bucket --------------------------------------------------------
color_phase "Step 1: S3 bucket"

if aws s3api head-bucket --bucket "$S3_BUCKET" --region "$REGION" 2>/dev/null; then
    color_ok "Bucket $S3_BUCKET already exists"
else
    if [[ "$REGION" == "us-east-1" ]]; then
        aws s3api create-bucket --bucket "$S3_BUCKET" --region "$REGION"
    else
        aws s3api create-bucket --bucket "$S3_BUCKET" --region "$REGION" \
            --create-bucket-configuration LocationConstraint="$REGION"
    fi
    color_ok "Created bucket $S3_BUCKET"
fi

# Block public access
aws s3api put-public-access-block \
    --bucket "$S3_BUCKET" \
    --public-access-block-configuration \
    "BlockPublicAcls=true,IgnorePublicAcls=true,BlockPublicPolicy=true,RestrictPublicBuckets=true" \
    2>/dev/null || true
color_ok "Public access blocked"

# ---- 2. DynamoDB table ---------------------------------------------------
color_phase "Step 2: DynamoDB table"

if aws dynamodb describe-table --table-name "$DDB_TABLE" --region "$REGION" >/dev/null 2>&1; then
    color_ok "Table $DDB_TABLE already exists"
else
    aws dynamodb create-table \
        --table-name "$DDB_TABLE" \
        --attribute-definitions AttributeName=ticket_id,AttributeType=S \
        --key-schema AttributeName=ticket_id,KeyType=HASH \
        --billing-mode PAY_PER_REQUEST \
        --region "$REGION" >/dev/null
    echo "Waiting for table to become active..."
    aws dynamodb wait table-exists --table-name "$DDB_TABLE" --region "$REGION"
    color_ok "Created $DDB_TABLE"
fi

# ---- 3. Lambda function shell --------------------------------------------
color_phase "Step 3: Lambda function shell"

# A minimal placeholder zip so create-function succeeds
PLACEHOLDER_DIR=$(mktemp -d)
trap "rm -rf $PLACEHOLDER_DIR" EXIT

cat > "$PLACEHOLDER_DIR/lambda_function.py" <<'PY'
def lambda_handler(event, context):
    return {"statusCode": 200, "body": "placeholder"}
PY

(cd "$PLACEHOLDER_DIR" && zip -q placeholder.zip lambda_function.py)

if aws lambda get-function --function-name "$FUNCTION_NAME" --region "$REGION" >/dev/null 2>&1; then
    color_ok "Lambda $FUNCTION_NAME already exists"
else
    aws lambda create-function \
        --function-name "$FUNCTION_NAME" \
        --runtime python3.11 \
        --role "$LAB_ROLE_ARN" \
        --handler lambda_function.lambda_handler \
        --zip-file "fileb://$PLACEHOLDER_DIR/placeholder.zip" \
        --timeout 30 \
        --memory-size 512 \
        --architectures x86_64 \
        --region "$REGION" >/dev/null

    echo "Waiting for function to become active..."
    aws lambda wait function-active --function-name "$FUNCTION_NAME" --region "$REGION"
    color_ok "Created Lambda shell $FUNCTION_NAME"
fi

# ---- 4. Lambda environment variables -------------------------------------
color_phase "Step 4: Lambda environment variables"

aws lambda update-function-configuration \
    --function-name "$FUNCTION_NAME" \
    --runtime python3.11 \
    --memory-size 512 \
    --timeout 30 \
    --environment "Variables={OPENAI_API_KEY=$OPENAI_API_KEY,OPENAI_BASE_URL=${OPENAI_BASE_URL:-https://is215-openai.upou.io/v1},OPENAI_MODEL=gpt-4o-mini,S3_BUCKET=$S3_BUCKET,S3_PREFIX=logs/,POLICY_INDEX_KEY=policy_index.json,DDB_TICKETS_TABLE=$DDB_TABLE,KEYWORD_THRESHOLD=0.15}" \
    --region "$REGION" >/dev/null

aws lambda wait function-updated --function-name "$FUNCTION_NAME" --region "$REGION"
color_ok "Env vars set"

color_phase "Bootstrap complete"
cat <<EOF

AWS resources ready:
  S3 bucket:       $S3_BUCKET
  DynamoDB table:  $DDB_TABLE
  Lambda:          $FUNCTION_NAME (python3.11, x86_64, 512MB, 30s)

Next steps:
  1. Run deploy_lambda.sh to upload the real Lambda code
  2. Run deploy_policy_index.sh to build and upload the embeddings
  Or just run deploy_all.sh from EC2 to do everything at once.

EOF
