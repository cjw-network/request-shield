#!/bin/sh
# The showcase on a shared host, from this repository's clone there:
#
#   git pull && sh examples/showcase/deploy.sh <target> [--host=<name>] [--admin=<address>,...]
#
#   sh examples/showcase/deploy.sh /var/www/vhosts/example.org/example.org/showcase \
#      --host=example.org --admin=2001:db8:1234:5600::/64,203.0.113.7   (the first time)
#
# --admin: your addresses, comma-separated -- for IPv6 the /64 of your line
# (the last half of an IPv6 address changes now and then), for IPv4 the address.
#   sh examples/showcase/deploy.sh /var/www/vhosts/example.org/example.org/showcase   (later: as before)
#
# Builds the standalone copy (build/showcase.php) next to <target>, takes the
# running copy's var/ over (the secret, the statistics, the cache, the log),
# then swaps the folders -- <target> is the domain's document root and stays
# a real folder (open_basedir names it; a link would point PHP elsewhere).
# --host and --admin are kept in <target>/var/deploy.conf (never served):
# given once, they hold for the next run. The copy before is kept as
# <target>.previous (mv it back to undo). <target> must be empty, missing
# or a copy this script made -- it never replaces another folder. PHP:
# $PHP, else php -- 8.0 or newer (Plesk: PHP=/opt/plesk/php/8.3/bin/php sh …).
set -eu

repo=$(cd "$(dirname "$0")/../.." && pwd)
php=${PHP:-php}
target=""
host=""
admin=""
for arg in "$@"; do
    case "$arg" in
        --host=*) host=${arg#--host=} ;;
        --admin=*) admin=${arg#--admin=} ;;
        -*) echo "deploy.sh: unknown option $arg" >&2; exit 1 ;;
        *) target=${arg%/} ;;
    esac
done
if [ -z "$target" ]; then
    echo "usage: sh examples/showcase/deploy.sh <target folder> [--host=<name>] [--admin=<address>,...]" >&2
    exit 1
fi
if [ -L "$target" ]; then
    echo "deploy.sh: $target is a link -- make it a real folder (open_basedir names the folder, PHP would follow the link elsewhere)" >&2
    exit 1
fi
# Never another folder: missing, empty, or a copy made here (lib/public.php) -- and never this clone.
if [ -d "$target" ] && [ -n "$(ls -A "$target")" ] && [ ! -f "$target/lib/public.php" ]; then
    echo "deploy.sh: $target holds something else than a showcase copy -- empty it first (the hoster's default page), or name another folder" >&2
    exit 1
fi
case "$repo/" in
    "$(cd "$(dirname "$target")" && pwd)/$(basename "$target")/"*)
        echo "deploy.sh: $target holds this clone itself -- name the document root folder, not the clone's" >&2
        exit 1 ;;
esac
if ! "$php" -r 'exit(PHP_VERSION_ID >= 80000 ? 0 : 1);' 2>/dev/null; then
    echo "deploy.sh: $php is not PHP 8.0 or newer -- name one: PHP=/opt/plesk/php/8.3/bin/php sh $0 …" >&2
    exit 1
fi

# What the last run was told, unless told again now.
conf="$target/var/deploy.conf"
if [ -f "$conf" ]; then
    [ -n "$host" ] || host=$(sed -n 's/^host=//p' "$conf")
    [ -n "$admin" ] || admin=$(sed -n 's/^admin=//p' "$conf")
fi
if [ -z "$host" ]; then
    echo "deploy.sh: name the address visitors use once: --host=example.org" >&2
    exit 1
fi
[ -n "$admin" ] || echo "deploy.sh: no --admin: the shield's own pages, the log and learning stay closed for everyone" >&2

new="$target.new-$$"
old="$target.previous"
trap 'rm -rf "$new"' EXIT          # a run that stops leaves no half-made copy
set -- "--out=$new" "--host=$host"
[ -z "$admin" ] || set -- "$@" "--admin=$admin"
"$php" "$repo/build/showcase.php" "$@"

# The running copy's var/: the secret (passes stay valid), statistics, the cache, the log.
if [ -d "$target/var" ]; then
    rm -rf "$new/var"
    cp -pR "$target/var" "$new/var"
fi
printf 'host=%s\nadmin=%s\n' "$host" "$admin" > "$new/var/deploy.conf"
chmod 600 "$new/var/deploy.conf"

# The swap: renames in the same parent folder, a moment without a page at most; the copy before stays.
rm -rf "$old"
if [ -d "$target" ]; then
    mv "$target" "$old"
fi
mv "$new" "$target"
trap - EXIT
echo "deploy.sh: $target is the new showcase (host $host${admin:+, admin $admin}) -- check that https://$host/var/secret is not served"
