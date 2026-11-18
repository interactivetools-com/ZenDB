#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Compare the utf8mb4 collations on one database server: which ones it has, which one a
 * new table gets, how fast each one sorts and searches, what converting a table costs,
 * what fails when two tables have different collations, and which values compare equal.
 *
 *     php .github/scripts/collation-probe.php --json=probe.json           # run the checks on one server
 *     php .github/scripts/collation-probe.php --bench --json=probe.json   # the checks, then the speed test
 *     php .github/scripts/collation-probe.php --bench-only --rows=5000    # a short speed test, no checks
 *     php .github/scripts/collation-probe.php --bench-only --only='whole table|List page'   # only the statements whose name matches
 *     php .github/scripts/collation-probe.php --bench-only --titles=titles.txt               # one title per line in place of the generated ones
 *     php .github/scripts/collation-probe.php --merge probes/*.json       # one report for every server
 *
 * CMS Builder 3.84 created every table with COLLATE=utf8mb4_unicode_ci. Later versions
 * write CHARSET=utf8mb4 with no COLLATE, so a new table gets the server's default utf8mb4
 * collation, and an upgraded site has tables with two collations. This probe records
 * what each server does, so the choice between "the server default everywhere",
 * "utf8mb4_unicode_ci everywhere" and "copy the existing tables" is made on measurements.
 *
 * The CI workflow (.github/workflows/collation-matrix.yml) runs this against every
 * database image in the matrix.
 *
 * Every table is named zdbcoll_*. With DB_DATABASE set they're created in that database
 * and dropped on exit, and the checks that need their own database report n/a. Without
 * it the probe creates and drops the zdb_collation_probe, zdb_collation_probe_uni and
 * zdb_collation_probe_latin1 databases. Uses plain mysqli, so it needs no composer
 * install. Connects with the same DB_* env vars as the test suite (see phpunit.xml.dist).
 * DB_LABEL names the server in the JSON output, e.g. "mariadb:10.6".
 */

require __DIR__ . '/ci-lib.php';

$opts = getopt('', ['json:', 'merge', 'bench', 'bench-only', 'rows:', 'passes:', 'only:', 'titles:'], $restIndex);
if (isset($opts['merge'])) {
    mergeReports(array_slice($argv, $restIndex));
    exit;
}

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$hostname = (getenv('DB_HOSTNAME') ?: '127.0.0.1') . (getenv('DB_PORT') ? ':' . getenv('DB_PORT') : '');
$username = getenv('DB_USERNAME') ?: 'root';
$password = getenv('DB_PASSWORD') ?: '';
$database = getenv('DB_DATABASE') ?: '';
$label    = getenv('DB_LABEL') ?: 'unlabeled';
$jsonPath = $opts['json'] ?? null;
$scratch  = $database === '' ? 'zdb_collation_probe' : null; // only created and dropped when no database was named

$mysqli = new mysqli($hostname, $username, $password);
$mysqli->set_charset('utf8mb4');
if ($scratch !== null) {
    foreach ([$scratch, "{$scratch}_uni", "{$scratch}_latin1"] as $name) {
        $mysqli->query("DROP DATABASE IF EXISTS `$name`");
    }
    $mysqli->query("CREATE DATABASE `$scratch` CHARACTER SET utf8mb4");
    $mysqli->query("CREATE DATABASE `{$scratch}_uni` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $mysqli->query("CREATE DATABASE `{$scratch}_latin1` CHARACTER SET latin1");
    $database = $scratch;
}
$mysqli->select_db($database);

// A second connection to the same server, for the checks that change session settings or need two sessions
$connect = static function (?string $otherDatabase = null) use ($hostname, $username, $password, $database): mysqli {
    $db = new mysqli($hostname, $username, $password, $otherDatabase ?? $database);
    $db->set_charset('utf8mb4');
    return $db;
};

$probe  = new CollationProbe($mysqli, 'zdbcoll_', $connect, $scratch);
$report = [
    'server'  => $label,
    'version' => $mysqli->query("SELECT VERSION()")->fetch_row()[0],
    'default' => $probe->default,
    'known'   => $probe->known,
    'facts'   => &$probe->facts,
    'grids'   => &$probe->grids,
    'bench'   => &$probe->bench,
];

// Drop the tables and write the JSON on every exit path, including a fatal partway
// through, so a broken probe still reports what it collected
register_shutdown_function(static function () use ($mysqli, $probe, $scratch, $jsonPath, &$report) {
    try {
        $probe->dropTables();
        if ($scratch !== null) {
            foreach ([$scratch, "{$scratch}_uni", "{$scratch}_latin1"] as $name) {
                $mysqli->query("DROP DATABASE IF EXISTS `$name`");
            }
        }
    } catch (Throwable $e) {
        fwrite(STDERR, "Could not drop the probe tables: {$e->getMessage()}\n");
    }
    if ($jsonPath !== null) {
        file_put_contents($jsonPath, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR) . "\n");
        fwrite(STDERR, "Wrote " . count($report['facts']) . " facts and " . count($report['grids']) . " grids to $jsonPath\n");
    }
});

$probe->dropTables();
if (!isset($opts['bench-only'])) {
    $probe->defaults();
    $probe->convertRules();
    $probe->mixedCollations();
    $probe->results();
}
if (isset($opts['bench']) || isset($opts['bench-only'])) {
    $probe->benchmark((int)($opts['rows'] ?? 100_000), (int)($opts['passes'] ?? 5), (string)($opts['only'] ?? ''), (string)($opts['titles'] ?? ''));
}

echo "## $label ({$report['version']})\n\n";
echo "- default utf8mb4 collation: $probe->default\n";
foreach ($probe->facts as $name => $value) {
    echo "- $name: $value\n";
}
foreach ($probe->grids as $name => $rows) {
    echo "- $name: " . count($rows) . " rows\n";
}
foreach ($probe->bench['ops'] ?? [] as $op => $arms) {
    echo "- speed, $op: " . implode('; ', array_map(static fn($arm, $ms) => "$arm " . formatMs(median($ms)), array_keys($arms), $arms)) . "\n";
}

/**
 * The check needs something this server doesn't have. Recorded as "n/a" with the reason.
 */
final class NotApplicable extends RuntimeException
{
}

/**
 * The checks and the speed test for one server. Each public method is one section of the report.
 *
 * In SQL passed to this class, `::` before a table name becomes the table prefix.
 */
final class CollationProbe
{
    /**
     * The collations compared, fastest expected first. `utf8mb4_bin` is the floor: it compares bytes.
     */
    public const COLLATIONS = ['utf8mb4_bin', 'utf8mb4_general_ci', 'utf8mb4_unicode_ci', 'utf8mb4_unicode_520_ci', 'utf8mb4_0900_ai_ci', 'utf8mb4_uca1400_ai_ci'];

    /**
     * ZenDB's default `sqlMode`, which CMS Builder runs under.
     */
    public const STRICT = 'STRICT_ALL_TABLES,NO_ZERO_IN_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION';

    /**
     * Pairs of values for section 5: label => [left, right].
     */
    public const PAIRS = [
        "'abc' and 'abc ' (trailing space)"                         => ['abc', 'abc '],
        "'' and ' ' (empty string and one space)"                   => ['', ' '],
        "'a' and 'A'"                                               => ['a', 'A'],
        "'cafe' and 'café'"                                         => ['cafe', 'café'],
        "'é' (U+00E9) and 'e' + U+0301 (combining accent)"          => ["\u{E9}", "e\u{301}"],
        "'ß' and 'ss'"                                              => ['ß', 'ss'],
        "'ß' and 's'"                                               => ['ß', 's'],
        "'æ' and 'ae'"                                              => ['æ', 'ae'],
        "'œ' and 'oe'"                                              => ['œ', 'oe'],
        "'ø' and 'o'"                                               => ['ø', 'o'],
        "'å' and 'a'"                                               => ['å', 'a'],
        "'ä' and 'a'"                                               => ['ä', 'a'],
        "'ä' and 'ae'"                                              => ['ä', 'ae'],
        "'ñ' and 'n'"                                               => ['ñ', 'n'],
        "'ł' and 'l'"                                               => ['ł', 'l'],
        "'đ' and 'd'"                                               => ['đ', 'd'],
        "'ð' and 'd'"                                               => ['ð', 'd'],
        "'þ' and 'th'"                                              => ['þ', 'th'],
        "'i' and 'İ' (U+0130, Turkish dotted capital I)"            => ['i', "\u{130}"],
        "'I' and 'ı' (U+0131, Turkish dotless i)"                   => ['I', "\u{131}"],
        "'i' and 'ı' (U+0131)"                                      => ['i', "\u{131}"],
        "'I' and 'İ' (U+0130)"                                      => ['I', "\u{130}"],
        "'σ' and 'ς' (Greek sigma and final sigma)"                 => ['σ', 'ς'],
        "'е' and 'ё' (Cyrillic)"                                    => ['е', 'ё'],
        "'か' and 'カ' (hiragana and katakana)"                       => ['か', 'カ'],
        "'か' and 'が' (with and without the voicing mark)"            => ['か', 'が'],
        "'Ａ' (U+FF21, fullwidth) and 'A'"                           => ["\u{FF21}", 'A'],
        "'ﬁ' (U+FB01, ligature) and 'fi'"                           => ["\u{FB01}", 'fi'],
        "'Å' (U+00C5) and 'Å' (U+212B, angstrom sign)"              => ["\u{C5}", "\u{212B}"],
        "'ab' and 'a' + U+200B (zero width space) + 'b'"            => ['ab', "a\u{200B}b"],
        "'ab' and 'a' + U+00AD (soft hyphen) + 'b'"                 => ['ab', "a\u{AD}b"],
        "'中' and '文' (two Chinese characters)"                      => ['中', '文'],
        "'😀' and '😁' (two emoji)"                                   => ["\u{1F600}", "\u{1F601}"],
        "'🍣' and '🍺' (two emoji)"                                   => ["\u{1F363}", "\u{1F37A}"],
        "'😀' and '🥲' (U+1F972, added in Unicode 13)"                => ["\u{1F600}", "\u{1F972}"],
        "'🥲' and '🫠' (added in Unicode 13 and 14)"                  => ["\u{1F972}", "\u{1FAE0}"],
        "'😀' and '' (an emoji and the empty string)"                => ["\u{1F600}", ''],
        "'😀' and U+FFFD (the replacement character)"                => ["\u{1F600}", "\u{FFFD}"],
        "'a😀' and 'a😁'"                                             => ["a\u{1F600}", "a\u{1F601}"],
    ];

    /**
     * Values sorted under each collation in section 5.
     */
    public const SORT_SAMPLE = [
        'a', 'A', 'b', 'B', 'z', 'Z', 'ä', 'Ä', 'å', 'æ', 'ae', 'af', 'ad', 'az', 'ö', 'o', 'oe', 'of', 'ø', 'p', 'ß', 'ss', 'st', 'sr', 'é', 'e', 'f', 'cote', 'côte', 'coté', 'côté',
        'ñ', 'n', 'nz', 'ł', 'l', 'lz', 'ı', 'i', 'İ', 'I', 'j', 'a b', 'a-b', 'ab', 'a_b', '_a', '-a', '10', '9', '2', 'Ω', 'я', 'Я', '中', 'あ', 'ア', '😀', '😁', '🥲',
    ];

    /** @var array<string, string> name => answer, in the order the checks ran */
    public array $facts = [];

    /** @var array<string, array<string, array<string, array{0: string, 1: string}>>> grid name => row => column => [short answer, detail] */
    public array $grids = [];

    /** @var array<string, mixed> the speed test's settings and numbers */
    public array $bench = [];

    /** @var array<string, string> collation => the name the server stores it under, for the collations it accepts */
    public array $known = [];

    /** @var array<string, string> collation => the error for the collations it refuses */
    public array $unknown = [];

    /**
     * The collation a CREATE TABLE ... CHARSET=utf8mb4 table gets on this server as it starts.
     */
    public string $default = '';

    /** @var array<string, array<string, bool>> pair label => collation => equal, filled by results() */
    private array $equal = [];

    private int $section = 0;
    private int $itemNum = 0;

    /**
     * @param mysqli      $db      Connection with the probe's database selected
     * @param string      $prefix  Start of every table name the probe creates
     * @param Closure     $connect Opens another connection; takes an optional database name
     * @param string|null $scratch The probe's own database, or null when it runs in a database it didn't create
     */
    public function __construct(private mysqli $db, private string $prefix, private Closure $connect, private ?string $scratch)
    {
        $this->exec("SET SESSION sql_mode = '" . self::STRICT . "'");
        try {
            $this->exec("SET SESSION explain_format = 'TRADITIONAL'"); // MySQL 9 prints a tree by default; the checks read the table/type/key columns
        } catch (mysqli_sql_exception) {
            // servers without the setting print the columns already
        }
        foreach (self::COLLATIONS as $collation) {
            try {
                $this->exec("DROP TABLE IF EXISTS ::known");
                $this->exec("CREATE TABLE ::known (c VARCHAR(10)) CHARSET=utf8mb4 COLLATE=$collation");
                $this->known[$collation] = $this->columnCollation('known', 'c');
            } catch (mysqli_sql_exception $e) {
                $this->unknown[$collation] = "error {$e->getCode()}: {$e->getMessage()}";
            }
        }
        $this->exec("DROP TABLE IF EXISTS ::known");
        $this->default = $this->newTableCollation($this->db, 'CHARSET=utf8mb4');
    }

    //region Section 1: what each server does by default

