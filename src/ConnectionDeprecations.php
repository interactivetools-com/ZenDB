<?php
declare(strict_types=1);

namespace Itools\ZenDB;

use InvalidArgumentException;
use JetBrains\PhpStorm\Deprecated;
use mysqli_sql_exception;

/**
 * Old Connection method names, phased out in stages.
 *
 * Everything here is at the Silent stage: it works, PHPStorm shows a
 * strikethrough and one-click rename via #[Deprecated], static analyzers see
 * each method's deprecation tag, and there is no runtime signal. See
 * DBDeprecations for the full five-stage ladder these move down in later releases.
 */
trait ConnectionDeprecations
{

    //region Silent Aliases

    /**
     * @deprecated Use $connection->table->exists() or ->table->existsFull() instead; note
     *             they throw for invalid names where this returns false
     * @see        TableInfo::exists()
     * @see        TableInfo::existsFull()
     */
    #[Deprecated(reason: 'use ->table->exists() or ->table->existsFull() instead')]
    public function hasTable(string $table, bool $isPrefixed = false): bool
    {
        try {
            return $isPrefixed ? $this->table->existsFull($table) : $this->table->exists($table);
        } catch (InvalidArgumentException) {
            return false; // invalid name can't be a table
        }
    }

    /**
     * @deprecated Use $connection->table->names() or ->namesFull() instead
     * @see        Table::names()
     * @see        Table::namesFull()
     */
    #[Deprecated(reason: 'use ->table->names() or ->table->namesFull() instead')]
    public function getTableNames(bool $withPrefix = false): array
    {
        return $withPrefix ? $this->table->namesFull() : $this->table->names();
    }

    /**
     * @deprecated Use $connection->table->columnDefinitions() instead; note it throws for unknown
     *             tables and invalid names where this returns []
     * @see        TableInfo::columnDefinitions()
     */
    #[Deprecated(reason: 'use ->table->columnDefinitions() instead')]
    public function getColumnDefinitions(string $baseTable): array
    {
        try {
            return $this->table->columnDefinitions($baseTable);
        } catch (mysqli_sql_exception $e) {
            if (2000 <= $e->getCode() && $e->getCode() <= 2999) {
                throw $e; // connection failure: no answer to report (see TableInfo::existsFull)
            }
            return []; // server answered: unknown table, invalid name, or no access means no definitions
        } catch (InvalidArgumentException) {
            return []; // invalid name can't have definitions
        }
    }

    //endregion
    //region Logged Aliases

    //endregion
    //region Visible Notices

    //endregion
    //region Fatal & Undefined

    //endregion

}
