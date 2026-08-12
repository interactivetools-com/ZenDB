<?php
declare(strict_types=1);

namespace InteractiveTools\Standards;

use ReflectionFunction;

use function array_diff_key, array_fill_keys, array_filter, array_keys, array_map, array_merge, array_pop, array_slice, array_values, basename, count, end, explode, file_get_contents, file_put_contents, function_exists, fwrite, get_defined_constants, implode, in_array, is_array, is_file, ksort, ltrim, preg_match, preg_match_all, preg_replace_callback, printf, realpath, sort, str_contains, strlen, strtolower, substr_replace, token_get_all, trim, usort;
use const PREG_OFFSET_CAPTURE, PREG_SET_ORDER, STDERR, T_AS, T_ATTRIBUTE, T_CASE, T_CLASS, T_COMMENT, T_CONST, T_CURLY_OPEN, T_DOC_COMMENT, T_DOLLAR_OPEN_CURLY_BRACES, T_DOUBLE_COLON, T_ENCAPSED_AND_WHITESPACE, T_ENUM, T_EXTENDS, T_FUNCTION, T_GOTO, T_IMPLEMENTS, T_INSTANCEOF, T_INTERFACE, T_NAMESPACE, T_NAME_FULLY_QUALIFIED, T_NAME_QUALIFIED, T_NEW, T_NULLSAFE_OBJECT_OPERATOR, T_OBJECT_OPERATOR, T_START_HEREDOC, T_STRING, T_TRAIT, T_USE, T_VARIABLE, T_WHITESPACE;

/**
 * Keeps built-in function calls and constant reads in namespaced files
 * resolved at compile time, with one import block per file and no backslashes
 * at the use sites.
 *
 * Inside a namespace, `strlen($s)` compiles to a runtime lookup: PHP checks for
 * `YourNamespace\strlen` first and falls back to the global one. Importing the
 * name settles it at compile time. `\strlen($s)` settles it too and costs the
 * same, but scatters the decision across every call site, so the house style is
 * the import. Constants do even better: an unqualified `SORT_STRING` compiles
 * to a runtime fetch with the same fallback, while an imported one is inlined
 * as a literal and costs nothing. See micro-optimizations-namespaced-calls.md
 * for what it is worth.
 *
 * Three things are reported, for built-in functions and constants alike, and
 * the import lines end up listing exactly what the file uses:
 *
 *   missing    a built-in used unqualified with no import
 *   qualified  a built-in written with a leading backslash; import it instead
 *   unused     a built-in imported but never used
 *
 * The `use function` line comes first and the `use const` line sits directly
 * below it, no blank line between: one block, one rule.
 *
 * The tokenizer says how each name was written and the interpreter says which
 * names are built-in, so this mostly stays correct on PHP versions that did not
 * exist when it was written. The two lists it carries, LATE_BUILTINS and
 * LATE_CONSTANTS, cover the gap that opens when the test runs on an older PHP
 * than the code supports.
 *
 *     $findings = NamespacedCallsCheck::scanPath('src/');
 *     NamespacedCallsCheck::fixFile('src/Thing.php');
 *
 * The original of this file lives in the internal docs repo under
 * programming/; SmartString, SmartArray, ZenDB, and CMS Builder each carry a
 * byte-identical copy next to their NamespacedCallsTest.php. Edit the
 * original and re-copy it to every repo rather than editing a copy; the
 * release checklist in open-source/repo-standards.md compares the copies.
 *
 * Point a test at it, or run it directly:
 *
 *     php NamespacedCallsCheck.php src/ lib/          # report
 *     php NamespacedCallsCheck.php --fix src/         # rewrite the imports
 */
class NamespacedCallsCheck
{
    /**
     * Built-ins that do not exist in every supported PHP version.
     *
     * Everything else is detected by asking the interpreter, which is what keeps
     * this correct when PHP adds a function. That breaks down in one place: a
     * test running on 8.1 cannot see that `str_increment` is a built-in on 8.3,
     * so a call to it looks like userland code and never gets flagged, and the
     * lookup then costs on newer versions forever with nothing to catch it.
     *
     * These names close that gap. `use function` is only a compile-time alias,
     * so importing a name the running version does not have is harmless.
     *
     * Regenerate by diffing `get_defined_functions()['internal']` across every
     * supported version, keeping only functions from extensions that all of
     * those builds load, so a build difference is not mistaken for a version
     * one. Language constructs (exit, die, clone) are left out: they never
     * arrive as a plain name token, so they can never be matched here.
     */
    private const LATE_BUILTINS = [
        'array_all', 'array_any', 'array_find', 'array_find_key', 'array_first', 'array_is_list',
        'array_last', 'curl_multi_get_handles', 'curl_share_init_persistent', 'curl_upkeep',
        'enum_exists', 'fdatasync', 'fpow', 'fsync', 'get_error_handler', 'get_exception_handler',
        'http_clear_last_response_headers', 'http_get_last_response_headers', 'ini_parse_quantity',
        'json_validate', 'libxml_get_external_entity_loader', 'mb_lcfirst', 'mb_ltrim', 'mb_rtrim',
        'mb_str_pad', 'mb_trim', 'mb_ucfirst', 'memory_reset_peak_usage', 'mysqli_execute_query',
        'opcache_is_script_cached_in_file_cache', 'opcache_jit_blacklist',
        'openssl_cipher_key_length', 'pcntl_getcpu', 'pcntl_getcpuaffinity',
        'pcntl_setcpuaffinity', 'pcntl_setns', 'pcntl_waitid', 'posix_eaccess', 'posix_fpathconf',
        'posix_pathconf', 'posix_sysconf', 'request_parse_body', 'socket_atmark',
        'sodium_crypto_core_ristretto255_add', 'sodium_crypto_core_ristretto255_from_hash',
        'sodium_crypto_core_ristretto255_is_valid_point', 'sodium_crypto_core_ristretto255_random',
        'sodium_crypto_core_ristretto255_scalar_add',
        'sodium_crypto_core_ristretto255_scalar_complement',
        'sodium_crypto_core_ristretto255_scalar_invert',
        'sodium_crypto_core_ristretto255_scalar_mul',
        'sodium_crypto_core_ristretto255_scalar_negate',
        'sodium_crypto_core_ristretto255_scalar_random',
        'sodium_crypto_core_ristretto255_scalar_reduce',
        'sodium_crypto_core_ristretto255_scalar_sub', 'sodium_crypto_core_ristretto255_sub',
        'sodium_crypto_scalarmult_ristretto255', 'sodium_crypto_scalarmult_ristretto255_base',
        'sodium_crypto_stream_xchacha20', 'sodium_crypto_stream_xchacha20_keygen',
        'sodium_crypto_stream_xchacha20_xor', 'sodium_crypto_stream_xchacha20_xor_ic',
        'str_decrement', 'str_increment', 'stream_context_set_options',
    ];

