<?php
declare(strict_types=1);

namespace Itools\ZenDB\Tests\Encryption;

use InvalidArgumentException;
use Itools\ZenDB\DB;
use PHPUnit\Framework\TestCase;

/**
 * Tests for DB::decryptExpr() name grammar: a column or table.column, one dot at most.
 * Pure string building, so no database connection is needed.
 *
 * @covers \Itools\ZenDB\DB::decryptExpr
 */
class DecryptExprTest extends TestCase
{
    public function testColumnAlone(): void
    {
        $this->assertSame('AES_DECRYPT(`apiToken`, @ek)', DB::decryptExpr('apiToken'));
    }

    public function testTableQualifiedColumn(): void
    {
        $this->assertSame('AES_DECRYPT(`blog`.`title`, @ek)', DB::decryptExpr('blog.title'));
    }

    public function testMoreThanOneDotThrows(): void
    {
        // A second dot would build a database-qualified reference, which no caller means
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('expected column or table.column');

        DB::decryptExpr('db.users.apiToken');
    }

    public function testInvalidCharactersThrow(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('expected column or table.column');

        DB::decryptExpr('apiToken`; DROP TABLE users');
    }
}
