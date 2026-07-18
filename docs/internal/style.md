# Documentation Style Guide

The shared writing standards for all InteractiveTools libraries (voice,
vocabulary, page structure, code examples, method tables, renderer facts)
live in the team's internal docs repo. This file holds ZenDB-specific
additions only.

- **Tone benchmark:** [Security Gotchas](../security-gotchas.md). When in
  doubt about tone, read a section of that page and match it.
- **Reader assumption:** a working programmer who knows SQL and PHP but not
  this library.
- **Simplest form first, applied here:** positional placeholders before
  named - the quick tool before the scalable one.
- **Show the generated SQL** as an inline comment on every example that
  produces SQL. "this runs:" separates context from SQL, and long SQL moves
  to its own comment line, aligned with the other SQL comments.
- **Colons are ZenDB syntax** (`:name`, `::`, `:::name`), so the
  punctuation-around-inline-code rules bite hardest here: never `` `code`: ``
  mid-sentence, and headings never end with a dash and a bare syntax token
  (write `` ## Table Prefixes in Raw SQL with `::` ``).
