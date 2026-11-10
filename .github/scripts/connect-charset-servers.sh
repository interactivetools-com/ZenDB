#!/usr/bin/env bash
#
# Start one database container per server variant for connect-charset-probe.php, and
# write a JSON list of them. GitHub service containers can't take mysqld flags, so
# each variant is a plain "docker run" of the same image on its own port. That
# includes the server with default settings ("default", port 3306), so the workflow
# needs no service container.
#
#     .github/scripts/connect-charset-servers.sh <image> <variants.json>
#
# A variant that refuses a flag or never accepts connections is recorded in the JSON
# with its status and the last server log lines; the script itself only fails when
# Docker does.
#
set -u

IMAGE="$1"
OUT="$2"
WAIT_SECONDS=300
NEXT_PORT=3306
NAMES=()
declare -A PORT_OF FLAGS_OF

# start <name> [mysqld flag ...]
start() {
    local name="$1"; shift
    local port=$NEXT_PORT
    NEXT_PORT=$((NEXT_PORT + 1))
    NAMES+=("$name")
    PORT_OF[$name]=$port
    FLAGS_OF[$name]="$*"
    docker run -d --name "zdb-$name" -p "$port:3306" \
        -e MYSQL_ALLOW_EMPTY_PASSWORD=yes -e MYSQL_ROOT_HOST=% \
        -e MARIADB_ALLOW_EMPTY_ROOT_PASSWORD=yes -e MARIADB_ROOT_HOST=% \
        --security-opt seccomp=unconfined \
        "$IMAGE" "$@" > /dev/null
}

# startWithInitConnect <name> <statement>
# The mariadb:10.2 and mariadb:10.3 entrypoints pass their arguments on unquoted, so a
# flag with spaces in it arrives as several arguments and mysqld stops with "Too many
# arguments". Those two images get init_connect from a mounted config file instead
startWithInitConnect() {
    local name="$1" statement="$2"
    case "$IMAGE" in
        mariadb:10.2|mariadb:10.3)
            local port=$NEXT_PORT
            local cnf
            cnf="$(dirname "$OUT")/zdb-$name.cnf"
            NEXT_PORT=$((NEXT_PORT + 1))
            NAMES+=("$name")
            PORT_OF[$name]=$port
            FLAGS_OF[$name]="(config file, not a flag) init_connect='$statement'"
            printf "[mysqld]\ninit_connect='%s'\n" "$statement" > "$cnf"
            chmod 644 "$cnf"
            docker run -d --name "zdb-$name" -p "$port:3306" \
                -e MYSQL_ALLOW_EMPTY_PASSWORD=yes -e MYSQL_ROOT_HOST=% \
                -e MARIADB_ALLOW_EMPTY_ROOT_PASSWORD=yes -e MARIADB_ROOT_HOST=% \
                --security-opt seccomp=unconfined \
                -v "$cnf:/etc/mysql/conf.d/zz-probe.cnf:ro" \
                "$IMAGE" > /dev/null
            ;;
        *)
            start "$name" "--init-connect=$statement"
            ;;
    esac
}

# MySQL 9 rejects the charset name "utf8"; MariaDB before 10.6 doesn't know "utf8mb3"
case "$IMAGE" in
    mysql:9*) UTF8MB3=utf8mb3 ;;
    *)        UTF8MB3=utf8 ;;
esac

# The MariaDB images set collation-server in a config file, and the server won't start
# when that collation belongs to another charset, so MariaDB gets the collation too.
# MySQL and Percona pick the charset's default collation themselves
collation() {
    case "$IMAGE" in
        mariadb:*) echo "--collation-server=$1" ;;
    esac
}

# Default settings, on the usual port; the variants below start at 3310
start default
NEXT_PORT=3310

# Server default charset: latin1, utf8mb3 and utf8mb4
start cs-latin1          --character-set-server=latin1 $(collation latin1_swedish_ci)
start cs-utf8mb3         --character-set-server=$UTF8MB3 $(collation utf8_general_ci)
start cs-utf8mb4         --character-set-server=utf8mb4

