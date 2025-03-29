<?php
/** @noinspection PhpIllegalPsrClassPathInspection */
declare(strict_types=1);

namespace Itools\ZenDB;


/**
 * Class RawSql
 *
 * Represents a raw SQL value.
 *
 * Usage:
 * $stringObj = new RawSql("NOW()");
 * if ($stringOrObj instanceof RawSql) { $sqlString = (string) $stringObj; }
 */
class RawSql
{
    #region Methods
    /**
     * @var string The raw SQL value
     */
    private string $rawSql;

    #endregion
    #region Magic Methods

    /**
     * DBRaw constructor.
     *
     * @param string $value
     */
    public function __construct(string $value) {
        $this->rawSql = $value;
    }

    public function __toString(): string
    {
        return $this->rawSql;
    }
    #endregion
}