    /**
     * Built-in constants that do not exist in every supported PHP version,
     * closing the same gap as LATE_BUILTINS: a test running on an older PHP
     * cannot see that a newer version's constant is built-in, so a read of one
     * would look like userland code and never get flagged.
     *
     * Regenerate by diffing `get_defined_constants(true)` minus its 'user'
     * category across every supported version, keeping only constants from
     * extensions that all of those builds load, and dropping namespaced names
     * (`Dom\INDEX_SIZE_ERR`), which can never appear as an unqualified read.
     */
    private const LATE_CONSTANTS = [
        'AF_PACKET', 'CURLALTSVC_H1', 'CURLALTSVC_H2', 'CURLALTSVC_H3', 'CURLALTSVC_READONLYFILE',
        'CURLAUTH_AWS_SIGV4', 'CURLE_PROXY', 'CURLFTPMETHOD_DEFAULT', 'CURLHSTS_ENABLE',
        'CURLHSTS_READONLYFILE', 'CURLINFO_CAINFO', 'CURLINFO_CAPATH', 'CURLINFO_CONN_ID',
        'CURLINFO_DATA_IN', 'CURLINFO_DATA_OUT', 'CURLINFO_EFFECTIVE_METHOD', 'CURLINFO_HEADER_IN',
        'CURLINFO_PROXY_ERROR', 'CURLINFO_REFERER', 'CURLINFO_RETRY_AFTER', 'CURLINFO_SSL_DATA_IN',
        'CURLINFO_SSL_DATA_OUT', 'CURLINFO_TEXT', 'CURLKHMATCH_LAST', 'CURLKHMATCH_MISMATCH',
        'CURLKHMATCH_MISSING', 'CURLKHMATCH_OK', 'CURLMIMEOPT_FORMESCAPE',
        'CURLMOPT_MAX_CONCURRENT_STREAMS', 'CURLOPT_ALTSVC', 'CURLOPT_ALTSVC_CTRL',
        'CURLOPT_AWS_SIGV4', 'CURLOPT_CAINFO_BLOB', 'CURLOPT_CA_CACHE_TIMEOUT',
        'CURLOPT_DEBUGFUNCTION', 'CURLOPT_DOH_SSL_VERIFYHOST', 'CURLOPT_DOH_SSL_VERIFYPEER',
        'CURLOPT_DOH_SSL_VERIFYSTATUS', 'CURLOPT_HSTS', 'CURLOPT_HSTS_CTRL',
        'CURLOPT_INFILESIZE_LARGE', 'CURLOPT_MAIL_RCPT_ALLLOWFAILS', 'CURLOPT_MAXAGE_CONN',
        'CURLOPT_MAXFILESIZE_LARGE', 'CURLOPT_MAXLIFETIME_CONN', 'CURLOPT_MIME_OPTIONS',
        'CURLOPT_PREREQFUNCTION', 'CURLOPT_PROTOCOLS_STR', 'CURLOPT_PROXY_CAINFO_BLOB',
        'CURLOPT_QUICK_EXIT', 'CURLOPT_REDIR_PROTOCOLS_STR', 'CURLOPT_SASL_AUTHZID',
        'CURLOPT_SERVER_RESPONSE_TIMEOUT', 'CURLOPT_SSH_HOSTKEYFUNCTION',
        'CURLOPT_SSH_HOST_PUBLIC_KEY_SHA256', 'CURLOPT_SSL_EC_CURVES',
        'CURLOPT_UPKEEP_INTERVAL_MS', 'CURLOPT_UPLOAD_BUFFERSIZE', 'CURLOPT_WS_OPTIONS',
        'CURLOPT_XFERINFOFUNCTION', 'CURLPROTO_MQTT', 'CURLPX_BAD_ADDRESS_TYPE',
        'CURLPX_BAD_VERSION', 'CURLPX_CLOSED', 'CURLPX_GSSAPI', 'CURLPX_GSSAPI_PERMSG',
        'CURLPX_GSSAPI_PROTECTION', 'CURLPX_IDENTD', 'CURLPX_IDENTD_DIFFER',
        'CURLPX_LONG_HOSTNAME', 'CURLPX_LONG_PASSWD', 'CURLPX_LONG_USER', 'CURLPX_NO_AUTH',
        'CURLPX_OK', 'CURLPX_RECV_ADDRESS', 'CURLPX_RECV_AUTH', 'CURLPX_RECV_CONNECT',
        'CURLPX_RECV_REQACK', 'CURLPX_REPLY_ADDRESS_TYPE_NOT_SUPPORTED',
        'CURLPX_REPLY_COMMAND_NOT_SUPPORTED', 'CURLPX_REPLY_CONNECTION_REFUSED',
        'CURLPX_REPLY_GENERAL_SERVER_FAILURE', 'CURLPX_REPLY_HOST_UNREACHABLE',
        'CURLPX_REPLY_NETWORK_UNREACHABLE', 'CURLPX_REPLY_NOT_ALLOWED', 'CURLPX_REPLY_TTL_EXPIRED',
        'CURLPX_REPLY_UNASSIGNED', 'CURLPX_REQUEST_FAILED', 'CURLPX_RESOLVE_HOST',
        'CURLPX_SEND_AUTH', 'CURLPX_SEND_CONNECT', 'CURLPX_SEND_REQUEST', 'CURLPX_UNKNOWN_FAIL',
        'CURLPX_UNKNOWN_MODE', 'CURLPX_USER_REJECTED', 'CURLSSLOPT_AUTO_CLIENT_CERT',
        'CURLSSLOPT_NATIVE_CA', 'CURLSSLOPT_NO_PARTIALCHAIN', 'CURLSSLOPT_REVOKE_BEST_EFFORT',
        'CURLWS_RAW_MODE', 'CURL_HTTP_VERSION_3', 'CURL_HTTP_VERSION_3ONLY',
        'CURL_PREREQFUNC_ABORT', 'CURL_PREREQFUNC_OK', 'CURL_VERSION_GSASL', 'CURL_VERSION_HSTS',
        'CURL_VERSION_HTTP3', 'CURL_VERSION_UNICODE', 'CURL_VERSION_ZSTD', 'CURRENCY_SYMBOL',
        'DATE_ISO8601_EXPANDED', 'DECIMAL_POINT', 'ERA_YEAR', 'ETH_P_ALL', 'ETH_P_IP',
        'ETH_P_IPV6', 'ETH_P_LOOP', 'FILEINFO_APPLE', 'FILTER_FLAG_GLOBAL_RANGE',
        'FILTER_THROW_ON_FAILURE', 'FRAC_DIGITS', 'GROUPING', 'IMAGETYPE_HEIF', 'IMAGETYPE_SVG',
        'INT_CURR_SYMBOL', 'INT_FRAC_DIGITS', 'IPPROTO_ICMP', 'IPPROTO_ICMPV6',
        'IP_BIND_ADDRESS_NO_PORT', 'IP_MTU_DISCOVER', 'IP_PMTUDISC_DO', 'IP_PMTUDISC_DONT',
        'IP_PMTUDISC_INTERFACE', 'IP_PMTUDISC_OMIT', 'IP_PMTUDISC_PROBE', 'IP_PMTUDISC_WANT',
        'LIBXML_RECOVER', 'MON_DECIMAL_POINT', 'MON_GROUPING', 'MON_THOUSANDS_SEP', 'MSG_ZEROCOPY',
        'MYSQLI_TYPE_VECTOR', 'NEGATIVE_SIGN', 'NOSTR', 'N_CS_PRECEDES', 'N_SEP_BY_SPACE',
        'N_SIGN_POSN', 'OPENSSL_CMS_OLDMIMETYPE', 'OPENSSL_KEYTYPE_ED25519',
        'OPENSSL_KEYTYPE_ED448', 'OPENSSL_KEYTYPE_X25519', 'OPENSSL_KEYTYPE_X448',
        'OPENSSL_PKCS1_PSS_PADDING', 'PHP_BUILD_DATE', 'PHP_BUILD_PROVIDER',
        'PHP_OUTPUT_HANDLER_PROCESSED', 'PHP_SBINDIR', 'PKCS7_CRLFEOL', 'PKCS7_NOCRL',
        'PKCS7_NOOLDMIMETYPE', 'PKCS7_NOSMIMECAP', 'PKCS7_NO_DUAL_CONTENT', 'POSITIVE_SIGN',
        'POSIX_PC_ALLOC_SIZE_MIN', 'POSIX_PC_CHOWN_RESTRICTED', 'POSIX_PC_LINK_MAX',
        'POSIX_PC_MAX_CANON', 'POSIX_PC_MAX_INPUT', 'POSIX_PC_NAME_MAX', 'POSIX_PC_NO_TRUNC',
        'POSIX_PC_PATH_MAX', 'POSIX_PC_PIPE_BUF', 'POSIX_PC_SYMLINK_MAX', 'POSIX_SC_ARG_MAX',
        'POSIX_SC_CHILD_MAX', 'POSIX_SC_CLK_TCK', 'POSIX_SC_NPROCESSORS_CONF',
        'POSIX_SC_NPROCESSORS_ONLN', 'POSIX_SC_OPEN_MAX', 'POSIX_SC_PAGESIZE', 'P_ALL',
        'P_CS_PRECEDES', 'P_PGID', 'P_PID', 'P_PIDFD', 'P_SEP_BY_SPACE', 'P_SIGN_POSN', 'SHUT_RD',
        'SHUT_RDWR', 'SHUT_WR', 'SKF_AD_ALU_XOR_X', 'SKF_AD_CPU', 'SKF_AD_HATYPE',
        'SKF_AD_IFINDEX', 'SKF_AD_MARK', 'SKF_AD_MAX', 'SKF_AD_NLATTR', 'SKF_AD_NLATTR_NEST',
        'SKF_AD_OFF', 'SKF_AD_PAY_OFFSET', 'SKF_AD_PKTTYPE', 'SKF_AD_PROTOCOL', 'SKF_AD_QUEUE',
        'SKF_AD_RANDOM', 'SKF_AD_RXHASH', 'SKF_AD_VLAN_TAG', 'SKF_AD_VLAN_TAG_PRESENT',
        'SKF_AD_VLAN_TPID', 'SOCK_CLOEXEC', 'SOCK_DCCP', 'SOCK_NONBLOCK', 'SOL_UDPLITE',
        'SO_ATTACH_REUSEPORT_CBPF', 'SO_BINDTOIFINDEX', 'SO_BPF_EXTENSIONS', 'SO_BUSY_POLL',
        'SO_DETACH_BPF', 'SO_DETACH_FILTER', 'SO_INCOMING_CPU', 'SO_MEMINFO', 'SO_ZEROCOPY',
        'TCP_CONGESTION', 'TCP_KEEPCNT', 'TCP_KEEPIDLE', 'TCP_KEEPINTVL', 'TCP_NOTSENT_LOWAT',
        'TCP_QUICKACK', 'TCP_REPAIR', 'TCP_SYNCNT', 'THOUSANDS_SEP', 'T_PIPE', 'T_PRIVATE_SET',
        'T_PROPERTY_C', 'T_PROTECTED_SET', 'T_PUBLIC_SET', 'T_VOID_CAST', 'UDP_SEGMENT', 'WEXITED',
        'WNOWAIT', 'WSTOPPED', 'X509_PURPOSE_OCSP_HELPER', 'X509_PURPOSE_TIMESTAMP_SIGN',
        'XML_OPTION_PARSE_HUGE', 'YESSTR', 'ZEND_VM_KIND',
    ];