# Servers told to ignore the charset a client asks for at login
start skip-latin1        --skip-character-set-client-handshake --character-set-server=latin1 $(collation latin1_swedish_ci)
start skip-utf8mb3       --skip-character-set-client-handshake --character-set-server=$UTF8MB3 $(collation utf8_general_ci)
start skip-gbk           --skip-character-set-client-handshake --character-set-server=gbk $(collation gbk_chinese_ci)
start handshake0-latin1  --character-set-client-handshake=0 --character-set-server=latin1 $(collation latin1_swedish_ci)
start skip-ucs2          --skip-character-set-client-handshake --character-set-server=ucs2 $(collation ucs2_general_ci)
start skip-coll-unicode  --skip-character-set-client-handshake --character-set-server=utf8mb4 --collation-server=utf8mb4_unicode_ci

# Servers that run a statement after every login (skipped for privileged users)
startWithInitConnect initconnect-latin1 "SET NAMES latin1"
startWithInitConnect initconnect-gbk    "SET NAMES gbk"

# utf8mb4 server with a collation that isn't the charset's default
start coll-unicode       --character-set-server=utf8mb4 --collation-server=utf8mb4_unicode_ci

# utf8mb4 server whose collation number is over 255 (utf8mb4_pl_0900_as_cs is 284). The
# server's greeting has one byte for it, so this shows what a client that sets no
# charset starts on. MySQL and Percona 8.0+ only; nothing older has the collation
case "$IMAGE" in
    mysql:[89]*|percona/percona-server:[89]*)
        start coll-over-255 --character-set-server=utf8mb4 --collation-server=utf8mb4_pl_0900_as_cs
        ;;
esac

# Default settings; the probe changes global variables on this one
start dyn

# Wait until PHP can log in to each variant, the container exits, or time runs out
tryConnect() {
    php -r '
        mysqli_report(MYSQLI_REPORT_OFF);
        $mysqli = mysqli_init();
        $mysqli->options(MYSQLI_SET_CHARSET_NAME, "utf8mb4");
        $mysqli->options(MYSQLI_OPT_CONNECT_TIMEOUT, 3);
        if (@$mysqli->real_connect("127.0.0.1", "root", "", null, (int)$argv[1])) {
            exit(0);
        }
        echo mysqli_connect_errno() . ": " . mysqli_connect_error();
        exit(1);
    ' "$1"
}

DEADLINE=$((SECONDS + WAIT_SECONDS))
JSON='[]'
for name in "${NAMES[@]}"; do
    port=${PORT_OF[$name]}
    status=timeout
    detail=''
    while [ $SECONDS -lt $DEADLINE ]; do
        if detail=$(tryConnect "$port"); then
            status=up
            detail=''
            break
        fi
        if [ "$(docker inspect -f '{{.State.Running}}' "zdb-$name" 2>/dev/null)" != "true" ]; then
            status=exited
            break
        fi
        sleep 2
    done
    if [ "$status" != up ]; then
        echo "::group::$name did not start ($status): server log"
        docker logs "zdb-$name" 2>&1 | tail -n 60
        echo "::endgroup::"
        # timestamps removed so the same refusal reads the same in every job
        logTail=$(docker logs "zdb-$name" 2>&1 | grep -E '\[ERROR\]|ERROR|unknown (variable|option)|Unknown' | tail -n 3 \
            | sed -E 's/[0-9]{4}-[0-9]{2}-[0-9]{2}[T ]+[0-9:.]+(Z|\+00:00)? ?//g' | cut -c1-300 | tr '\n' ' ')
        detail="last connect error: ${detail:-none}; server log: ${logTail:-none}"
    fi
    echo "$name (port $port): $status"
    JSON=$(jq -c --arg name "$name" --arg flags "${FLAGS_OF[$name]}" --argjson port "$port" --arg status "$status" --arg detail "$detail" \
        '. + [{name: $name, flags: $flags, port: $port, status: $status, detail: $detail}]' <<< "$JSON")
done

echo "$JSON" > "$OUT"
docker ps --format '{{.Names}}\t{{.Status}}' | sort
free -m | sed -n 2p
