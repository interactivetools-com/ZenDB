#!/usr/bin/env bash
# Scan library code with Codex Security. Maintainer tooling: runs locally, not
# in CI, and needs the codex-security CLI installed. Results go to the CLI's
# state dir; view them with: codex-security scans list
# The defaults (gpt-5.6-sol, xhigh effort, stop-after-no-new 4) are already the
# strongest settings; extra flags only lower them. Run scans one repo at a time:
# concurrent scans share a sandbox dir in /tmp and kill each other's workers.
# Full pre-release scan:
#   .github/scripts/codex-security-scan.sh --mode deep
set -euo pipefail
cd "$(dirname "$0")/../.."

# There's no exclude flag, so build the path list here: everything except tests,
# gitignored files (vendor, caches, .idea) and __* scratch notes. Skipping those
# keeps the scan on shipped code; the scratch notes also get quoted back as
# evidence, which we don't want steering the results.
shopt -s dotglob
paths=()
for entry in *; do
    if [[ $entry == .git || $entry == tests || $entry == __* ]] || git check-ignore -q "$entry"; then
        continue
    fi
    paths+=(--path "$entry")
done

# The scan prompt lives here (written to a temp file at runtime) so the repo
# needs no scratch file. It names rawSql() and {{column}} as documented
# trusted-SQL routes; without that, the scanner reports their existence as a
# parameterization bypass instead of tracing what flows into them.
prompt_file=$(mktemp)
trap 'rm -f "$prompt_file"' EXIT
cat > "$prompt_file" <<'PROMPT'
This is a whole-library pre-release scan of ZenDB, a PHP 8.1+ mysqli database
layer. tests/ and vendor/ are excluded on purpose; everything else is the
shipping code. Treat findings as release blockers, not incremental review notes.

Core security promises to verify:

- Every value reaches MySQL through parameterized placeholders. There is no
  supported way to concatenate user input into SQL.
- Identifiers (table names, column names, ORDER BY input) are validated
  against an allowlist pattern, not escaped.
- When `encryptionKey` is set, MEDIUMBLOB columns are encrypted in PHP before
  the value reaches MySQL and decrypted on read.

Intended API, not findings: rawSql() exists to mark developer-trusted SQL and
the `{{column}}` decryption syntax is a documented raw-SQL feature. Their
existence is by design; flag untrusted data flowing into them, not the
methods themselves. Returned SmartArray and SmartString values expose raw
data for logic per those libraries' documented contracts.

Prioritize, in order:

1. SQL injection paths that bypass the placeholder system: identifier
   placeholders, the `::` table prefix, `{{column}}` decryption syntax in raw
   SQL, and SQL helpers like `likeContains()` and `pagingSql()`.
2. Encryption weaknesses: key handling, IV or nonce reuse, values that skip
   encryption silently, plaintext leaking into logs or error messages.
3. Untrusted input reaching the wire, the filesystem, or error output
   unparameterized or unencoded.
4. Unbounded work on attacker-controlled input: loops whose pass count
   depends on input syntax.
PROMPT

codex-security scan . "${paths[@]}" \
    --knowledge-base docs/security-gotchas.md \
    --knowledge-base docs/encryption.md \
    --knowledge-base docs/placeholders.md \
    --scan-prompt-file "$prompt_file" \
    "$@"
