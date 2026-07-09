<?php
declare(strict_types=1);

namespace Itools\ZenDB\Tests\Table;

use Itools\ZenDB\Table;
use Itools\ZenDB\TableInfo;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Tests for the DB-free parser halves: TableInfo's parseShowIndexRows() (behind indexes()) and
 * parseCreateTableColumns() (behind columnDefinitions()), plus the pure string transforms
 * Table::normalizeCreateTable() and Table::defaultFromDefinition().
 * Index fixture rows use the SHOW INDEX column names (Key_name, Non_unique, Column_name), with
 * only the keys the parser reads. The parse methods are private implementation details, so
 * calls go through ReflectionMethod.
 */
#[CoversClass(Table::class)]
#[CoversClass(TableInfo::class)]
final class TableTest extends TestCase
{
    private static function parse(array $rows, array $fkColumnSets = []): array
    {
        return (new ReflectionMethod(TableInfo::class, 'parseShowIndexRows'))->invoke(null, $rows, $fkColumnSets);
    }

    private static function parseDdl(string $createTableSql): array
    {
        return (new ReflectionMethod(TableInfo::class, 'parseCreateTableColumns'))->invoke(null, $createTableSql);
    }

    //region parseCreateTableColumns()

    #[Test]
    public function extractsColumnDefinitionsAndSkipsKeyAndConstraintLines(): void
    {
        // real MariaDB 11 output shape: quoted default with a comma and doubled quote, a generated
        // column, and PRIMARY KEY / KEY / CONSTRAINT lines that must not parse as columns
        $definitions = self::parseDdl(<<<'SQL'
            CREATE TABLE `articles` (
              `num` int(11) NOT NULL AUTO_INCREMENT,
              `title` varchar(50) NOT NULL DEFAULT 'O''Brien, Jr',
              `full_name` varchar(101) GENERATED ALWAYS AS (concat(`last_name`,', ',`first_name`)) STORED,
              PRIMARY KEY (`num`),
              KEY `idx_title` (`title`),
              CONSTRAINT `fk_owner` FOREIGN KEY (`num`) REFERENCES `accounts` (`num`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            SQL);

        $this->assertSame(['num', 'title', 'full_name'], array_keys($definitions), 'columns only, in table order');
        $this->assertSame('int NOT NULL AUTO_INCREMENT', $definitions['num'], 'display width cropped');
        $this->assertSame("varchar(50) NOT NULL DEFAULT 'O''Brien, Jr'", $definitions['title'], 'only the line-end comma is stripped, not one inside a quoted default');
        $this->assertSame("varchar(101) GENERATED ALWAYS AS (concat(`last_name`,', ',`first_name`)) STORED", $definitions['full_name']);
    }

    #[Test]
    public function stripsTableDefaultCharsetAndCollationButKeepsDifferentOnes(): void
    {
        $definitions = self::parseDdl(<<<'SQL'
            CREATE TABLE `t` (
              `body` mediumtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
              `code` varchar(20) CHARACTER SET ascii COLLATE ascii_bin NOT NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            SQL);

        $this->assertSame('mediumtext NOT NULL', $definitions['body'], 'clauses matching the table defaults are noise');
        $this->assertSame('varchar(20) CHARACTER SET ascii COLLATE ascii_bin NOT NULL', $definitions['code'], "a column's own charset survives");
    }

    #[Test]
    public function handlesClosingLineWithoutCollate(): void
    {
        // MySQL 5.7 omits COLLATE= from the closing line when the collation is the charset's default
        $definitions = self::parseDdl(<<<'SQL'
            CREATE TABLE `t` (
              `body` varchar(10) CHARACTER SET latin1 NOT NULL
            ) ENGINE=MyISAM DEFAULT CHARSET=latin1
            SQL);

        $this->assertSame('varchar(10) NOT NULL', $definitions['body']);
    }

    #[Test]
    public function tableDefaultCharsetThatPrefixesAColumnCharsetIsNotRemoved(): void
    {
        // 'utf8' is the leading substring of 'utf8mb4'; the removal must match whole names only,
        // or the column's clause is left corrupted as 'mediumtextmb4 COLLATE ...'. The unicode_ci
        // pin strips separately as a default collation; the charset must survive intact
        $definitions = self::parseDdl(<<<'SQL'
            CREATE TABLE `t` (
              `body` mediumtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL
            ) ENGINE=MyISAM DEFAULT CHARSET=utf8 COLLATE=utf8_general_ci
            SQL);

        $this->assertSame('mediumtext CHARACTER SET utf8mb4 NOT NULL', $definitions['body']);
    }

    #[Test]
    public function legacyUtf8ColumnsReadTheSameFromEveryServer(): void
    {
        // the same VARCHAR(50) CHARSET utf8 column prints four ways across servers: the utf8mb3
        // rename, plus whether the charset's default collation is printed at all. All four must
        // normalize to one string. Column lines are real server output from
        // tools/db-behavior-report.md (2026-07), 'SHOW CREATE: oldText'
        $serverVariants = [
            'mysql/percona 5.7, mariadb 10.2'   => "`oldText` varchar(50) CHARACTER SET utf8 NOT NULL DEFAULT ''",
            'mariadb 10.3-10.5'                 => "`oldText` varchar(50) CHARACTER SET utf8 COLLATE utf8_general_ci NOT NULL DEFAULT ''",
            'mysql/percona 8.0+, mariadb 10.6+' => "`oldText` varchar(50) CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci NOT NULL DEFAULT ''",
            'mariadb 11.8+'                     => "`oldText` varchar(50) CHARACTER SET utf8mb3 COLLATE utf8mb3_uca1400_ai_ci NOT NULL DEFAULT ''",
        ];

        foreach ($serverVariants as $server => $columnLine) {
            $definitions = self::parseDdl("CREATE TABLE `t` (\n  $columnLine\n) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
            $this->assertSame("varchar(50) CHARACTER SET utf8 NOT NULL DEFAULT ''", $definitions['oldText'], $server);
        }
    }

    #[Test]
    public function stripsServerDefaultCollationsButKeepsIntentionalOnes(): void
    {
        // ascii_general_ci and latin1_swedish_ci are their charsets' defaults on every supported
        // server: 5.7-era prints the bare charset, newer servers append the collation, so the same
        // column reads two ways. Stripping the default makes them identical; _bin choices survive
        $definitions = self::parseDdl(<<<'SQL'
            CREATE TABLE `t` (
              `tableName` varchar(255) CHARACTER SET ascii COLLATE ascii_general_ci NOT NULL,
              `legacy` varchar(100) CHARACTER SET latin1 COLLATE latin1_swedish_ci NOT NULL DEFAULT '',
              `code` varchar(20) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
              `pinned` varchar(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
            SQL);

        $this->assertSame('varchar(255) CHARACTER SET ascii NOT NULL', $definitions['tableName']);
        $this->assertSame("varchar(100) CHARACTER SET latin1 NOT NULL DEFAULT ''", $definitions['legacy']);
        $this->assertSame('varchar(20) CHARACTER SET ascii COLLATE ascii_bin NOT NULL', $definitions['code'], 'a deliberate _bin collation survives');
        $this->assertSame('varchar(80) NOT NULL', $definitions['pinned'], 'table-default charset and the unicode_ci pin both strip');
    }

    #[Test]
    public function charsetSpellingInsideStringDefaultsIsNeverModified(): void
    {
        // string defaults can contain the exact phrases the charset normalizations look for;
        // quoted literals are masked before normalizing, so the text comes through byte-identical
        $definitions = self::parseDdl(<<<'SQL'
            CREATE TABLE `t` (
              `hint` varchar(80) NOT NULL DEFAULT 'use CHARACTER SET utf8mb3 here',
              `note` varchar(80) NOT NULL DEFAULT 'sorts by COLLATE utf8mb4_general_ci'
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
            SQL);

        $this->assertSame("varchar(80) NOT NULL DEFAULT 'use CHARACTER SET utf8mb3 here'", $definitions['hint'], 'utf8mb3 inside a string must not be rewritten');
        $this->assertSame("varchar(80) NOT NULL DEFAULT 'sorts by COLLATE utf8mb4_general_ci'", $definitions['note'], 'a default collation inside a string must not be stripped');
    }

    #[Test]
    public function cropsIntWidthsButKeepsTinyint1AndRealLengths(): void
    {
        $definitions = self::parseDdl(<<<'SQL'
            CREATE TABLE `t` (
              `flag` tinyint(1) NOT NULL DEFAULT 0,
              `views` bigint(20) unsigned NOT NULL DEFAULT 0,
              `padded` int(5) unsigned zerofill NOT NULL DEFAULT 00000,
              `initial` char(1) NOT NULL,
              `price` decimal(10,2) NOT NULL DEFAULT 0.00,
              `label` varchar(50) NOT NULL DEFAULT 'int(11)'
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            SQL);

        $this->assertSame("tinyint(1) NOT NULL DEFAULT '0'", $definitions['flag'], 'tinyint(1) keeps its width; connectors treat it as boolean');
        $this->assertSame("bigint unsigned NOT NULL DEFAULT '0'", $definitions['views']);
        $this->assertSame("int(5) unsigned zerofill NOT NULL DEFAULT '00000'", $definitions['padded'], 'zerofill keeps its width; it sets the zero-padding amount');
        $this->assertSame('char(1) NOT NULL', $definitions['initial'], 'char(1) is a real length, not a display width');
        $this->assertSame("decimal(10,2) NOT NULL DEFAULT '0.00'", $definitions['price'], 'precision/scale is not a display width');
        $this->assertSame("varchar(50) NOT NULL DEFAULT 'int(11)'", $definitions['label'], 'the crop only applies to the leading type, never text inside the definition');
    }

    #[Test]
    public function cropsYearWidthAndUnsignedTinyint1Width(): void
    {
        // MySQL 8 drops these widths from its own output (year(4) → year, and tinyint(1) unsigned →
        // tinyint unsigned per MySQL bugs #100309/#105667); cropping makes MariaDB and 5.7 output
        // read the same. Fixture strings come from tools/db-behavior-report.md probes.
        $definitions = self::parseDdl(<<<'SQL'
            CREATE TABLE `t` (
              `birthYear` year(4) NOT NULL,
              `flags` tinyint(1) unsigned NOT NULL DEFAULT 0,
              `isAdmin` tinyint(1) NOT NULL DEFAULT 0
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            SQL);

        $this->assertSame('year NOT NULL', $definitions['birthYear']);
        $this->assertSame("tinyint unsigned NOT NULL DEFAULT '0'", $definitions['flags'], 'unsigned tinyint(1) loses the width, like MySQL 8');
        $this->assertSame("tinyint(1) NOT NULL DEFAULT '0'", $definitions['isAdmin'], 'plain signed tinyint(1) keeps it: the boolean marker');
    }

    #[Test]
    public function normalizesMariaDbCurrentTimestampSpelling(): void
    {
        // MariaDB prints current_timestamp(), MySQL prints CURRENT_TIMESTAMP; identical schemas
        // must return identical definition strings on both
        $definitions = self::parseDdl(<<<'SQL'
            CREATE TABLE `t` (
              `createdDate` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
              `createdMs` datetime(3) NOT NULL DEFAULT current_timestamp(3) ON UPDATE current_timestamp(3)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            SQL);

        $this->assertSame('timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP', $definitions['createdDate']);
        $this->assertSame('datetime(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3)', $definitions['createdMs'], 'fractional seconds precision survives');
    }

    #[Test]
    public function commentTextIsNeverModified(): void
    {
        // the comment contains MariaDB's timestamp spelling and a doubled quote; it must come
        // through untouched because quoted literals are masked before normalizing and restored after
        $definitions = self::parseDdl(<<<'SQL'
            CREATE TABLE `t` (
              `num` int(11) NOT NULL COMMENT 'uses current_timestamp() and it''s quoted',
              `ts` timestamp NOT NULL DEFAULT current_timestamp() COMMENT 'when created',
              `hits` int(11) NOT NULL DEFAULT 5 COMMENT 'was DEFAULT 5 before'
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            SQL);

        $this->assertSame("int NOT NULL COMMENT 'uses current_timestamp() and it''s quoted'", $definitions['num']);
        $this->assertSame("timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'when created'", $definitions['ts'], 'definition normalized, comment untouched');
        $this->assertSame("int NOT NULL DEFAULT '5' COMMENT 'was DEFAULT 5 before'", $definitions['hits'], "the column's default quotes, the DEFAULT 5 inside the comment doesn't");
    }

    #[Test]
    public function quotesBareNumericDefaultsLikeMysql(): void
    {
        // MariaDB prints numeric-typed defaults bare, MySQL prints them quoted; both accept either
        // form in DDL, so quoting is spelling, not type. Fixture lines are real MariaDB 11.3 output
        $definitions = self::parseDdl(<<<'SQL'
            CREATE TABLE `t` (
              `i_bare` int(11) DEFAULT 0,
              `i_neg` int(11) DEFAULT -1,
              `dec_d` decimal(10,2) NOT NULL DEFAULT 1.50,
              `dbl_small` double DEFAULT 0.00001,
              `dbl_exp` double DEFAULT 1.5e-15,
              `padded` int(6) unsigned zerofill DEFAULT 000000
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            SQL);

        $this->assertSame("int DEFAULT '0'", $definitions['i_bare']);
        $this->assertSame("int DEFAULT '-1'", $definitions['i_neg'], 'negative defaults quote too');
        $this->assertSame("decimal(10,2) NOT NULL DEFAULT '1.50'", $definitions['dec_d']);
        $this->assertSame("double DEFAULT '0.00001'", $definitions['dbl_small']);
        $this->assertSame("double DEFAULT '1.5e-15'", $definitions['dbl_exp'], 'scientific notation is still one numeric token');
        $this->assertSame("int(6) unsigned zerofill DEFAULT '000000'", $definitions['padded'], 'leading zeros survive; the string is what MySQL prints');
    }

    #[Test]
    public function quotedDefaultsPassThroughUnchanged(): void
    {
        // MySQL-style output is already the normalized form, so a second pass must be a no-op:
        // running MySQL output through the parser returns it byte-identical (idempotence)
        $definitions = self::parseDdl(<<<'SQL'
            CREATE TABLE `t` (
              `isAdmin` tinyint(1) NOT NULL DEFAULT '0',
              `price` decimal(10,2) NOT NULL DEFAULT '1.50',
              `padded` int(6) unsigned zerofill NOT NULL DEFAULT '000000'
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            SQL);

        $this->assertSame("tinyint(1) NOT NULL DEFAULT '0'", $definitions['isAdmin']);
        $this->assertSame("decimal(10,2) NOT NULL DEFAULT '1.50'", $definitions['price']);
        $this->assertSame("int(6) unsigned zerofill NOT NULL DEFAULT '000000'", $definitions['padded']);
    }

    #[Test]
    public function bareNonNumericDefaultsAreNeverQuoted(): void
    {
        // the don't-overshoot cases: bare tokens that aren't plain numbers carry meaning in their
        // bare form and must never gain quotes. NULL is the keyword (quoting it would make it the
        // string 'NULL'), CURRENT_TIMESTAMP is a generator, uuid() is an expression, b'101' is a
        // bit literal, and 0x1F would be hex (the number match requires a clean token boundary)
        $definitions = self::parseDdl(<<<'SQL'
            CREATE TABLE `t` (
              `d_null` datetime DEFAULT NULL,
              `d_ts` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
              `d_expr` varchar(36) NOT NULL DEFAULT uuid(),
              `d_bit` bit(4) NOT NULL DEFAULT b'101',
              `d_hex` int(11) NOT NULL DEFAULT 0x1F
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            SQL);

        $this->assertSame('datetime DEFAULT NULL', $definitions['d_null'], 'the NULL keyword stays bare');
        $this->assertSame('timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP', $definitions['d_ts']);
        $this->assertSame('varchar(36) NOT NULL DEFAULT uuid()', $definitions['d_expr'], "MariaDB's bare expression defaults stay bare");
        $this->assertSame("bit(4) NOT NULL DEFAULT b'101'", $definitions['d_bit']);
        $this->assertSame('int NOT NULL DEFAULT 0x1F', $definitions['d_hex'], 'hex is not a plain number, left alone');
    }

    #[Test]
    public function stringDefaultsAreNeverMistakenForNumbers(): void
    {
        // string-typed defaults arrive quoted from every server, whatever their content: numeric-
        // looking strings, the string 'NULL', dates, and escapes must all pass through untouched.
        // Fixture lines are real MariaDB 11.3 output
        $definitions = self::parseDdl(<<<'SQL'
            CREATE TABLE `t` (
              `vc_num` varchar(10) DEFAULT '123',
              `vc_nullstr` varchar(10) DEFAULT 'NULL',
              `vc_empty` varchar(10) DEFAULT '',
              `vc_apos` varchar(20) DEFAULT 'it''s',
              `vc_bslash` varchar(20) DEFAULT 'a\\b',
              `dt_d` datetime DEFAULT '2024-01-01 00:00:00',
              `char0` char(4) DEFAULT '0000'
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            SQL);

        $this->assertSame("varchar(10) DEFAULT '123'", $definitions['vc_num'], 'a string that looks like a number is already quoted');
        $this->assertSame("varchar(10) DEFAULT 'NULL'", $definitions['vc_nullstr'], "the string 'NULL' keeps its quotes and its meaning");
        $this->assertSame("varchar(10) DEFAULT ''", $definitions['vc_empty']);
        $this->assertSame("varchar(20) DEFAULT 'it''s'", $definitions['vc_apos'], 'doubled-quote escaping untouched');
        $this->assertSame("varchar(20) DEFAULT 'a\\\\b'", $definitions['vc_bslash'], 'backslash escaping untouched');
        $this->assertSame("datetime DEFAULT '2024-01-01 00:00:00'", $definitions['dt_d']);
        $this->assertSame("char(4) DEFAULT '0000'", $definitions['char0']);
    }

    #[Test]
    public function stringDefaultTextIsNeverModified(): void
    {
        // a string default can contain any text, including the exact phrases the normalizations
        // look for; the quoted literal must come through byte-identical
        $definitions = self::parseDdl(<<<'SQL'
            CREATE TABLE `t` (
              `promo` varchar(50) NOT NULL DEFAULT 'save DEFAULT 5 each',
              `hint` varchar(50) NOT NULL DEFAULT 'use CHARACTER SET utf8mb4 here',
              `note` varchar(50) NOT NULL DEFAULT 'runs current_timestamp() daily'
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            SQL);

        $this->assertSame("varchar(50) NOT NULL DEFAULT 'save DEFAULT 5 each'", $definitions['promo'], 'DEFAULT <number> inside the string must not gain quotes');
        $this->assertSame("varchar(50) NOT NULL DEFAULT 'use CHARACTER SET utf8mb4 here'", $definitions['hint'], 'table-default charset text inside the string must not be stripped');
        $this->assertSame("varchar(50) NOT NULL DEFAULT 'runs current_timestamp() daily'", $definitions['note'], 'timestamp spelling inside the string must not be uppercased');
    }

    #[Test]
    public function generatedColumnExpressionTextIsNeverModified(): void
    {
        // generated-column expressions embed string literals too, and unlike defaults they can
        // sit anywhere in the definition; their text gets the same protection
        $definitions = self::parseDdl(<<<'SQL'
            CREATE TABLE `t` (
              `label` varchar(101) GENERATED ALWAYS AS (concat('use DEFAULT 5 here')) STORED,
              `cs` varchar(101) GENERATED ALWAYS AS (concat('x CHARACTER SET utf8mb4 y')) STORED,
              `n` int(11) GENERATED ALWAYS AS (if(`mode` = 'zerofill',0,1)) STORED
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            SQL);

        $this->assertSame("varchar(101) GENERATED ALWAYS AS (concat('use DEFAULT 5 here')) STORED", $definitions['label'], 'DEFAULT <number> inside the expression must not gain quotes');
        $this->assertSame("varchar(101) GENERATED ALWAYS AS (concat('x CHARACTER SET utf8mb4 y')) STORED", $definitions['cs'], 'charset text inside the expression must not be stripped');
        $this->assertSame("int GENERATED ALWAYS AS (if(`mode` = 'zerofill',0,1)) STORED", $definitions['n'], "the 'zerofill' inside the expression must not stop the int(11) crop");
    }

    #[Test]
    public function returnsEmptyArrayForEmptyDdl(): void
    {
        $this->assertSame([], self::parseDdl(''));
    }

    //endregion
    //region normalizeCreateTable()

    #[Test]
    public function normalizeCreateTableCropsWidthsAndStripsDefaultCollations(): void
    {
        // MariaDB 11.4 output shape: int display widths everywhere, uca1400 as the table default
        $normalized = Table::normalizeCreateTable(<<<'SQL'
            CREATE TABLE `articles` (
              `num` int(11) NOT NULL AUTO_INCREMENT,
              `padded` int(6) unsigned zerofill DEFAULT NULL,
              `isAdmin` tinyint(1) NOT NULL DEFAULT 0,
              `flags` tinyint(1) unsigned NOT NULL DEFAULT 0,
              `title` varchar(255) COLLATE utf8mb4_uca1400_ai_ci NOT NULL COMMENT 'not int(11), COLLATE utf8mb4_general_ci here',
              `body` mediumtext CHARACTER SET utf8mb4 COLLATE utf8mb4_uca1400_ai_ci DEFAULT NULL,
              `code` varchar(20) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
              PRIMARY KEY (`num`),
              KEY `idx_title` (`title`(10))
            ) ENGINE=InnoDB AUTO_INCREMENT=42 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci
            SQL);

        $this->assertSame(<<<'SQL'
            CREATE TABLE `articles` (
              `num` int NOT NULL AUTO_INCREMENT,
              `padded` int(6) unsigned zerofill DEFAULT NULL,
              `isAdmin` tinyint(1) NOT NULL DEFAULT 0,
              `flags` tinyint unsigned NOT NULL DEFAULT 0,
              `title` varchar(255) NOT NULL COMMENT 'not int(11), COLLATE utf8mb4_general_ci here',
              `body` mediumtext DEFAULT NULL,
              `code` varchar(20) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
              PRIMARY KEY (`num`),
              KEY `idx_title` (`title`(10))
            ) ENGINE=InnoDB AUTO_INCREMENT=42 DEFAULT CHARSET=utf8mb4
            SQL, $normalized);
    }

    #[Test]
    public function normalizeCreateTableStripsTheLegacyUnicodeCiPinButKeepsIntentionalCollations(): void
    {
        // utf8mb4_unicode_ci is no server's default, but CMS Builder pinned it for years, so it
        // strips like one; utf8mb4_bin is a deliberate choice and must survive
        $normalized = Table::normalizeCreateTable(<<<'SQL'
            CREATE TABLE `t` (
              `name` varchar(80) COLLATE utf8mb4_unicode_ci NOT NULL,
              `slug` varchar(80) COLLATE utf8mb4_bin NOT NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            SQL);

        $this->assertSame(<<<'SQL'
            CREATE TABLE `t` (
              `name` varchar(80) NOT NULL,
              `slug` varchar(80) COLLATE utf8mb4_bin NOT NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            SQL, $normalized);
    }

    #[Test]
    public function normalizeCreateTableRewritesUtf8mb3SpellingOnColumns(): void
    {
        // MySQL 8 output for legacy utf8 columns prints the renamed utf8mb3 spelling, which
        // MySQL 5.7 rejects in DDL; utf8 is the spelling every supported server accepts. The
        // charset default collation strips, a _bin choice keeps its collation under the old name
        $normalized = Table::normalizeCreateTable(<<<'SQL'
            CREATE TABLE `legacy` (
              `name` varchar(50) CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci DEFAULT NULL,
              `sorted` varchar(50) CHARACTER SET utf8mb3 COLLATE utf8mb3_bin DEFAULT NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=latin1
            SQL);

        $this->assertSame(<<<'SQL'
            CREATE TABLE `legacy` (
              `name` varchar(50) CHARACTER SET utf8 DEFAULT NULL,
              `sorted` varchar(50) CHARACTER SET utf8 COLLATE utf8_bin DEFAULT NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=latin1
            SQL, $normalized);
    }

    #[Test]
    public function normalizeCreateTableRewritesUtf8mb3TableOptions(): void
    {
        // a whole legacy-utf8 table on MySQL 8: CHARSET=utf8mb3 in the table options must become
        // CHARSET=utf8 or the statement won't replay on MySQL 5.7
        $normalized = Table::normalizeCreateTable(<<<'SQL'
            CREATE TABLE `legacy` (
              `name` varchar(50) DEFAULT NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci
            SQL);

        $this->assertSame(<<<'SQL'
            CREATE TABLE `legacy` (
              `name` varchar(50) DEFAULT NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8
            SQL, $normalized);
    }

    #[Test]
    public function normalizeCreateTableStripsUniversalCharsetDefaultCollations(): void
    {
        // latin1_swedish_ci and ascii_general_ci are their charsets' defaults on every supported
        // server; newer servers print them, 5.7-era doesn't, so they strip as noise
        $normalized = Table::normalizeCreateTable(<<<'SQL'
            CREATE TABLE `t` (
              `legacy` varchar(100) CHARACTER SET latin1 COLLATE latin1_swedish_ci NOT NULL DEFAULT '',
              `tableName` varchar(255) CHARACTER SET ascii COLLATE ascii_general_ci NOT NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            SQL);

        $this->assertSame(<<<'SQL'
            CREATE TABLE `t` (
              `legacy` varchar(100) CHARACTER SET latin1 NOT NULL DEFAULT '',
              `tableName` varchar(255) CHARACTER SET ascii NOT NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            SQL, $normalized);
    }

    #[Test]
    public function normalizeCreateTableLeavesAlreadyNormalizedStatementsUntouched(): void
    {
        // clean MySQL 8 output: nothing to crop, nothing to strip
        $sql = <<<'SQL'
            CREATE TABLE `t` (
              `num` int NOT NULL AUTO_INCREMENT,
              `title` varchar(255) NOT NULL DEFAULT '',
              PRIMARY KEY (`num`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            SQL;

        $this->assertSame($sql, Table::normalizeCreateTable($sql));
    }

    #[Test]
    public function normalizeCreateTableRemovesNoiseButNeverUpgradesSchemas(): void
    {
        // engine and charset replay as-is: a legacy MyISAM/latin1 table stays one, and a column's
        // own charset survives (only its era-default collation strips; the column keeps that same
        // collation back as utf8's default on every server)
        $normalized = Table::normalizeCreateTable(<<<'SQL'
            CREATE TABLE `legacy` (
              `num` int(11) NOT NULL,
              `name` varchar(50) CHARACTER SET utf8 COLLATE utf8_general_ci DEFAULT NULL
            ) ENGINE=MyISAM DEFAULT CHARSET=latin1
            SQL);

        $this->assertSame(<<<'SQL'
            CREATE TABLE `legacy` (
              `num` int NOT NULL,
              `name` varchar(50) CHARACTER SET utf8 DEFAULT NULL
            ) ENGINE=MyISAM DEFAULT CHARSET=latin1
            SQL, $normalized);
    }

    #[Test]
    public function maskStringLiteralsHidesQuotedTextAndRestoresItByteIdentical(): void
    {
        $original = "varchar(255) DEFAULT 'a, ''b''' COMMENT 'not int(11)'";

        [$masked, $literals] = Table::maskStringLiterals($original);

        $this->assertStringNotContainsString('int(11)', $masked, 'quoted text is hidden from transforms');
        $this->assertSame($original, strtr($masked, $literals), 'strtr() restores the original');
    }

    //endregion
    //region defaultFromDefinition()

    #[Test]
    public function extractsPlainDefaultValues(): void
    {
        $this->assertSame('draft',   Table::defaultFromDefinition("varchar(20) NOT NULL DEFAULT 'draft'"));
        $this->assertSame('',        Table::defaultFromDefinition("varchar(20) NOT NULL DEFAULT ''"));
        $this->assertSame('0',       Table::defaultFromDefinition('int NOT NULL DEFAULT 0'));
        $this->assertSame('0.00',    Table::defaultFromDefinition('decimal(10,2) NOT NULL DEFAULT 0.00'));
        $this->assertSame("O'Brien", Table::defaultFromDefinition("varchar(50) NOT NULL DEFAULT 'O''Brien'"), 'doubled quotes unescape');
        $this->assertSame('a\\b',    Table::defaultFromDefinition('varchar(50) NOT NULL DEFAULT \'a\\\\b\''), 'backslash escapes unescape');
    }

    #[Test]
    public function returnsNullForNoDefaultAndForDefaultNull(): void
    {
        $this->assertNull(Table::defaultFromDefinition('mediumtext NOT NULL'));
        $this->assertNull(Table::defaultFromDefinition('datetime DEFAULT NULL'));
        $this->assertNull(Table::defaultFromDefinition(''));
    }

    #[Test]
    public function quotedStringNullStaysAStringValue(): void
    {
        // the corruption artifact Backup.php repairs on restore; in DDL the quotes make it unambiguous
        $this->assertSame('NULL', Table::defaultFromDefinition("varchar(20) DEFAULT 'NULL'"));
    }

    #[Test]
    public function expressionDefaultsReturnTheirSqlText(): void
    {
        // an ON UPDATE clause after the default must not be swallowed by the match
        $this->assertSame('current_timestamp()', Table::defaultFromDefinition('timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()'));

        // MySQL 8 expression defaults are parenthesized; the match must balance parens and ignore a
        // ')' inside a string literal, stopping at the expression instead of a later parenthesis
        $this->assertSame("(concat('a',')'))", Table::defaultFromDefinition("varchar(20) DEFAULT (concat('a',')')) COMMENT 'note (x)'"));
    }

    #[Test]
    public function generatedColumnsHaveNoDefault(): void
    {
        // generated columns can't have a DEFAULT clause, so any DEFAULT text in their definition
        // is inside the expression, not a default value
        $this->assertNull(Table::defaultFromDefinition("varchar(101) GENERATED ALWAYS AS (concat('DEFAULT 5')) STORED"));
        $this->assertNull(Table::defaultFromDefinition("varchar(101) GENERATED ALWAYS AS (concat(`last_name`,', ',`first_name`)) VIRTUAL"));
    }

    #[Test]
    public function keywordTextInsideStringLiteralsIsNotStructure(): void
    {
        // DEFAULT and GENERATED ALWAYS AS only count outside quotes: comment text, enum values,
        // and default values themselves can all contain the keywords as plain text
        $this->assertNull(Table::defaultFromDefinition("int NOT NULL COMMENT 'DEFAULT 5 legacy'"), 'a DEFAULT mentioned in the comment is not a default');
        $this->assertSame('7', Table::defaultFromDefinition("int NOT NULL DEFAULT '7' COMMENT 'DEFAULT 9 was the old value'"));
        $this->assertSame('other', Table::defaultFromDefinition("enum('DEFAULT','other') NOT NULL DEFAULT 'other'"), "the enum value 'DEFAULT' is a string, not the keyword");
        $this->assertSame('GENERATED ALWAYS AS text', Table::defaultFromDefinition("varchar(50) DEFAULT 'GENERATED ALWAYS AS text'"), 'a default whose value mentions GENERATED is still a default');
        $this->assertSame('5', Table::defaultFromDefinition("int NOT NULL DEFAULT 5 COMMENT 'GENERATED ALWAYS AS junk'"), 'GENERATED in the comment does not erase a real default');
    }

    //endregion
    //region parseShowIndexRows()

    #[Test]
    public function groupsCompositeIndexColumnsInRowOrder(): void
    {
        $indexes = self::parse([
            ['Key_name' => 'idx_name_date', 'Non_unique' => 1, 'Column_name' => 'name'],
            ['Key_name' => 'idx_name_date', 'Non_unique' => 1, 'Column_name' => 'event_date'],
        ]);

        $this->assertSame(['idx_name_date'], array_keys($indexes));
        $this->assertSame(['name', 'event_date'], $indexes['idx_name_date']['cols']);
        $this->assertSame('name, event_date', $indexes['idx_name_date']['colsCsv']);
    }

    #[Test]
    public function classifiesAutoPrimaryAndCustomIndexes(): void
    {
        $indexes = self::parse([
            ['Key_name' => 'PRIMARY',    'Non_unique' => 0, 'Column_name' => 'num'],
            ['Key_name' => '_auto_name', 'Non_unique' => 1, 'Column_name' => 'name'],
            ['Key_name' => 'idx_custom', 'Non_unique' => 1, 'Column_name' => 'name'],
        ]);

        $flags = array_map(fn($i) => [$i['isAuto'], $i['isPrimary'], $i['isCustom']], $indexes);
        $this->assertSame([false, true,  false], $flags['PRIMARY'],    'PRIMARY is neither auto nor custom');
        $this->assertSame([true,  false, false], $flags['_auto_name'], '_auto_ prefix marks the CMS\'s own indexes');
        $this->assertSame([false, false, true],  $flags['idx_custom'], 'everything else is custom');
    }

    #[Test]
    public function readsUniqueFlagFromIntOrStringNonUnique(): void
    {
        // mysqli can return Non_unique as an int or a numeric string depending on the driver setup
        $indexes = self::parse([
            ['Key_name' => 'idx_unique_int', 'Non_unique' => 0,   'Column_name' => 'a'],
            ['Key_name' => 'idx_unique_str', 'Non_unique' => '0', 'Column_name' => 'b'],
            ['Key_name' => 'idx_plain',      'Non_unique' => 1,   'Column_name' => 'c'],
        ]);

        $this->assertTrue($indexes['idx_unique_int']['isUnique']);
        $this->assertTrue($indexes['idx_unique_str']['isUnique']);
        $this->assertFalse($indexes['idx_plain']['isUnique']);
    }

    #[Test]
    public function fkBackingIndexesAreNotCustom(): void
    {
        // MySQL auto-creates an index (named after the column, no _auto_ prefix) to back a FOREIGN KEY.
        // Index column and constraint column use different casing to prove the match is case-insensitive
        // on both sides, like MySQL itself.
        $indexes = self::parse([
            ['Key_name' => 'bug_test',      'Non_unique' => 1, 'Column_name' => 'Bug_Test'],
            ['Key_name' => 'idx_composite', 'Non_unique' => 1, 'Column_name' => 'Bug_Test'],
            ['Key_name' => 'idx_composite', 'Non_unique' => 1, 'Column_name' => 'status'],
        ], [['BUG_TEST']]);

        $this->assertTrue($indexes['bug_test']['isFk'], 'exact column match backs the FK');
        $this->assertFalse($indexes['bug_test']['isCustom'], "FK-backing index isn't custom, so it can't block erasing the field");
        $this->assertFalse($indexes['idx_composite']['isFk'], "composite index starting with the FK column is still the user's");
        $this->assertTrue($indexes['idx_composite']['isCustom']);
    }

    #[Test]
    public function uniqueIndexOnForeignKeyColumnIsStillCustom(): void
    {
        // a hand-added UNIQUE index on an FK column (e.g. enforcing one-to-one) matches the constraint's
        // columns, but it's a rule the admin should see, not FK bookkeeping - MySQL never auto-creates a
        // UNIQUE backing index, so it must classify as custom and stay visible
        $indexes = self::parse([
            ['Key_name' => 'uq_customer', 'Non_unique' => 0, 'Column_name' => 'customer_id'],
        ], [['customer_id']]);

        $this->assertFalse($indexes['uq_customer']['isFk']);
        $this->assertTrue($indexes['uq_customer']['isCustom']);
    }

    #[Test]
    public function functionalIndexPartsShowAsExpression(): void
    {
        // MySQL 8 functional index parts have Column_name = NULL; with no Expression column reported
        // (servers before 8.0.13) the placeholder '(expression)' stands in
        $indexes = self::parse([
            ['Key_name' => 'idx_func', 'Non_unique' => 1, 'Column_name' => null],
            ['Key_name' => 'idx_func', 'Non_unique' => 1, 'Column_name' => 'status'],
        ]);

        $this->assertSame(['(expression)', 'status'], $indexes['idx_func']['cols']);
        $this->assertSame('(expression), status', $indexes['idx_func']['colsCsv']);
    }

    #[Test]
    public function functionalIndexPartsShowTheReportedExpression(): void
    {
        // MySQL 8.0.13+ reports the expression in SHOW INDEX's Expression column
        $indexes = self::parse([
            ['Key_name' => 'idx_func', 'Non_unique' => 1, 'Column_name' => null, 'Expression' => 'lower(`name`)'],
        ]);

        $this->assertSame(['(lower(`name`))'], $indexes['idx_func']['cols']);
    }

    #[Test]
    public function prefixLengthShowsInColsCsvButNotCols(): void
    {
        // KEY `idx_email` (`email`(10), `status`) - SHOW INDEX reports the prefix length in Sub_part.
        // Like Non_unique, mysqli can return Sub_part as an int or a numeric string, so cover both.
        $indexes = self::parse([
            ['Key_name' => 'idx_email', 'Non_unique' => 1, 'Column_name' => 'email', 'Sub_part' => 10],
            ['Key_name' => 'idx_email', 'Non_unique' => 1, 'Column_name' => 'status'],
            ['Key_name' => 'idx_body',  'Non_unique' => 1, 'Column_name' => 'body',  'Sub_part' => '191'],
        ]);

        $this->assertSame(['email', 'status'], $indexes['idx_email']['cols'], 'cols stays plain column names for FK/column matching');
        $this->assertSame('email(10), status', $indexes['idx_email']['colsCsv'], 'colsCsv is the display form');
        $this->assertSame('body(191)', $indexes['idx_body']['colsCsv'], 'string Sub_part works too');
    }

    #[Test]
    public function capturesIndexTypeVisibilityAndComment(): void
    {
        $indexes = self::parse([
            ['Key_name' => 'idx_ft',      'Non_unique' => 1, 'Column_name' => 'body', 'Index_type' => 'FULLTEXT'],
            ['Key_name' => 'idx_hidden',  'Non_unique' => 1, 'Column_name' => 'a',    'Index_type' => 'BTREE', 'Visible' => 'NO'],
            ['Key_name' => 'idx_ignored', 'Non_unique' => 1, 'Column_name' => 'b',    'Index_type' => 'BTREE', 'Ignored' => 'YES'],
            ['Key_name' => 'idx_shown',   'Non_unique' => 1, 'Column_name' => 'd',    'Index_type' => 'BTREE', 'Visible' => 'YES'],
            ['Key_name' => 'idx_noted',   'Non_unique' => 1, 'Column_name' => 'c',    'Index_type' => 'BTREE', 'Index_comment' => 'covering index for reports'],
        ]);

        $this->assertSame('FULLTEXT', $indexes['idx_ft']['indexType']);
        $this->assertTrue($indexes['idx_ft']['isVisible'], 'no Visible/Ignored column (MySQL 5.7) means visible');
        $this->assertSame('', $indexes['idx_ft']['comment']);
        $this->assertFalse($indexes['idx_hidden']['isVisible'], 'MySQL 8 reports invisible indexes as Visible=NO');
        $this->assertFalse($indexes['idx_ignored']['isVisible'], 'MariaDB reports them as Ignored=YES');
        $this->assertTrue($indexes['idx_shown']['isVisible'], "MySQL 8's normal Visible=YES stays visible");
        $this->assertSame('covering index for reports', $indexes['idx_noted']['comment']);
    }

    #[Test]
    public function returnsEmptyArrayForNoRows(): void
    {
        // a table with no indexes (or a filtered-to-nothing result) must not warp into a stray entry
        $this->assertSame([], self::parse([]));
        $this->assertSame([], self::parse([], [['a', 'b']]));
    }

    #[Test]
    public function matchesMultiColumnForeignKeyInColumnOrder(): void
    {
        // a composite FK is backed by an index whose columns equal the constraint's, in order
        $indexes = self::parse([
            ['Key_name' => 'idx_fk', 'Non_unique' => 1, 'Column_name' => 'a'],
            ['Key_name' => 'idx_fk', 'Non_unique' => 1, 'Column_name' => 'b'],
        ], [['a', 'b']]);

        $this->assertTrue($indexes['idx_fk']['isFk'], 'exact multi-column match backs the FK');
        $this->assertFalse($indexes['idx_fk']['isCustom'], "FK-backing index isn't custom");
    }

    #[Test]
    public function foreignKeyMatchIsOrderSensitive(): void
    {
        // MySQL requires the FK columns to be the index's leftmost prefix in order, so (b, a) does NOT
        // back a FK on (a, b). The whole-array strict compare in the parser depends on this - a refactor
        // that sorts columns before comparing would silently break FK detection, so pin the behavior.
        $indexes = self::parse([
            ['Key_name' => 'idx_ba', 'Non_unique' => 1, 'Column_name' => 'b'],
            ['Key_name' => 'idx_ba', 'Non_unique' => 1, 'Column_name' => 'a'],
        ], [['a', 'b']]);

        $this->assertFalse($indexes['idx_ba']['isFk'], 'reversed column order is not an FK-backing match');
        $this->assertTrue($indexes['idx_ba']['isCustom']);
    }

    #[Test]
    public function matchesAgainstAnyOfSeveralForeignKeyConstraints(): void
    {
        // a table can have several FKs; an index backs one if it matches ANY constraint's column set
        $indexes = self::parse([
            ['Key_name' => 'idx_second', 'Non_unique' => 1, 'Column_name' => 'owner_num'],
        ], [['parent_num'], ['owner_num']]);

        $this->assertTrue($indexes['idx_second']['isFk'], 'matches the second constraint');
    }

    #[Test]
    public function compositeSupersetOfForeignKeyColumnsIsClassifiedCustom(): void
    {
        // BY DESIGN: MySQL will use a composite index (parent_num, sort_date) to enforce a FK on
        // parent_num alone (the FK column is its leftmost prefix), but the admin added that composite
        // on purpose, so it must stay visible as custom in the Database Editor. Only an EXACT column
        // match counts as FK bookkeeping. Don't "fix" the parser into a leftmost-prefix match.
        $indexes = self::parse([
            ['Key_name' => 'idx_fkcomposite', 'Non_unique' => 1, 'Column_name' => 'parent_num'],
            ['Key_name' => 'idx_fkcomposite', 'Non_unique' => 1, 'Column_name' => 'sort_date'],
        ], [['parent_num']]);

        $this->assertFalse($indexes['idx_fkcomposite']['isFk']);
        $this->assertTrue($indexes['idx_fkcomposite']['isCustom']);
    }

    //endregion
}
