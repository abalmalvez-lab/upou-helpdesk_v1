"""
UPOU AI HelpDesk Lambda - Keyword search version (no embeddings).

Pipeline:
1. Receive question
2. Tokenize question, score each policy chunk by keyword overlap
3. If top score >= threshold -> "Official Policy" (grounded answer)
4. Below threshold or AI says it can't answer -> "General Knowledge" or escalate
5. Always log to S3, escalate to DynamoDB ticket on Needs Human Review

Required environment variables:
    OPENAI_API_KEY         - API key (UPOU proxy or real OpenAI)
    OPENAI_BASE_URL        - base URL, e.g. https://is215-openai.upou.io/v1
    OPENAI_MODEL           - chat model name, e.g. gpt-4o-mini
    S3_BUCKET              - bucket holding policy_index.json and logs
    S3_PREFIX              - log key prefix, default "logs/"
    POLICY_INDEX_KEY       - default "policy_index.json"
    DDB_TICKETS_TABLE      - default "upou-helpdesk-tickets"
    KEYWORD_THRESHOLD      - 0..1, default 0.15
"""

import json
import os
import re
import uuid
import datetime
import traceback
import boto3
from openai import OpenAI

# ---- Initialized once per cold start, reused on warm invocations ----
_s3 = boto3.client("s3")
_ddb = boto3.client("dynamodb")
_client = OpenAI(
    api_key=os.environ["OPENAI_API_KEY"],
    base_url=os.environ.get("OPENAI_BASE_URL", "https://api.openai.com/v1"),
)

CHAT_MODEL    = os.environ.get("OPENAI_MODEL", "gpt-4o-mini")
BUCKET        = os.environ["S3_BUCKET"]
PREFIX        = os.environ.get("S3_PREFIX", "logs/")
INDEX_KEY     = os.environ.get("POLICY_INDEX_KEY", "policy_index.json")
TICKETS_TABLE = os.environ.get("DDB_TICKETS_TABLE", "upou-helpdesk-tickets")
THRESHOLD     = float(os.environ.get("KEYWORD_THRESHOLD", "0.15"))
TOP_K = 3

# Cached on cold start
_policy_index = None

STOPWORDS = {
    "a","an","and","are","as","at","be","but","by","can","do","does","for",
    "from","have","has","how","i","if","in","is","it","its","my","of","on",
    "or","that","the","this","to","was","were","what","when","where","which",
    "who","why","will","with","you","your","yours","me","we","us","our","am",
    "been","being","than","then","there","these","those","they","their",
}

WORD_RE = re.compile(r"[a-z0-9]+")


def tokenize(text):
    if not text:
        return []
    return [w for w in WORD_RE.findall(text.lower()) if w not in STOPWORDS and len(w) > 1]


def load_policy_index():
    global _policy_index
    if _policy_index is None:
        obj = _s3.get_object(Bucket=BUCKET, Key=INDEX_KEY)
        _policy_index = json.loads(obj["Body"].read())
    return _policy_index


def score_chunk(question_tokens_set, chunk):
    """
    Score a chunk against a tokenized question.
    Strong matches (keywords/subtopic/section_title) count 3x.
    Weak matches (chunk_text) count 1x.
    Normalized by question size so the result is "fraction of the question matched".
    """
    if not question_tokens_set:
        return 0.0

    strong_set = set(chunk.get("tokens_strong") or [])
    weak_set   = set(chunk.get("tokens_weak") or [])

    strong_hits = len(question_tokens_set & strong_set)
    weak_hits   = len(question_tokens_set & weak_set)

    score = (strong_hits * 3 + weak_hits * 1) / (len(question_tokens_set) * 3)
    return min(score, 1.0)


def search_policy(question, top_k=TOP_K):
    index = load_policy_index()
    q_tokens = set(tokenize(question))

    scored = []
    for chunk in index.get("chunks", []):
        s = score_chunk(q_tokens, chunk)
        scored.append((s, chunk))

    scored.sort(key=lambda x: x[0], reverse=True)
    return scored[:top_k], q_tokens


def build_messages(question, matches):
    if matches and matches[0][0] >= THRESHOLD:
        context_blocks = []
        for score, chunk in matches:
            if score < THRESHOLD * 0.6:
                continue
            context_blocks.append(
                f"[{chunk.get('chunk_id','?')}] {chunk.get('section_title','')} "
                f"(from {chunk.get('source_title','')})\n{chunk.get('chunk_text','')}"
            )
        context = "\n\n".join(context_blocks)
        system = (
            "You are the UPOU HelpDesk assistant. Use ONLY the official UPOU policy "
            "excerpts below to answer. If the excerpts do not contain enough "
            "information to answer the question, reply EXACTLY with: "
            "CANNOT_ANSWER_FROM_POLICY\n\n"
            "Cite the chunk IDs you used in square brackets at the end of your answer.\n\n"
            "=== OFFICIAL UPOU POLICY EXCERPTS ===\n"
            f"{context}\n"
            "=== END EXCERPTS ==="
        )
    else:
        system = (
            "You are the UPOU HelpDesk assistant. The user's question does not "
            "match any official UPOU policy in the knowledge base. Try to answer "
            "from your general knowledge, but be honest about uncertainty. If you "
            "are not confident or the question requires UPOU-specific information "
            "you do not have, reply EXACTLY with: CANNOT_ANSWER_FROM_POLICY"
        )

    return [
        {"role": "system", "content": system},
        {"role": "user",   "content": question},
    ]


