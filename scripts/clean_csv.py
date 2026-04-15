#!/usr/bin/env python3
"""
clean_csv.py - Fix common issues in data/policies.csv before embedding.

Catches and fixes:
  - Rows with '…' (ellipsis) as chunk_id
  - Rows with empty chunk_id or empty chunk_text
  - Duplicate chunk IDs
  - BOM/encoding issues
  - Inconsistent line endings

Usage:
    python3 scripts/clean_csv.py [input_csv] [output_csv]

Defaults to data/policies.csv for both (in-place cleanup with backup).
"""

import csv
import os
import shutil
import sys


def clean(input_path: str, output_path: str) -> None:
    rows = []
    with open(input_path, encoding="utf-8-sig", newline="") as f:
        reader = csv.DictReader(f)
        fieldnames = reader.fieldnames
        if not fieldnames or "chunk_id" not in fieldnames:
            print(f"ERROR: {input_path} has no 'chunk_id' column", file=sys.stderr)
            sys.exit(1)
        for row in reader:
            rows.append(row)

    print(f"Read {len(rows)} rows from {input_path}")

    # Separate into valid content rows and broken rows
    enr_rows = []
    cal_rows = []
    dropped = 0

    for row in rows:
        cid = (row.get("chunk_id") or "").strip()
        section = (row.get("section_title") or "").strip()
        text = (row.get("chunk_text") or "").strip()

        if not section and not text:
            dropped += 1
            continue

        # CAL rows keep their explicit IDs
        if cid.startswith("CAL"):
            cal_rows.append(row)
            continue

        # ENR rows (or anything with content but broken ID) get renumbered
        enr_rows.append(row)

    # Renumber ENR rows sequentially
    for i, row in enumerate(enr_rows, start=1):
        row["chunk_id"] = f"ENR{i:03d}"
        if not row.get("domain"):
            row["domain"] = "Registration and Enrollment"

    output = enr_rows + cal_rows

    # Write back
    if output_path == input_path:
        backup = input_path + ".bak"
        shutil.copy2(input_path, backup)
        print(f"Backup saved to {backup}")

    with open(output_path, "w", encoding="utf-8-sig", newline="") as f:
        writer = csv.DictWriter(f, fieldnames=fieldnames, lineterminator="\r\n")
        writer.writeheader()
        writer.writerows(output)

    print(f"Wrote {len(output)} rows to {output_path}")
    print(f"  ENR: {len(enr_rows)}")
    print(f"  CAL: {len(cal_rows)}")
    print(f"  Dropped (empty): {dropped}")


if __name__ == "__main__":
    inp = sys.argv[1] if len(sys.argv) > 1 else "data/policies.csv"
    out = sys.argv[2] if len(sys.argv) > 2 else inp
    if not os.path.isfile(inp):
        print(f"ERROR: {inp} not found", file=sys.stderr)
        sys.exit(1)
    clean(inp, out)