    #region Scanning

    /**
     * @return list<array{type: string, function: string, line: int}> Empty when
     *         the file is clean or is not in a namespace.
     */
    public static function scanFile(string $path): array
    {
        return self::scanSource((string)file_get_contents($path));
    }

    /** @return list<array{type: string, kind: string, name: string, line: int}> */
    public static function scanSource(string $source): array
    {
        $tokens = token_get_all($source);
        if (!self::isNamespaced($tokens)) {
            return [];
        }

        $declared = self::declaredNames($tokens);
        $findings = [];

        foreach (['function', 'constant'] as $kind) {
            // The file declares these names itself, so the global versions are not
            // what its uses mean and importing them would change or break the code.
            $called   = array_diff_key(self::calledBuiltins($tokens, $kind), $declared[$kind]);
            $imported = array_diff_key(self::importedBuiltins($tokens, $kind), $declared[$kind]);

            foreach ($called as $name => $call) {
                if ($call['qualified']) {
                    $findings[] = ['type' => 'qualified', 'kind' => $kind, 'name' => $name, 'line' => $call['line']];
                } elseif (!isset($imported[$name])) {
                    $findings[] = ['type' => 'missing', 'kind' => $kind, 'name' => $name, 'line' => $call['line']];
                }
            }

            foreach ($imported as $name => $line) {
                if (!isset($called[$name])) {
                    $findings[] = ['type' => 'unused', 'kind' => $kind, 'name' => $name, 'line' => $line];
                }
            }
        }

        usort($findings, static fn(array $a, array $b): int => $a['line'] <=> $b['line']);

        return $findings;
    }

