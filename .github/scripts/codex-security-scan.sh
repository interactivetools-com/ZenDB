#!/usr/bin/env bash
# Scan library code with Codex Security. Maintainer tooling: runs locally, not
# in CI, and needs the codex-security CLI installed. Results go to the CLI's
# state dir; view them with: codex-security scans list
# Uses gpt-6-astra with the CLI's default reasoning effort and scan limits.
# Additional CLI flags can be passed as arguments. Run scans one repo at a time:
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
layer. tests/ and vendor/ are excluded on purpose. Review runtime source,
documentation examples, and maintainer/CI tooling in their actual contexts;
do not assume every included file runs in a deployed web application.
Assess the current checkout for release readiness. Calibrate severity to the
evidence; not every finding is a release blocker.

Core security promises to verify:

- On the supported query path, untrusted values enter through placeholders.
  ZenDB escapes and quotes them client-side before sending completed SQL;
  absence of server-side prepared statements is not itself a vulnerability.
- Table/column arguments, array keys, and backtick identifier placeholders
  use identifier validation. SQL template text is developer-trusted; the
  template guard is not a SQL parser or a provenance check for interpolation.
- With a nonempty `encryptionKey`, supported insert()/update() assignments
  to MEDIUMBLOB columns encrypt in PHP and query results decrypt on read.
  NULL passes through; booleans must throw. Check other documented exceptions
  and raw-query behavior against docs/encryption.md and docs/ai-reference.md.

Intended API, not findings: rawSql() exists to mark developer-trusted SQL and
the `{{column}}` decryption syntax is a documented raw-SQL feature. Their
existence is by design; flag untrusted data flowing into them, not the
methods themselves. Returned SmartArray and SmartString values expose raw
data for logic per those libraries' documented contracts.

Known limitations and finding criteria:

- docs/security-gotchas.md documents interpolated SQL identifiers/grammar
  and balanced empty-quote interpolation. Do not re-report these unchanged
  limitations as new placeholder bypasses. Report a new path through value
  or identifier parameters, a regression, or an unsafe shipping example.
  Apply the same distinction to caller-interpolated {{column}} text, rawSql(),
  and direct mysqli access. Still inspect placeholder lexical contexts:
  comments and partial backtick identifiers can change how bound data parses.
- Connection configuration and direct property/session mutations are not
  automatically attacker-controlled. For tablePrefix, sqlMode, charset
  changes, and internal helpers, identify the supported input boundary and
  prerequisite that creates exposure. Distinguish API hardening from an
  exploit through normal untrusted value/identifier inputs. A minimal library
  reproduction is sufficient for that latter case; no deployed app is needed.
- Encryption deliberately uses deterministic, unauthenticated AES-128-ECB
  for MySQL compatibility and dump-at-rest protection. There is no IV/nonce.
  Equality leakage and ciphertext tampering are documented tradeoffs, not
  new findings by themselves. Server-side @ek setup, including triggering
  from quoted data, and access through the general log/performance_schema
  are documented in docs/encryption.md. Distinguish those known exposures
  from new key leaks, broken redaction, or silent plaintext writes.
- requireSSL promises encryption, not certificate/hostname verification.
  Distinguish that documented limitation from failure to provide the promised
  encryption or examples claiming authenticated transport.
- Raw result access is intentional; trace an actual output context before
  claiming XSS. Unsafe documentation examples remain in scope. Likewise,
  distinguish opt-in query logging and test replay diagnostics from default
  runtime leaks, and check for data outside their documented contracts.
- For resource exhaustion, establish how untrusted input controls work and
  what limits apply. Separate SQL-safe pagination from workload limits;
  large LIMIT/OFFSET is not SQL injection. Do not assume internal schema/test
  utilities are remotely exposed simply because their methods are public.

Use prior findings as leads, not proof against the current checkout. Verify
the current implementation and consolidate repeated manifestations of one
root cause. Separate known documented risks, hardening suggestions, and
confirmed current defects. Documentation is counterevidence, not an exemption:
report contradictions, unsafe recommended usage, and new attack paths with
concrete inputs, source-to-sink evidence, prerequisites, and validation limits.

Prioritize, in order:

1. SQL injection paths that bypass the placeholder system: identifier
   placeholders, the `::` table prefix, `{{column}}` decryption syntax in raw
   SQL, and SQL helpers like `likeContains()` and `pagingSql()`.
2. Encryption implementation defects: key handling, values that skip
   encryption silently, new key/plaintext leaks into logs or error messages.
3. Untrusted input reaching the wire, the filesystem, or error output
   unparameterized or unencoded.
4. Unbounded work on attacker-controlled input: loops whose pass count
   depends on input syntax.
PROMPT

codex-security scan . "${paths[@]}" \
    --model gpt-6-astra \
    --knowledge-base docs/ai-reference.md \
    --knowledge-base docs/security-gotchas.md \
    --knowledge-base docs/encryption.md \
    --knowledge-base docs/placeholders.md \
    --scan-prompt-file "$prompt_file" \
    "$@"
