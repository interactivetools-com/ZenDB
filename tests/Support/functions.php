<?php
declare(strict_types=1);

/*
 * Plain functions for the test suite, loaded by tests/bootstrap.php.
 *
 * Copy-in file: SmartArray, SmartString, and ZenDB carry it byte-identical (no namespace line, so
 * fully identical). Edit every copy or none.
 */

/**
 * Put http_response_code() back to false, as if nothing had been set.
 *
 * PHP stores the code in a 32-bit C int, so an int whose low 32 bits are zero stores as 0, which is
 * what "never set" looks like. 1 << 32 is the smallest such number. Verified against php-src 8.1
 * through master (ext/standard/head.c). Nothing else clears it: 0 means "read", header_remove()
 * leaves it alone, and header('HTTP/...') blocks later calls with a warning on PHP 8.5.
 */
function http_response_code_clear(): void
{
    http_response_code(1 << 32);
}
