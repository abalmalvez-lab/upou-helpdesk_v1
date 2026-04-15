"""
build_policy_index.py — Keyword-search version (no embeddings).

Reads data/policies.csv and writes a JSON index to S3 that the Lambda
loads at cold start. Each chunk gets a normalized list of keyword tokens
(from the `keywords`, `section_title`, and `subtopic` columns) so the
Lambda can score by overlap at query time.

This version requires NO embeddings provider — works entirely with the
UPOU proxy (or any chat-only OpenAI-compatible endpoint) because we don't
call any embedding API.

Usage:
    pip install boto3
    export S3_BUCKET=your-bucket
    python3 scripts/build_policy_index.py
"""

import csv
import json
import os
import re
import sys
import boto3

CSV_PATH  = os.environ.get("POLICY_CSV", "data/policies.csv")
S3_BUCKET = os.environ["S3_BUCKET"]
S3_KEY    = os.environ.get("POLICY_INDEX_KEY", "policy_index.json")

s3 = boto3.client("s3")

STOPWORDS = {
    "a","an","and","are","as","at","be","but","by","can","do","does","for",
    "from","have","has","how","i","if","in","is","it","its","my","of","on",
    "or","that","the","this","to","was","were","what","when","where","which",
    "who","why","will","with","you","your","yours","me","we","us","our","am",
    "been","being","than","then","there","these","those","they","their",
}

WORD_RE = re.compile(r"[a-z0-9]+")


def tokenize(text: str) -> list[str]:
    """Lowercase, strip punctuation, split on whitespace, drop stopwords."""
    if not text:
        return []
    return [w for w in WORD_RE.findall(text.lower()) if w not in STOPWORDS and len(w) > 1]


def read_csv(path: str) -> list[dict]:
    rows = []
    with open(path, encoding="utf-8-sig", newline="") as f:
        reader = csv.DictReader(f)
        for row in reader:
            text = (row.get("chunk_text") or "").strip()
            if not text:
                continue
            rows.append({
                "chunk_id":      (row.get("chunk_id") or "").strip(),
                "domain":        (row.get("domain") or "").strip(),
                "subtopic":      (row.get("subtopic") or "").strip(),
                "section_title": (row.get("section_title") or "").strip(),
                "chunk_text":    text,
                "keywords":      (row.get("keywords") or "").strip(),
                "source_url":    (row.get("source_url") or "").strip(),
                "source_title":  (row.get("source_title") or "").strip(),
            })
    return rows


def main() -> None:
    print(f"Reading {CSV_PATH}...")
    rows = read_csv(CSV_PATH)
    print(f"  {len(rows)} non-empty rows")

    print("Building keyword tokens for each chunk...")
    for r in rows:
        # Strong signal — explicit keywords + subtopic + section_title
        strong = " ".join([r["keywords"], r["subtopic"], r["section_title"]])
        # Weak signal — full chunk text (lots of noise but useful for fallback)
        weak = r["chunk_text"]

        r["tokens_strong"] = list(set(tokenize(strong)))
        r["tokens_weak"]   = list(set(tokenize(weak)))

    index = {
        "version": "keyword-1",
        "count":   len(rows),
        "chunks":  rows,
    }

    print(f"Uploading to s3://{S3_BUCKET}/{S3_KEY} ...")
    s3.put_object(
        Bucket=S3_BUCKET,
        Key=S3_KEY,
        Body=json.dumps(index, ensure_ascii=False).encode("utf-8"),
        ContentType="application/json",
    )
    size_kb = len(json.dumps(index)) / 1024
    print(f"Done. Index size: {size_kb:.1f} KB")
    print(f"Sample tokens for first chunk ({rows[0]['chunk_id']}):")
    print(f"  strong: {rows[0]['tokens_strong'][:10]}")
    print(f"  weak:   {rows[0]['tokens_weak'][:10]}")


if __name__ == "__main__":
    try:
        main()
    except KeyError as e:
        print(f"Missing env var: {e}", file=sys.stderr)
        sys.exit(1)
