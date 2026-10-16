#!/usr/bin/env bash
# Scan library code with Codex Security. Maintainer tooling: runs locally, not
# in CI, and needs the codex-security CLI installed. Results go to the CLI's
# state dir; view them with: codex-security scans list
# Uses gpt-6-astra with the CLI's default reasoning effort and scan limits.
# Additional CLI flags can be passed as arguments. Run one scan per repo at a
# time; scans of different repos can run side by side.
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

# The scan prompt is kept here (written to a temp file at runtime) so the repo
# needs no scratch file. It says what the library promises and lists the
# documented decisions; without that, the scanner reports rawSql(), {{column}}
# and the other developer-trusted routes as findings instead of tracing what
# flows into them. It asks for a harmless marker row, not a working
# demonstration: workers turn attack vocabulary into attack payloads, and
# OpenAI's content check refuses those workers mid-scan.
prompt_file=$(mktemp)
trap 'rm -f "$prompt_file"' EXIT
cat > "$prompt_file" <<'PROMPT'
This is a whole-library pre-release scan of ZenDB, a PHP 8.1+ mysqli database
layer. tests/ and vendor/ are excluded on purpose. Review runtime source,
documentation examples, and maintainer tooling (.github/) in their actual
contexts; the tooling runs on a developer machine or a CI runner, never in a
deployed web application. Assess the current checkout for release readiness.
Calibrate severity to the evidence; not every finding is a release blocker.

What the library promises

- Values passed through placeholders (?, :name) stay values: ZenDB escapes
  and quotes them client-side and sends completed SQL. Not using server-side
  prepared statements is a design choice, not a finding.
- Table and column arguments, array keys, and backtick placeholders (`?`,
  `:name`, `::?`, `:::name`) accept only identifier characters and throw on
  anything else.
- SQL template text is written by the developer and trusted. The template
  guard catches common mistakes; it is not a SQL parser and does not track
  where interpolated text came from.
- With a nonempty `encryptionKey`, insert() and update() encrypt values
  assigned to MEDIUMBLOB columns in PHP, and query results decrypt on read.
  NULL passes through; booleans throw. docs/encryption.md and
  docs/ai-reference.md list the other cases.
- The password and encryption key stay out of var_dump() output, stack
  traces and the query logger.

What to look for

A place where the code does not keep one of those promises. The likely places:

- A bound value that changes the structure of the query instead of staying a
  value: a placeholder the replacement reads in the wrong context (inside a
  comment, next to the `::` prefix), an escaping assumption that does not
  hold in some connection state, or a helper such as likeContains() or
  pagingSql() whose output a value can reach.
- A value written to a MEDIUMBLOB column without being encrypted while a key
  is set, or a read that returns ciphertext or the wrong plaintext: column
  detection, the column cache, case, schema changes, connection cloning.
- The password, the encryption key, or the plaintext of an encrypted value
  reaching an error message, a stack trace argument, a log callback or page
  output.
- Work that grows faster than the input on caller-supplied text: a loop whose
  pass count depends on input syntax, or a regex that backtracks. And a
  preg_* call that returns false or null past the PCRE limits where that
  result lets something through.
- A documentation example that shows unsafe usage or contradicts the code.

For each, give the promise that does not hold, the code path from the input
to where it goes wrong, and the prerequisites. Show the gap with the smallest
harmless input: a value that makes the query return one extra marker row
(SELECT 'zendb-marker'), or a test value that ends up stored as plaintext.
Do not write queries that read other tables, credentials or real data; a
working demonstration is not wanted and not needed.

Do not report any of these, not as a finding, a note, or a hardening
suggestion. They are documented decisions, and time spent on them is wasted:

- rawSql(), `{{column}}` names in raw SQL, and direct mysqli access are
  developer-trusted routes. Report a shipping example that passes untrusted
  data into them, not their existence.
- Developer code that builds SQL template text from untrusted input:
  interpolated identifiers or keywords, or a value inside developer-written
  quotes (including balanced empty-quote pairs). docs/security-gotchas.md
  covers this; the template guard is not meant to stop it.
- A placeholder inside part of a backtick name (`prefix_:name`). Only a
  backtick placeholder that is the whole name is supported.
- Connection config and properties (sqlMode, tablePrefix, the queryLogger
  callback, requireSSL) are written by the developer, not by an attacker;
  being interpolated or writable is not a finding. A SET NAMES in a template
  is the developer's choice too; set_charset() refuses anything but utf8mb4.
- Encryption is AES-128-ECB, deterministic and unauthenticated, with no IV,
  for MySQL compatibility. Equal plaintexts give equal ciphertext, and anyone
  who can write to the database can change ciphertext. Server-side @ek setup,
  including setup that quoted data triggers, and the key's visibility in
  MySQL's general log and performance_schema are in docs/encryption.md.
- requireSSL encrypts the connection; it does not verify the server's
  certificate or hostname.
- PHP 8.1 ignores #[\SensitiveParameter]; trace redaction applies on 8.2+.
- Large LIMIT/OFFSET values and other workload size; the application sets
  those limits.
- Time and memory that grow in step with the input, and the generated-column
  normalization of SHOW CREATE TABLE output, which comes from the
  developer's own schema.
- The full SQL in the replay wrapper's corpus-miss exception, and the query
  logger receiving the request URI: both are developer tools.
- CI testing against the current main branches of SmartArray and SmartString.
- Returned SmartArray and SmartString values expose raw data for logic; trace
  an actual output context before calling something XSS.

Use prior findings as leads, not proof. Verify the current implementation,
consolidate one root cause reported several ways, and calibrate severity to
what a caller-supplied value can actually do.
PROMPT

codex-security scan . "${paths[@]}" \
    --model gpt-6-astra \
    --knowledge-base docs/ai-reference.md \
    --knowledge-base docs/security-gotchas.md \
    --knowledge-base docs/encryption.md \
    --knowledge-base docs/placeholders.md \
    --knowledge-base docs/internal/design-decisions.md \
    --scan-prompt-file "$prompt_file" \
    "$@"