def call_chat(messages):
    """
    Call chat.completions.create with defensive parsing.
    The UPOU proxy sometimes returns choices/usage in slightly different
    shapes than real OpenAI, so we extract the answer text carefully.
    """
    completion = _client.chat.completions.create(
        model=CHAT_MODEL,
        messages=messages,
    )

    if not completion:
        raise RuntimeError("Chat API returned None")

    choices = getattr(completion, "choices", None)
    if not choices:
        raise RuntimeError(f"Chat API returned no choices: {completion}")

    choice = choices[0]
    msg = getattr(choice, "message", None)
    text = getattr(msg, "content", None) if msg else None
    if not text:
        raise RuntimeError(f"Chat API returned empty message: choice={choice}")

    # usage is optional - some proxies don't return it
    usage_dict = None
    usage = getattr(completion, "usage", None)
    if usage is not None:
        if hasattr(usage, "model_dump"):
            try:
                usage_dict = usage.model_dump()
            except Exception:
                usage_dict = None
        else:
            try:
                usage_dict = dict(usage)
            except Exception:
                usage_dict = None

    return text.strip(), usage_dict


def create_ticket(question, answer_attempt, top_score, user_email=None):
    ticket_id = str(uuid.uuid4())
    timestamp = datetime.datetime.utcnow().isoformat() + "Z"
    item = {
        "ticket_id":      {"S": ticket_id},
        "created_at":     {"S": timestamp},
        "status":         {"S": "OPEN"},
        "question":       {"S": question},
        "ai_attempt":     {"S": answer_attempt or ""},
        "top_similarity": {"N": str(round(top_score, 4))},
    }
    if user_email:
        item["user_email"] = {"S": user_email}
    try:
        _ddb.put_item(TableName=TICKETS_TABLE, Item=item)
        return ticket_id
    except Exception as e:
        print(f"Failed to create ticket: {e}")
        return None


def write_log(record):
    ts = record["timestamp"]
    key = f"{PREFIX}{ts[:10]}/{record['id']}.json"
    try:
        _s3.put_object(
            Bucket=BUCKET,
            Key=key,
            Body=json.dumps(record, ensure_ascii=False).encode("utf-8"),
            ContentType="application/json",
        )
        return key
    except Exception as e:
        print(f"Failed to write log to S3: {e}")
        return None


def _ddb_item_to_dict(item):
    """Convert a DynamoDB item (with type descriptors) to a plain dict."""
    out = {}
    for key, val in item.items():
        if "S" in val:
            out[key] = val["S"]
        elif "N" in val:
            out[key] = float(val["N"])
        elif "BOOL" in val:
            out[key] = val["BOOL"]
        elif "NULL" in val:
            out[key] = None
        else:
            out[key] = str(val)
    return out


def _parse_body(event):
    if isinstance(event, dict) and "body" in event and event["body"] is not None:
        body = event["body"]
        if isinstance(body, str):
            return json.loads(body)
        return body
    return event


def _response(status, payload):
    return {
        "statusCode": status,
        "headers": {
            "Content-Type": "application/json",
            "Access-Control-Allow-Origin": "*",
            "Access-Control-Allow-Headers": "Content-Type",
            "Access-Control-Allow-Methods": "POST, OPTIONS",
        },
        "body": json.dumps(payload),
    }


