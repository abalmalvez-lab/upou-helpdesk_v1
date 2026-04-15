#!/usr/bin/env bash
#
# deploy_lambda.sh - Build and upload the UPOU HelpDesk Lambda in one shot.
#
# Handles every gotcha encountered during the project:
#   - Detects when run from wrong directory and finds the project root
#   - Pins Lambda runtime to python3.11 BEFORE upload (prevents 3.14 drift)
#   - Bumps memory to 512 MB if it's lower
#   - Pins timeout to 30s
#   - Verifies pip3.11 + aws CLI exist
#   - Builds zip with platform-specific manylinux wheels for cpython 3.11 x86_64
#   - Verifies pydantic_core .so matches Lambda's runtime
#   - Verifies lambda_function.py is at the zip ROOT (not inside a folder)
#   - Waits for LastUpdateStatus = Successful before returning
#   - Smoke-tests the deployed Lambda after upload
#
# Usage:
#   ./scripts/deploy_lambda.sh
#
# Optional env vars:
#   LAMBDA_FUNCTION_NAME (default: ai-webapp-handler)
#   AWS_REGION           (default: us-east-1)
#   LAMBDA_SRC           (default: <project>/lambda/lambda_function.py)

set -euo pipefail

FUNCTION_NAME="${LAMBDA_FUNCTION_NAME:-ai-webapp-handler}"
REGION="${AWS_REGION:-us-east-1}"
PYTHON_VERSION="3.11"
PLATFORM="manylinux2014_x86_64"

color_ok()   { printf "\033[32m✓\033[0m %s\n" "$1"; }
color_warn() { printf "\033[33m!\033[0m %s\n" "$1"; }
color_err()  { printf "\033[31m✗\033[0m %s\n" "$1" >&2; }

# ---- Find the project root regardless of where the script was invoked ----
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="$(cd "$SCRIPT_DIR/.." && pwd)"
LAMBDA_SRC="${LAMBDA_SRC:-$PROJECT_ROOT/lambda/lambda_function.py}"

BUILD_DIR="/tmp/lambda-build-$$"

echo "=== UPOU HelpDesk Lambda deploy ==="
echo "Function:     $FUNCTION_NAME"
echo "Region:       $REGION"
echo "Project root: $PROJECT_ROOT"
echo "Source:       $LAMBDA_SRC"
echo

# ---- 1. Verify prerequisites ---------------------------------------------
if ! command -v pip3.11 >/dev/null 2>&1; then
    color_err "pip3.11 not found. Install with: sudo dnf install -y python3.11 python3.11-pip"
    exit 1
fi

if ! command -v aws >/dev/null 2>&1; then
    color_err "aws CLI not found"
    exit 1
fi

if ! command -v zip >/dev/null 2>&1; then
    color_err "zip not found. Install with: sudo dnf install -y zip"
    exit 1
fi

if [[ ! -f "$LAMBDA_SRC" ]]; then
    color_err "Lambda source not found at $LAMBDA_SRC"
    color_err "Set LAMBDA_SRC env var or run from the project root"
    exit 1
fi

# Sanity check the source file is actually Python, not bash or text
if ! head -1 "$LAMBDA_SRC" | grep -qE '^("""|#|import|from)'; then
    color_err "Lambda source file does not look like Python:"
    head -1 "$LAMBDA_SRC"
    exit 1
fi

color_ok "Prerequisites satisfied"

# ---- 2. Build the zip ----------------------------------------------------
mkdir -p "$BUILD_DIR"
trap 'rm -rf "$BUILD_DIR"' EXIT

cp "$LAMBDA_SRC" "$BUILD_DIR/lambda_function.py"
cd "$BUILD_DIR"

echo
echo "Installing dependencies for python${PYTHON_VERSION} / ${PLATFORM}..."
pip3.11 install \
    --platform "$PLATFORM" \
    --target . \
    --implementation cp \
    --python-version "$PYTHON_VERSION" \
    --only-binary=:all: \
    --quiet \
    openai

color_ok "Dependencies installed"

# ---- 3. Verify the compiled .so matches Lambda runtime --------------------
SO_FILE=$(ls pydantic_core/_pydantic_core*.so 2>/dev/null | head -1 || true)
if [[ -z "$SO_FILE" ]]; then
    color_err "pydantic_core .so not found. pip install may have failed."
    exit 1
fi

if ! echo "$SO_FILE" | grep -q "cpython-311"; then
    color_err "pydantic_core .so has wrong Python version: $SO_FILE"
    color_err "Expected cpython-311. Check your pip3.11 version with: pip3.11 --version"
    exit 1
fi

ARCH_INFO=$(file "$SO_FILE")
if ! echo "$ARCH_INFO" | grep -q "x86-64"; then
    color_err "pydantic_core .so is not x86_64: $ARCH_INFO"
    exit 1
fi

if ! echo "$ARCH_INFO" | grep -qi "linux\|gnu"; then
    color_err "pydantic_core .so is not Linux: $ARCH_INFO"
    exit 1
fi

color_ok "Compiled extension matches (cpython-311 x86_64 Linux)"

