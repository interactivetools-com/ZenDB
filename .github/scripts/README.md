# CI Scripts

PHP scripts run by the workflows in [`../workflows/`](../workflows/). Nothing
here is part of the library. Each script's header docblock has its full usage;
this is the map.

**Test timing** (`tests.yml`) - times the suite across every PHP x database job
and builds a grid for the run-summary page.

- `ci-timing.php` - parse one job's JUnit XML into a timing JSON
- `ci-timing-summary.php` - merge those JSONs into the PHP x database grid

**DB behavior matrix** (`db-behavior-matrix.yml`) - probes every database image
with plain mysqli (not ZenDB, so the library's own fixes can't hide what the
server returns) and merges the results into
[docs/internal/db-behavior-matrix.md](../../docs/internal/db-behavior-matrix.md).

- `db-behavior-probe.php` - probe one server, print markdown (and optional JSON)
- `db-behavior-merge.php` - merge per-server JSONs into one "who differs" report

**Shared code**

- `ci-lib.php` - helpers used by the scripts above (server sort order); `require`-only, no entry point