def lambda_handler(event, context):
    try:
        body = _parse_body(event)
        action = (body.get("action") or "ask").strip()

        # ---- ACTION: escalate ----
        # Creates a DynamoDB ticket on explicit user confirmation
        if action == "escalate":
            question = (body.get("question") or "").strip()
            ai_attempt = (body.get("ai_attempt") or "").strip()
            top_score = float(body.get("top_similarity") or 0)
            user_email = (body.get("user_email") or "").strip() or None
            if not question:
                return _response(400, {"error": "Field 'question' is required."})
            ticket_id = create_ticket(question, ai_attempt, top_score, user_email)
            return _response(200, {
                "ticket_id": ticket_id,
                "status": "OPEN",
                "message": "Your question has been forwarded to a human agent who will follow up.",
            })

        # ---- ACTION: ticket_status ----
        # Returns ticket details from DynamoDB for a given ticket_id or user_email
        if action == "ticket_status":
            ticket_id = (body.get("ticket_id") or "").strip()
            user_email = (body.get("user_email") or "").strip()
            if ticket_id:
                # Single ticket lookup
                try:
                    resp = _ddb.get_item(
                        TableName=TICKETS_TABLE,
                        Key={"ticket_id": {"S": ticket_id}},
                    )
                    item = resp.get("Item")
                    if not item:
                        return _response(404, {"error": "Ticket not found."})
                    ticket = _ddb_item_to_dict(item)
                    return _response(200, {"ticket": ticket})
                except Exception as e:
                    return _response(500, {"error": f"DynamoDB read failed: {e}"})
            elif user_email:
                # All tickets for a user email (scan with filter)
                try:
                    resp = _ddb.scan(
                        TableName=TICKETS_TABLE,
                        FilterExpression="user_email = :email",
                        ExpressionAttributeValues={":email": {"S": user_email}},
                    )
                    tickets = [_ddb_item_to_dict(item) for item in resp.get("Items", [])]
                    tickets.sort(key=lambda t: t.get("created_at", ""), reverse=True)
                    return _response(200, {"tickets": tickets})
                except Exception as e:
                    return _response(500, {"error": f"DynamoDB scan failed: {e}"})
            else:
                return _response(400, {"error": "Provide 'ticket_id' or 'user_email'."})

        # ---- ACTION: ask (default) ----
        question = (body.get("question") or "").strip()
        user_email = (body.get("user_email") or "").strip() or None
        if not question:
            return _response(400, {"error": "Field 'question' is required."})

        # 1. Keyword search over the policy index
        matches, q_tokens = search_policy(question)
        top_score = matches[0][0] if matches else 0.0

        # Debug logging - visible in CloudWatch
        idx = load_policy_index()
        print(f"DEBUG Q: {question!r}")
        print(f"DEBUG q_tokens: {sorted(q_tokens)}")
        print(f"DEBUG index count: {len(idx.get('chunks', []))}")
        print(f"DEBUG top5: " + " | ".join(
            f"{m[1].get('chunk_id','?')}={m[0]:.3f}" for m in matches[:5]
        ))
        print(f"DEBUG threshold: {THRESHOLD}, used_policy: {top_score >= THRESHOLD}")

        # 2. Build prompt and call chat completion
        messages = build_messages(question, matches)
        raw_answer, usage_dict = call_chat(messages)

        # 3. Classify the answer
        used_policy = top_score >= THRESHOLD
        cannot_answer = "CANNOT_ANSWER_FROM_POLICY" in raw_answer

        if cannot_answer:
            source_label = "Needs Human Review"
            answer_text = (
                "I couldn't find a confident answer to your question in the official "
                "UPOU policies, and I don't want to guess."
            )
            # Do NOT auto-create ticket here — wait for user confirmation.
            # The frontend will show a Yes/No prompt and call the escalate action.
            ticket_id = None
        elif used_policy:
            source_label = "Official Policy"
            answer_text = raw_answer
            ticket_id = None
        else:
            source_label = "General Knowledge"
            answer_text = raw_answer
            ticket_id = None

        # 4. Build sources list for the UI
        sources = []
        if used_policy and not cannot_answer:
            for score, chunk in matches:
                if score >= THRESHOLD * 0.6:
                    sources.append({
                        "chunk_id":      chunk.get("chunk_id", ""),
                        "section_title": chunk.get("section_title", ""),
                        "source_title":  chunk.get("source_title", ""),
                        "source_url":    chunk.get("source_url", ""),
                        "similarity":    round(score, 4),
                    })

        # 5. Log to S3 (best-effort)
        record_id = str(uuid.uuid4())
        timestamp = datetime.datetime.utcnow().isoformat() + "Z"
        record = {
            "id":             record_id,
            "timestamp":      timestamp,
            "model":          CHAT_MODEL,
            "question":       question,
            "answer":         answer_text,
            "source_label":   source_label,
            "top_similarity": round(top_score, 4),
            "sources":        sources,
            "ticket_id":      ticket_id,
            "user_email":     user_email,
            "usage":          usage_dict,
        }
        s3_key = write_log(record)

        return _response(200, {
            "id":             record_id,
            "answer":         answer_text,
            "source_label":   source_label,
            "sources":        sources,
            "ticket_id":      ticket_id,
            "top_similarity": round(top_score, 4),
            "s3_key":         s3_key,
        })

    except Exception as e:
        tb = traceback.format_exc()
        print("=== LAMBDA ERROR ===")
        print(tb)
        return _response(500, {
            "error": str(e),
            "type":  type(e).__name__,
            "trace": tb.split("\n")[-6:],
        })