    /**
     * Scans a directory tree, or a single file, for .php files.
     *
     * @return array<string, list<array{type: string, function: string, line: int}>>
     *         Keyed by path, containing only files with findings.
     */
    public static function scanPath(string $path): array
    {
        $results = [];
        foreach (self::phpFiles($path) as $file) {
            $found = self::scanFile($file);
            if ($found !== []) {
                $results[$file] = $found;
            }
        }
        ksort($results);
        return $results;
    }

    #endregion
    #region Reading the tokens

    /**
     * Every built-in function this file calls, or built-in constant it reads,
     * however each was written.
     *
     * A name is recorded as qualified if any use site backslashes it, so a file
     * that writes it both ways is reported rather than half-fixed.
     *
     * @return array<string, array{line: int, qualified: bool}>
     */
    private static function calledBuiltins(array $tokens, string $kind): array
    {
        $called = [];

        foreach ($tokens as $i => $token) {
            $name = $kind === 'function' ? self::calledName($tokens, $i) : self::constantName($tokens, $i);
            if ($name === null) {
                continue;
            }
            [$bare, $qualified] = $name;
            if (!self::isBuiltinName($kind, $bare)) {
                continue;
            }
            $called[$bare] = [
                'line'      => $called[$bare]['line'] ?? $token[2],
                'qualified' => ($called[$bare]['qualified'] ?? false) || $qualified,
            ];
        }

        return $called;
    }

