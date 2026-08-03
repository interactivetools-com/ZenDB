# Escape Benchmark Results

No full CI run recorded yet. This file is the committed, citable source for the
escape benchmark suite: after a full dispatch of `escape-matrix.yml` (CPU
family, 30 cells) and `escape-e2e-matrix.yml` (per-server family), paste the
merged grids here with the run IDs and write the verdict sections.

Regenerate:

    gh workflow run escape-matrix.yml
    gh workflow run escape-e2e-matrix.yml

Structure for the first full run (mirrors SmartString's speed-results.md):

- **Adopted** - candidates the shipping code will use, each entry citing test
  ids, the ratio range across cells, and the user-facing claim with mechanism
- **Rejected** - candidates that lost or failed a gate, with the losing cells
- **Measured for reference** - floors, ceilings, and ecosystem-shaped rows
- Full B-vs-A grids (CPU cells, then server cells) with the test legend

Until then, local direction-check results (PHP 8.1 WSL2, MariaDB 11.3, opcache,
xdebug off, 2026-08-02): inline str_replace beats real_escape_string 10-25x on
clean/multibyte 1KB pools and 1.06x on escape-dense pools (quoted-concat sink);
both selftie cells TIE; dispatch ladder named +6ns through __call +35ns;
class-const operand arrays free; shape A guard retains ~91% of the inline win;
one-shot interpolation beats fresh prepared 1.77x; statement-reuse crossover
near N=10; bulk multi-row 3.3x over prepared row-reuse, LOAD DATA fastest.
Local numbers are direction checks, not citable.