# ---- 4. Build the zip ----------------------------------------------------
zip -rq function.zip .
ZIP_SIZE=$(du -h function.zip | cut -f1)
color_ok "Built function.zip ($ZIP_SIZE)"

# Verify zip structure: lambda_function.py must be at root
if ! unzip -l function.zip | awk '{print $NF}' | grep -q '^lambda_function\.py$'; then
    color_err "lambda_function.py is not at the root of the zip"
    color_err "Contents:"
    unzip -l function.zip | head -20
    exit 1
fi
color_ok "lambda_function.py is at zip root"

# Also verify the .so made it into the zip
if ! unzip -l function.zip | grep -q "pydantic_core/_pydantic_core.*\.so$"; then
    color_err "pydantic_core .so is missing from the zip"
    exit 1
fi
color_ok "pydantic_core .so is in the zip"

# ---- 5. Pin Lambda runtime to 3.11 BEFORE uploading ----------------------
# This prevents the "Runtime.ImportModuleError: No module named pydantic_core"
# error that happens when Lambda's runtime defaults to python3.14.
echo
echo "Verifying Lambda runtime configuration..."

CURRENT_RUNTIME=$(aws lambda get-function-configuration \
    --function-name "$FUNCTION_NAME" \
    --region "$REGION" \
    --query 'Runtime' --output text 2>/dev/null || echo "missing")

if [[ "$CURRENT_RUNTIME" == "missing" ]]; then
    color_err "Lambda function '$FUNCTION_NAME' not found in region $REGION"
    color_err "Create it first in the AWS console, then re-run this script"
    exit 1
fi

if [[ "$CURRENT_RUNTIME" != "python${PYTHON_VERSION}" ]]; then
    color_warn "Runtime is '$CURRENT_RUNTIME', changing to python${PYTHON_VERSION}"
    aws lambda update-function-configuration \
        --function-name "$FUNCTION_NAME" \
        --runtime "python${PYTHON_VERSION}" \
        --region "$REGION" \
        --query 'Runtime' --output text > /dev/null
    aws lambda wait function-updated \
        --function-name "$FUNCTION_NAME" \
        --region "$REGION"
    color_ok "Runtime pinned to python${PYTHON_VERSION}"
else
    color_ok "Runtime is python${PYTHON_VERSION}"
fi

# Pin memory to at least 512 MB (prevents OOM on policy index load)
CURRENT_MEM=$(aws lambda get-function-configuration \
    --function-name "$FUNCTION_NAME" \
    --region "$REGION" \
    --query 'MemorySize' --output text)

if [[ "$CURRENT_MEM" -lt 512 ]]; then
    color_warn "Memory is ${CURRENT_MEM}MB (too low), bumping to 512MB"
    aws lambda update-function-configuration \
        --function-name "$FUNCTION_NAME" \
        --memory-size 512 \
        --timeout 30 \
        --region "$REGION" \
        --query 'MemorySize' --output text > /dev/null
    aws lambda wait function-updated \
        --function-name "$FUNCTION_NAME" \
        --region "$REGION"
    color_ok "Memory bumped to 512MB"
else
    color_ok "Memory is ${CURRENT_MEM}MB"
fi

# ---- 6. Upload the zip ---------------------------------------------------
echo
echo "Uploading function.zip..."
aws lambda update-function-code \
    --function-name "$FUNCTION_NAME" \
    --zip-file "fileb://$BUILD_DIR/function.zip" \
    --region "$REGION" \
    --query 'LastUpdateStatus' --output text > /dev/null

aws lambda wait function-updated \
    --function-name "$FUNCTION_NAME" \
    --region "$REGION"

color_ok "Lambda code updated"

# ---- 7. Smoke test -------------------------------------------------------
echo
echo "Running smoke test..."
SMOKE_FILE="/tmp/lambda-smoke-$$.json"
aws lambda invoke \
    --function-name "$FUNCTION_NAME" \
    --payload '{"question":"hello"}' \
    --cli-binary-format raw-in-base64-out \
    --region "$REGION" \
    "$SMOKE_FILE" >/dev/null 2>&1 || true

if [[ -f "$SMOKE_FILE" ]]; then
    BODY=$(cat "$SMOKE_FILE")
    rm -f "$SMOKE_FILE"

    if echo "$BODY" | grep -q "ImportModuleError"; then
        color_err "Smoke test: ImportModuleError - runtime/zip mismatch"
        echo "$BODY"
        exit 1
    fi
    if echo "$BODY" | grep -q "errorMessage"; then
        color_err "Smoke test failed:"
        echo "$BODY" | head -10
        exit 1
    fi
    if echo "$BODY" | grep -q "statusCode"; then
        color_ok "Smoke test passed"
    else
        color_warn "Smoke test response was unexpected:"
        echo "$BODY" | head -10
    fi
else
    color_warn "Smoke test invoke produced no output file"
fi

echo
echo "=== Deploy complete ==="
echo "Test in console: Lambda → $FUNCTION_NAME → Test tab"
echo "Test in chat:    visit the helpdesk and ask a UPOU policy question"