    /**
     * The function name called at token $i, and whether it was backslashed.
     *
     * PHP 8 hands us most of this: `\trim(` arrives as T_NAME_FULLY_QUALIFIED
     * and `Other\helper(` as T_NAME_QUALIFIED, so only a bare T_STRING can be
     * the unqualified case. What is left to exclude is everything else a bare
     * name can be: a method, a static call, a declaration, a class after `new`.
     *
     * @return array{0: string, 1: bool}|null
     */
    private static function calledName(array $tokens, int $i): ?array
    {
        $token = $tokens[$i];
        if (!is_array($token)) {
            return null;
        }
        if (!in_array($token[0], [T_STRING, T_NAME_FULLY_QUALIFIED], true)) {
            return null;
        }
        if (self::nextSignificant($tokens, $i) !== '(') {
            return null;
        }

        if ($token[0] === T_NAME_FULLY_QUALIFIED) {
            $bare = ltrim($token[1], '\\');
            // \Foo\bar() is somebody else's function, not a built-in with a backslash.
            return str_contains($bare, '\\') ? null : [strtolower($bare), true];
        }

        $previous = self::previousSignificant($tokens, $i);
        if ($previous === null || $previous === '\\' || $previous === '&') {
            return null;
        }
        $excluded = [
            T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON,
            T_FUNCTION, T_CLASS, T_INTERFACE, T_TRAIT, T_ENUM, T_NEW,
            T_CONST, T_USE, T_NAMESPACE, T_ATTRIBUTE, T_GOTO,
        ];

        return in_array($previous, $excluded, true) ? null : [strtolower($token[1]), false];
    }

    /**
     * The constant name read at token $i, and whether it was backslashed.
     *
     * A name is a constant read by elimination: not a call (no '(' after it),
     * not a class reference (no '::' after it), not a type ahead of a variable,
     * and not a declaration, import, or member access. Constants keep their
     * exact case, because unlike function names they are case-sensitive.
     * true/false/null are keywords the compiler always resolves, and they are
     * screened out with the other non-built-ins by the caller.
     *
     * @return array{0: string, 1: bool}|null
     */
    private static function constantName(array $tokens, int $i): ?array
    {
        $token = $tokens[$i];
        if (!is_array($token) || !in_array($token[0], [T_STRING, T_NAME_FULLY_QUALIFIED], true)) {
            return null;
        }

        $next     = self::nextSignificantIndex($tokens, $i);
        $nextText = $next === null ? null : (is_array($tokens[$next]) ? $tokens[$next][1] : $tokens[$next]);
        $nextType = $next !== null && is_array($tokens[$next]) ? $tokens[$next][0] : null;
        if ($nextText === '(' || $nextType === T_DOUBLE_COLON || $nextType === T_VARIABLE) {
            return null;   // a call, a class reference, or a type ahead of a variable
        }

        $previous = self::previousSignificant($tokens, $i);
        $excluded = [
            T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON,
            T_FUNCTION, T_CLASS, T_INTERFACE, T_TRAIT, T_ENUM, T_NEW,
            T_CONST, T_USE, T_NAMESPACE, T_ATTRIBUTE, T_GOTO,
            T_INSTANCEOF, T_EXTENDS, T_IMPLEMENTS, T_AS,
        ];
        if ($previous === '\\' || $previous === '&' || in_array($previous, $excluded, true)) {
            return null;
        }
        // `case NAME;` and `case NAME = 1;` declare an enum case; `case NAME:` in a switch reads a constant.
        if ($previous === T_CASE && ($nextText === ';' || $nextText === '=')) {
            return null;
        }
        // `"$row[KEY]"` interpolated in a string is a literal array key, not a
        // constant read. Only there, the bracket and variable are glued to the
        // name with no whitespace tokens, and a string token sits just before.
        if (($tokens[$i - 1] ?? null) === '['
            && is_array($tokens[$i - 2] ?? null) && $tokens[$i - 2][0] === T_VARIABLE
            && (($tokens[$i - 3] ?? null) === '"'
                || (is_array($tokens[$i - 3] ?? null) && in_array($tokens[$i - 3][0], [T_ENCAPSED_AND_WHITESPACE, T_START_HEREDOC], true)))) {
            return null;
        }

        if ($token[0] === T_NAME_FULLY_QUALIFIED) {
            $bare = ltrim($token[1], '\\');
            // \Foo\BAR is somebody else's constant, not a built-in with a backslash.
            return str_contains($bare, '\\') ? null : [$bare, true];
        }
        return [$token[1], false];
    }

    /** True when the file declares a namespace, since global-scope code has nothing to fix. */
    private static function isNamespaced(array $tokens): bool
    {
        foreach ($tokens as $token) {
            if (is_array($token) && $token[0] === T_NAMESPACE) {
                return true;
            }
        }
        return false;
    }