    /**
     * Which collations the server has, which one a new table gets, and which query reports that.
     */
    public function defaults(): void
    {
        $this->startSection(1);

        foreach (self::COLLATIONS as $collation) {
            $this->fact("CREATE TABLE ... CHARSET=utf8mb4 COLLATE=$collation", function () use ($collation) {
                $this->exec("DROP TABLE IF EXISTS ::known");
                $this->exec("CREATE TABLE ::known (c VARCHAR(10)) CHARSET=utf8mb4 COLLATE=$collation");
                $warnings = $this->warnings();
                $stored   = $this->columnCollation('known', 'c');
                return 'accepted' . ($stored !== $collation ? ", stored as $stored" : '') . ($warnings !== '' ? "; $warnings" : '');
            });
        }
        $this->exec("DROP TABLE IF EXISTS ::known");

        // A default can come from the image's config file and not from the server binary, so record both
        $this->fact('Settings as the image starts', function () {
            $settings = [];
            foreach (['character_set_server', 'collation_server', 'character_set_collations', 'default_collation_for_utf8mb4'] as $variable) {
                try {
                    $settings[] = "$variable = " . ($this->value("SELECT @@global.$variable") ?: '(empty)');
                } catch (mysqli_sql_exception) {
                    $settings[] = "$variable: no such variable";
                }
            }
            return implode('; ', $settings);
        });
        $this->fact("Charset and collation lines in the image's config files", static fn() => getenv('SERVER_CONFIG') === false ? throw new NotApplicable('SERVER_CONFIG was not set') : (trim(preg_replace('/\s*\n\s*/', '; ', trim(getenv('SERVER_CONFIG')))) ?: 'none'));
        $this->fact('CREATE TABLE ... CHARSET=utf8mb4 with no COLLATE: the collation the table gets', fn() => $this->newTableCollation($this->db, 'CHARSET=utf8mb4'));
        $this->fact('CREATE TABLE with no CHARSET, in a database created with CHARACTER SET utf8mb4 and no COLLATE', fn() => $this->scratch === null ? throw new NotApplicable('the probe ran in a database it did not create') : $this->newTableCollation($this->db, ''));
        $this->fact('A column declared VARCHAR(10) CHARACTER SET utf8mb4 with no COLLATE, in a utf8mb4_unicode_ci table', function () {
            $this->exec("DROP TABLE IF EXISTS ::dflt");
            $this->exec("CREATE TABLE ::dflt (a VARCHAR(10), b VARCHAR(10) CHARACTER SET utf8mb4) CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
            return "the column with no charset gets {$this->columnCollation('dflt', 'a')}, the column with CHARACTER SET utf8mb4 gets {$this->columnCollation('dflt', 'b')}";
        });
        $this->fact('ALTER TABLE ... CONVERT TO CHARACTER SET utf8mb4 with no COLLATE, on a latin1 table', fn() => $this->convertedCollation($this->db, 'latin1', 'latin1_swedish_ci'));
        $this->fact('ALTER TABLE ... CONVERT TO CHARACTER SET utf8mb4 with no COLLATE, on a utf8mb4_unicode_ci table', fn() => $this->convertedCollation($this->db, 'utf8mb4', 'utf8mb4_unicode_ci'));

        // Each state is a fresh connection with one thing changed. The first two rows are what
        // the server does; the rows after them are the queries that might report it
        $states = [
            'as the image starts'                                                                     => static fn() => null,
            'database default is utf8mb4_unicode_ci'                                                  => fn(mysqli $db) => $this->scratch === null ? throw new NotApplicable('the probe ran in a database it did not create') : $db->select_db("{$this->scratch}_uni"),
            'database default is latin1'                                                              => fn(mysqli $db) => $this->scratch === null ? throw new NotApplicable('the probe ran in a database it did not create') : $db->select_db("{$this->scratch}_latin1"),
            'SET SESSION collation_server = utf8mb4_unicode_ci'                                       => static fn(mysqli $db) => $db->query("SET SESSION collation_server = 'utf8mb4_unicode_ci'"),
            'SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci'                                            => static fn(mysqli $db) => $db->query("SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci"),
            'SET SESSION default_collation_for_utf8mb4 = utf8mb4_general_ci (MySQL 8.0+)'             => static fn(mysqli $db) => $db->query("SET SESSION default_collation_for_utf8mb4 = 'utf8mb4_general_ci'"),
            "SET SESSION character_set_collations = 'utf8mb4=utf8mb4_unicode_ci' (MariaDB 11.2+)"     => static fn(mysqli $db) => $db->query("SET SESSION character_set_collations = 'utf8mb4=utf8mb4_unicode_ci'"),
        ];
        $one        = static fn(mysqli $db, string $sql, int|string $column = 0): string => (string)($db->query($sql)->fetch_array()[$column] ?? '(no row)');
        $candidates = [
            "SELECT DEFAULT_COLLATE_NAME FROM information_schema.CHARACTER_SETS WHERE CHARACTER_SET_NAME = 'utf8mb4'" => static fn(mysqli $db) => $one($db, "SELECT DEFAULT_COLLATE_NAME FROM information_schema.CHARACTER_SETS WHERE CHARACTER_SET_NAME = 'utf8mb4'"),
            "SHOW CHARACTER SET LIKE 'utf8mb4' (the `Default collation` column)"                                       => static fn(mysqli $db) => $one($db, "SHOW CHARACTER SET LIKE 'utf8mb4'", 'Default collation'),
            "SELECT COLLATION_NAME FROM information_schema.COLLATIONS WHERE CHARACTER_SET_NAME = 'utf8mb4' AND IS_DEFAULT = 'Yes'" => static fn(mysqli $db) => $one($db, "SELECT COLLATION_NAME FROM information_schema.COLLATIONS WHERE CHARACTER_SET_NAME = 'utf8mb4' AND IS_DEFAULT = 'Yes'"),
            "SELECT COLLATION(CONVERT('a' USING utf8mb4))"                                                             => static fn(mysqli $db) => $one($db, "SELECT COLLATION(CONVERT('a' USING utf8mb4))"),
            "SELECT @@default_collation_for_utf8mb4"                                                                   => static fn(mysqli $db) => $one($db, "SELECT @@default_collation_for_utf8mb4"),
            "SELECT @@character_set_collations (the utf8mb4 entry)"                                                    => static fn(mysqli $db) => preg_match('/(?:^|,)utf8mb4=(\w+)/', $one($db, "SELECT @@character_set_collations"), $m) ? $m[1] : '(no utf8mb4 entry)', // "utf8mb3=utf8mb3_uca1400_ai_ci,utf8mb4=utf8mb4_uca1400_ai_ci" → the utf8mb4 one
            "SELECT @@collation_server"                                                                                => static fn(mysqli $db) => $one($db, "SELECT @@collation_server"),
            "SELECT @@collation_database"                                                                              => static fn(mysqli $db) => $one($db, "SELECT @@collation_database"),
            "SELECT @@collation_connection"                                                                            => static fn(mysqli $db) => $one($db, "SELECT @@collation_connection"),
        ];
        $grid = $this->grid('Which query returns the collation a new CHARSET=utf8mb4 table gets');
        foreach ($states as $state => $apply) {
            $db     = null;
            $actual = null;
            $reason = null; // why this state can't be set on this server
            try {
                $db = ($this->connect)();
                $db->query("SET SESSION sql_mode = '" . self::STRICT . "'");
                $apply($db);
                $actual = $this->newTableCollation($db, 'CHARSET=utf8mb4');
            } catch (NotApplicable $e) {
                $reason = ['n/a', $e->getMessage()];
            } catch (mysqli_sql_exception $e) {
                $reason = ['n/a', "error {$e->getCode()}: {$e->getMessage()}"];
            }
            $this->cell($grid, 'CREATE TABLE ... CHARSET=utf8mb4 gets', $state, fn() => $reason ?? $this->orDefault($actual));
            $this->cell($grid, 'CREATE TABLE with no CHARSET gets', $state, fn() => $reason ?? $this->orDefault($this->newTableCollation($db, '')));
            $this->cell($grid, 'CONVERT TO CHARACTER SET utf8mb4 on a utf8mb4_unicode_ci table gives', $state, fn() => $reason ?? $this->orDefault($this->convertedCollation($db, 'utf8mb4', 'utf8mb4_unicode_ci')));
            $this->cell($grid, 'CONVERT TO CHARACTER SET utf8mb4 on a latin1 table gives', $state, fn() => $reason ?? $this->orDefault($this->convertedCollation($db, 'latin1', 'latin1_swedish_ci')));
            foreach ($candidates as $candidate => $ask) {
                $this->cell($grid, $candidate, $state, static function () use ($reason, $ask, $db, $actual) {
                    if ($reason !== null) {
                        return $reason;
                    }
                    $answer = $ask($db);
                    return $answer === $actual ? 'right' : ["wrong", "says $answer, the table got $actual"];
                });
            }
            if ($db !== null) {
                $db->query("DROP TABLE IF EXISTS `{$this->prefix}dflt`");
                $db->close();
            }
        }
    }

    /**
     * Create a table with these table options on this connection and return the collation it got.
     * "utf8mb4_general_ci", or "table utf8mb4_general_ci, column utf8mb4_bin" when the two differ.
     */
    private function newTableCollation(mysqli $db, string $tableOptions): string
    {
        $db->query("DROP TABLE IF EXISTS `{$this->prefix}dflt`");
        $db->query("CREATE TABLE `{$this->prefix}dflt` (c VARCHAR(10)) $tableOptions");
        return $this->collations('dflt', 'c', $db);
    }

    /**
     * Create a table in one charset and collation, run CONVERT TO CHARACTER SET utf8mb4 with no COLLATE, and return what it has afterwards.
     */
    private function convertedCollation(mysqli $db, string $charset, string $collation): string
    {
        $db->query("DROP TABLE IF EXISTS `{$this->prefix}dflt`");
        $db->query("CREATE TABLE `{$this->prefix}dflt` (c VARCHAR(10)) CHARSET=$charset COLLATE=$collation");
        $db->query("INSERT INTO `{$this->prefix}dflt` (c) VALUES ('a'), ('b')");
        $db->query("ALTER TABLE `{$this->prefix}dflt` CONVERT TO CHARACTER SET utf8mb4");
        return $this->collations('dflt', 'c', $db);
    }

    //endregion
    //region Section 3: converting a table

    /**
     * Which ALTER TABLE forms change a collation, with which algorithm, and what stops them.
     */
    public function convertRules(): void
    {
        $this->startSection(3);
        $default = $this->default;
        $columns = "num INT UNSIGNED NOT NULL AUTO_INCREMENT, title VARCHAR(255) NOT NULL, content MEDIUMTEXT NOT NULL, PRIMARY KEY (num)";
        $rows    = "INSERT INTO ::alg (title, content) VALUES " . implode(', ', array_map(static fn($i) => "('title $i', 'content $i')", range(1, 300)));
        $shapes  = [
            'no index on the text columns' => '',
            'index on the VARCHAR column'  => ', KEY title (title)',
        ];
        $clauses = ['(no ALGORITHM or LOCK)' => '', 'ALGORITHM=INSTANT' => ', ALGORITHM=INSTANT', 'ALGORITHM=NOCOPY' => ', ALGORITHM=NOCOPY', 'ALGORITHM=INPLACE' => ', ALGORITHM=INPLACE', 'ALGORITHM=INPLACE, LOCK=NONE' => ', ALGORITHM=INPLACE, LOCK=NONE', 'LOCK=NONE' => ', LOCK=NONE', 'ALGORITHM=COPY, LOCK=NONE' => ', ALGORITHM=COPY, LOCK=NONE', 'ALGORITHM=COPY' => ', ALGORITHM=COPY'];

        // affected rows is the table's row count when the server copied the table, and 0 when it didn't
        $alter = function (string $from, string $keys, string $change, ?string $to = null) use ($columns, $rows) {
            $this->exec("DROP TABLE IF EXISTS ::alg");
            $this->exec("CREATE TABLE ::alg ($columns$keys) ENGINE=InnoDB CHARSET=utf8mb4 COLLATE=$from");
            $this->exec($rows);
            $affected = $this->exec("ALTER TABLE ::alg $change");
            $now      = $this->columnCollation('alg', 'title');
            return "ok, $affected rows" . ($to !== null && $now !== $to ? ", but title is $now" : '');
        };

        $grid = $this->grid('ALTER TABLE ... CONVERT TO CHARACTER SET utf8mb4 COLLATE x on a 300-row table, with each ALGORITHM and LOCK clause');
        foreach ($clauses as $clauseLabel => $clause) {
            foreach ($shapes as $shapeLabel => $keys) {
                $this->cell($grid, $clauseLabel, "$shapeLabel, utf8mb4_unicode_ci to the server default", fn() => $default === 'utf8mb4_unicode_ci' ? throw new NotApplicable('the server default is utf8mb4_unicode_ci') : $alter('utf8mb4_unicode_ci', $keys, "CONVERT TO CHARACTER SET utf8mb4 COLLATE $default$clause", $default));
                $this->cell($grid, $clauseLabel, "$shapeLabel, the server default to utf8mb4_unicode_ci", fn() => $default === 'utf8mb4_unicode_ci' ? throw new NotApplicable('the server default is utf8mb4_unicode_ci') : $alter($default, $keys, "CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci$clause", 'utf8mb4_unicode_ci'));
            }
        }

        $grid = $this->grid('ALTER TABLE ... MODIFY title VARCHAR(255) CHARACTER SET utf8mb4 COLLATE x NOT NULL (one column, utf8mb4_unicode_ci to the server default)');
        foreach ($clauses as $clauseLabel => $clause) {
            foreach ($shapes as $shapeLabel => $keys) {
                $this->cell($grid, $clauseLabel, $shapeLabel, fn() => $default === 'utf8mb4_unicode_ci' ? throw new NotApplicable('the server default is utf8mb4_unicode_ci') : $alter('utf8mb4_unicode_ci', $keys, "MODIFY title VARCHAR(255) CHARACTER SET utf8mb4 COLLATE $default NOT NULL$clause", $default));
            }
        }

        // The form CMS Builder writes: the table default and a MODIFY for each text column, so a column left out keeps its collation
        $modifyAll = static fn(string $to) => "DEFAULT CHARACTER SET utf8mb4 COLLATE $to, MODIFY title VARCHAR(255) CHARACTER SET utf8mb4 COLLATE $to NOT NULL, MODIFY content MEDIUMTEXT CHARACTER SET utf8mb4 COLLATE $to NOT NULL";
        $grid      = $this->grid('ALTER TABLE t DEFAULT CHARACTER SET utf8mb4 COLLATE x, MODIFY title ... COLLATE x, MODIFY content ... COLLATE x (every text column in one statement)');
        foreach ($clauses as $clauseLabel => $clause) {
            foreach ($shapes as $shapeLabel => $keys) {
                $this->cell($grid, $clauseLabel, "$shapeLabel, utf8mb4_unicode_ci to the server default", fn() => $default === 'utf8mb4_unicode_ci' ? throw new NotApplicable('the server default is utf8mb4_unicode_ci') : $alter('utf8mb4_unicode_ci', $keys, $modifyAll($default) . $clause, $default));
                $this->cell($grid, $clauseLabel, "$shapeLabel, the server default to utf8mb4_unicode_ci", fn() => $default === 'utf8mb4_unicode_ci' ? throw new NotApplicable('the server default is utf8mb4_unicode_ci') : $alter($default, $keys, $modifyAll('utf8mb4_unicode_ci') . $clause, 'utf8mb4_unicode_ci'));
            }
        }
        $afterModifyAll = function (string $from, string $to) use ($alter, $modifyAll) {
            $alter($from, ', KEY title (title)', $modifyAll($to));
            return "table default {$this->tableCollation('alg')}; title {$this->columnCollation('alg', 'title')}; content {$this->columnCollation('alg', 'content')}; SHOW CREATE TABLE: {$this->showCreate('alg')}";
        };
        $this->fact('After that statement takes a utf8mb4_unicode_ci table to the server default', fn() => $default === 'utf8mb4_unicode_ci' ? throw new NotApplicable('the server default is utf8mb4_unicode_ci') : $afterModifyAll('utf8mb4_unicode_ci', $default));
        $this->fact('After that statement takes a table in the server default to utf8mb4_unicode_ci', fn() => $default === 'utf8mb4_unicode_ci' ? throw new NotApplicable('the server default is utf8mb4_unicode_ci') : $afterModifyAll($default, 'utf8mb4_unicode_ci'));
        $this->fact('For comparison, SHOW CREATE TABLE after CONVERT TO CHARACTER SET utf8mb4 COLLATE x takes a utf8mb4_unicode_ci table to the server default', function () use ($alter, $default) {
            if ($default === 'utf8mb4_unicode_ci') {
                throw new NotApplicable('the server default is utf8mb4_unicode_ci');
            }
            $alter('utf8mb4_unicode_ci', ', KEY title (title)', "CONVERT TO CHARACTER SET utf8mb4 COLLATE $default");
            return $this->showCreate('alg');
        });

        // The table default alone: no CONVERT and no MODIFY
        $tableDefaultOnly = [
            'ALTER TABLE t CHARACTER SET utf8mb4'                     => 'CHARACTER SET utf8mb4',
            'ALTER TABLE t DEFAULT CHARACTER SET utf8mb4'             => 'DEFAULT CHARACTER SET utf8mb4',
            'ALTER TABLE t COLLATE <server default>'                  => "COLLATE $default",
            'ALTER TABLE t CHARACTER SET utf8mb4 COLLATE utf8mb4_bin' => 'CHARACTER SET utf8mb4 COLLATE utf8mb4_bin',
        ];
        foreach ($tableDefaultOnly as $label => $change) {
            $this->fact("$label, on a utf8mb4_unicode_ci table: collations afterwards", function () use ($alter, $change) {
                $alter('utf8mb4_unicode_ci', '', $change);
                $this->exec("ALTER TABLE ::alg ADD COLUMN added VARCHAR(10)");
                return "table default {$this->tableCollation('alg')}; existing column {$this->columnCollation('alg', 'title')}; a column added afterwards {$this->columnCollation('alg', 'added')}";
            });
        }
        $this->fact('SHOW CREATE TABLE after ALTER TABLE t CHARACTER SET utf8mb4 on a utf8mb4_unicode_ci table', function () use ($alter) {
            $alter('utf8mb4_unicode_ci', '', 'CHARACTER SET utf8mb4');
            return $this->showCreate('alg');
        });
        $this->fact('SHOW CREATE TABLE of a utf8mb4_unicode_ci table, for comparison', function () use ($alter) {
            $alter('utf8mb4_unicode_ci', '', 'COMMENT = ""');
            return $this->showCreate('alg');
        });
        $this->fact('SHOW CREATE TABLE of a table in the server default collation', function () use ($alter, $default) {
            $alter($default, '', 'COMMENT = ""');
            return $this->showCreate('alg');
        });

        // Columns that might stop a convert. Each table starts in utf8mb4_unicode_ci and is converted to the server default
        $convert = fn(string $table) => "ALTER TABLE ::$table CONVERT TO CHARACTER SET utf8mb4 COLLATE $default";
        $special = function (string $name, string $create, callable $run) use ($default) {
            $this->fact($name, function () use ($create, $run, $default) {
                if ($default === 'utf8mb4_unicode_ci') {
                    throw new NotApplicable('the server default is utf8mb4_unicode_ci');
                }
                $this->exec("SET SESSION foreign_key_checks = 0");
                $this->exec("DROP TABLE IF EXISTS ::sp_child, ::sp_parent, ::sp");
                $this->exec("SET SESSION foreign_key_checks = 1");
                foreach (explode(";\n", $create) as $sql) {
                    $this->exec($sql);
                }
                try {
                    return $run();
                } finally {
                    $this->exec("SET SESSION foreign_key_checks = 1");
                }
            });
        };
        $foreignKey = "CREATE TABLE ::sp_parent (code VARCHAR(50) NOT NULL, PRIMARY KEY (code)) ENGINE=InnoDB CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;\n"
                    . "CREATE TABLE ::sp_child (num INT NOT NULL AUTO_INCREMENT PRIMARY KEY, code VARCHAR(50) NOT NULL, CONSTRAINT ::sp_fk FOREIGN KEY (code) REFERENCES ::sp_parent (code)) ENGINE=InnoDB CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;\n"
                    . "INSERT INTO ::sp_parent (code) VALUES ('a'), ('b');\n"
                    . "INSERT INTO ::sp_child (code) VALUES ('a'), ('b')";
        $foreignKeyState = function (): string {
            $enforced = 'still enforced';
            try {
                $this->exec("INSERT INTO ::sp_child (code) VALUES ('missing')");
                $enforced = 'NOT enforced: a row with no parent was stored';
            } catch (mysqli_sql_exception $e) {
                $enforced .= $e->getCode() === 1452 ? '' : " (error {$e->getCode()}: {$this->clean($e->getMessage())})";
            }
            return "parent column {$this->columnCollation('sp_parent', 'code')}, child column {$this->columnCollation('sp_child', 'code')}, foreign key $enforced";
        };
        $special('Text column in a foreign key: convert the child table', $foreignKey, fn() => 'ok, ' . $this->exec($convert('sp_child')) . ' rows; ' . $foreignKeyState());
        $special('Text column in a foreign key: convert the parent table', $foreignKey, fn() => 'ok, ' . $this->exec($convert('sp_parent')) . ' rows; ' . $foreignKeyState());
        $special('Text column in a foreign key: SET foreign_key_checks = 0, convert the child then the parent', $foreignKey, function () use ($convert, $foreignKeyState) {
            $this->exec("SET SESSION foreign_key_checks = 0");
            $this->exec($convert('sp_child'));
            $this->exec($convert('sp_parent'));
            $this->exec("SET SESSION foreign_key_checks = 1");
            return 'ok; ' . $foreignKeyState();
        });
        $special('Text column in a foreign key: SET foreign_key_checks = 0, convert the parent then the child', $foreignKey, function () use ($convert, $foreignKeyState) {
            $this->exec("SET SESSION foreign_key_checks = 0");
            $this->exec($convert('sp_parent'));
            $this->exec($convert('sp_child'));
            $this->exec("SET SESSION foreign_key_checks = 1");
            return 'ok; ' . $foreignKeyState();
        });
        $withRows = ";\nINSERT INTO ::sp (title, content) VALUES ('First Title', 'some content here'), ('Second Title', 'more content here')";
        $special('Table with a STORED generated column built from a text column', "CREATE TABLE ::sp (num INT NOT NULL AUTO_INCREMENT PRIMARY KEY, title VARCHAR(255) NOT NULL, content MEDIUMTEXT NOT NULL, slug VARCHAR(255) AS (LOWER(title)) STORED) ENGINE=InnoDB CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci$withRows",
            fn() => 'ok, ' . $this->exec($convert('sp')) . " rows; the generated column is now {$this->columnCollation('sp', 'slug')}");
        $special('Table with an indexed VIRTUAL generated column built from a text column', "CREATE TABLE ::sp (num INT NOT NULL AUTO_INCREMENT PRIMARY KEY, title VARCHAR(255) NOT NULL, content MEDIUMTEXT NOT NULL, slug VARCHAR(255) AS (LOWER(title)) VIRTUAL, KEY slug (slug)) ENGINE=InnoDB CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci$withRows",
            fn() => 'ok, ' . $this->exec($convert('sp')) . " rows; the generated column is now {$this->columnCollation('sp', 'slug')}");
        $special('Table with a FULLTEXT index on (title, content)', "CREATE TABLE ::sp (num INT NOT NULL AUTO_INCREMENT PRIMARY KEY, title VARCHAR(255) NOT NULL, content MEDIUMTEXT NOT NULL, FULLTEXT KEY ft (title, content)) ENGINE=InnoDB CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci$withRows",
            fn() => 'ok, ' . $this->exec($convert('sp')) . ' rows; MATCH ... AGAINST finds ' . $this->value("SELECT COUNT(*) FROM ::sp WHERE MATCH (title, content) AGAINST ('content')") . ' rows');
        $special('Table with prefix indexes on title(50) and content(100)', "CREATE TABLE ::sp (num INT NOT NULL AUTO_INCREMENT PRIMARY KEY, title VARCHAR(255) NOT NULL, content MEDIUMTEXT NOT NULL, KEY title (title(50)), KEY content (content(100))) ENGINE=InnoDB CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci$withRows",
            fn() => 'ok, ' . $this->exec($convert('sp')) . ' rows; ' . $this->value("SELECT GROUP_CONCAT(CONCAT(COLUMN_NAME, '(', SUB_PART, ')') ORDER BY SEQ_IN_INDEX, INDEX_NAME DESC SEPARATOR ', ') FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '{$this->prefix}sp' AND SUB_PART IS NOT NULL"));
        $special('Table with a latin1 column beside the utf8mb4 columns', "CREATE TABLE ::sp (num INT NOT NULL AUTO_INCREMENT PRIMARY KEY, title VARCHAR(255) NOT NULL, content MEDIUMTEXT NOT NULL, old VARCHAR(50) CHARACTER SET latin1 NOT NULL DEFAULT '') ENGINE=InnoDB CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci$withRows",
            fn() => 'ok, ' . $this->exec($convert('sp')) . " rows; the latin1 column is now {$this->columnCollation('sp', 'old')}");
        $special('Table with a utf8mb4_bin column beside the utf8mb4_unicode_ci columns', "CREATE TABLE ::sp (num INT NOT NULL AUTO_INCREMENT PRIMARY KEY, title VARCHAR(255) NOT NULL, content MEDIUMTEXT NOT NULL, token VARCHAR(50) COLLATE utf8mb4_bin NOT NULL DEFAULT '') ENGINE=InnoDB CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci$withRows",
            fn() => 'ok, ' . $this->exec($convert('sp')) . " rows; the utf8mb4_bin column is now {$this->columnCollation('sp', 'token')}");
        $special('Table with a utf8mb4_bin column, converted with DEFAULT CHARACTER SET plus a MODIFY for title and content only', "CREATE TABLE ::sp (num INT NOT NULL AUTO_INCREMENT PRIMARY KEY, title VARCHAR(255) NOT NULL, content MEDIUMTEXT NOT NULL, token VARCHAR(50) COLLATE utf8mb4_bin NOT NULL DEFAULT '') ENGINE=InnoDB CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci$withRows",
            fn() => 'ok, ' . $this->exec("ALTER TABLE ::sp " . $modifyAll($default)) . " rows; title is now {$this->columnCollation('sp', 'title')}, the utf8mb4_bin column is still {$this->columnCollation('sp', 'token')}");
        $this->exec("DROP TABLE IF EXISTS ::sp_child, ::sp_parent, ::sp, ::alg");
    }

    //endregion
    //region Section 4: two collations in one statement

    /**
     * What fails between two columns with different collations, which rewrite fixes it, and whether the rewrite still uses an index.
     */
    public function mixedCollations(): void
    {
        $this->startSection(4);
        $words = ['garden', 'house', 'market', 'river', 'mountain', 'street', 'coffee', 'summer', 'winter', 'family', 'window', 'kitchen', 'bridge', 'forest', 'island', 'village'];
        $names = array_map(static fn($i) => "('" . $words[$i % count($words)] . " " . $words[intdiv($i, count($words)) % count($words)] . " $i')", range(1, 1000));
        $mixTables = ['mix_unicode_ci_2' => 'utf8mb4_unicode_ci']; // a second utf8mb4_unicode_ci table, for the join with no fix
        foreach (array_keys($this->known) as $collation) {
            $mixTables['mix_' . self::short($collation)] = $collation;
        }
        foreach ($mixTables as $table => $collation) {
            $this->exec("DROP TABLE IF EXISTS ::$table");
            $this->exec("CREATE TABLE ::$table (num INT NOT NULL AUTO_INCREMENT PRIMARY KEY, name VARCHAR(100) NOT NULL, KEY name (name)) ENGINE=InnoDB CHARSET=utf8mb4 COLLATE=$collation");
            $this->exec("INSERT INTO ::$table (name) VALUES " . implode(', ', $names));
            $this->exec("ANALYZE TABLE ::$table");
        }

        $pairs = [
            'unicode_ci and general_ci'     => ['utf8mb4_unicode_ci', 'utf8mb4_general_ci'],
            'unicode_ci and 0900_ai_ci'     => ['utf8mb4_unicode_ci', 'utf8mb4_0900_ai_ci'],
            'unicode_ci and uca1400_ai_ci'  => ['utf8mb4_unicode_ci', 'utf8mb4_uca1400_ai_ci'],
            'unicode_ci and unicode_520_ci' => ['utf8mb4_unicode_ci', 'utf8mb4_unicode_520_ci'],
            'general_ci and 0900_ai_ci'     => ['utf8mb4_general_ci', 'utf8mb4_0900_ai_ci'],
            'general_ci and uca1400_ai_ci'  => ['utf8mb4_general_ci', 'utf8mb4_uca1400_ai_ci'],
            'unicode_ci and bin'            => ['utf8mb4_unicode_ci', 'utf8mb4_bin'],
        ];
        // {A} and {B} become the pair's two tables
        $inPair = function (array $pair, string $sql): string {
            foreach ($pair as $collation) {
                if (!isset($this->known[$collation])) {
                    throw new NotApplicable("no $collation on this server");
                }
            }
            return str_replace(['{A}', '{B}'], ['::mix_' . self::short($pair[0]), '::mix_' . self::short($pair[1])], $sql);
        };
        $both      = "FROM {A} a JOIN {B} b ON a.num = b.num";
        $value     = static fn(string $expression) => ["SELECT $expression FROM {A} a JOIN {B} b ON a.num = b.num LIMIT 5", "SELECT COLLATION($expression) FROM {A} a JOIN {B} b ON a.num = b.num LIMIT 1"];
        $search    = static fn(string $a, string $b) => "SELECT * FROM (SELECT 'a' AS tablename, num, $a AS _title, CONCAT_WS('\\t', $a, $a) AS _content FROM {A} UNION SELECT 'b', num, $b, CONCAT_WS('\\t', $b, $b) FROM {B}) combinedTable WHERE _content LIKE '%garden%' LIMIT 10";
        $statements = [
            'UNION'                                                     => ["SELECT name FROM {A} UNION SELECT name FROM {B}", "SELECT COLLATION(name) FROM (SELECT name FROM {A} UNION SELECT name FROM {B}) u LIMIT 1"],
            'UNION ALL'                                                 => ["SELECT name FROM {A} UNION ALL SELECT name FROM {B}", "SELECT COLLATION(name) FROM (SELECT name FROM {A} UNION ALL SELECT name FROM {B}) u LIMIT 1"],
            'UNION of a subquery per table, then WHERE _content LIKE (the searchMultipleTables() query)' => [$search('name', 'name'), null],
            'JOIN ... ON a.name = b.name'                               => ["SELECT COUNT(*) FROM {A} a JOIN {B} b ON a.name = b.name", null],
            'JOIN ... USING (name)'                                     => ["SELECT COUNT(*) FROM {A} a JOIN {B} b USING (name)", null],
            'WHERE a.name IN (SELECT b.name FROM b)'                    => ["SELECT COUNT(*) FROM {A} a WHERE a.name IN (SELECT b.name FROM {B} b)", null],
            'WHERE a.name = (SELECT b.name FROM b LIMIT 1)'             => ["SELECT COUNT(*) FROM {A} a WHERE a.name = (SELECT b.name FROM {B} b ORDER BY b.num LIMIT 1)", null],
            'WHERE a.name < b.name'                                     => ["SELECT COUNT(*) $both WHERE a.name < b.name", null],
            'WHERE a.name LIKE b.name'                                  => ["SELECT COUNT(*) $both WHERE a.name LIKE b.name", null],
            "WHERE 'text' IN (a.name, b.name)"                          => ["SELECT COUNT(*) $both WHERE 'text' IN (a.name, b.name)", null],
            "WHERE a.name = 'text' AND b.name = 'text' (string literal)" => ["SELECT COUNT(*) $both WHERE a.name = 'text' AND b.name = 'text'", null],
            "WHERE CONCAT(a.name, 'text') = b.name"                     => ["SELECT COUNT(*) $both WHERE CONCAT(a.name, 'text') = b.name", null],
            'CONCAT(a.name, b.name)'                                    => $value('CONCAT(a.name, b.name)'),
            "CONCAT_WS(' ', a.name, b.name)"                            => $value("CONCAT_WS(' ', a.name, b.name)"),
            'COALESCE(a.name, b.name)'                                  => $value('COALESCE(a.name, b.name)'),
            'IFNULL(a.name, b.name)'                                    => $value('IFNULL(a.name, b.name)'),
            'IF(a.num > 5, a.name, b.name)'                             => $value('IF(a.num > 5, a.name, b.name)'),
            'CASE WHEN a.num > 5 THEN a.name ELSE b.name END'           => $value('CASE WHEN a.num > 5 THEN a.name ELSE b.name END'),
            'GREATEST(a.name, b.name)'                                  => $value('GREATEST(a.name, b.name)'),
            'INSERT INTO a (name) SELECT name FROM b'                   => ["INSERT INTO {A} (name) SELECT name FROM {B} WHERE num = 1", null],
        ];
        // Run the statement; when it works and has a result collation to report, add it: "ok, utf8mb4_unicode_ci"
        $run = function (array $pair, string $sql, ?string $collationSql) use ($inPair) {
            $this->rows($inPair($pair, $sql));
            return $collationSql === null ? 'ok' : 'ok, ' . $this->orDefault((string)$this->value($inPair($pair, $collationSql)), str_contains($sql, 'COLLATE'));
        };

        $grid = $this->grid('Statements that use a column from each of two tables with different collations');
        foreach ($statements as $label => [$sql, $collationSql]) {
            foreach ($pairs as $pairLabel => $pair) {
                $this->cell($grid, $label, $pairLabel, fn() => $run($pair, $sql, $collationSql));
            }
        }
        foreach ($pairs as $pairLabel => $pair) {
            $this->cell($grid, 'WHERE a.name = ? AND b.name = ? (prepared statement parameters)', $pairLabel, function () use ($pair, $inPair, $both) {
                $statement = $this->db->prepare($this->sql($inPair($pair, "SELECT COUNT(*) $both WHERE a.name = ? AND b.name = ?")));
                $text      = 'text';
                $statement->bind_param('ss', $text, $text);
                $statement->execute();
                $statement->close();
                return 'ok';
            });
        }

        // Each fix is written around the column on side a and side b
        $fixes = [
            'CONVERT(col USING utf8mb4) on both sides'              => ['CONVERT(%s USING utf8mb4)', 'CONVERT(%s USING utf8mb4)'],
            'CONVERT(col USING utf8mb4) on the second side only'    => ['%s', 'CONVERT(%s USING utf8mb4)'],
            'col COLLATE utf8mb4_unicode_ci on both sides'          => ['%s COLLATE utf8mb4_unicode_ci', '%s COLLATE utf8mb4_unicode_ci'],
            'col COLLATE utf8mb4_unicode_ci on the second side only' => ['%s', '%s COLLATE utf8mb4_unicode_ci'],
            'col COLLATE utf8mb4_general_ci on both sides'          => ['%s COLLATE utf8mb4_general_ci', '%s COLLATE utf8mb4_general_ci'],
            'col COLLATE utf8mb4_bin on both sides'                 => ['%s COLLATE utf8mb4_bin', '%s COLLATE utf8mb4_bin'],
            'CAST(col AS CHAR) on both sides'                       => ['CAST(%s AS CHAR)', 'CAST(%s AS CHAR)'],
            'CAST(col AS CHAR CHARACTER SET utf8mb4) on both sides' => ['CAST(%s AS CHAR CHARACTER SET utf8mb4)', 'CAST(%s AS CHAR CHARACTER SET utf8mb4)'],
            'CONVERT(col USING utf8mb4) COLLATE utf8mb4_unicode_ci on both sides' => ['CONVERT(%s USING utf8mb4) COLLATE utf8mb4_unicode_ci', 'CONVERT(%s USING utf8mb4) COLLATE utf8mb4_unicode_ci'],
        ];
        $unionGrid  = $this->grid('UNION with each fix, and the collation of the result column');
        $joinGrid   = $this->grid('JOIN ... ON a.name = b.name with each fix');
        $searchGrid = $this->grid('The searchMultipleTables() query with each fix around every column');
        foreach ($fixes as $fixLabel => [$fixA, $fixB]) {
            foreach ($pairs as $pairLabel => $pair) {
                $unionSql = "SELECT " . sprintf($fixA, 'name') . " AS name FROM {A} UNION SELECT " . sprintf($fixB, 'name') . " FROM {B}";
                $this->cell($unionGrid, $fixLabel, $pairLabel, fn() => $run($pair, $unionSql, "SELECT COLLATION(name) FROM ($unionSql) u LIMIT 1"));
                $this->cell($joinGrid, $fixLabel, $pairLabel, fn() => $run($pair, "SELECT COUNT(*) FROM {A} a JOIN {B} b ON " . sprintf($fixA, 'a.name') . " = " . sprintf($fixB, 'b.name'), null));
                $this->cell($searchGrid, $fixLabel, $pairLabel, fn() => $run($pair, $search(sprintf($fixA, 'name'), sprintf($fixB, 'name')), null));
            }
        }

        // A site upgraded from an old version can still have a latin1 column. Side a is that column
        $this->exec("DROP TABLE IF EXISTS ::mix_latin1");
        $this->exec("CREATE TABLE ::mix_latin1 (num INT NOT NULL AUTO_INCREMENT PRIMARY KEY, name VARCHAR(100) NOT NULL, KEY name (name)) ENGINE=InnoDB CHARSET=latin1");
        $this->exec("INSERT INTO ::mix_latin1 (name) VALUES " . implode(', ', $names));
        $mixTables['mix_latin1'] = 'latin1_swedish_ci';
        $latinGrid = $this->grid('One table has a latin1 column, the other a utf8mb4 column');
        $others    = ['a utf8mb4_unicode_ci column' => '::mix_unicode_ci', 'a column in the server default' => '::mix_' . self::short($this->default)];
        foreach (['(no fix)' => ['%s', '%s']] + $fixes as $fixLabel => [$fixA, $fixB]) {
            foreach ($others as $otherLabel => $otherTable) {
                $tables   = static fn(string $sql) => str_replace(['{A}', '{B}'], ['::mix_latin1', $otherTable], $sql);
                $unionSql = $tables("SELECT " . sprintf($fixA, 'name') . " AS name FROM {A} UNION SELECT " . sprintf($fixB, 'name') . " FROM {B}");
                $this->cell($latinGrid, $fixLabel, "UNION with $otherLabel", function () use ($unionSql, $fixLabel) {
                    $this->rows($unionSql);
                    return 'ok, ' . $this->orDefault((string)$this->value("SELECT COLLATION(name) FROM ($unionSql) u LIMIT 1"), str_contains($fixLabel, 'COLLATE'));
                });
                $this->cell($latinGrid, $fixLabel, "JOIN with $otherLabel", function () use ($tables, $fixA, $fixB) {
                    $this->rows($tables("SELECT COUNT(*) FROM {A} a JOIN {B} b ON " . sprintf($fixA, 'a.name') . " = " . sprintf($fixB, 'b.name')));
                    return 'ok';
                });
                $this->cell($latinGrid, $fixLabel, "searchMultipleTables() query with $otherLabel", function () use ($tables, $search, $fixA, $fixB) {
                    $this->rows($tables($search(sprintf($fixA, 'name'), sprintf($fixB, 'name'))));
                    return 'ok';
                });
            }
        }

        // EXPLAIN on 1,000-row tables. u and u2 are utf8mb4_unicode_ci, g is utf8mb4_general_ci, each with an index on name
        $explain = function (string $sql): string {
            $parts = [];
            foreach ($this->db->query("EXPLAIN " . $this->sql($sql))->fetch_all(MYSQLI_ASSOC) as $row) {
                $flags   = array_filter(['filesort', 'temporary', 'join buffer'], static fn($flag) => stripos((string)($row['Extra'] ?? ''), $flag) !== false);
                $parts[] = "{$row['table']}: type={$row['type']}, key=" . ($row['key'] ?? 'none') . ($flags ? ', ' . implode(', ', $flags) : '');
            }
            return implode('; ', $parts);
        };
        $u     = '::mix_unicode_ci u';
        $u2    = '::mix_unicode_ci_2 u2';
        $g     = '::mix_general_ci g';
        $plans = [
            "WHERE name = 'garden house 1' (no fix)"                                         => "SELECT num, name FROM $u WHERE name = 'garden house 1'",
            "WHERE CONVERT(name USING utf8mb4) = 'garden house 1'"                           => "SELECT num, name FROM $u WHERE CONVERT(name USING utf8mb4) = 'garden house 1'",
            "WHERE CAST(name AS CHAR) = 'garden house 1'"                                    => "SELECT num, name FROM $u WHERE CAST(name AS CHAR) = 'garden house 1'",
            "WHERE name COLLATE utf8mb4_unicode_ci = 'garden house 1' (the column's own collation)" => "SELECT num, name FROM $u WHERE name COLLATE utf8mb4_unicode_ci = 'garden house 1'",
            "WHERE name COLLATE utf8mb4_general_ci = 'garden house 1' (another collation)"   => "SELECT num, name FROM $u WHERE name COLLATE utf8mb4_general_ci = 'garden house 1'",
            "WHERE name = 'garden house 1' COLLATE utf8mb4_unicode_ci (the column's own collation, on the value)" => "SELECT num, name FROM $u WHERE name = 'garden house 1' COLLATE utf8mb4_unicode_ci",
            "WHERE name = 'garden house 1' COLLATE utf8mb4_general_ci (another collation, on the value)" => "SELECT num, name FROM $u WHERE name = 'garden house 1' COLLATE utf8mb4_general_ci",
            "WHERE name LIKE 'garden h%' (no fix)"                                           => "SELECT num, name FROM $u WHERE name LIKE 'garden h%'",
            "WHERE name COLLATE utf8mb4_general_ci LIKE 'garden h%'"                         => "SELECT num, name FROM $u WHERE name COLLATE utf8mb4_general_ci LIKE 'garden h%'",
            'ORDER BY name LIMIT 20 (no fix)'                                                => "SELECT num, name FROM $u ORDER BY name LIMIT 20",
            'ORDER BY name COLLATE utf8mb4_unicode_ci LIMIT 20 (the column\'s own collation)' => "SELECT num, name FROM $u ORDER BY name COLLATE utf8mb4_unicode_ci LIMIT 20",
            'ORDER BY name COLLATE utf8mb4_general_ci LIMIT 20 (another collation)'          => "SELECT num, name FROM $u ORDER BY name COLLATE utf8mb4_general_ci LIMIT 20",
            'JOIN of two utf8mb4_unicode_ci tables ON u.name = u2.name (no fix), u read first' => "SELECT u.num, u2.num FROM $u STRAIGHT_JOIN $u2 ON u.name = u2.name",
            'JOIN ON u.name = g.name COLLATE utf8mb4_unicode_ci, g read first'               => "SELECT u.num, g.num FROM $g STRAIGHT_JOIN $u ON u.name = g.name COLLATE utf8mb4_unicode_ci",
            'JOIN ON u.name = g.name COLLATE utf8mb4_unicode_ci, u read first'               => "SELECT u.num, g.num FROM $u STRAIGHT_JOIN $g ON u.name = g.name COLLATE utf8mb4_unicode_ci",
            'JOIN ON u.name = g.name COLLATE utf8mb4_unicode_ci, the server picks the order' => "SELECT u.num, g.num FROM $g JOIN $u ON u.name = g.name COLLATE utf8mb4_unicode_ci",
            'JOIN ON CONVERT(u.name USING utf8mb4) = CONVERT(g.name USING utf8mb4), g read first' => "SELECT u.num, g.num FROM $g STRAIGHT_JOIN $u ON CONVERT(u.name USING utf8mb4) = CONVERT(g.name USING utf8mb4)",
            'JOIN ON u.name COLLATE utf8mb4_bin = g.name COLLATE utf8mb4_bin, g read first'  => "SELECT u.num, g.num FROM $g STRAIGHT_JOIN $u ON u.name COLLATE utf8mb4_bin = g.name COLLATE utf8mb4_bin",
        ];
        foreach ($plans as $label => $sql) {
            $this->fact("EXPLAIN: $label", fn() => $explain($sql));
        }

        foreach (array_keys($mixTables) as $table) {
            $this->exec("DROP TABLE IF EXISTS ::$table");
        }
    }

    //endregion
    //region Section 5: results that change between collations

    /**
     * Which values each collation treats as equal, what a UNIQUE index does with them, and the sort order.
     */
    public function results(): void
    {
        $this->startSection(5);
        $hex = static fn(string $text): string => "_utf8mb4 X'" . bin2hex($text) . "'";

        $equalGrid  = $this->grid('Is left = right');
        $likeGrid   = $this->grid('Is left LIKE right');
        $uniqueGrid = $this->grid('A UNIQUE index, after both values are inserted');
        foreach (self::COLLATIONS as $collation) {
            $isKnown = isset($this->known[$collation]);
            if ($isKnown) {
                $this->exec("DROP TABLE IF EXISTS ::uq");
                $this->exec("CREATE TABLE ::uq (v VARCHAR(50) NOT NULL, UNIQUE KEY v (v)) ENGINE=InnoDB CHARSET=utf8mb4 COLLATE=$collation");
            }
            $unknown = fn() => throw new NotApplicable("no $collation on this server");
            foreach (self::PAIRS as $label => [$left, $right]) {
                $this->cell($equalGrid, $label, $collation, !$isKnown ? $unknown : function () use ($hex, $left, $right, $collation, $label) {
                    $isEqual                         = $this->value("SELECT {$hex($left)} COLLATE $collation = {$hex($right)} COLLATE $collation") === '1';
                    $this->equal[$label][$collation] = $isEqual;
                    return $isEqual ? 'equal' : 'different';
                });
                $this->cell($likeGrid, $label, $collation, !$isKnown ? $unknown : fn() => $this->value("SELECT {$hex($left)} COLLATE $collation LIKE {$hex($right)} COLLATE $collation") === '1' ? 'match' : 'no match');
                $this->cell($uniqueGrid, $label, $collation, !$isKnown ? $unknown : function () use ($hex, $left, $right) {
                    $this->exec("DELETE FROM ::uq");
                    $this->exec("INSERT INTO ::uq (v) VALUES ({$hex($left)})");
                    try {
                        $this->exec("INSERT INTO ::uq (v) VALUES ({$hex($right)})");
                    } catch (mysqli_sql_exception $e) {
                        return ["refused, error {$e->getCode()}", $this->clean($e->getMessage())];
                    }
                    return 'both stored';
                });
            }
        }
        $this->exec("DROP TABLE IF EXISTS ::uq");

        // A convert fails when the table has a UNIQUE index and two of its values are equal in the new collation
        $default    = $this->default;
        $directions = ['utf8mb4_unicode_ci to the server default' => ['utf8mb4_unicode_ci', $default], 'the server default to utf8mb4_unicode_ci' => [$default, 'utf8mb4_unicode_ci']];
        foreach (['utf8mb4_general_ci', 'utf8mb4_unicode_520_ci', 'utf8mb4_0900_ai_ci', 'utf8mb4_uca1400_ai_ci'] as $other) {
            $directions["utf8mb4_unicode_ci to $other"] = ['utf8mb4_unicode_ci', $other];
            $directions["$other to utf8mb4_unicode_ci"] = [$other, 'utf8mb4_unicode_ci'];
        }
        foreach ($directions as $label => [$from, $to]) {
            foreach (['ALTER TABLE' => 'CONVERT a UNIQUE column', 'ALTER IGNORE TABLE' => 'ALTER IGNORE TABLE ... CONVERT a UNIQUE column'] as $verb => $what) {
                if ($verb === 'ALTER IGNORE TABLE' && !str_contains($label, 'server default')) {
                    continue;
                }
                $this->fact("$what, $label", function () use ($from, $to, $verb, $hex) {
                    foreach ([$from, $to] as $collation) {
                        if (!isset($this->known[$collation])) {
                            throw new NotApplicable("no $collation on this server");
                        }
                    }
                    if ($from === $to) {
                        throw new NotApplicable('the server default is utf8mb4_unicode_ci');
                    }
                    $becomeEqual = array_keys(array_filter($this->equal, static fn(array $equal) => !$equal[$from] && $equal[$to]));
                    if (!$becomeEqual) {
                        return 'ok: no pair in the sample is different before and equal after';
                    }
                    $this->exec("DROP TABLE IF EXISTS ::uq");
                    $this->exec("CREATE TABLE ::uq (v VARCHAR(50) NOT NULL, UNIQUE KEY v (v)) ENGINE=InnoDB CHARSET=utf8mb4 COLLATE=$from");
                    foreach ($becomeEqual as $pairLabel) {
                        foreach (self::PAIRS[$pairLabel] as $text) {
                            $this->exec("INSERT IGNORE INTO ::uq (v) VALUES ({$hex($text)})");
                        }
                    }
                    $before = $this->value("SELECT COUNT(*) FROM ::uq");
                    try {
                        $this->exec("$verb ::uq CONVERT TO CHARACTER SET utf8mb4 COLLATE $to");
                        $result = "ok, the table had $before rows and now has " . $this->value("SELECT COUNT(*) FROM ::uq");
                    } catch (mysqli_sql_exception $e) {
                        $result = "error {$e->getCode()}: {$this->clean($e->getMessage())}";
                    }
                    return "$result. " . count($becomeEqual) . " sample pairs are different before and equal after";
                });
            }
        }
        $this->exec("DROP TABLE IF EXISTS ::uq");

        // One sample sorted under each collation. " = " joins values the collation gives the same weight
        $this->exec("DROP TABLE IF EXISTS ::ord");
        $this->exec("CREATE TABLE ::ord (v VARCHAR(50) NOT NULL) ENGINE=InnoDB CHARSET=utf8mb4 COLLATE=utf8mb4_bin");
        $this->exec("INSERT INTO ::ord (v) VALUES " . implode(', ', array_map(static fn($text) => "({$hex($text)})", self::SORT_SAMPLE)));
        foreach (self::COLLATIONS as $collation) {
            $this->fact("ORDER BY under $collation", function () use ($collation) {
                if (!isset($this->known[$collation])) {
                    throw new NotApplicable("no $collation on this server");
                }
                $sorted = '';
                $last   = null;
                foreach ($this->rows("SELECT v, HEX(WEIGHT_STRING(v COLLATE $collation)) FROM ::ord ORDER BY v COLLATE $collation, v") as [$text, $weight]) {
                    $sorted .= ($last === null ? '' : ($weight === $last ? ' = ' : ' < ')) . $text;
                    $last    = $weight;
                }
                return $sorted;
            });
        }
        $this->exec("DROP TABLE IF EXISTS ::ord");
    }

    //endregion
    //region Speed test

    /**
     * Time the same statements on one table per collation, then time converting a table.
     *
     * Every collation gets a table `a` shaped like a CMS Builder section (15 columns: the
     * record number, three dates, two integers, three flags, five VARCHAR(255) and one
     * MEDIUMTEXT, with indexes on name and titleIndexed) and a smaller table `b` (num, name,
     * title, index on name) with the same rows. The control is a second pair of
     * utf8mb4_general_ci tables: its difference from the first pair is the noise of the
     * machine.
     *
     * @param int    $rowCount Rows in each table
     * @param int    $passes Timed passes for each statement; the slow ones run 3
     * @param string $only   A regex: time only the statements whose name matches, and skip the convert
     * @param string $titlesFile A text file with one title per line to use in place of the generated titles
     */
    public function benchmark(int $rowCount, int $passes, string $only = '', string $titlesFile = ''): void
    {
        $started = hrtime(true);
        $note    = static fn(string $text) => fwrite(STDERR, sprintf("[%4ds] %s\n", (hrtime(true) - $started) / 1e9, $text));

        // MariaDB images thru 10.4 start with the query cache on. A cached result comes back
        // before the statement runs, so the arms would time the cache and not the collation
        try {
            [$cacheType, $cacheSize] = $this->rows("SELECT @@query_cache_type, @@query_cache_size")[0];
            $this->exec("SET SESSION query_cache_type = OFF");
            $queryCache = $cacheType === 'OFF' || (int)$cacheSize === 0 ? 'off' : "on ($cacheSize bytes), turned off for this session";
        } catch (mysqli_sql_exception) {
            $queryCache = 'none'; // MySQL 8.0+ has no query cache
        }
        try {
            $this->exec("SET SESSION information_schema_stats_expiry = 0"); // MySQL 8.0+ caches table sizes for a day
        } catch (mysqli_sql_exception) {
            // older servers read the sizes live
        }

        $arms = []; // arm label => table suffix
        foreach (array_keys($this->known) as $collation) {
            $arms[$collation] = self::short($collation);
        }
        $arms['utf8mb4_general_ci (control)'] = 'control';
        $collationOf = static fn(string $arm) => explode(' ', $arm)[0];

        $this->bench = [
            'rows'       => $rowCount,
            'titles'     => $titlesFile === '' ? '' : basename($titlesFile), // the runs with another file's titles are reported apart
            'passes'     => $passes,
            'queryCache' => $queryCache,
            'bufferPool' => (int)$this->value("SELECT @@innodb_buffer_pool_size"),
            'default'    => $this->default,
            'storedAs'   => $this->known,
            'ops'        => [],
            'errors'     => [],
            'plans'      => [],
            'sizes'      => [],
            'convert'    => [],
        ];

        // Load the rows once, then copy them into each collation's tables
        $this->exec("DROP TABLE IF EXISTS ::base");
        $this->exec("CREATE TABLE ::base (num INT UNSIGNED NOT NULL AUTO_INCREMENT, name VARCHAR(255) NOT NULL, title VARCHAR(255) NOT NULL, content MEDIUMTEXT NOT NULL, PRIMARY KEY (num)) ENGINE=InnoDB CHARSET=utf8mb4 COLLATE=utf8mb4_bin");
        $sample = ['names' => [], 'titles' => [], 'inserts' => []];
        $batch  = [];
        $escape = fn(string $text) => "'" . $this->db->real_escape_string($text) . "'";
        foreach (self::corpus($rowCount, $titlesFile) as $i => [$name, $title, $content]) {
            $batch[] = "({$escape($name)}, {$escape($title)}, {$escape($content)})";
            if (count($batch) === 500) {
                $this->exec("INSERT INTO ::base (name, title, content) VALUES " . implode(', ', $batch));
                $batch = [];
            }
            if ($i % intdiv($rowCount, 500) === 0 && count($sample['names']) < 500) {
                $sample['names'][] = $name;
            }
            if ($i % intdiv($rowCount, 3) === 1) {
                $sample['titles'][] = $title;
            }
            if ($i <= 10_000) {
                $sample['inserts'][] = "({$escape($name)}, {$escape($title)})";
            }
        }
        if ($batch) {
            $this->exec("INSERT INTO ::base (name, title, content) VALUES " . implode(', ', $batch));
        }
        [$nameChars, $titleChars, $contentChars] = $this->rows("SELECT ROUND(AVG(CHAR_LENGTH(name))), ROUND(AVG(CHAR_LENGTH(title))), ROUND(AVG(CHAR_LENGTH(content))) FROM ::base")[0];
        $this->bench['averageChars'] = ['name' => (int)$nameChars, 'title' => (int)$titleChars, 'content' => (int)$contentChars];
        $note("loaded $rowCount rows");

        foreach ($arms as $arm => $short) {
            $collation = $collationOf($arm);
            $this->exec("DROP TABLE IF EXISTS ::a_$short, ::b_$short, ::m_$short, ::ins_$short, ::insu_$short");
            $this->exec("CREATE TABLE ::a_$short (num INT UNSIGNED NOT NULL AUTO_INCREMENT, createdDate DATETIME NOT NULL, updatedDate DATETIME NOT NULL, publishDate DATETIME NOT NULL, "
                . "createdByUserNum INT UNSIGNED NOT NULL, dragSortOrder INT UNSIGNED NOT NULL, hidden TINYINT UNSIGNED NOT NULL, featured TINYINT UNSIGNED NOT NULL, approved TINYINT UNSIGNED NOT NULL, "
                . "name VARCHAR(255) NOT NULL, title VARCHAR(255) NOT NULL, titleIndexed VARCHAR(255) NOT NULL, summary VARCHAR(255) NOT NULL, permalink VARCHAR(255) NOT NULL, content MEDIUMTEXT NOT NULL, "
                . "PRIMARY KEY (num)) ENGINE=InnoDB CHARSET=utf8mb4 COLLATE=$collation");
            $this->exec("CREATE TABLE ::b_$short (num INT UNSIGNED NOT NULL AUTO_INCREMENT, name VARCHAR(255) NOT NULL, title VARCHAR(255) NOT NULL, PRIMARY KEY (num)) ENGINE=InnoDB CHARSET=utf8mb4 COLLATE=$collation");
            // The dates and dragSortOrder are the row number multiplied by a prime, so they're in no order; 1 row in 20 is hidden
            $this->exec("INSERT INTO ::a_$short (num, createdDate, updatedDate, publishDate, createdByUserNum, dragSortOrder, hidden, featured, approved, name, title, titleIndexed, summary, permalink, content) "
                . "SELECT num, '2015-01-01' + INTERVAL ((num * 7919) % 300000000) SECOND, '2015-01-01' + INTERVAL ((num * 104729) % 300000000) SECOND, '2015-01-01' + INTERVAL ((num * 15485863) % 300000000) SECOND, "
                . "1 + num % 7, (num * 7919) % 1000003, num % 20 = 0, num % 10 = 0, num % 3 > 0, name, title, title, LEFT(content, 150), LOWER(REPLACE(name, ' ', '-')), content FROM ::base");
            $this->exec("INSERT INTO ::b_$short (num, name, title) SELECT num, name, title FROM ::base");
            $this->exec("ALTER TABLE ::a_$short ADD INDEX name (name), ADD INDEX titleIndexed (titleIndexed)");
            $this->exec("ALTER TABLE ::b_$short ADD INDEX name (name)");
            // The title as CMS Builder stores a text field: MEDIUMTEXT, and an index on it can only be a prefix index
            $this->exec("CREATE TABLE ::m_$short (num INT UNSIGNED NOT NULL, hidden TINYINT UNSIGNED NOT NULL, dragSortOrder INT UNSIGNED NOT NULL, title MEDIUMTEXT NOT NULL, titleIndexed MEDIUMTEXT NOT NULL, PRIMARY KEY (num)) ENGINE=InnoDB CHARSET=utf8mb4 COLLATE=$collation");
            $this->exec("INSERT INTO ::m_$short (num, hidden, dragSortOrder, title, titleIndexed) SELECT num, hidden, dragSortOrder, title, titleIndexed FROM ::a_$short");
            $this->exec("ALTER TABLE ::m_$short ADD INDEX titleIndexed (titleIndexed(768))");
            $this->exec("ANALYZE TABLE ::m_$short");
            $this->exec("CREATE TABLE ::ins_$short (num INT UNSIGNED NOT NULL AUTO_INCREMENT, name VARCHAR(255) NOT NULL, title VARCHAR(255) NOT NULL, PRIMARY KEY (num), KEY name (name)) ENGINE=InnoDB CHARSET=utf8mb4 COLLATE=$collation");
            $this->exec("CREATE TABLE ::insu_$short (num INT UNSIGNED NOT NULL AUTO_INCREMENT, name VARCHAR(255) NOT NULL, title VARCHAR(255) NOT NULL, PRIMARY KEY (num), UNIQUE KEY name (name)) ENGINE=InnoDB CHARSET=utf8mb4 COLLATE=$collation");
            $this->exec("ANALYZE TABLE ::a_$short");
            $this->exec("ANALYZE TABLE ::b_$short");
            [$dataBytes, $indexBytes] = $this->rows("SELECT DATA_LENGTH, INDEX_LENGTH FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '{$this->prefix}a_$short'")[0];
            $this->bench['sizes'][$arm] = ['data' => (int)$dataBytes, 'index' => (int)$indexBytes];
            $note("built the $arm tables");
        }

        $quoted  = array_map($escape, $sample['names']);
        $titles  = array_map($escape, $sample['titles']);
        $inserts = array_map(static fn(array $rows) => "(name, title) VALUES " . implode(', ', $rows), array_chunk($sample['inserts'], 100));
        $search  = static fn(string $short, string $wrap) => "SELECT SQL_CALC_FOUND_ROWS * FROM ("
            . "SELECT 'a' AS tablename, num, " . sprintf($wrap, 'title') . " AS _title, '' AS _summary, " . sprintf($wrap, "CONCAT_WS('\\t', title, content)") . " AS _content FROM ::a_$short"
            . " UNION SELECT 'b' AS tablename, num, " . sprintf($wrap, 'title') . " AS _title, '' AS _summary, " . sprintf($wrap, "CONCAT_WS('\\t', title, name)") . " AS _content FROM ::b_$short"
            . ") combinedTable WHERE _content LIKE '%garden%' LIMIT 10";
        $none = static fn() => [];
        // The list query CMS Builder's getRecords() sends for a page of a section, with one ORDER BY column swapped in
        $list = static fn(string $short, string $orderBy) => "SELECT * FROM ::a_$short WHERE hidden = 0 ORDER BY $orderBy LIMIT 25";
        // The last page of that list: 19 rows in 20 aren't hidden
        $lastPage    = static fn(string $short, string $orderBy) => $list($short, $orderBy) . " OFFSET " . (intdiv($rowCount * 19, 20) - 25);
        $sortAll     = static fn(string $short) => "SELECT num FROM ::a_$short ORDER BY title LIMIT " . ($rowCount - 1) . ", 1";
        $sortBuffer  = static fn(int $bytes) => static fn() => ["SET SESSION sort_buffer_size = $bytes"];
        $sortDefault = static fn() => ["SET SESSION sort_buffer_size = DEFAULT"];

        // name => [statements per pass that the time is divided by, slow?, setup, timed statements, teardown]
        $ops = [
            'List page: SELECT * ... WHERE hidden = 0 ORDER BY title LIMIT 25, no index on title'     => [5, false, $none, static fn($s) => array_fill(0, 5, $list($s, 'title')), $none],
            'List page: the same, ORDER BY dragSortOrder (an integer, no index)'                    => [5, false, $none, static fn($s) => array_fill(0, 5, $list($s, 'dragSortOrder')), $none],
            'List page: the same, ORDER BY createdDate (a datetime, no index)'                      => [5, false, $none, static fn($s) => array_fill(0, 5, $list($s, 'createdDate')), $none],
            'List page: the same, ORDER BY titleIndexed (the title again, with an index)'           => [100, false, $none, static fn($s) => array_fill(0, 100, $list($s, 'titleIndexed')), $none],
            'List page, last page: SELECT * ... WHERE hidden = 0 ORDER BY title LIMIT 25 OFFSET n, no index on title' => [1, true, $none, static fn($s) => [$lastPage($s, 'title')], $none],
            'List page, last page: the same, ORDER BY dragSortOrder (an integer, no index)'         => [1, true, $none, static fn($s) => [$lastPage($s, 'dragSortOrder')], $none],
            'List page, last page: the same, ORDER BY titleIndexed (the title again, with an index)' => [1, true, $none, static fn($s) => [$lastPage($s, 'titleIndexed')], $none],
            'ORDER BY title, whole table, no index'                         => [1, false, $none, static fn($s) => [$sortAll($s)], $none],
            'ORDER BY title, whole table, no index, sort_buffer_size = 64 MB' => [1, false, $sortBuffer(64 * 1024 * 1024), static fn($s) => [$sortAll($s)], $sortDefault],
            'ORDER BY title, whole table, no index, sort_buffer_size = 2 GB' => [1, false, $sortBuffer(2 * 1024 * 1024 * 1024), static fn($s) => [$sortAll($s)], $sortDefault],
            'MEDIUMTEXT title: ORDER BY title, whole table, no index'       => [1, false, $none, static fn($s) => ["SELECT num FROM ::m_$s ORDER BY title LIMIT " . ($rowCount - 1) . ", 1"], $none],
            'MEDIUMTEXT title: the same, sort_buffer_size = 2 GB'           => [1, false, $sortBuffer(2 * 1024 * 1024 * 1024), static fn($s) => ["SELECT num FROM ::m_$s ORDER BY title LIMIT " . ($rowCount - 1) . ", 1"], $sortDefault],
            'MEDIUMTEXT title: first page, ORDER BY title LIMIT 25, no index' => [5, false, $none, static fn($s) => array_fill(0, 5, "SELECT * FROM ::m_$s WHERE hidden = 0 ORDER BY title LIMIT 25"), $none],
            'MEDIUMTEXT title: first page, ORDER BY titleIndexed LIMIT 25, prefix index (768)' => [5, false, $none, static fn($s) => array_fill(0, 5, "SELECT * FROM ::m_$s WHERE hidden = 0 ORDER BY titleIndexed LIMIT 25"), $none],
            'MEDIUMTEXT title: last page, ORDER BY titleIndexed LIMIT 25 OFFSET n, prefix index (768)' => [1, true, $none, static fn($s) => ["SELECT * FROM ::m_$s WHERE hidden = 0 ORDER BY titleIndexed LIMIT 25 OFFSET " . (intdiv($rowCount * 19, 20) - 25)], $none],
            'ORDER BY title LIMIT 20, no index'                             => [5, false, $none, static fn($s) => array_fill(0, 5, "SELECT num, title FROM ::a_$s ORDER BY title LIMIT 20"), $none],
            'ORDER BY name LIMIT 20, indexed'                               => [100, false, $none, static fn($s) => array_fill(0, 100, "SELECT num, name FROM ::a_$s ORDER BY name LIMIT 20"), $none],
            'WHERE name = ?, indexed (point lookup)'                        => [count($quoted), false, $none, static fn($s) => array_map(static fn($name) => "SELECT num FROM ::a_$s WHERE name = $name", $quoted), $none],
            'WHERE title = ?, no index (scan)'                              => [count($titles), false, $none, static fn($s) => array_map(static fn($title) => "SELECT COUNT(*) FROM ::a_$s WHERE title = $title", $titles), $none],
            "WHERE title LIKE '%word%' (VARCHAR, scan)"                     => [2, false, $none, static fn($s) => ["SELECT COUNT(*) FROM ::a_$s WHERE title LIKE '%garden%'", "SELECT COUNT(*) FROM ::a_$s WHERE title LIKE '%café%'"], $none],
            "WHERE content LIKE '%word%' (MEDIUMTEXT, scan)"                => [2, false, $none, static fn($s) => ["SELECT COUNT(*) FROM ::a_$s WHERE content LIKE '%garden%'", "SELECT COUNT(*) FROM ::a_$s WHERE content LIKE '%café%'"], $none],
            "WHERE name LIKE 'prefix%', indexed"                            => [20, false, $none, static fn($s) => array_map(static fn($prefix) => "SELECT COUNT(*) FROM ::a_$s WHERE name LIKE '$prefix%'", ['gar', 'mar', 'sto', 'riv', 'caf', 'win', 'for', 'bri', 'mus', 'hol', 'gard', 'mark', 'ston', 'rive', 'café', 'wind', 'fore', 'brid', 'musi', 'holi']), $none],
            'GROUP BY title'                                                => [1, false, $none, static fn($s) => ["SELECT COUNT(*) FROM (SELECT title, COUNT(*) AS c FROM ::a_$s GROUP BY title) g"], $none],
            'SELECT DISTINCT title'                                         => [1, false, $none, static fn($s) => ["SELECT COUNT(*) FROM (SELECT DISTINCT title FROM ::a_$s) d"], $none],
            'COUNT(DISTINCT title)'                                         => [1, false, $none, static fn($s) => ["SELECT COUNT(DISTINCT title) FROM ::a_$s"], $none],
            'JOIN ON a.name = b.name, both indexed'                         => [1, false, $none, static fn($s) => ["SELECT COUNT(*) FROM ::a_$s a JOIN ::b_$s b ON a.name = b.name"], $none],
            'JOIN ON a.name = b.name, no index, 200 rows of a against all of b' => [1, true, $none, static fn($s) => ["SELECT COUNT(*) FROM ::a_$s a IGNORE INDEX (name) JOIN ::b_$s b IGNORE INDEX (name) ON a.name = b.name WHERE a.num <= 200"], $none],
            'UNION of two tables, then WHERE _content LIKE (searchMultipleTables)' => [1, true, $none, static fn($s) => [$search($s, '%s')], $none],
            'the same UNION with CONVERT(col USING utf8mb4) around every column' => [1, true, $none, static fn($s) => [$search($s, 'CONVERT(%s USING utf8mb4)')], $none],
            'the same UNION with COLLATE utf8mb4_unicode_ci after every column' => [1, true, $none, static fn($s) => [$search($s, '%s COLLATE utf8mb4_unicode_ci')], $none],
            'INSERT 10,000 rows, 100 per statement, index on name'          => [1, true, static fn($s) => ["TRUNCATE TABLE ::ins_$s"], static fn($s) => ["START TRANSACTION", ...array_map(static fn($values) => "INSERT INTO ::ins_$s $values", $inserts), "COMMIT"], $none],
            'INSERT 10,000 rows, 100 per statement, UNIQUE index on name'   => [1, true, static fn($s) => ["TRUNCATE TABLE ::insu_$s"], static fn($s) => ["START TRANSACTION", ...array_map(static fn($values) => "INSERT INTO ::insu_$s $values", $inserts), "COMMIT"], $none],
            'ALTER TABLE ... ADD INDEX (title)'                             => [1, true, $none, static fn($s) => ["ALTER TABLE ::b_$s ADD INDEX title (title)"], static fn($s) => ["ALTER TABLE ::b_$s DROP INDEX title"]],
        ];

        if ($only !== '') {
            $ops = array_filter($ops, static fn(string $opName) => preg_match("~$only~i", $opName) === 1, ARRAY_FILTER_USE_KEY);
        }
        $mergePasses = fn(): int => (int)$this->rows("SHOW SESSION STATUS LIKE 'Sort_merge_passes'")[0][1]; // how many times this session's sorts merged temp files

        // A SELECT on a small table takes well under a millisecond, so it runs several times per pass: 100 times at 1,000 rows, once from 100,000
        $repeat   = max(1, min(100, intdiv(100_000, $rowCount)));
        $armNames = array_keys($arms);
        $this->bench['selectRepeat'] = $repeat;
        foreach ($ops as $opName => [$divisor, $isSlow, $setup, $statements, $teardown]) {
            $opPasses = $isSlow ? min(3, $passes) : $passes;
            $first    = $statements('general_ci')[$opName[0] === 'I' ? 1 : 0]; // the INSERT ops start with START TRANSACTION
            $isSelect = str_starts_with($first, 'SELECT');
            $times    = $isSelect ? max(1, min($repeat, intdiv(200, count($statements('general_ci'))))) : 1; // an op that already sends hundreds of statements per pass isn't repeated
            try {
                if ($isSelect) {
                    $plan = [];
                    foreach ($this->db->query("EXPLAIN " . $this->sql($first))->fetch_all(MYSQLI_ASSOC) as $row) {
                        $plan[] = str_replace($this->prefix, '', "{$row['table']}: type={$row['type']}, key=" . ($row['key'] ?? 'none') . ", " . ($row['Extra'] ?? ''));
                    }
                    $this->bench['plans'][$opName] = implode(' | ', $plan);
                }
            } catch (mysqli_sql_exception $e) {
                $this->bench['plans'][$opName] = "error {$e->getCode()}: {$this->clean($e->getMessage())}";
            }
            for ($pass = 0; $pass <= $opPasses; $pass++) { // pass 0 is the uncounted first pass
                $turn  = $pass % count($armNames);
                $order = [...array_slice($armNames, $turn), ...array_slice($armNames, 0, $turn)];
                foreach ($order as $arm) {
                    $short = $arms[$arm];
                    try {
                        foreach ($setup($short) as $sql) {
                            $this->run($sql);
                        }
                        $timed  = array_map($this->sql(...), $statements($short));
                        $merges = $isSelect ? $mergePasses() : 0;
                        $start  = hrtime(true);
                        for ($time = 0; $time < $times; $time++) {
                            foreach ($timed as $sql) {
                                $result = $this->db->query($sql);
                                if ($result instanceof mysqli_result) {
                                    $result->fetch_all();
                                    $result->free();
                                }
                            }
                        }
                        $ns = hrtime(true) - $start;
                        if ($isSelect) {
                            $this->bench['mergePasses'][$opName][$arm] = intdiv($mergePasses() - $merges, $times * count($timed)); // per statement, from the last pass
                        }
                        foreach ($teardown($short) as $sql) {
                            $this->run($sql);
                        }
                    } catch (mysqli_sql_exception $e) {
                        $this->bench['errors'][$opName][$arm] = "error {$e->getCode()}: {$this->clean($e->getMessage())}";
                        continue;
                    }
                    if ($pass > 0) {
                        $this->bench['ops'][$opName][$arm][] = round($ns / 1e6 / $divisor / $times, 5); // ms per statement
                    }
                }
            }
            $note($opName);
        }

        if ($only === '') {
            $this->convertCost($note);
        }
    }

    /**
     * Time ALTER TABLE ... CONVERT on a copy of the utf8mb4_unicode_ci table with indexes on
     * name and title, in both directions, and try a SELECT and an INSERT from a second
     * connection while one convert runs.
     */
    private function convertCost(callable $note): void
    {
        $default = $this->default;
        if ($default === 'utf8mb4_unicode_ci') {
            $this->bench['convert'] = ['skipped' => 'the server default is utf8mb4_unicode_ci'];
            return;
        }
        $this->exec("DROP TABLE IF EXISTS ::conv");
        $this->exec("CREATE TABLE ::conv LIKE ::a_unicode_ci");
        $this->exec("INSERT INTO ::conv SELECT * FROM ::a_unicode_ci");
        $toDefault = "ALTER TABLE ::conv CONVERT TO CHARACTER SET utf8mb4 COLLATE $default";
        $toUnicode = "ALTER TABLE ::conv CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci";

        // One untimed round with a second connection reading and writing while the ALTER runs
        $this->bench['convert']['whileRunning'] = $this->whileAltering($toDefault);
        $this->exec($toUnicode);
        $note('tried a SELECT and an INSERT during a convert');

        // The same change written as the table default plus a MODIFY for each text column, as CMS Builder writes it
        $modifyAll = static fn(string $to) => "ALTER TABLE ::conv DEFAULT CHARACTER SET utf8mb4 COLLATE $to"
            . implode('', array_map(static fn($column) => ", MODIFY $column VARCHAR(255) CHARACTER SET utf8mb4 COLLATE $to NOT NULL", ['name', 'title', 'titleIndexed', 'summary', 'permalink']))
            . ", MODIFY content MEDIUMTEXT CHARACTER SET utf8mb4 COLLATE $to NOT NULL";
        $statements = [
            'utf8mb4_unicode_ci to the server default'         => $toDefault,
            'the server default to utf8mb4_unicode_ci'         => $toUnicode,
            'MODIFY: utf8mb4_unicode_ci to the server default' => $modifyAll($default),
            'MODIFY: the server default to utf8mb4_unicode_ci' => $modifyAll('utf8mb4_unicode_ci'),
        ];
        for ($pass = 1; $pass <= 3; $pass++) {
            foreach ($statements as $direction => $sql) {
                $start    = hrtime(true);
                $affected = $this->exec($sql);
                $this->bench['convert']['ms'][$direction][] = round((hrtime(true) - $start) / 1e6, 1);
                $this->bench['convert']['affectedRows'][$direction] = $affected;
            }
        }
        $this->exec("DROP TABLE IF EXISTS ::conv");
        $note('timed the converts');
    }

    /**
     * Start an ALTER on its own connection, then run a SELECT and an INSERT on another while it runs.
     *
     * @return array{select: string, insert: string, alterMs: float}
     */
    private function whileAltering(string $alterSql): array
    {
        $alter = ($this->connect)();
        $other = ($this->connect)();
        $other->query("SET SESSION lock_wait_timeout = 30");
        $other->query("SET SESSION innodb_lock_wait_timeout = 30");
        $isRunning = static function () use ($alter): bool {
            $read = $error = $reject = [$alter];
            return mysqli_poll($read, $error, $reject, 0, 0) === 0;
        };
        // "not blocked" when the statement came back while the ALTER was still running
        $try = function (string $sql) use ($other, $isRunning): string {
            if (!$isRunning()) {
                return 'n/a: the ALTER had finished before the statement was sent';
            }
            $start = hrtime(true);
            try {
                $result = $other->query($this->sql($sql));
                if ($result instanceof mysqli_result) {
                    $result->free();
                }
                $ms = round((hrtime(true) - $start) / 1e6);
                return $isRunning() ? "not blocked: returned in $ms ms while the ALTER was running" : "waited: returned after $ms ms, when the ALTER had finished";
            } catch (mysqli_sql_exception $e) {
                return "blocked: error {$e->getCode()}: {$e->getMessage()} (lock_wait_timeout = 30)";
            }
        };

        $start = hrtime(true);
        $alter->query($this->sql($alterSql), MYSQLI_ASYNC);
        usleep(200_000);
        $select = $try("SELECT COUNT(*) FROM ::conv WHERE num <= 100");
        $insert = $try("INSERT INTO ::conv (createdDate, updatedDate, publishDate, createdByUserNum, dragSortOrder, hidden, featured, approved, name, title, titleIndexed, summary, permalink, content) "
            . "VALUES (NOW(), NOW(), NOW(), 1, 1, 0, 0, 1, 'probe', 'probe', 'probe', 'probe', 'probe', 'probe')");
        try {
            $alter->reap_async_query();
            $alterResult = 'ok';
        } catch (mysqli_sql_exception $e) {
            $alterResult = "error {$e->getCode()}: {$this->clean($e->getMessage())}";
        }
        $alterMs = round((hrtime(true) - $start) / 1e6, 1);
        $alter->close();
        $other->close();
        $this->exec("DELETE FROM ::conv WHERE name = 'probe'");
        return ['select' => $select, 'insert' => $insert, 'alter' => $alterResult, 'alterMs' => $alterMs];
    }

    /**
     * The rows for the speed test: [name, title, content], the same on every server.
     *
     * Mostly ASCII words, with about 9% accented Latin words, 2% words in other scripts and 1%
     * emoji. `name` is two or three words and the row number, so it's unique under every
     * collation. `title` is three to eight words taken from a pool a quarter the size of the
     * table, so titles repeat, and one title in twenty is upper case. `content` is 45 to 85
     * words in sentences.
     *
     * With $titlesFile, row n's title is line n of that file instead (from the top again when
     * the file runs out), and `name` and `content` are generated as before.
     *
     * @return Generator<int, array{0: string, 1: string, 2: string}> row number from 1 => [name, title, content]
     */
    public static function corpus(int $rowCount, string $titlesFile = ''): Generator
    {
        $ascii = ['the', 'and', 'for', 'with', 'from', 'this', 'that', 'garden', 'house', 'market', 'river', 'mountain', 'street', 'coffee', 'summer', 'winter', 'spring', 'autumn', 'family', 'friend',
            'school', 'music', 'story', 'travel', 'window', 'kitchen', 'bridge', 'forest', 'island', 'village', 'morning', 'evening', 'dinner', 'lunch', 'breakfast', 'report', 'project', 'meeting',
            'product', 'service', 'customer', 'company', 'design', 'review', 'update', 'release', 'feature', 'article', 'gallery', 'contact', 'about', 'team', 'news', 'event', 'ticket', 'order', 'price',
            'quality', 'support', 'guide', 'recipe', 'weather', 'holiday', 'season', 'business', 'partner', 'community', 'history', 'museum', 'library', 'theatre', 'concert', 'festival', 'harbour',
            'station', 'airport', 'hotel', 'beach', 'lake', 'valley', 'bakery', 'flower', 'orange', 'yellow', 'purple', 'silver', 'golden', 'simple', 'modern', 'classic', 'local', 'fresh', 'quiet',
            'bright', 'little', 'great', 'new', 'old', 'first', 'last', 'best', 'open', 'free', 'daily', 'weekly', 'annual', 'north', 'south', 'east', 'west', 'city', 'country', 'world', 'people',
            'children', 'water', 'light', 'paper', 'stone', 'glass', 'green', 'blue', 'white', 'black'];
        $accented = ['café', 'résumé', 'naïve', 'Zürich', 'São', 'señor', 'niño', 'über', 'façade', 'crème', 'brûlée', 'jalapeño', 'piñata', 'déjà', 'fiancée', 'Köln', 'München', 'Québec', 'Montréal',
            'Málaga', 'Åland', 'Øresund', 'smörgåsbord', 'straße', 'Łódź', 'Kraków', 'İstanbul', 'çay', 'Dvořák', 'Škoda', 'château', 'élève', 'garçon', 'Ærø', 'œuvre', 'mañana', 'açaí', 'pâté',
            'touché', 'Noël', 'Zoë', 'Chloé', 'Pokémon'];
        $otherScripts = ['Ελλάδα', 'καλημέρα', 'Москва', 'привет', 'спасибо', '東京', '日本語', 'こんにちは', 'ありがとう', 'カタカナ', '北京', '你好', '谢谢', '서울', '안녕하세요', 'שלום', 'مرحبا', 'สวัสดี', 'नमस्ते', 'Київ'];
        $emoji        = ["\u{1F600}", "\u{1F601}", "\u{1F389}", "\u{1F680}", "\u{1F355}", "\u{1F363}", "\u{1F37A}", "\u{2764}\u{FE0F}", "\u{1F44D}", "\u{1F30D}", "\u{1F525}", "\u{2728}", "\u{1F431}", "\u{1F436}", "\u{1F4F7}", "\u{1F972}", "\u{1FAE0}"];

        mt_srand(20261117);
        $word = static function () use ($ascii, $accented, $otherScripts, $emoji): string {
            $roll = mt_rand(1, 100);
            $list = $roll <= 88 ? $ascii : ($roll <= 97 ? $accented : ($roll <= 99 ? $otherScripts : $emoji));
            return $list[mt_rand(0, count($list) - 1)];
        };
        $words = static function (int $count) use ($word): string {
            $picked = [];
            for ($i = 0; $i < $count; $i++) {
                $picked[] = $word();
            }
            return implode(' ', $picked);
        };

        $titlePool = [];
        for ($i = 0, $poolSize = max(1, intdiv($rowCount, 4)); $i < $poolSize; $i++) {
            $title       = ucfirst($words(mt_rand(3, 8)));
            $titlePool[] = mt_rand(1, 20) === 1 ? mb_strtoupper($title) : $title;
        }
        $titleLines = $titlesFile === '' ? null : new SplFileObject($titlesFile);
        $nextTitle  = static function () use ($titleLines): string {
            do {
                if ($titleLines->eof()) {
                    $titleLines->rewind();
                }
                $line = trim((string)$titleLines->fgets());
            } while ($line === '');
            return mb_substr($line, 0, 255);
        };
        for ($i = 1; $i <= $rowCount; $i++) {
            $sentences = [];
            for ($left = mt_rand(45, 85); $left > 0; $left -= $length) {
                $length      = min($left, mt_rand(8, 14));
                $sentences[] = ucfirst($words($length)) . '.';
            }
            $title = $titleLines ? $nextTitle() : $titlePool[mt_rand(0, count($titlePool) - 1)];
            yield $i => [$words(mt_rand(2, 3)) . " $i", $title, implode(' ', $sentences)];
        }
    }

    //endregion
    //region Helpers

    /**
     * Start a numbered section. Names get the section and a counter in front: "4.07 UNION".
     */
    private function startSection(int $section): void
    {
        $this->section = $section;
        $this->itemNum = 0;
    }

    /**
     * Run one check and record its answer as text. A server error is recorded as "error 1267: ...".
     */
    private function fact(string $name, callable $run): void
    {
        $name = sprintf('%d.%02d %s', $this->section, ++$this->itemNum, $name);
        try {
            $this->facts[$name] = $this->clean((string)$run());
        } catch (NotApplicable $e) {
            $this->facts[$name] = "n/a: {$e->getMessage()}";
        } catch (mysqli_sql_exception $e) {
            $this->facts[$name] = "error {$e->getCode()}: {$this->clean($e->getMessage())}";
        }
    }

    /**
     * Start a numbered grid and return its full name, for cell().
     */
    private function grid(string $name): string
    {
        $name               = sprintf('%d.%02d %s', $this->section, ++$this->itemNum, $name);
        $this->grids[$name] = [];
        return $name;
    }

    /**
     * Run one check and record it as a grid cell.
     *
     * @param callable $run Returns the short answer, or [short answer, detail]. A server error is recorded as ["error 1267", the message]
     */
    private function cell(string $grid, string $row, string $column, callable $run): void
    {
        try {
            $answer = $run();
            $answer = is_array($answer) ? $answer : [$answer, ''];
        } catch (NotApplicable $e) {
            $answer = ['n/a', $e->getMessage()];
        } catch (mysqli_sql_exception $e) {
            $answer = ["error {$e->getCode()}", $e->getMessage()];
        }
        $this->grids[$grid][$row][$column] = [$this->clean((string)$answer[0]), $this->clean((string)$answer[1])];
    }

    /**
     * Drop every table the probe created.
     */
    public function dropTables(): void
    {
        $this->db->query("SET SESSION foreign_key_checks = 0");
        $pattern = str_replace('_', '\_', $this->prefix) . '%';
        foreach ($this->db->query("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME LIKE '$pattern'")->fetch_all() as [$table]) {
            $this->db->query("DROP TABLE IF EXISTS `$table`");
        }
        $this->db->query("SET SESSION foreign_key_checks = 1");
    }

    /**
     * "utf8mb4_unicode_ci" → "unicode_ci", for table names and narrow table cells.
     */
    public static function short(string $collation): string
    {
        return str_replace('utf8mb4_', '', $collation);
    }

    /**
     * A collation for a grid cell: "server default" when it's the one a new table gets on this
     * server, so servers with different defaults give the same answer; otherwise the short name.
     *
     * @param bool $alwaysByName True when the statement names its collation, so the name is the answer
     */
    private function orDefault(string $collation, bool $alwaysByName = false): string
    {
        return $collation === $this->default && !$alwaysByName ? 'server default' : self::short($collation);
    }

    /**
     * Replace `::` with the table prefix.
     */
    private function sql(string $sql): string
    {
        return str_replace('::', $this->prefix, $sql);
    }

    /**
     * Take the table prefix out of text the server sent back, so every server's answer reads the same.
     */
    private function clean(string $text): string
    {
        return str_replace($this->prefix, '', $text);
    }

    /**
     * Run a statement that returns no rows. Returns the affected row count.
     */
    private function exec(string $sql): int
    {
        $this->db->query($this->sql($sql));
        return (int)$this->db->affected_rows;
    }

    /**
     * Run a statement and throw its rows away.
     */
    private function run(string $sql): void
    {
        $result = $this->db->query($this->sql($sql));
        if ($result instanceof mysqli_result) {
            $result->free();
        }
    }

    /**
     * @return list<list<string|null>>
     */
    private function rows(string $sql): array
    {
        $result = $this->db->query($this->sql($sql));
        return $result instanceof mysqli_result ? $result->fetch_all() : [];
    }

    /**
     * The first column of the first row.
     */
    private function value(string $sql): ?string
    {
        return $this->rows($sql)[0][0] ?? null;
    }

    /**
     * The warnings from the last statement as "warning 1287: text; ...", or '' for none.
     */
    private function warnings(): string
    {
        return implode('; ', array_map(static fn(array $row) => strtolower($row[0]) . " $row[1]: $row[2]", $this->rows("SHOW WARNINGS")));
    }

    private function tableCollation(string $table, ?mysqli $db = null): string
    {
        return (string)($db ?? $this->db)->query("SELECT TABLE_COLLATION FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '{$this->prefix}$table'")->fetch_row()[0];
    }

    private function columnCollation(string $table, string $column, ?mysqli $db = null): string
    {
        return (string)($db ?? $this->db)->query("SELECT COLLATION_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '{$this->prefix}$table' AND COLUMN_NAME = '$column'")->fetch_row()[0];
    }

    /**
     * The table's collation, or "table x, column y" when its column has another one.
     */
    private function collations(string $table, string $column, ?mysqli $db = null): string
    {
        $ofTable  = $this->tableCollation($table, $db);
        $ofColumn = $this->columnCollation($table, $column, $db);
        return $ofTable === $ofColumn ? $ofTable : "table $ofTable, column $ofColumn";
    }

    /**
     * SHOW CREATE TABLE on one line, without the AUTO_INCREMENT counter.
     */
    private function showCreate(string $table): string
    {
        $create = (string)$this->rows("SHOW CREATE TABLE ::$table")[0][1];
        return preg_replace(['/\s+/', '/ AUTO_INCREMENT=\d+/'], [' ', ''], $create); // newlines and indents → one space
    }

    //endregion
}

//region Report

/**
 * The middle value of a list of numbers.
 */
function median(array $numbers): float
{
    sort($numbers);
    $count = count($numbers);
    return $count % 2 ? (float)$numbers[intdiv($count, 2)] : ($numbers[$count / 2 - 1] + $numbers[$count / 2]) / 2;
}

/**
 * Milliseconds with three significant digits: 1234, 123, 12.3, 1.23, 0.123.
 */
function formatMs(float $ms): string
{
    return match (true) {
        $ms >= 100 => number_format($ms, 0, '.', ''),
        $ms >= 10  => number_format($ms, 1),
        $ms >= 1   => number_format($ms, 2),
        default    => number_format($ms, 3),
    };
}

/**
 * Server label for a table heading: "percona/percona-server:8.0" → "Percona 8.0".
 */
function shortServer(string $server): string
{
    return str_replace(['percona/percona-server:', 'mariadb:', 'mysql:'], ['Percona ', 'MariaDB ', 'MySQL '], $server);
}

/**
 * The group a server's speed numbers are summarized in. Servers in one group have the same collations.
 */
function speedGroup(string $server): string
{
    [$vendor, $version] = explode(':', $server, 2) + [1 => '0'];
    if ($vendor === 'mariadb') {
        return match (true) {
            version_compare($version, '11.4', '>=')  => 'MariaDB 11.4 and later',
            version_compare($version, '10.10', '>=') => 'MariaDB 10.10 to 11.3',
            default                                  => 'MariaDB 10.2 to 10.9',
        };
    }
    return version_compare($version, '8.0', '>=') ? 'MySQL and Percona 8.0 and later' : 'MySQL and Percona 5.7';
}

/**
 * "all servers", a family label such as "MariaDB 10.5+", or the server names.
 */
function who(array $group, int $total, array $families): string
{
    return count($group) === $total ? 'all servers' : (serverGroupLabel($group, $families) ?? implode(', ', array_map('shortServer', $group)));
}

/**
 * Print one fact: the answer when every server gave the same one, or each answer with its servers.
 */
function printFact(string $name, array $reports, array $families): void
{
    $serversByAnswer = [];
    foreach ($reports as $server => $report) {
        $serversByAnswer[$report['facts'][$name] ?? '(no data)'][] = $server;
    }
    echo "**$name**\n\n";
    foreach ($serversByAnswer as $answer => $group) {
        echo "- " . who($group, count($reports), $families) . ": " . str_replace(["\r", "\n"], ' ', (string)$answer) . "\n";
    }
    echo "\n";
}

/**
 * Print one grid as a table. A cell has the answer when every server that could run the check
 * gave the same one; otherwise a number that points at the list under the table.
 */
function printGrid(string $name, array $reports, array $families): void
{
    $rows    = [];
    $columns = [];
    foreach ($reports as $report) {
        foreach ($report['grids'][$name] ?? [] as $row => $cells) {
            $rows[$row] = true;
            foreach ($cells as $column => $cell) {
                $columns[$column] = true;
            }
        }
    }
    echo "**$name**\n\n";
    echo "| | " . implode(' | ', array_map(static fn($column) => str_replace('|', '\|', (string)$column), array_keys($columns))) . " |\n";
    echo "|---|" . str_repeat('---|', count($columns)) . "\n";
    $differs = []; // lines for the list under the table
    $details = []; // detail text => [where it applies => servers]
    foreach (array_keys($rows) as $row) {
        $line = [];
        foreach (array_keys($columns) as $column) {
            $serversByAnswer = [];
            $notApplicable   = [];
            foreach ($reports as $server => $report) {
                $cell = $report['grids'][$name][$row][$column] ?? null;
                if ($cell === null) {
                    continue;
                }
                if ($cell[0] === 'n/a') {
                    $notApplicable[] = $server;
                    continue;
                }
                $serversByAnswer[$cell[0]][] = $server;
                if ($cell[1] !== '') {
                    $details[$cell[1]]["$row / $column"][] = $server;
                }
            }
            if (!$serversByAnswer) {
                $line[] = 'n/a';
            } elseif (count($serversByAnswer) === 1) {
                $answered = reset($serversByAnswer);
                $line[]   = array_key_first($serversByAnswer) . ($notApplicable && count($reports) > 1 ? ' (' . count($answered) . ')' : '');
            } else {
                $number  = count($differs) + 1;
                $line[]  = "differs [$number]";
                $parts   = [];
                foreach ($serversByAnswer as $answer => $group) {
                    $parts[] = "$answer on " . who($group, count($reports), $families);
                }
                $differs[] = "[$number] $row / $column: " . implode('; ', $parts);
            }
        }
        echo "| " . str_replace('|', '\|', (string)$row) . " | " . implode(' | ', $line) . " |\n";
    }
    echo "\n";
    foreach ($differs as $line) {
        echo "- $line\n";
    }
    if ($differs) {
        echo "\n";
    }
    if ($details) {
        echo "Details:\n\n";
        foreach ($details as $detail => $places) {
            $servers = array_values(array_unique(array_merge(...array_values($places))));
            $where   = count($places) > 3 ? count($places) . ' cells, first ' . array_key_first($places) : implode('; ', array_keys($places));
            echo "- " . str_replace(["\r", "\n"], ' ', (string)$detail) . " ($where; " . who($servers, count($reports), $families) . ")\n";
        }
        echo "\n";
    }
}

/**
 * Merge the JSON files written by --json into one markdown report: the findings, every
 * check with the servers that differ, and the speed numbers.
 */
function mergeReports(array $paths): void
{
    // Several files for one server are several runs. The checks are printed from the first
    // one and compared with the rest; the speed test keeps every run
    $reports        = []; // server => report
    $runDifferences = []; // "server, check: first answer / other answer"
    foreach ($paths as $path) {
        $report = is_file($path) ? json_decode(file_get_contents($path), true) : null;
        if (!isset($report['server'], $report['facts'], $report['grids'])) {
            fwrite(STDERR, "Skipping $path: not a collation-probe JSON file\n");
            continue;
        }
        $server            = $report['server'];
        $reports[$server] ??= $report + ['runs' => 0, 'benchRuns' => []];
        $reports[$server]['runs']++;
        if (!empty($report['bench']['ops'])) {
            $reports[$server]['benchRuns'][benchSet($report['bench'])][] = $report['bench']; // one list of runs per table size and title source
        }
        foreach ($report['facts'] as $name => $answer) {
            $first = $reports[$server]['facts'][$name] ?? $answer;
            if ($first !== $answer) {
                $runDifferences["$server, $name: $first / $answer"] = true;
            }
        }
        foreach ($report['grids'] as $name => $rows) {
            foreach ($rows as $row => $cells) {
                foreach ($cells as $column => $cell) {
                    $first = $reports[$server]['grids'][$name][$row][$column][0] ?? $cell[0];
                    if ($first !== $cell[0]) {
                        $runDifferences["$server, $name, $row / $column: $first / $cell[0]"] = true;
                    }
                }
            }
        }
    }
    uksort($reports, static fn($a, $b) => databaseSortKey($a) <=> databaseSortKey($b));

    echo "# Collation Matrix\n\n";
    if (!$reports) {
        echo "No probe data found.\n";
        return;
    }
    $servers  = array_keys($reports);
    $families = serverFamilies($servers);
    echo "The utf8mb4 collations on every supported database server: which ones each server has, which one a new table gets, how fast each one is, what converting a table costs, what fails between two collations, and which values compare equal. ";
    echo "Generated by the \"Collation Matrix\" workflow (.github/workflows/collation-matrix.yml); regenerate with:\n\n";
    echo "    gh workflow run collation-matrix.yml   # wait for the run to finish, then:\n";
    echo "    gh run download --dir probes           # pick the latest Collation Matrix run\n";
    echo "    php .github/scripts/collation-probe.php --merge probes/*/collation-probe-*.json > docs/internal/collation-matrix.md\n\n";
    $runCounts = array_unique(array_column($reports, 'runs'));
    echo "Last generated: " . date('Y-m-d') . " from " . count($reports) . " servers, " . (count($runCounts) === 1 ? reset($runCounts) : min($runCounts) . ' to ' . max($runCounts)) . " run(s) of each. Generated file - don't hand-edit. ";
    echo "The \"Summary\", \"Verdicts\" and \"Findings\" sections below are maintained in heredocs in .github/scripts/collation-probe.php; edit them there.\n\n";
    echo "Servers: " . implode(', ', array_map(static fn($s) => "$s ({$reports[$s]['version']})", $servers)) . "\n\n";

    // The speed test's reports for one table size: server => report, with that size's runs in 'benchRuns'
    $sizes = [];
    foreach ($reports as $report) {
        $sizes += array_fill_keys(array_keys($report['benchRuns']), true);
    }
    uksort($sizes, static fn($a, $b) => [(int)$b, (string)$a] <=> [(int)$a, (string)$b]); // largest first, the generated titles before a file's
    $benchedAt = static function (string|int $size) use ($reports): array {
        $benched = [];
        foreach ($reports as $server => $report) {
            if (isset($report['benchRuns'][$size])) {
                $benched[$server] = ['benchRuns' => $report['benchRuns'][$size]] + $report;
            }
        }
        return $benched;
    };
    echo <<<'__SUMMARY__'
        ## Summary

        - Fastest for sorting a large table by a text column: `utf8mb4_0900_ai_ci` on MySQL and Percona 8.0+, and `utf8mb4_general_ci` on MySQL 5.7 and every MariaDB. That is the server default except on MariaDB 11.4+, where the default `utf8mb4_uca1400_ai_ci` sorts a 1,000,000-row `MEDIUMTEXT` column about 8x slower than `utf8mb4_general_ci`
        - `utf8mb4_unicode_ci`, while it is supported everywhere, is never the fastest. At 1,000,000 rows it sorts a `MEDIUMTEXT` column 6 to 8x slower than the fastest collation on every server tested; on a 10,000-row table a list page differs by about 2 ms between any two collations
        - A prefix index on a `MEDIUMTEXT` column, such as `(768)`, is never used for ORDER BY, so a list sorted by a `MEDIUMTEXT` column sorts the whole table
        - The large differences come from sorts that don't fit in `sort_buffer_size` and go to temp files; with more sort memory they mostly go away
        - Two collations in one UNION or JOIN fail with error 1271 or 1267; "Verdicts" compares the ways back to one collation


        __SUMMARY__;

    // The largest table with the generated titles, then each title file at its largest size
    $fastestSets = [];
    foreach (array_keys($sizes) as $size) {
        $titles = explode(' ', (string)$size, 2)[1] ?? '';
        $fastestSets[$titles] ??= $size;
    }
    foreach ($fastestSets as $size) {
        printFastest($benchedAt($size));
    }

    echo <<<'__FINDINGS__'
        ## Verdicts

        CMS Builder 3.84 created every table with `COLLATE=utf8mb4_unicode_ci`. Later versions write `CHARSET=utf8mb4` with no COLLATE, so a new table gets the server's default, an upgraded site has tables in two collations, and a UNION or JOIN across them fails with error 1271 or 1267. Three ways back to one collation, on every server in the matrix (MySQL 5.7 to 9.7, MariaDB 10.2.6 to 12.3, Percona Server 5.7 to 8.4):

        | Option | What it costs | What it risks |
        |---|---|---|
        | A. Every table in the server default; the CMS upgrade converts existing tables | One ALTER per table. Every MySQL and Percona version and MariaDB thru 10.5 copy the table (1.4 to 2.7 s per 100,000 rows here, 21 to 44 s per 1,000,000) and writes wait until it's done. MariaDB 10.6+ rebuilds only the indexes (about 0.3 s per 100,000 rows, 2.6 to 4.4 s per 1,000,000) and writes continue. It is the fastest of the ci collations on MySQL 8.0+, MySQL 5.7 and MariaDB thru 10.11. On MariaDB 11.4+ the default `utf8mb4_uca1400_ai_ci` sorts a 1,000,000-row `MEDIUMTEXT` title in about 8x the time `utf8mb4_general_ci` takes | "The server default" is three different collations, and it changes when the server is upgraded: old tables keep theirs, new tables get the new one, and the two-collation error is back until the convert runs again. Query results change with it (trailing spaces on MySQL 8.0+; ß, æ, ø and emoji everywhere). The convert stops with error 1062 on a UNIQUE column that holds two values the new collation treats as equal. A dump with `utf8mb4_0900_ai_ci` or `utf8mb4_uca1400_ai_ci` doesn't load on an older server or on the other vendor |
        | B. Every table in `utf8mb4_unicode_ci` | No convert and no change in results for a site that came from 3.84. At 100,000 rows, sorts, GROUP BY, DISTINCT and text joins take 1.3x to 2.1x the default's time on MySQL 5.7 and MariaDB thru 10.6, and 1.2x to 2.6x on MySQL 8.0+. A sort of the whole table with no index grows faster than the table does: at 1,000,000 rows and the default `sort_buffer_size` it takes 14.7 s against 0.8 s on MySQL 8.0+, 13.8 s against 2.8 s on MySQL 5.7 and about 8.6 s against 3.3 s on MariaDB thru 10.4 (see the table above). With a `MEDIUMTEXT` title, as CMS Builder makes a text field, that sort takes 7 to 12 s against 1.2 to 2.0 s on every server tested, MariaDB 11.8 included. On MariaDB 11.4+ it is up to 14% faster than the default on the `VARCHAR` statements. Point lookups, ORDER BY on an indexed column and INSERT cost the same on every server | Tables created in the server default since the CMS stopped writing COLLATE need the same convert as option A, in the other direction, with the same error 1062 on a UNIQUE column. Any two emoji compare as equal |
        | C. New tables copy the collation the site's existing tables have | No convert, no speed change and no change in results | Sites differ from each other: a new install gets the server default, with option A's dump limits. A site that already has two collations still needs one convert and a rule for which collation stays. A table whose default was changed without its columns (`ALTER TABLE t CHARACTER SET utf8mb4`) reports one collation and holds another |

        `utf8mb4_unicode_ci` is the only one of the three that exists on all 19 servers under one name and stays the same through a server upgrade. Its price is the sort and grouping times in the speed tables below.

        For sorting a large table by a text column, the fastest collation on each server is `utf8mb4_0900_ai_ci` on MySQL and Percona 8.0+ and `utf8mb4_general_ci` on everything else, MariaDB 11.4+ included. That is the server default except on MariaDB 11.4+. `utf8mb4_unicode_ci` is never the fastest for a whole-table sort. The difference shows on large tables only: a list page on 10,000 rows differs by about 2 ms between any two collations.

        ## Findings

        Every run gave the same answer for every check: three runs at 100,000 rows and one each at 1,000, 10,000 and 1,000,000. A finding is the same on all 19 servers unless it names versions.

        What a new table gets:

        - `CREATE TABLE ... CHARSET=utf8mb4` with no COLLATE gives `utf8mb4_general_ci` on MySQL and Percona 5.7 and MariaDB 10.2.6 to 10.11, `utf8mb4_0900_ai_ci` on MySQL and Percona 8.0 to 9.7, and `utf8mb4_uca1400_ai_ci` on MariaDB 11.4, 11.8 and 12.3. `ALTER TABLE ... CONVERT TO CHARACTER SET utf8mb4` with no COLLATE gives the same collation, from a latin1 table and from a `utf8mb4_unicode_ci` table
        - MariaDB 11.4 gets its answer from the image's config file, which has the line `character-set-collations = utf8mb4=uca1400_ai_ci`. The built-in default changed in MariaDB 11.5 ([MDEV-25829](https://jira.mariadb.org/browse/MDEV-25829)), so an 11.4 server without that line gives `utf8mb4_general_ci`. The 11.8 and 12.3 images have no such line and give `utf8mb4_uca1400_ai_ci`
        - The database's default collation, `collation_server` and `SET NAMES ... COLLATE` don't change what a `CHARSET=utf8mb4` table gets. They only apply to a CREATE TABLE with no CHARSET. Two settings do change it: `default_collation_for_utf8mb4` on MySQL 8.0+ and `character_set_collations` on MariaDB 11.4+
        - `SHOW CHARACTER SET LIKE 'utf8mb4'`, column `Default collation`, named the collation a new table gets on every server in all seven states tried. `information_schema.CHARACTER_SETS.DEFAULT_COLLATE_NAME` and `COLLATION(CONVERT('a' USING utf8mb4))` are wrong on MySQL 8.0+ once `default_collation_for_utf8mb4` is changed. `information_schema.COLLATIONS ... IS_DEFAULT = 'Yes'` returns no row on MariaDB 11.4+. `@@collation_server`, `@@collation_database` and `@@collation_connection` are each wrong in at least two states
        - `utf8mb4_bin`, `utf8mb4_general_ci`, `utf8mb4_unicode_ci` and `utf8mb4_unicode_520_ci` exist on all 19 servers, and none prints a warning. `utf8mb4_0900_ai_ci` exists on MySQL and Percona 8.0+ and on MariaDB 11.4+. `utf8mb4_uca1400_ai_ci` exists on MariaDB 10.11+ and on no MySQL. A server that doesn't have one answers `error 1273: Unknown collation: 'utf8mb4_0900_ai_ci'`
        - A server upgrade keeps every existing column's collation and changes what a new table gets. The upgrade job started MySQL 8.0 on a MySQL 5.7 data directory and MariaDB 11.8 on a MariaDB 10.11 one: the old tables stayed `utf8mb4_general_ci`, a new `CHARSET=utf8mb4` table got `utf8mb4_0900_ai_ci` and `utf8mb4_uca1400_ai_ci`, and a UNION of an old and a new table failed with error 1271. The database default stayed `utf8mb4_general_ci`, so a CREATE TABLE with no CHARSET, and a column added to an old table, still got `utf8mb4_general_ci`. `CHECK TABLE ... FOR UPGRADE` reported nothing to rebuild

        Sorting a large table, at 1,000,000 rows: a second run on MySQL 5.7, 8.0, 8.4 and MariaDB 10.4, 11.8 with the generated titles, and a run on MySQL 8.0, 8.4 and MariaDB 10.6, 10.11, 11.8 with English Wikipedia article titles in plain ASCII (the tables at the top):

        - The fastest collation for sorting by title is the same with English titles as with the generated ones: `utf8mb4_0900_ai_ci` on MySQL 8.0+ and `utf8mb4_general_ci` on MariaDB and on MySQL 5.7. Sorting every row of an English `VARCHAR(255)` title with no index takes 0.6 to 0.8 s in `utf8mb4_0900_ai_ci`, 1.6 to 3.3 s in `utf8mb4_general_ci` and 9.1 to 14.2 s in `utf8mb4_unicode_ci` on MySQL 8.0 and 8.4. On MariaDB 10.6 to 11.8 it takes 1.9 to 2.1 s in `utf8mb4_general_ci` and 2.0 to 2.4 s in the others
        - A `MEDIUMTEXT` title, the type CMS Builder gives a text field, makes the difference larger. Sorting every row of English titles takes 1.2 to 1.8 s in `utf8mb4_0900_ai_ci` and 10.0 to 12.3 s in both `utf8mb4_general_ci` and `utf8mb4_unicode_ci` on MySQL 8.0 and 8.4. On MariaDB 10.6 to 11.8 it takes 1.4 to 1.5 s in `utf8mb4_general_ci` and 7.3 to 11.9 s in `utf8mb4_unicode_ci`, `utf8mb4_unicode_520_ci`, `utf8mb4_0900_ai_ci` and `utf8mb4_uca1400_ai_ci`, so the MariaDB 11.4+ default takes about 8x as long as `utf8mb4_general_ci`. The generated titles agree: MySQL 5.7 4.5 s against 10.6 s, MariaDB 10.4 2.0 s against 9.7 s
        - A prefix index on a `MEDIUMTEXT` column, `titleIndexed(768)`, is never used for ORDER BY: EXPLAIN shows `type=ALL, Using filesort` on every server, for the first page and the last, and the first page takes the same time with it as without it (342 ms against 342 ms on MySQL 8.0). Every list page sorted by a CMS Builder text field sorts the table
        - The first list page (`LIMIT 25`) with a `MEDIUMTEXT` title takes 342 to 470 ms in `utf8mb4_0900_ai_ci` on MySQL 8.0 and 8.4, and `utf8mb4_unicode_ci` takes 1.3x that. On MariaDB the collations are within 20% of each other there, and on MariaDB 10.4 and 10.6 `utf8mb4_unicode_ci` was the fastest (263 ms against 325 ms on 10.6), as it was for the `VARCHAR` title on MariaDB 10.3 to 10.6
        - The last page of a list, at an OFFSET of 19/20 of the rows, costs about what sorting every row costs, with or without an index on a `VARCHAR` title. Only MariaDB 11.8 read the index for it (`type=index`), and took 1.6 s in every collation; every other server sorted the table
        - The large differences come from sorts that don't fit in `sort_buffer_size` and go to temp files. With `SET SESSION sort_buffer_size` at 2 GB the sort stays in memory and the differences mostly go away: English `VARCHAR` titles on MySQL 8.0 and 8.4 take 1.4 to 1.7 s in `utf8mb4_unicode_ci` against 0.7 to 0.8 s in `utf8mb4_0900_ai_ci`, and `MEDIUMTEXT` titles on MariaDB 11.8 take 2.2 s in `utf8mb4_uca1400_ai_ci` against 1.8 s in `utf8mb4_general_ci`. At 64 MB, `utf8mb4_unicode_ci` on MySQL takes 5.0 to 5.6 s. The slow collations write more to the temp files: the server merged them 628 times for `utf8mb4_unicode_ci` and 41 times for `utf8mb4_0900_ai_ci` in one MySQL 8.4 sort. Why their sort values are larger was not looked into in the server code
        - A larger `sort_buffer_size` isn't free: on MariaDB the whole-table sort took 10% to 20% longer at 2 GB than at the default, in every collation

        Speed at 1,000,000 rows, the first run on all 19 servers:

        - The fastest ci collation is `utf8mb4_0900_ai_ci` on MySQL and Percona 8.0+ and `utf8mb4_general_ci` on MySQL and Percona 5.7 and on every MariaDB version. That is the server default everywhere except MariaDB 11.4+, where the default `utf8mb4_uca1400_ai_ci` takes longer than `utf8mb4_general_ci`: 6% on the whole-table sort, 19% on the first list page, 15% on GROUP BY, and 2.5x on `content LIKE '%word%'` (2,158 ms against 864 ms)
        - Sorting every row by a column with no index, which is the sort the last page of a list ordered by `title` needs: `utf8mb4_unicode_ci` takes 14.7 s against 0.8 s for `utf8mb4_0900_ai_ci` on MySQL and Percona 8.0+ (18x; it was 2.6x at 100,000 rows), 13.8 s against 2.8 s for `utf8mb4_general_ci` on MySQL and Percona 5.7 (4.9x; it was 1.36x), and 7.9 to 9.5 s against 2.2 to 3.7 s on MariaDB 10.2 to 10.4. MariaDB 10.5 and 10.6 are 1.25x and 1.31x, MariaDB 10.11 is 1.11x, and on MariaDB 11.4+ `utf8mb4_unicode_ci` is within 5% of the default
        - Those whole-table sorts ran with each server's default `sort_buffer_size`, which 1,000,000 titles don't fit in, so the server sorts in temp files. The ratios are for default settings; the section above has the same sorts with more memory
        - The first list page (`LIMIT 25`, no index on `title`) keeps the ratios it had at 100,000 rows: `utf8mb4_unicode_ci` takes 1.46x the default's time on MySQL 5.7 (691 ms against 472 ms), 1.35x on MySQL 8.0+ (837 ms against 597 ms), 0.97x on MariaDB thru 10.6, 1.12x on MariaDB 10.11 and 0.91x on MariaDB 11.4+. The same page sorted by an integer column takes 225 to 285 ms (470 ms on MySQL 8.0+). With an index on the ORDER BY column it takes 0.1 to 0.3 ms in every collation
        - On MariaDB 10.3 to 10.6 the first list page was 3% to 24% faster in `utf8mb4_unicode_ci` than in `utf8mb4_general_ci` in this run. At 100,000 rows the two were the same there (0.99x over three runs)
        - GROUP BY, SELECT DISTINCT, COUNT(DISTINCT), a join on an indexed text column and ADD INDEX: on MySQL and Percona 8.0+ `utf8mb4_unicode_ci` is within 3% of the default. On MySQL and Percona 5.7 it takes 1.43x to 1.74x the default's time, and on MariaDB thru 10.6 1.10x to 1.93x

        Speed at 100,000 rows, three runs:

        - A statement that reads rows through an index costs the same in every collation: a point lookup, ORDER BY an indexed column with a LIMIT, and the list page with an index on its ORDER BY column (0.2 ms at every table size). So do `WHERE title = ?` over the whole table and INSERT into a table with a plain index. No collation differs from another there by more than the control does (0.94x to 1.06x)
        - `utf8mb4_unicode_ci` against `utf8mb4_general_ci` on MySQL and Percona 5.7 and on MariaDB 10.2 to 10.6, where `utf8mb4_general_ci` is the default: ORDER BY with no index 1.36x and 1.30x, GROUP BY 1.42x and 1.29x, COUNT(DISTINCT) 1.60x and 2.11x, a join on an indexed text column 1.44x and 1.66x, ALTER TABLE ... ADD INDEX 1.38x and 1.68x, the searchMultipleTables() query 1.12x. On MariaDB 10.11 the same statements are 1.05x to 1.20x
        - `utf8mb4_unicode_ci` against `utf8mb4_0900_ai_ci` on MySQL and Percona 8.0+: ORDER BY with no index 2.60x (221 ms against 84 ms), GROUP BY 1.24x, SELECT DISTINCT 1.26x, a join with no index 1.55x, the searchMultipleTables() query 1.29x, INSERT into a UNIQUE text index 1.60x. A join on an indexed text column, COUNT(DISTINCT) and ADD INDEX are within 3%. `utf8mb4_0900_ai_ci` also sorts faster than `utf8mb4_general_ci` there (0.60x)
        - `utf8mb4_unicode_ci` against `utf8mb4_uca1400_ai_ci` on MariaDB 11.4+: 0.86x to 1.00x on every statement, so the default is the slower one. `utf8mb4_general_ci` is the fastest ci collation on every MariaDB version: `content LIKE '%word%'` takes 146 ms in `utf8mb4_general_ci` and 337 ms in each of the other four on MariaDB 11.4+
        - `utf8mb4_unicode_520_ci` costs the same as `utf8mb4_unicode_ci` on every server (within 3% on most statements)
        - `utf8mb4_bin` is not always the floor: ORDER BY with no index is 1.2x to 1.35x the `utf8mb4_general_ci` time on MySQL and on MariaDB thru 10.6
        - Table and index size on disk is the same for all six collations: 83.6 MB of data and 10.1 MB of indexes

        The list page, `SELECT * FROM t WHERE hidden = 0 ORDER BY title LIMIT 25` with no index on `title`, at 1,000, 10,000 and 100,000 rows:

        - The same query sorted by an integer or a datetime column is the part no collation touches: 0.3 to 0.7 ms at 1,000 rows, 1.2 to 4.7 ms at 10,000 and 25 ms at 100,000 (60 ms on MySQL 8.0+). It is the same in every collation's table
        - Sorting by `title` adds to that, at 100,000 rows: 20 ms in `utf8mb4_general_ci` and 43 ms in `utf8mb4_unicode_ci` on MySQL 5.7; 13 ms in `utf8mb4_0900_ai_ci` and 43 ms in `utf8mb4_unicode_ci` on MySQL 8.0+; about 30 ms in either on MariaDB thru 10.6; 27 ms in `utf8mb4_unicode_ci` and 31 ms in `utf8mb4_uca1400_ai_ci` on MariaDB 11.4+
        - So the text sort is 43% of the whole query in `utf8mb4_general_ci` and 62% in `utf8mb4_unicode_ci` on MySQL 5.7, 18% in `utf8mb4_0900_ai_ci` and 42% in `utf8mb4_unicode_ci` on MySQL 8.0+, and a little over half in every collation on MariaDB
        - `utf8mb4_unicode_ci` against the server default, whole query: MySQL 5.7 0.94 ms against 0.67 ms at 1,000 rows, 5.5 against 3.4 at 10,000, 69 against 46 at 100,000. MySQL 8.0+ 0.94 against 0.75, 7.5 against 5.4, 103 against 74. That is 0.2 to 0.3 ms more for each 1,000 rows in the table, in a straight line. MariaDB thru 10.6 shows no difference at any size, and on MariaDB 11.4+ `utf8mb4_unicode_ci` is the faster one by 6 ms at 100,000 rows

        Converting a table:

        - `ALTER TABLE ... CONVERT TO CHARACTER SET utf8mb4 COLLATE x` on 100,000 rows (84 MB, six text columns, two text indexes) takes 1.5 to 2.7 s on MySQL and Percona (all versions) and 1.4 to 2.3 s on MariaDB thru 10.5. Those servers copy the table: 100,000 rows affected, a SELECT from another connection runs meanwhile, and an INSERT waits until the ALTER is done. MariaDB 10.6+ takes 0.25 to 0.36 s, reports 0 rows affected and doesn't block the INSERT. Both directions cost the same
        - On 1,000,000 rows (834 MB) the same convert takes 24 to 44 s on MySQL and Percona and 21 to 44 s on MariaDB thru 10.5, and an INSERT from another connection waits that long. On the servers that took over 30 s the probe's INSERT stopped at its own lock wait limit with error 1205. MariaDB 10.6+ takes 2.6 to 4.4 s and doesn't block the INSERT
        - The form CMS Builder writes, `ALTER TABLE t DEFAULT CHARACTER SET utf8mb4 COLLATE x, MODIFY a ... COLLATE x, MODIFY b ... COLLATE x`, takes the same time and uses the same algorithm as CONVERT TO on every server, and leaves the table default and each listed column on x. A `utf8mb4_bin` column it doesn't list stays `utf8mb4_bin`; CONVERT TO changes that column too, and a latin1 column
        - After the MODIFY form, MySQL and Percona 8.0+ print `CHARACTER SET utf8mb4 COLLATE x` on each modified column in SHOW CREATE TABLE, even when x is the table default. After CONVERT TO they print none. MariaDB 10.3+ prints none in both cases. MySQL 5.7 and MariaDB 10.2 print a column's COLLATE whenever it isn't `utf8mb4_general_ci`, however the table was made
        - A column with no index changes collation without a table copy on MySQL and Percona 8.0+ (`ALGORITHM=INPLACE` works, 0 rows affected) and on MariaDB 10.4+ (`ALGORITHM=INSTANT` works for CONVERT TO, `ALGORITHM=NOCOPY` for the MODIFY form). MySQL and Percona 5.7 and MariaDB thru 10.3 copy the table
        - An indexed column: `ALGORITHM=INPLACE` is `error 1846: ALGORITHM=INPLACE is not supported. Reason: Cannot change column type INPLACE. Try ALGORITHM=COPY.` on every MySQL and Percona version and on MariaDB thru 10.5 (10.4 and 10.5 give the reason as "Collation change on an indexed column"). It works on MariaDB 10.6+. `ALGORITHM=INPLACE, LOCK=NONE` on a column with no index is error 1845 on MySQL 8.4 and 9.7 and works on MySQL 8.0 and 9.6. `ALGORITHM=COPY, LOCK=NONE` works on MariaDB 11.4+ only
        - `ALTER TABLE t CHARACTER SET utf8mb4`, with no CONVERT and no MODIFY, changes the table default only. The existing columns keep `utf8mb4_unicode_ci`, SHOW CREATE TABLE then prints `CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci` on each of them, and a column added afterwards gets the new default. That is two collations inside one table
        - A text column in a foreign key stops the convert of either table: error 1832 for the child and 1833 for the parent, and error 3780 for both on MySQL and Percona 8.0+. With `foreign_key_checks = 0` both tables convert on MySQL and Percona and on MariaDB thru 10.3, and the key is still enforced afterwards. MariaDB 10.4+ refuses even then
        - A STORED or indexed VIRTUAL generated column, a FULLTEXT index and prefix indexes convert without error, and the prefix lengths stay as they were

        Two collations in one statement:

        - Between any two ci collations these fail: UNION and UNION ALL (`error 1271: Illegal mix of collations for operation 'UNION'`), `JOIN ... ON a.name = b.name`, `USING (name)`, `IN (subquery)`, `= (subquery)`, `<` and LIKE between the two columns (`error 1267: Illegal mix of collations (utf8mb4_unicode_ci,IMPLICIT) and (utf8mb4_general_ci,IMPLICIT) for operation '='`), and `'text' IN (a.name, b.name)` (error 1270)
        - These work: a comparison with a string literal or a prepared-statement parameter, and `INSERT ... SELECT` from one table into the other. CONCAT, CONCAT_WS, COALESCE, IFNULL, IF and CASE over both columns work and return `utf8mb4_bin`. GREATEST does the same, except on MySQL and Percona 5.7 and MariaDB 10.2, where it is error 1267
        - A ci column against a `utf8mb4_bin` column works everywhere and compares as `utf8mb4_bin`
        - A latin1 column against a utf8mb4 column works with no fix: the server converts the latin1 side
        - `col COLLATE utf8mb4_unicode_ci` fixes the UNION, the JOIN and the searchMultipleTables() query between utf8mb4 columns on all 19 servers, written on both sides or only on the side that isn't `utf8mb4_unicode_ci`. On a latin1 column it is `error 1253: COLLATION 'utf8mb4_unicode_ci' is not valid for CHARACTER SET 'latin1'`
        - `CONVERT(col USING utf8mb4)` on both sides fixes all three, latin1 columns included, and the result has the server default collation. Written on one side only it fails on most servers. `CAST(col AS CHAR)` on both sides behaves like CONVERT on both sides
        - `CONVERT(col USING utf8mb4) COLLATE utf8mb4_unicode_ci` on both sides works in every case tried, latin1 included, and the result is `utf8mb4_unicode_ci` on every server
        - A function or a COLLATE around a column stops the server from looking that column up in its index: `WHERE name = 'x'` is `type=ref`, and with CONVERT, CAST or `name COLLATE x` it is `type=index`, a scan of the whole index. MySQL and Percona 8.0+ still use the index when x is the column's own collation; no other server does. A COLLATE on the value keeps the index when it names the column's collation and loses it when it names another. ORDER BY with a COLLATE on the column adds a filesort
        - In a join, `ON u.name = g.name COLLATE utf8mb4_unicode_ci` keeps the index lookup on `u`, the `utf8mb4_unicode_ci` table, when `g` is read first. CONVERT on both sides, or `COLLATE utf8mb4_bin` on both sides, leaves neither table an index lookup
        - On the searchMultipleTables() query, `COLLATE utf8mb4_unicode_ci` after every column makes a table in any collation take the time a `utf8mb4_unicode_ci` table takes without it. `CONVERT(col USING utf8mb4)` around every column takes 6% to 17% longer than a table in the server default without it

        Results that change:

        - Trailing spaces: `'abc' = 'abc '` is true in `utf8mb4_bin`, `utf8mb4_general_ci`, `utf8mb4_unicode_ci`, `utf8mb4_unicode_520_ci` and `utf8mb4_uca1400_ai_ci`, and false in `utf8mb4_0900_ai_ci`, on MySQL and on MariaDB 11.4+. A UNIQUE index stores both values only in `utf8mb4_0900_ai_ci`. LIKE doesn't match them in any collation
        - Emoji: any two emoji are equal in `utf8mb4_general_ci` and `utf8mb4_unicode_ci`, and a UNIQUE index refuses the second one with error 1062. They are different in `utf8mb4_unicode_520_ci`, `utf8mb4_0900_ai_ci` and `utf8mb4_uca1400_ai_ci`
        - `'ß' = 'ss'` is false in `utf8mb4_general_ci` and true in the other four ci collations. `'ß' = 's'`, `'i' = 'ı'` and `'I' = 'ı'` are true only in `utf8mb4_general_ci`
        - `'æ' = 'ae'`, `'ø' = 'o'`, `'ł' = 'l'`, `'đ' = 'd'` and `'ð' = 'd'` are false in `utf8mb4_general_ci` and `utf8mb4_unicode_ci` and true in the three newer ones
        - `'é'` against `'e'` plus a combining accent, hiragana against katakana, a fullwidth `'Ａ'` against `'A'`, `'ﬁ'` against `'fi'` and a zero width space inside a word: different in `utf8mb4_general_ci`, equal in `utf8mb4_unicode_ci` and later. A soft hyphen inside a word is ignored only in `utf8mb4_0900_ai_ci` and `utf8mb4_uca1400_ai_ci`
        - `'a' = 'A'`, `'cafe' = 'café'`, `'ä' = 'a'` and `'ñ' = 'n'` are true in all five ci collations
        - So a convert of a UNIQUE column can stop in either direction, with `error 1062: Duplicate entry 'ı' for key 'v'` (MySQL and Percona 8.0+ print the key as `'table.v'`; MariaDB 10.11+ prints an emoji as `?`). Of the 39 sample pairs, 4 are different in `utf8mb4_unicode_ci` and equal in `utf8mb4_general_ci`, 9 the other way round; 6 are different in `utf8mb4_unicode_ci` and equal in `utf8mb4_0900_ai_ci`, 7 the other way round, among them `'abc'` against `'abc '` and every emoji pair; 6 and 5 for `utf8mb4_uca1400_ai_ci`
        - `ALTER IGNORE TABLE ... CONVERT` on MariaDB doesn't stop: it deletes the rows that became duplicates (16 rows became 7 in one check). MySQL and Percona have no ALTER IGNORE (error 1064)
        - ORDER BY changes as well. `utf8mb4_general_ci` sorts `_a`, `æ`, `ø` and `ł` after `z`; `utf8mb4_unicode_ci` sorts `_a` first and the letters beside `a`, `o` and `l`. `utf8mb4_0900_ai_ci` and `utf8mb4_uca1400_ai_ci` sort emoji before the digits; the older three sort them last

        Looked up, not tested:

        - No utf8mb4 collation is listed as deprecated in the MySQL 8.0, 8.4 or 9.7 manuals ([8.4 What Is New](https://dev.mysql.com/doc/refman/8.4/en/mysql-nutshell.html)), and no deprecation notice was found for one in MariaDB's documentation. No server in the matrix printed a warning for any of the six
        - `default_collation_for_utf8mb4` accepts `utf8mb4_0900_ai_ci` and `utf8mb4_general_ci` and "is for internal use by MySQL Replication only" ([manual](https://dev.mysql.com/doc/refman/8.4/en/server-system-variables.html#sysvar_default_collation_for_utf8mb4)). Setting it prints a deprecation warning ([bug 104066](https://bugs.mysql.com/bug.php?id=104066))
        - MySQL's default changed from `utf8mb4_general_ci` to `utf8mb4_0900_ai_ci` in 8.0.1 ([release notes](https://dev.mysql.com/doc/relnotes/mysql/8.0/en/news-8-0-1.html)). MariaDB added the uca1400 collations in 10.10 ([MDEV-27009](https://jira.mariadb.org/browse/MDEV-27009)) and `character_set_collations` in 11.2 ([MDEV-30164](https://jira.mariadb.org/browse/MDEV-30164)), and gave that variable its `utf8mb4=utf8mb4_uca1400_ai_ci` default in 11.5 ([MDEV-25829](https://jira.mariadb.org/browse/MDEV-25829))
        - MariaDB's `utf8mb4_0900_ai_ci` is an alias for `utf8mb4_uca1400_nopad_ai_ci`, added in 11.4.5 so that MySQL dumps load ([MDEV-20912](https://jira.mariadb.org/browse/MDEV-20912)). An 11.4 server older than 11.4.5 doesn't have it
        - "The upgrade from 5.7 to 8.0 does not change any collation for any existing database objects, but takes effect for new objects" ([MySQL manual](https://dev.mysql.com/doc/refman/8.0/en/upgrading-from-previous-series.html)), which matches the upgrade job. MariaDB's list of collations that need an index rebuild after an upgrade has no utf8mb4 entry (`collation_changed_order()` in `sql_string.h`)
        - Pad attribute: "The Unicode collations based on UCA 9.0.0 and higher have a pad attribute of NO PAD" and the rest are PAD SPACE ([MySQL manual](https://dev.mysql.com/doc/refman/8.4/en/charset-binary-collations.html)). MariaDB names its NO PAD collations with `_nopad_`, so `utf8mb4_uca1400_ai_ci` is PAD SPACE, which matches the trailing-space check
        - `utf8mb4_unicode_ci` follows Unicode 4.0.0, `utf8mb4_unicode_520_ci` 5.2.0, `utf8mb4_0900_ai_ci` 9.0.0 and `utf8mb4_uca1400_ai_ci` 14.0.0. For the first, "all supplementary characters are equal to each other" ([MySQL manual](https://dev.mysql.com/doc/refman/8.4/en/charset-unicode-sets.html)), which is the emoji result above
        - MariaDB has an open issue that a table can end up with old and new columns in different collations after the default change ([MDEV-40343](https://jira.mariadb.org/browse/MDEV-40343)), and a documentation task that says some comparisons are "significantly slower with utf8mb4_uca1400_ai_ci" ([MDEV-38743](https://jira.mariadb.org/browse/MDEV-38743)). A sort speed fix for the UCA collations is in 11.8.6 and 12.2.1 ([MDEV-37849](https://jira.mariadb.org/browse/MDEV-37849))
        - Other projects: WordPress creates tables in `utf8mb4_unicode_520_ci` when the server has it and `utf8mb4_unicode_ci` otherwise (`determine_charset()` in `class-wpdb.php`). Laravel 12 and Joomla 5 use `utf8mb4_unicode_ci` on every server; Laravel 11.0.0 shipped with the two server defaults and went back in 11.0.7. Drupal 11 writes no COLLATE unless one is set in `settings.php`
        - Amazon RDS, Aurora, Azure and Google Cloud SQL: no page was found that says they change the default utf8mb4 collation. Not known for cPanel hosts


        __FINDINGS__;

    echo "## Server defaults\n\n";
    echo "| Server | A new CHARSET=utf8mb4 table gets | " . implode(' | ', array_map(static fn($c) => CollationProbe::short($c), CollationProbe::COLLATIONS)) . " |\n";
    echo "|---|---|" . str_repeat('---|', count(CollationProbe::COLLATIONS)) . "\n";
    foreach ($reports as $server => $report) {
        $cells = [];
        foreach (CollationProbe::COLLATIONS as $collation) {
            $stored  = $report['known'][$collation] ?? null;
            $cells[] = $stored === null ? 'no' : ($stored === $collation ? 'yes' : 'stored as ' . CollationProbe::short($stored));
        }
        echo "| $server | {$report['default']} | " . implode(' | ', $cells) . " |\n";
    }
    echo "\n";

    $sections = [1 => 'What each server does by default', 3 => 'Converting a table', 4 => 'Two collations in one statement', 5 => 'Results that change between collations'];
    $names    = [];
    foreach ($reports as $report) {
        $names += array_fill_keys(array_keys($report['facts']), 'fact') + array_fill_keys(array_keys($report['grids']), 'grid');
    }
    uksort($names, 'strnatcmp');
    $lastSection = 0;
    foreach ($names as $name => $kind) {
        $section = (int)$name;
        if ($section !== $lastSection) {
            echo "## $section. {$sections[$section]}\n\n";
            if ($section === 4) {
                echo "The column headings name the two collations. \"ok, unicode_ci\" means the statement ran and its result has that collation. A number in brackets after an answer is how many servers have both collations.\n\n";
            }
            if ($section === 5) {
                echo "A number in brackets after an answer is how many servers have that collation.\n\n";
            }
            $lastSection = $section;
        }
        $kind === 'fact' ? printFact($name, $reports, $families) : printGrid($name, $reports, $families);
    }

    foreach (array_keys($sizes) as $size) {
        printSpeed($benchedAt($size), $families);
    }

    if (max($runCounts) > 1) {
        echo "## Checks that gave different answers in different runs\n\n";
        echo $runDifferences ? "First run's answer / another run's answer:\n\n" : "None: every check gave the same answer in every run.\n";
        foreach (array_keys($runDifferences) as $line) {
            echo "- " . str_replace(["\r", "\n"], ' ', $line) . "\n";
        }
    }
}

/**
 * The table at the top of the report: for the largest table size tested, each server's time
 * per collation on the statements a CMS list page and search send, and which collation is fastest.
 *
 * `utf8mb4_bin` isn't a candidate, because it compares case, so it isn't in the table.
 *
 * @param array<string, array> $benched server => report, with that size's runs in 'benchRuns'
 */
function printFastest(array $benched): void
{
    $rows   = (int)reset($benched)['benchRuns'][0]['rows'];
    $titles = titlesNote(reset($benched)['benchRuns'][0]);
    $statements = [
        'List page: SELECT * ... WHERE hidden = 0 ORDER BY title LIMIT 25, no index on title' => 'A list page: `SELECT * FROM t WHERE hidden = 0 ORDER BY title LIMIT 25`, no index on `title`',
        'ORDER BY title, whole table, no index'                                              => 'Sorting every row: `ORDER BY title` with no index and no LIMIT to cut it short',
        'ORDER BY title, whole table, no index, sort_buffer_size = 64 MB'                    => 'Sorting every row again, after `SET SESSION sort_buffer_size` to 64 MB',
        'ORDER BY title, whole table, no index, sort_buffer_size = 2 GB'                     => 'Sorting every row again, with `sort_buffer_size` at 2 GB so the sort stays in memory',
        'List page, last page: SELECT * ... WHERE hidden = 0 ORDER BY title LIMIT 25 OFFSET n, no index on title' => 'The last page of the list: the same query with an OFFSET of 19/20 of the rows, no index on `title`',
        'List page, last page: the same, ORDER BY titleIndexed (the title again, with an index)' => 'The last page again, with an index on the ORDER BY column',
        'MEDIUMTEXT title: first page, ORDER BY title LIMIT 25, no index'                  => 'A `MEDIUMTEXT` title, as CMS Builder makes a text field: first page, no index',
        'MEDIUMTEXT title: first page, ORDER BY titleIndexed LIMIT 25, prefix index (768)' => 'A `MEDIUMTEXT` title with a prefix index `(768)`: first page',
        'MEDIUMTEXT title: last page, ORDER BY titleIndexed LIMIT 25 OFFSET n, prefix index (768)' => 'A `MEDIUMTEXT` title with a prefix index `(768)`: last page',
        'MEDIUMTEXT title: ORDER BY title, whole table, no index'                          => 'A `MEDIUMTEXT` title: sorting every row, default `sort_buffer_size`',
        'MEDIUMTEXT title: the same, sort_buffer_size = 2 GB'                              => 'A `MEDIUMTEXT` title: sorting every row, `sort_buffer_size` at 2 GB',
        'GROUP BY title'                                                                     => '`GROUP BY title`',
        "WHERE content LIKE '%word%' (MEDIUMTEXT, scan)"                                     => "A keyword search: `WHERE content LIKE '%word%'`",
        'List page: the same, ORDER BY titleIndexed (the title again, with an index)'        => 'The list page again, with an index on the ORDER BY column',
    ];
    $candidates = array_values(array_diff(CollationProbe::COLLATIONS, ['utf8mb4_bin']));
    $ms         = static function (array $report, string $op, string $arm): ?float { // the median over the runs of each run's median
        $perRun = [];
        foreach ($report['benchRuns'] as $run) {
            if (isset($run['ops'][$op][$arm])) {
                $perRun[] = median($run['ops'][$op][$arm]);
            }
        }
        return $perRun ? median($perRun) : null;
    };

    echo "## Fastest collation at " . number_format($rows) . " rows$titles\n\n";
    echo "Milliseconds per statement on a " . number_format($rows) . "-row table, one table per collation with the same rows. An empty cell means the server doesn't have that collation. ";
    echo "\"Fastest\" names the quickest one and any other within 5% of it, which is about how far two identical tables differ on these runners. The server's own default is marked with (default). ";
    echo "How the test is set up, every other statement and the smaller table sizes are under \"Speed\" further down.\n\n";
    foreach ($statements as $op => $title) {
        if (!array_filter($benched, static fn($report) => $ms($report, $op, 'utf8mb4_general_ci') !== null)) {
            continue;
        }
        echo "**$title**\n\n";
        echo "| Server | " . implode(' | ', array_map(static fn($c) => CollationProbe::short($c), $candidates)) . " | Fastest |\n";
        echo "|---|" . str_repeat('---|', count($candidates) + 1) . "\n";
        foreach ($benched as $server => $report) {
            $times = [];
            foreach ($candidates as $collation) {
                $time = $ms($report, $op, $collation);
                if ($time !== null) {
                    $times[$collation] = $time;
                }
            }
            if (!$times) {
                continue;
            }
            $best    = min($times);
            $fastest = array_keys(array_filter($times, static fn($time) => $time <= $best * 1.05));
            $cells   = array_map(static fn($c) => isset($times[$c]) ? formatMs($times[$c]) . ($c === $report['default'] ? ' (default)' : '') : '', $candidates);
            echo "| $server | " . implode(' | ', $cells) . " | " . implode(', ', array_map(static fn($c) => CollationProbe::short($c), $fastest)) . " |\n";
        }
        echo "\n";
    }
}

/**
 * Which set of speed-test runs a run belongs to: its table size, plus the title file when it used one.
 *
 *     benchSet(['rows' => 1000000, 'titles' => ''])                           // 1000000
 *     benchSet(['rows' => 1000000, 'titles' => 'english-wikipedia-titles.txt']) // '1000000 english-wikipedia-titles.txt'
 */
function benchSet(array $bench): string|int
{
    return empty($bench['titles']) ? (int)$bench['rows'] : "{$bench['rows']} {$bench['titles']}";
}

/**
 * ", titles from x.txt" for a heading, or '' for the generated titles.
 */
function titlesNote(array $bench): string
{
    return empty($bench['titles']) ? '' : ", titles from {$bench['titles']}";
}

/**
 * The speed section: a summary per server group, then every server's numbers.
 *
 * @param array<string, array> $benched server => report, only the servers that ran the speed test
 */
function printSpeed(array $benched, array $families): void
{
    if (!$benched) {
        return;
    }
    $first   = reset($benched)['benchRuns'][0];
    $runs    = max(array_map(static fn($report) => count($report['benchRuns']), $benched));
    $control = 'utf8mb4_general_ci (control)';
    $arms    = [...CollationProbe::COLLATIONS, $control];
    $ops     = [];
    foreach ($benched as $report) {
        foreach ($report['benchRuns'] as $run) { // a run started with --only has some of the statements, and a later probe has more
            $ops += array_fill_keys(array_keys($run['ops']), true);
        }
    }
    $ops = array_keys($ops);
    // what one of a server's runs recorded under $key for a statement, from the first run that has it
    $recorded = static function (array $report, string $key, string $op): mixed {
        foreach ($report['benchRuns'] as $run) {
            if (isset($run[$key][$op])) {
                return $run[$key][$op];
            }
        }
        return null;
    };
    // Each run is on a different runner, so a ratio is taken inside one run and the runs are combined after
    $runMs = static function (array $report, string $op, string $arm): array { // each run's median ms
        $perRun = [];
        foreach ($report['benchRuns'] as $run) {
            if (isset($run['ops'][$op][$arm])) {
                $perRun[] = median($run['ops'][$op][$arm]);
            }
        }
        return $perRun;
    };
    $runRatios = static function (array $report, string $op, string $arm): array { // each run's time divided by its utf8mb4_general_ci time
        $perRun = [];
        foreach ($report['benchRuns'] as $run) {
            if (isset($run['ops'][$op][$arm], $run['ops'][$op]['utf8mb4_general_ci'])) {
                $perRun[] = median($run['ops'][$op][$arm]) / median($run['ops'][$op]['utf8mb4_general_ci']);
            }
        }
        return $perRun;
    };
    // the median over the runs, or null when that server has no such collation
    $ms    = static fn(array $report, string $op, string $arm): ?float => ($perRun = $runMs($report, $op, $arm)) ? median($perRun) : null;
    $ratio = static fn(array $report, string $op, string $arm): ?float => ($perRun = $runRatios($report, $op, $arm)) ? median($perRun) : null;

    echo "## 2. Speed, " . number_format($first['rows']) . " rows" . titlesNote($first) . "\n\n";
    if (titlesNote($first) !== '') {
        echo "In these runs `title` and `titleIndexed` hold one line each of {$first['titles']}, in place of the generated titles described below; `name` and `content` are the same as in the other runs.\n\n";
    }
    echo "One InnoDB table per collation with the same " . number_format($first['rows']) . " rows, shaped like a CMS Builder section. It has 15 columns: the record number, three DATETIME, two INT, three TINYINT flags, five VARCHAR(255) and one MEDIUMTEXT. `name` and `titleIndexed` have an index; `title` holds the same text as `titleIndexed` and has none. ";
    echo "The statements named \"MEDIUMTEXT title\" use a narrower table with the same rows: num, hidden, dragSortOrder, and `title` and `titleIndexed` as MEDIUMTEXT, the type CMS Builder gives a text field, with a prefix index `titleIndexed(768)`. ";
    echo "Average length in characters: name {$first['averageChars']['name']}, title {$first['averageChars']['title']}, content {$first['averageChars']['content']}. ";
    echo "The text is mostly ASCII words, with about 9% accented Latin words, 2% words in other scripts and 1% emoji. ";
    echo "Numbers are milliseconds per statement, the median of {$first['passes']} passes (3 for the slow statements), after one uncounted pass" . ($runs > 1 ? ", then the median of that server's runs of the workflow (up to $runs), each on its own runner. " : ". ");
    if (($first['selectRepeat'] ?? 1) > 1) {
        echo "At this table size a SELECT runs up to {$first['selectRepeat']} times in a pass (200 statements at most) and the time is divided by the count. ";
    }
    echo "The number in brackets is the time divided by the utf8mb4_general_ci time on the same server in the same run. ";
    echo "\"Control\" is a second set of utf8mb4_general_ci tables timed the same way: how far it is from 1.00x is the noise of that runner, and a difference no larger than that isn't a real difference. ";
    echo "The servers ran with innodb_buffer_pool_size = " . number_format($first['bufferPool'] / 1048576) . " MB so every table stays in memory, and with the query cache off.\n\n";

    $groups = [];
    foreach ($benched as $server => $report) {
        $groups[speedGroup($server)][$server] = $report;
    }
    // The choice is between utf8mb4_unicode_ci everywhere and each server's own default, so put those two side by side
    $against = static function (array $report, string $op, string $arm, string $base): ?float { // median over the runs of arm time / base time
        $perRun = [];
        foreach ($report['benchRuns'] as $run) {
            if (isset($run['ops'][$op][$arm], $run['ops'][$op][$base])) {
                $perRun[] = median($run['ops'][$op][$arm]) / median($run['ops'][$op][$base]);
            }
        }
        return $perRun ? median($perRun) : null;
    };
    echo "### utf8mb4_unicode_ci against each server's default\n\n";
    echo "The utf8mb4_unicode_ci time, the time of the collation a new table gets on that server, and the first divided by the second. Each number is the median over the group's servers. Above 1.00x, utf8mb4_unicode_ci is slower than the default.\n\n";
    echo "| Statement | " . implode(' | ', array_map(static fn($group) => $group . ' (' . implode(', ', array_unique(array_map(static fn($report) => CollationProbe::short($report['default']), $groups[$group]))) . ')', array_keys($groups))) . " |\n";
    echo "|---|" . str_repeat('---|', count($groups)) . "\n";
    foreach ($ops as $op) {
        $cells = [];
        foreach ($groups as $groupReports) {
            $unicode  = array_filter(array_map(static fn($report) => $ms($report, $op, 'utf8mb4_unicode_ci'), $groupReports), static fn($v) => $v !== null);
            $default  = array_filter(array_map(static fn($report) => $ms($report, $op, $report['default']), $groupReports), static fn($v) => $v !== null);
            $ratios   = array_filter(array_map(static fn($report) => $against($report, $op, 'utf8mb4_unicode_ci', $report['default']), $groupReports), static fn($v) => $v !== null);
            $cells[]  = $unicode && $default && $ratios ? formatMs(median($unicode)) . ' / ' . formatMs(median($default)) . sprintf(' (%.2fx)', median($ratios)) : '';
        }
        echo "| " . str_replace('|', '\|', $op) . " | " . implode(' | ', $cells) . " |\n";
    }
    echo "\n";

    echo "### Summary by server group\n\n";
    echo "Each cell is the median over the group's servers.\n\n";
    foreach ($groups as $group => $groupReports) {
        $groupArms = array_values(array_filter($arms, static fn($arm) => array_filter($groupReports, static fn($report) => $ms($report, $ops[0], $arm) !== null)));
        echo "**$group** (" . implode(', ', array_map('shortServer', array_keys($groupReports))) . ")\n\n";
        echo "| Statement | " . implode(' | ', array_map(static fn($arm) => $arm === $control ? 'control' : CollationProbe::short($arm), $groupArms)) . " |\n";
        echo "|---|" . str_repeat('---|', count($groupArms)) . "\n";
        foreach ($ops as $op) {
            $cells = [];
            foreach ($groupArms as $arm) {
                $times  = array_filter(array_map(static fn($report) => $ms($report, $op, $arm), $groupReports), static fn($v) => $v !== null);
                $ratios = array_filter(array_map(static fn($report) => $ratio($report, $op, $arm), $groupReports), static fn($v) => $v !== null);
                if (!$times) {
                    $cells[] = '';
                } elseif ($arm === $control && count($ratios) > 1) {
                    $cells[] = sprintf('%.2fx to %.2fx', min($ratios), max($ratios));
                } else {
                    $cells[] = formatMs(median($times)) . ($arm === 'utf8mb4_general_ci' || !$ratios ? '' : sprintf(' (%.2fx)', median($ratios)));
                }
            }
            echo "| " . str_replace('|', '\|', $op) . " | " . implode(' | ', $cells) . " |\n";
        }
        echo "\n";
    }

    // A run started with --only skips the convert, so a set of such runs has no convert table
    $hasConvert = array_filter($benched, static fn($report) => array_filter($report['benchRuns'], static fn($run) => !empty($run['convert'])));
    if ($hasConvert) {
        echo "### Converting a " . number_format($first['rows']) . "-row table\n\n";
        echo "ALTER TABLE ... CONVERT TO CHARACTER SET utf8mb4 COLLATE x on a copy of the utf8mb4_unicode_ci table, which has two text indexes (`name` and `titleIndexed`). Milliseconds: the median of every timing (3 in each run), then the fastest and slowest. ";
        echo "\"Rows affected\" is what the server reports: the row count when it copied the table. ";
        echo "The last two columns are a SELECT and an INSERT sent from a second connection 200 ms after the convert started.\n\n";
        echo "The MODIFY columns are the same change written as ALTER TABLE t DEFAULT CHARACTER SET utf8mb4 COLLATE x with a MODIFY ... COLLATE x for each of the six text columns.\n\n";
        $directions = ['utf8mb4_unicode_ci to the server default', 'the server default to utf8mb4_unicode_ci', 'MODIFY: utf8mb4_unicode_ci to the server default', 'MODIFY: the server default to utf8mb4_unicode_ci'];
        echo "| Server | Default | CONVERT, unicode_ci to default | CONVERT, default to unicode_ci | MODIFY, unicode_ci to default | MODIFY, default to unicode_ci | Rows affected | SELECT during the convert | INSERT during the convert |\n";
        echo "|---|---|---|---|---|---|---|---|---|\n";
        foreach ($benched as $server => $report) {
            $convert = $report['benchRuns'][0]['convert'] ?? [];
            if (!isset($convert['ms'])) {
                echo "| $server | {$report['default']} | " . ($convert['skipped'] ?? 'no data') . " | | | | | | |\n";
                continue;
            }
            $cells = [];
            foreach ($directions as $direction) {
                $times   = array_merge(...array_map(static fn($run) => $run['convert']['ms'][$direction] ?? [], $report['benchRuns']));
                $cells[] = !$times ? '' : formatMs(median($times)) . " (" . formatMs(min($times)) . " to " . formatMs(max($times)) . ")";
            }
            echo "| $server | " . CollationProbe::short($report['default']) . " | " . implode(' | ', $cells) . " | "
                . implode(' / ', $convert['affectedRows']) . " | {$convert['whileRunning']['select']} | {$convert['whileRunning']['insert']} |\n";
        }
        echo "\n";
    }

    echo "### Table size\n\n";
    echo "DATA_LENGTH and INDEX_LENGTH of the " . number_format($first['rows']) . "-row table from information_schema.TABLES after ANALYZE TABLE, in MB. The indexes are the ones on `name` and `titleIndexed`, built by ALTER TABLE ... ADD INDEX after the rows were loaded.\n\n";
    echo "| Server | " . implode(' | ', array_map(static fn($arm) => $arm === $control ? 'control' : CollationProbe::short($arm), $arms)) . " |\n";
    echo "|---|" . str_repeat('---|', count($arms)) . "\n";
    foreach ($benched as $server => $report) {
        $cells = [];
        foreach ($arms as $arm) {
            $size    = $report['benchRuns'][0]['sizes'][$arm] ?? null;
            $cells[] = $size === null ? '' : sprintf('%.1f + %.1f', $size['data'] / 1048576, $size['index'] / 1048576);
        }
        echo "| $server | " . implode(' | ', $cells) . " |\n";
    }
    echo "\n";

    echo "### Every server\n\n";
    echo "\"Control, each run\" is the lowest and highest control ratio of that server's runs. \"Widest column\" is the collation whose ratio moved most between runs, and how far.\n\n";
    foreach ($ops as $op) {
        echo "**$op**\n\n";
        echo "| Server | " . implode(' | ', array_map(static fn($arm) => $arm === $control ? 'control' : CollationProbe::short($arm), $arms)) . " | Control, each run | Widest column |\n";
        echo "|---|" . str_repeat('---|', count($arms) + 2) . "\n";
        foreach ($benched as $server => $report) {
            $cells  = [];
            $widest = [0.0, ''];
            foreach ($arms as $arm) {
                $mine    = $ms($report, $op, $arm);
                $cells[] = $mine === null ? ($report['benchRuns'][0]['errors'][$op][$arm] ?? '') : formatMs($mine) . ($arm === 'utf8mb4_general_ci' ? '' : sprintf(' (%.2fx)', $ratio($report, $op, $arm)));
                $ratios  = $runRatios($report, $op, $arm);
                if ($ratios && $arm !== $control && max($ratios) - min($ratios) > $widest[0]) {
                    $widest = [max($ratios) - min($ratios), sprintf('%s %.2fx to %.2fx', CollationProbe::short($arm), min($ratios), max($ratios))];
                }
            }
            $controls = $runRatios($report, $op, $control);
            echo "| $server | " . implode(' | ', $cells) . " | " . ($controls ? sprintf('%.2fx to %.2fx', min($controls), max($controls)) : '') . " | $widest[1] |\n";
        }
        echo "\n";
    }

    // Only the statements where some server merged temp files, and only the servers whose probe counted them
    $merged = array_filter($ops, static fn($op) => array_filter($benched, static fn($report) => array_filter($recorded($report, 'mergePasses', $op) ?? [])));
    if ($merged) {
        echo "### Sorts that went to temp files\n\n";
        echo "`Sort_merge_passes` for one statement: how many times the server merged temp files because the rows to sort didn't fit in `sort_buffer_size`. 0 means the sort stayed in memory. A statement that isn't listed was 0 on every server.\n\n";
        foreach ($merged as $op) {
            echo "**$op**\n\n";
            echo "| Server | " . implode(' | ', array_map(static fn($arm) => $arm === $control ? 'control' : CollationProbe::short($arm), $arms)) . " |\n";
            echo "|---|" . str_repeat('---|', count($arms)) . "\n";
            foreach ($benched as $server => $report) {
                $passes = $recorded($report, 'mergePasses', $op);
                if ($passes !== null) {
                    echo "| $server | " . implode(' | ', array_map(static fn($arm) => $passes[$arm] ?? '', $arms)) . " |\n";
                }
            }
            echo "\n";
        }
    }

    echo "### Query plans\n\n";
    echo "EXPLAIN for the utf8mb4_general_ci table, where the statement is a SELECT.\n\n";
    foreach ($ops as $op) {
        $serversByPlan = [];
        foreach ($benched as $server => $report) {
            $plan = $recorded($report, 'plans', $op);
            if ($plan !== null) {
                $serversByPlan[$plan][] = $server;
            }
        }
        if (!$serversByPlan) {
            continue;
        }
        echo "**$op**\n\n";
        foreach ($serversByPlan as $plan => $group) {
            echo "- " . who($group, count($benched), $families) . ": $plan\n";
        }
        echo "\n";
    }
}

//endregion
