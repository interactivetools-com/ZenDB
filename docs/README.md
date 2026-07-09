# ZenDB Documentation

Guides and reference for ZenDB, a PHP/MySQL database library that's easy to use
and hard to misuse. New to ZenDB? Read the first six pages in order; each builds
on the one before. The rest are standalone: open whichever matches your task.

**The basics** (read in order)

1. [Getting Started](getting-started.md) - Install, connect, and fetch your first rows.
2. [Querying Data](querying-data.md) - WHERE conditions, sorting, and pagination with `select()`, `selectOne()`, and `count()`.
3. [Working with Results](working-with-results.md) - Result sets, rows, and values: HTML-safe output by default, raw access when you need it.
4. [Modifying Data](modifying-data.md) - `insert()`, `update()`, and `delete()`, plus SQL expressions like `NOW()`.
5. [Placeholders](placeholders.md) - Every placeholder type and when to use each.
6. [Joins and Custom SQL](joins-and-custom-sql.md) - Full SQL with `query()` and `queryOne()`, keeping the same safety guarantees.

**Everyday use**

- [Common Patterns](common-patterns.md) - Copy-paste recipes: record-or-404, search filters, paginated lists.
- [Helpers and Utilities](helpers-and-utilities.md) - Raw SQL expressions, pagination SQL, LIKE pattern builders, table prefix conversion.

**Advanced setup**

- [Multiple Connections](multiple-connections.md) - Connecting to more than one database, or one database with different settings.
- [Encryption](encryption.md) - Automatic column encryption with `encryptionKey`.

**Lookup**

- [Security Gotchas](security-gotchas.md) - The narrow cases that still let you write an unsafe query, and the safe form for each.
- [Troubleshooting](troubleshooting.md) - Exception messages explained, connection problems, behavior gotchas, debugging.
- [Method Reference](method-reference.md) - Every method, parameter, and return type in one table.
- [AI Reference](ai-reference.md) - The complete API in one dense file, written for AI coding assistants.

---

[← Back to main README](../README.md)
