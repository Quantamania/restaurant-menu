#!/usr/bin/env bash
# Loads schema.sql and seed.sql into a hosted MySQL, then checks the result.
#
#   ./tools/load-remote-db.sh
#
# It asks for the password rather than taking it as an argument, so the
# password never lands in your shell history or in `ps`.

set -u
cd "$(dirname "$0")/.." || exit 1

read -rp "Host        (e.g. mysql-x.aivencloud.com) : " HOST
read -rp "Port        (e.g. 24067)                 : " PORT
read -rp "User        (e.g. avnadmin)              : " USER
read -rp "Database    (e.g. defaultdb)             : " DB
read -rsp "Password                                 : " PASS
echo

trim () { printf '%s' "$1" | tr -d '[:space:]'; }
HOST=$(trim "$HOST"); PORT=$(trim "$PORT"); USER=$(trim "$USER"); DB=$(trim "$DB")

for v in HOST PORT USER DB PASS; do
    if [ -z "${!v}" ]; then echo "  $v is required."; exit 1; fi
done

echo
echo "Using:  $USER@$HOST:$PORT/$DB"

run () { mariadb -h "$HOST" -P "$PORT" -u "$USER" -p"$PASS" --ssl "$DB" "$@"; }

echo
echo "Connecting…"
if ! err=$(run -e "SELECT 1" 2>&1 >/dev/null); then
    echo "  FAILED. MySQL said:"
    echo
    echo "    $err"
    echo
    case "$err" in
      *caching_sha2*|*sha256*|*plugin*)
        echo "  That is the MySQL 8.4 auth plugin. Run the PHP importer instead:"
        echo "    php tools/load-remote-db.php" ;;
      *"Unknown MySQL server host"*|*"Name or service not known"*)
        echo "  The hostname did not resolve — check it for typos or stray spaces." ;;
      *"Access denied"*)
        echo "  Host and port are fine; the user or password is wrong." ;;
      *timed\ out*|*"Can't connect"*)
        echo "  Reached nothing on that port. Check the port, or your network." ;;
    esac
    exit 1
fi
echo "  connected."

echo "Loading schema.sql…"
run < database/schema.sql || { echo "  schema failed"; exit 1; }

echo "Loading seed.sql…"
# Only safe on an empty database: seed.sql stops at the first duplicate row
# and silently skips everything after it, including the dish photos.
if [ "$(run -N -e 'SELECT COUNT(*) FROM menu_items')" = "0" ]; then
    run < database/seed.sql || { echo "  seed failed"; exit 1; }
else
    echo "  skipped — menu_items already has rows."
fi

echo
echo "Checking:"
printf '  tables      %s (expected 13)\n' "$(run -N -e 'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE()')"
printf '  dishes      %s (expected 12)\n' "$(run -N -e 'SELECT COUNT(*) FROM menu_items')"
printf '  with photos %s (expected 12)\n' "$(run -N -e 'SELECT COUNT(*) FROM menu_items WHERE image IS NOT NULL')"
printf '  room tables %s (expected 19)\n' "$(run -N -e 'SELECT COUNT(*) FROM restaurant_tables')"
printf '  sessions    %s (must be 1 — the site breaks without it)\n' \
       "$(run -N -e "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'sessions'")"

echo
echo "Now set these in Vercel (Settings → Environment Variables), tick"
echo "Production, Preview and Development, then redeploy:"
echo
echo "  DB_HOST=$HOST"
echo "  DB_PORT=$PORT"
echo "  DB_NAME=$DB"
echo "  DB_USER=$USER"
echo "  DB_PASS=(the password you just typed)"
echo "  DB_SSL=require"