    /**
     * Built-in names already imported by `use function` or `use const`, and
     * the line each sits on.
     *
     * Userland imports are left out on purpose: this rule is about built-ins,
     * and removing somebody's `use function App\Helpers\slugify;` because it
     * looked unused is not this tool's business.
     *
     * @return array<string, int>
     */
    private static function importedBuiltins(array $tokens, string $kind): array
    {
        $marker   = $kind === 'function' ? T_FUNCTION : T_CONST;
        $imported = [];
        $count    = count($tokens);

        for ($i = 0; $i < $count; $i++) {
            if (!is_array($tokens[$i]) || $tokens[$i][0] !== T_USE) {
                continue;
            }
            $next = self::nextSignificantIndex($tokens, $i);
            if ($next === null || !is_array($tokens[$next]) || $tokens[$next][0] !== $marker) {
                continue;   // a class import, the other kind, or a closure's `use (...)`
            }

            for ($j = $next + 1; $j < $count && $tokens[$j] !== ';'; $j++) {
                if (!is_array($tokens[$j])) {
                    continue;
                }
                if (!in_array($tokens[$j][0], [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true)) {
                    continue;
                }
                $name = ltrim($tokens[$j][1], '\\');
                if ($kind === 'function') {
                    $name = strtolower($name);
                }
                if (!str_contains($name, '\\') && self::isBuiltinName($kind, $name)) {
                    $imported[$name] = $tokens[$j][2];
                }
            }
        }

        return $imported;
    }

    /** The previous token's type, or its literal text when it is punctuation. */
    private static function previousSignificant(array $tokens, int $i): string|int|null
    {
        for ($j = $i - 1; $j >= 0; $j--) {
            if (is_array($tokens[$j])) {
                if (in_array($tokens[$j][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                    continue;
                }
                return $tokens[$j][0];
            }
            return $tokens[$j];
        }
        return null;
    }

    /** The next token's literal text, for checking that a name is followed by '('. */
    private static function nextSignificant(array $tokens, int $i): ?string
    {
        $next = self::nextSignificantIndex($tokens, $i);
        if ($next === null) {
            return null;
        }
        return is_array($tokens[$next]) ? $tokens[$next][1] : $tokens[$next];
    }

    private static function nextSignificantIndex(array $tokens, int $i): ?int
    {
        $count = count($tokens);
        for ($j = $i + 1; $j < $count; $j++) {
            if (is_array($tokens[$j]) && in_array($tokens[$j][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            return $j;
        }
        return null;
    }

    /**
     * True for functions PHP itself provides.
     *
     * Asking the interpreter rather than carrying a list keeps this correct on
     * versions that added functions after this file was written. Functions
     * defined by an extension count: they are equally subject to the lookup.
     */
    private static function isBuiltin(string $name): bool
    {
        static $known = [];
        if (!isset($known[$name])) {
            $known[$name] = (function_exists($name) && (new ReflectionFunction($name))->isInternal())
                || in_array($name, self::LATE_BUILTINS, true);
        }
        return $known[$name];
    }

    /**
     * True for constants PHP itself provides, matched by exact name because
     * constants are case-sensitive. TRUE/FALSE/NULL are excluded: the parser
     * treats them as keywords whatever the case, so there is never a lookup
     * to save and `use const TRUE` would not even compile.
     */
    private static function isBuiltinConstant(string $name): bool
    {
        static $known = null;
        if ($known === null) {
            $categories = get_defined_constants(true);
            unset($categories['user']);
            $known = array_fill_keys(array_keys(array_merge(...array_values($categories))), true);
            unset($known['TRUE'], $known['FALSE'], $known['NULL']);
        }
        return isset($known[$name]) || in_array($name, self::LATE_CONSTANTS, true);
    }

    private static function isBuiltinName(string $kind, string $name): bool
    {
        return $kind === 'function' ? self::isBuiltin($name) : self::isBuiltinConstant($name);
    }

    /**
     * Function and constant names the file declares itself, functions
     * lowercased and constants exact-case.
     *
     * Class members do not claim a name: a class can have its own count()
     * method or SORT_STRING constant while the file imports the built-in, and
     * the two never collide, because members are only reached through -> or ::.
     * A declaration at namespace level does claim it (for functions, including
     * one nested in an if or another function, which PHP registers at the
     * namespace level when the declaration runs), so importing the global one
     * would change what the uses mean, or fail outright with "the name is
     * already in use". Declared names are left alone.
     *
     * @return array{function: array<string, true>, constant: array<string, true>}
     */
    private static function declaredNames(array $tokens): array
    {
        $declared   = ['function' => [], 'constant' => []];
        $bodyStack  = [];    // one entry per open '{': true when it opens a class-like body
        $parenDepth = 0;
        $classDepth = null;  // paren depth at a class keyword, until its body brace opens

        foreach ($tokens as $i => $token) {
            if (!is_array($token)) {
                if ($token === '(') {
                    $parenDepth++;
                } elseif ($token === ')') {
                    $parenDepth--;
                } elseif ($token === '{') {
                    // `new class(function () { ... }) { ... }`: the closure body brace
                    // sits inside the argument parens, so only a brace back at the
                    // keyword's own paren depth opens the class body.
                    $bodyStack[] = $classDepth === $parenDepth;
                    if ($classDepth === $parenDepth) {
                        $classDepth = null;
                    }
                } elseif ($token === '}') {
                    array_pop($bodyStack);
                }
                continue;
            }

            if (in_array($token[0], [T_CLASS, T_INTERFACE, T_TRAIT, T_ENUM], true)) {
                // Foo::class is the class-name constant, not a declaration opening a body.
                if (self::previousSignificant($tokens, $i) !== T_DOUBLE_COLON) {
                    $classDepth = $parenDepth;
                }
                continue;
            }

            // '{' inside string interpolation opens no body but is closed by a plain '}'
            if ($token[0] === T_CURLY_OPEN || $token[0] === T_DOLLAR_OPEN_CURLY_BRACES) {
                $bodyStack[] = false;
                continue;
            }

            // Inside a class body, methods and class constants claim nothing.
            if (end($bodyStack) === true || !in_array($token[0], [T_FUNCTION, T_CONST], true)) {
                continue;
            }
            // `use function strlen;` and `use const PHP_EOL;` are imports, and
            // importing a name is the opposite of declaring one.
            if (self::previousSignificant($tokens, $i) === T_USE) {
                continue;
            }

            if ($token[0] === T_FUNCTION) {
                $next = self::nextSignificantIndex($tokens, $i);
                if ($next !== null && is_array($tokens[$next]) && $tokens[$next][0] === T_STRING) {
                    $declared['function'][strtolower($tokens[$next][1])] = true;
                }
            } elseif ($token[0] === T_CONST) {
                foreach (self::constNamesAfter($tokens, $i) as $name) {
                    $declared['constant'][$name] = true;
                }
            }
        }
        return $declared;
    }

    /**
     * The names a `const A = 1, B = 2;` statement declares, reading from the
     * T_CONST token at $i to the closing semicolon. Commas inside a value
     * (`const A = [1, 2], B = 3;`) do not start a new name because only a
     * comma at the statement's own bracket depth does.
     *
     * @return list<string>
     */
    private static function constNamesAfter(array $tokens, int $i): array
    {
        $names      = [];
        $expectName = true;
        $depth      = 0;
        $count      = count($tokens);

        for ($j = $i + 1; $j < $count; $j++) {
            $token = $tokens[$j];
            if (!is_array($token)) {
                if ($token === ';' && $depth === 0) {
                    break;
                }
                if ($token === '(' || $token === '[' || $token === '{') {
                    $depth++;
                } elseif ($token === ')' || $token === ']' || $token === '}') {
                    $depth--;
                } elseif ($token === ',' && $depth === 0) {
                    $expectName = true;
                } elseif ($token === '=') {
                    $expectName = false;
                }
                continue;
            }
            if ($expectName && $token[0] === T_STRING) {
                $names[]    = $token[1];
                $expectName = false;
            }
        }
        return $names;
    }

    #endregion
    #region Fixing

    /**
     * Rewrites a file to the house style: backslashes removed from built-in
     * uses, a `use function` line naming exactly the built-in functions the
     * file calls, and a `use const` line directly below it for the constants.
     *
     * Returns the names now imported (constants prefixed "const "), or an
     * empty list when nothing changed. Files whose imports use the grouped
     * `use function Foo\{a, b};` form are left alone, because rewriting those
     * blind is not worth the risk; they are still reported by the scan.
     *
     * @return list<string>
     */
    public static function fixFile(string $path): array
    {
        $source  = (string)file_get_contents($path);
        $patched = self::fixSource($source);
        if ($patched === null || $patched === $source) {
            return [];
        }
        file_put_contents($path, $patched);

        [$functions, $constants] = self::wantedImports(token_get_all($patched));

        return array_merge($functions, array_map(static fn(string $c): string => "const $c", $constants));
    }

    /** Returns the corrected source, or null when there is no safe way to edit it. */
    public static function fixSource(string $source): ?string
    {
        $tokens = token_get_all($source);
        if (!self::isNamespaced($tokens)) {
            return null;
        }

        [$functions, $constants] = self::wantedImports($tokens);

        return self::setImports(self::stripBackslashes($tokens), $functions, $constants);
    }

    /**
     * The function and constant names the file's import lines should list:
     * every built-in it uses, minus the names it declares itself, sorted.
     *
     * @return array{list<string>, list<string>}
     */
    private static function wantedImports(array $tokens): array
    {
        $declared  = self::declaredNames($tokens);
        $functions = array_keys(array_diff_key(self::calledBuiltins($tokens, 'function'), $declared['function']));
        $constants = array_keys(array_diff_key(self::calledBuiltins($tokens, 'constant'), $declared['constant']));
        sort($functions);
        sort($constants);

        return [$functions, $constants];
    }

    /**
     * Rebuilds the source with `\builtin(` written as `builtin(` and
     * `\BUILT_IN` as `BUILT_IN`.
     *
     * Rebuilding from tokens rather than a regex means a backslashed name inside
     * a string or a comment is never touched, because it never arrives as a
     * name token in the first place.
     */
    private static function stripBackslashes(array $tokens): string
    {
        $declared = self::declaredNames($tokens);
        $out      = '';
        foreach ($tokens as $i => $token) {
            if (!is_array($token)) {
                $out .= $token;
                continue;
            }
            $strip = false;
            if (($name = self::calledName($tokens, $i)) !== null) {
                $strip = $name[1] && self::isBuiltin($name[0]) && !isset($declared['function'][$name[0]]);
            } elseif (($name = self::constantName($tokens, $i)) !== null) {
                $strip = $name[1] && self::isBuiltinConstant($name[0]) && !isset($declared['constant'][$name[0]]);
            }
            $out .= $strip ? ltrim($token[1], '\\') : $token[1];
        }
        return $out;
    }

    /**
     * Replaces the file's built-in imports with exactly $functions and
     * $constants, or returns it unchanged when the form is unsupported.
     *
     * The `use function` line comes first and the `use const` line sits
     * directly below it, no blank line between: one block, one rule.
     */
    private static function setImports(string $source, array $functions, array $constants): string
    {
        if ($functions === [] && $constants === []) {
            return self::removeImports($source);
        }

        $block = static fn(string $indent): string =>
            ($functions === [] ? '' : $indent . 'use function ' . implode(', ', $functions) . ";\n")
          . ($constants === [] ? '' : $indent . 'use const ' . implode(', ', $constants) . ";\n");
        $lines = ($functions === [] ? 0 : 1) + ($constants === [] ? 0 : 1);

        // Replace the first built-in `use function a, b;` or `use const A, B;`
        // statement, keeping its indentation, and drop any others. Somebody's
        // userland import is not ours to replace.
        if (preg_match_all('/^([ \t]*)use\s+(function|const)\s+([^;{]+);[ \t]*\R/mi', $source, $all, PREG_OFFSET_CAPTURE | PREG_SET_ORDER)) {
            foreach ($all as $m) {
                if (!self::importsOnlyBuiltins(strtolower((string)$m[2][0]), (string)$m[3][0])) {
                    continue;
                }
                $source = substr_replace($source, $block((string)$m[1][0]), (int)$m[0][1], strlen((string)$m[0][0]));
                return self::removeImports($source, $lines);
            }
        }

        // No import lines yet. Go after the last existing `use`, because PSR-12
        // puts class imports before function ones, and only fall back to the
        // namespace declaration when the file imports nothing at all.
        [$at, $indent] = self::afterLastUse($source) ?? self::afterNamespace($source) ?? [null, ''];

        return $at === null
            ? $source   // brace-style namespace and no imports: too varied to edit blind
            : substr_replace($source, "\n" . $block($indent), $at, 0);
    }

    /**
     * Byte offset just past the last top-level `use ...;` statement and that
     * line's indentation, or null when there is none. Top-level means column 0:
     * an indented `use` is a trait inside a class body, and the import line must
     * not end up in there.
     *
     * @return array{int, string}|null
     */
    private static function afterLastUse(string $source): ?array
    {
        if (!preg_match_all('/^use\s+[^;{]+;[ \t]*\R/m', $source, $all, PREG_OFFSET_CAPTURE)) {
            return null;
        }
        $last = end($all[0]);

        return [(int)$last[1] + strlen((string)$last[0]), ''];
    }

    /**
     * Byte offset just past the namespace declaration and that line's
     * indentation, or null for the brace form. The line may be indented; the
     * inserted import line then matches it.
     *
     * @return array{int, string}|null
     */
    private static function afterNamespace(string $source): ?array
    {
        if (!preg_match('/^([ \t]*)namespace\s+[^;{]+;[ \t]*\R/m', $source, $m, PREG_OFFSET_CAPTURE)) {
            return null;
        }
        return [(int)$m[0][1] + strlen((string)$m[0][0]), (string)$m[1][0]];
    }

    /** Removes built-in `use function` and `use const` statements, optionally keeping the first few (the freshly written block). */
    private static function removeImports(string $source, int $keep = 0): string
    {
        $kept = 0;

        return (string)preg_replace_callback(
            '/^[ \t]*use\s+(function|const)\s+([^;{]+);[ \t]*\R/mi',
            static function (array $m) use (&$kept, $keep): string {
                // Keep statements that import somebody's userland names.
                if (!self::importsOnlyBuiltins(strtolower($m[1]), $m[2])) {
                    return $m[0];
                }
                if ($kept < $keep) {
                    $kept++;
                    return $m[0];
                }
                return '';
            },
            $source,
        );
    }

    /** True when every name in an import statement's list is a built-in, making the statement this tool's to rewrite. */
    private static function importsOnlyBuiltins(string $keyword, string $names): bool
    {
        $kind = $keyword === 'function' ? 'function' : 'constant';
        foreach (explode(',', $names) as $name) {
            $name = ltrim(trim($name), '\\');
            if ($kind === 'function') {
                $name = strtolower($name);
            }
            if (str_contains($name, '\\') || !self::isBuiltinName($kind, $name)) {
                return false;
            }
        }
        return true;
    }

    #endregion
    #region Files

    /** @return list<string> */
    private static function phpFiles(string $path): array
    {
        if (is_file($path)) {
            return [$path];
        }

        $files    = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
        );
        foreach ($iterator as $file) {
            if ($file->isFile() && strtolower($file->getExtension()) === 'php') {
                $files[] = $file->getPathname();
            }
        }
        return $files;
    }

    #endregion
}

// Command line entry point. Skipped when this file is included by a test.
if (isset($argv[0]) && realpath($argv[0]) === __FILE__) {
    $paths = array_slice($argv, 1);
    $fix   = in_array('--fix', $paths, true);
    $paths = array_values(array_filter($paths, static fn(string $a): bool => $a !== '--fix'));

    if ($paths === []) {
        fwrite(STDERR, "Usage: php " . basename(__FILE__) . " [--fix] <path> [path...]\n");
        exit(1);
    }

    $files   = 0;
    $skipped = 0;
    foreach ($paths as $path) {
        foreach (NamespacedCallsCheck::scanPath($path) as $file => $findings) {
            $files++;
            if ($fix) {
                $imported = NamespacedCallsCheck::fixFile($file);
                if (NamespacedCallsCheck::scanFile($file) !== []) {
                    $skipped++;
                    printf("%s\n  skipped: no place to put the import line, add it by hand\n", $file);
                    continue;
                }
                printf("%s\n  imports: %s\n", $file, $imported ? implode(', ', $imported) : '(none needed)');
                continue;
            }
            echo "$file\n";
            foreach (['missing', 'qualified', 'unused'] as $type) {
                $names = array_map(
                    static fn(array $f): string => $f['kind'] === 'constant' ? "const $f[name]" : $f['name'],
                    array_filter($findings, static fn(array $f): bool => $f['type'] === $type),
                );
                if ($names !== []) {
                    sort($names);
                    printf("  %-10s %s\n", $type, implode(', ', $names));
                }
            }
        }
    }

    if ($files === 0) {
        echo "All namespaced files import exactly the built-ins they call.\n";
        exit(0);
    }
    printf("\n%d file(s).%s\n", $files, $fix ? '' : ' Run again with --fix to rewrite the imports.');
    exit($fix && $skipped === 0 ? 0 : 1);
}
