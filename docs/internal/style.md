# Documentation Style Guide

How to write ZenDB documentation: the voice, the structure, and the rules for
code examples. This is a communication and tone guide first, a formatting guide
second. It exists so every page sounds like the same person wrote it, no matter
who (or what) actually did.

The in-repo benchmark is [Security Gotchas](../security-gotchas.md). When in
doubt about tone, read a section of that page and match it.

## Voice and Tone

**Talk like a scientist.** Plain verbs, literal descriptions, concrete numbers.
Say exactly what happens: "throws InvalidArgumentException", "returns an empty
SmartArrayHtml", "stops at the first match". Precision is the baseline because
readers act on what we write; a vague sentence becomes a bug in their code.

**A little personality is allowed when it earns its keep.** A light metaphor
that names a concept ("guardrails", "gotcha", "blind spot") is fine; it gives
the reader a handle to remember the idea by. A dry aside is fine. The limit is
roughly one per section, and the test is: does the metaphor need a second
sentence to explain itself? If yes, cut it. We name concepts with metaphors; we
never explain mechanisms with them. See [Vocabulary](#vocabulary) for the
approved and banned lists.

**Talk to a smart colleague.** Assume the reader is a working programmer who
knows SQL and PHP but not this library. Never make them feel slow. When
documenting a mistake, normalize it ("easy to mix up", "a common thing to
write") rather than scolding.

**No hype, but confidence is welcome.** No "blazing fast", "powerful",
"revolutionary", "seamless". We think this library is great and the docs can
say so, as long as every positive claim ships with the mechanism or number that
makes it true. "SQL injection is impossible by design: quotes, standalone
numbers, and hex literals are rejected before the query runs" is a brag that
happens to be a spec. "World-class security" with nothing attached is hype.

**Be honest about limits.** Nothing builds trust faster than saying plainly
where the protection ends, what the tradeoffs are, and what the library will
not do for you. The Encryption Threat Model section in Security Gotchas is the
model: state the design choice, state what it costs, state what to do if you
need more.

## Vocabulary

Three tests decide whether a term is usable:

1. **The decode test.** Would a non-native English speaker, or a programmer
   outside a particular subculture, get it on first read? Terms that only work
   for readers who already know the slang fail, no matter how apt they are.
2. **No injury imagery.** Nothing that evokes weapons, blades, or explosions.
   The docs describe a database library, not a hazard site.
3. **Established terms pass.** A standard engineering term with a precise,
   widely taught meaning is fine even when it started as an idiom: "defense in
   depth", "fail fast", "hot path".

**Use freely:**

- safe by default, guardrails, safety net, injection-proof by design,
  impossible by design
- gotcha, pitfall, trap, quirk, edge case, blind spot, gap
- deny by default, allowlist, fail fast, fail loud, defense in depth
- opinionated, minimal ceremony, no magic, does the right thing, thin layer
- principle of least surprise, syntactic sugar
- hot path, fast path, battle-tested
- escape hatch (a deliberate exit from the protection, not a hole in the
  design), raw access, direct access, opt out

**Do not use:**

- footgun, pit of success, dog-fooding: fail the decode test. Say what they
  mean instead ("ways to write an unsafe query", "designed so the easy way is
  the right way", "we run it in production on our own sites").
- sharp edges, landmine: injury imagery.
- belt and suspenders, batteries included, happy path: read as filler idiom;
  say the concrete thing instead ("the normal path", "the default path").

## What to Document

**Document the edges of the protection, not mistakes the library makes
impossible.** If writing the wrong form throws the first time it runs, there is
nothing to warn about; the exception is the documentation. Spend the words on
the narrow cases that pass silently, because those are the ones that reach
production.

**Explain the mental model once, early.** One short section on how to think
about the library ("method names are SQL statements", "values only enter
through placeholders") makes the whole API predictable. Say it once, then let
the reader predict the rest. Don't re-explain it on every page.

**Organize by task, not by API.** Readers arrive with a goal ("how do I count
rows?"), not a method name. Headings name the task, with the method in the
heading when there is one: `## Counting Rows - DB::count()`.

**Progressive disclosure.** Common case first, edge cases after, deeper topics
linked rather than inlined. A reader should be able to stop reading at any
point and have correct, if incomplete, knowledge.

**Write for CMS Builder readers too.** They're the largest audience. Early
pages get skip-notes where CMS Builder already did the step (install,
connect), and CMSB tools get mentioned next to generic ones where natural
(`showme()` beside `print_r()`).

**Split big tables.** Separate common rows from advanced ones, label rare rows
as rare, and say what they exist for. A sentence that introduces a table ends
with a period, not a colon.

## Page Structure

- Open with 1-3 sentences stating what the page covers. Just say what it
  explains; not "this guide walks you through".
- Common case first, then variations, then edge cases.
- End with prev/next navigation links back to the README and adjacent pages.

## Code Examples

Code examples are the load-bearing element. Most readers scan the prose and
read the code, so the examples have to stand on their own.

**Every example is copy-paste runnable.** Real table names, real column names,
real values. No `...` gaps, no `$yourVariable` placeholders, no pseudo-code.

**Simplest form first.** Open with the plainest call that does the job;
variations and advanced forms come after. Positional placeholders before named
(the quick tool before the scalable one), common arguments before rare ones.

**Show result shapes in comments.** When a call returns a structure, show it:
`$names = $users->pluck('name'); // ['Alice', 'Bob', ...]`.

**Realistic inputs, no redundant hardening.** Use inputs as they actually
arrive (`$_GET['page'] ?? 1`), and don't add casts or clamping the library
already does. Verify whether it does before writing it.

**Name ambiguous arguments.** When an argument list doesn't read on its own,
break it into named variables first (`$newValues`, `$where`) instead of
passing inline arrays.

**Show the generated SQL as an inline comment.** The single highest-trust move
in a database library's docs: show what the call produces.

```php
$users = DB::select('users', ['status' => 'Active', 'city' => 'Vancouver']);
// WHERE `status` = 'Active' AND `city` = 'Vancouver'
```

When the SQL depends on context (a config setting, a specific input), state
the context in the comment and end it with `this runs:` so the reader can see
where the sentence stops and the SQL starts. One line when it fits, otherwise
the SQL goes on its own comment line:

```php
// WRONG - context and SQL blur into one unmarked line
// With tablePrefix 'cms_': SELECT * FROM cms_orders WHERE total > 100

// RIGHT - "this runs:" marks the boundary
// with tablePrefix 'cms_' this runs: SELECT * FROM cms_orders WHERE total > 100

// RIGHT - long SQL moves to its own line, aligned with every other SQL comment
// with tablePrefix 'cms_' this runs:
// SELECT * FROM cms_orders LEFT JOIN cms_users ON cms_orders.userId = cms_users.id
```

**Use WRONG/RIGHT pairs for real mistakes.** Wrong form first, right form
second, both runnable, each labeled with a comment saying what happens:

```php
// Deprecated -- runs as IN (1) and logs a warning
DB::select('users', "id IN (?)", [1, 2, 3]);

// Use a named placeholder instead
DB::select('users', "id IN (:ids)", [':ids' => [1, 2, 3]]);
```

Only pair forms the reader could actually write and ship. If the wrong form
throws immediately on first use, show it once as "this throws" and move on;
don't build a lesson around a mistake the library already catches.

**Error messages verbatim as headings.** In troubleshooting pages, the exact
exception text is the heading, because that is what readers paste into search.
Follow each with "What happened" and "Fix".

**Verify every example against current `src/` before a page ships.** A doc that
lies is worse than no doc, because the reader trusts it over their own reading
of the code. When the API changes, the examples are part of the change.

## Punctuation Around Inline Code

Colons are ZenDB syntax (`:name`, `::`, `:::name`), so punctuation rendered
against a code chip can read as part of the token. Three rules keep prose and
code visually separate:

**Mid-sentence, never `` `code`: `` + clause.** Start a new sentence instead.
If two sentences genuinely don't work, reorder so a plain word carries the
colon.

- WRONG: "...with the same protections as `select()`: the template guard
  still rejects quotes."
- RIGHT: "...with the same protections as `select()`. The template guard
  still rejects quotes."
- Fallback: "...with the same protections `select()` has: the template guard
  still rejects quotes."

**Before a code block, end the intro sentence on a plain word when a natural
one exists.** The block break keeps a trailing `` `code`: `` readable, so
this is a preference, not a ban; never contort the sentence to comply, and
never trade the colon for a period (the colon is what ties the sentence to
the block).

- BETTER: "...accepts the same WHERE forms as `select()` does:"
- ACCEPTABLE: "...accepts the same WHERE forms as `select()`:"

**Headings never end with a dash and a bare syntax token.** Method and config
names read fine after the dash (`` ## Updating Rows - `DB::update()` ``);
punctuation-like tokens (`::`, `{{column}}`, `::?`) don't, so write those
into the phrase. Parentheses stay fine when the heading names the token
itself.

- WRONG: `` ## Table Prefixes in Raw SQL - `::` ``
- RIGHT: `` ## Table Prefixes in Raw SQL with `::` ``
- ALSO RIGHT: `` ## Positional Placeholders (`?`) `` (the token is the
  subject being named)

## Mechanics

- No em-dashes or en-dashes anywhere. Use a hyphen, comma, colon, parentheses,
  or restructure the sentence.
- The rightwards arrow `→` is allowed for transformations and navigation paths:
  `O'Brien → "O\'Brien"`.
- Backticks for every identifier, method, and config key in prose:
  `DB::select()`, `tablePrefix`. Bare SQL keywords in running prose (SELECT,
  WHERE, LIMIT) need no backticks; use them when quoting a literal SQL
  fragment.
- Tables for option and method references. Prose for concepts. Never a table
  where two sentences would do.
- Headings in Title Case. Method names keep their real casing.
- Bold for the one term being defined or the one word carrying the warning,
  not for decoration.

## The Three Audiences

Each artifact serves one reader. Don't blend them.

| Artifact                 | Reader                | Job                                                        |
|--------------------------|-----------------------|------------------------------------------------------------|
| `README.md`              | The evaluator         | What it is, why it's different, 30-second demo             |
| `docs/` guide pages      | The learner           | Task-oriented guides, tutorials, troubleshooting           |
| `docs/ai-reference.md`   | AI coding assistants  | Everything needed to write correct code, one dense file    |

The AI reference gets no narrative, no encouragement, no progressive
disclosure: complete coverage, tight examples, stated constraints. The guides
get the voice described above. The README gets the strongest three claims we
can back up with code, and nothing we can't.

## Reference Points

External guides worth consulting when a question isn't covered here:

- **Diátaxis** (diataxis.fr) - the tutorial / how-to / reference / explanation
  split behind "The Three Audiences" above.
- **Google Developer Documentation Style Guide** - second person, present
  tense, active voice, one idea per sentence.
- **Stripe's API docs** - the standard for runnable examples and progressive
  disclosure.

Where any of these conflict with this guide, this guide wins.
