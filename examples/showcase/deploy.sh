#!/bin/sh
# The showcase on a shared host, from this repository's clone there:
#
#   git pull && sh examples/showcase/deploy.sh <target> [--host=<name>] [--admin=<addresses>|--admin=none]
#
#   sh examples/showcase/deploy.sh /var/www/vhosts/example.org/example.org/showcase \
#      --host=example.org --admin=2001:db8:1234:5600::/64,203.0.113.7   (the first time)
#   sh examples/showcase/deploy.sh /var/www/vhosts/example.org/example.org/showcase   (later: as before)
#
# --admin: your addresses, comma-separated -- for IPv6 the /64 of your line
# (the last half of an IPv6 address changes now and then), for IPv4 the
# address; --admin=none forgets them.
#
# Builds the standalone copy (build/showcase.php) next to <target>, takes the
# running copy's var/ over (the secret, the statistics, the cache, the log),
# then swaps the folders -- <target> is the domain's document root and stays
# a real folder (open_basedir names it; a link would point PHP elsewhere).
# --host and --admin are kept in <target>/var/deploy.conf (never served):
# given once, they hold for the next run. The copy before is kept as
# <target>.previous; to go back:
#
#   mv <target> <target>.bad && mv <target>.previous <target>
#
# (what var/ got since the deploy stays in <target>.bad). <target> and
# <target>.previous must be missing, empty or copies this script made -- it
# never replaces or removes another folder, nor a link. PHP: $PHP, else php
# -- 8.0 or newer (Plesk: PHP=/opt/plesk/php/8.3/bin/php sh …).
set -eu

fail() {
    echo "deploy.sh: $*" >&2
    exit 1
}
# A copy this script made: its build's marker and its remembered settings.
made_here() {
    [ -f "$1/lib/public.php" ] && [ -f "$1/var/deploy.conf" ]
}

repo=$(CDPATH= cd -P -- "$(dirname -- "$0")/../.." && pwd)
php=${PHP:-php}
target=""
host=""
admin=""
admin_given=no
for arg in "$@"; do
    case "$arg" in
        --host=*) host=${arg#--host=} ;;
        --admin=*) admin=${arg#--admin=}; admin_given=yes ;;
        -*) fail "unknown option $arg" ;;
        *) [ -z "$target" ] || fail "one target folder, not \"$target\" and \"$arg\""
           target=$arg ;;
    esac
done
[ -n "$target" ] || fail "usage: sh examples/showcase/deploy.sh <target folder> [--host=<name>] [--admin=<addresses>|--admin=none]"
while [ "${target%/}" != "$target" ]; do        # every trailing slash: site// is site
    target=${target%/}
done
[ -n "$target" ] || fail "the target is a folder below /, not / itself"
case "$(basename -- "$target")" in
    .|..) fail "name the folder itself, not \"$target\"" ;;
esac
parent=$(dirname -- "$target")
[ -d "$parent" ] || fail "$parent does not exist"
parent=$(CDPATH= cd -P -- "$parent" && pwd)
[ "$parent" != / ] || parent=""
target=$parent/$(basename -- "$target")
old="$target.previous"

[ ! -L "$target" ] || fail "$target is a link -- make it a real folder (open_basedir names the folder, PHP would follow the link elsewhere)"
[ ! -L "$old" ] || fail "$old is a link -- not this script's"
# Never another folder: missing, empty, or a copy made here -- and never this clone or a folder holding it.
case "$repo/" in
    "$target/"*) fail "$target holds this clone itself -- name the document root folder, not the clone's" ;;
    "$old/"*) fail "$old holds this clone itself -- move the clone elsewhere first" ;;
esac
for dir in "$target" "$old"; do
    if [ -e "$dir" ]; then
        [ -d "$dir" ] || fail "$dir is no folder"
        [ -r "$dir" ] || fail "$dir cannot be read here"
        if [ -n "$(ls -A -- "$dir")" ] && ! made_here "$dir"; then
            if [ -f "$dir/lib/public.php" ]; then
                # A copy build/showcase.php made by hand: its var/ (the secret, statistics) is worth keeping.
                fail "$dir is a copy built by hand -- once: touch \"$dir/var/deploy.conf\" and run again with --host and --admin (its var/ is kept)"
            fi
            fail "$dir holds something else than a copy this script made -- empty it (the hoster's default page), or name another folder"
        fi
    fi
done
"$php" -r 'exit(PHP_VERSION_ID >= 80000 ? 0 : 1);' 2>/dev/null \
    || fail "$php is not PHP 8.0 or newer -- name one: PHP=/opt/plesk/php/8.3/bin/php sh $0 …"

# What the last run was told, unless told again now (--admin=none: nobody).
conf="$target/var/deploy.conf"
if [ -f "$conf" ]; then
    [ -n "$host" ] || host=$(sed -n 's/^host=//p' "$conf")
    [ "$admin_given" = yes ] || admin=$(sed -n 's/^admin=//p' "$conf")
fi
[ "$admin" != none ] || admin=""
[ -n "$host" ] || fail "name the address visitors use once: --host=example.org"

new="$target.new-$$"
gone="$old.gone-$$"
for dir in "$new" "$gone"; do
    [ ! -e "$dir" ] || fail "$dir is there already -- not this run's; remove it first"
done
trap 'rm -rf "$new"' EXIT              # a run that stops leaves no half-made copy (it holds the secret)
trap 'exit 1' HUP INT TERM             # dash runs the EXIT trap on exit only
set -- "--out=$new" "--host=$host"
[ -z "$admin" ] || set -- "$@" "--admin=$admin"
"$php" "$repo/build/showcase.php" "$@"

# The running copy's var/: the secret (passes stay valid), statistics, the cache, the log --
# with this build's .htaccess (the old one may be older).
if [ -d "$target/var" ]; then
    cp "$new/var/.htaccess" "$new/var.htaccess"
    rm -rf "$new/var"
    cp -pR "$target/var" "$new/var"
    mv "$new/var.htaccess" "$new/var/.htaccess"
fi
printf 'host=%s\nadmin=%s\n' "$host" "$admin" > "$new/var/deploy.conf"
chmod 600 "$new/var/deploy.conf"
# The folder as the web server could read the one before (a Plesk document root: user:psaserv 750).
# Its mode only with its group: 750 under a group the web server is not in would lock it out.
if [ -d "$target" ]; then
    group=$(stat -c %g "$target" 2>/dev/null || echo "")
    mode=$(stat -c %a "$target" 2>/dev/null || echo "")
    if [ -n "$group" ] && { [ "$(stat -c %g "$new")" = "$group" ] || chgrp "$group" "$new" 2>/dev/null; }; then
        [ -z "$mode" ] || chmod "$mode" "$new"
    else
        chmod 755 "$new"
        echo "deploy.sh: could not give the new folder the group of the one before ($group) -- it is 755; check that the site answers" >&2
    fi
fi

# The swap: renames in the same parent folder, a moment without a page at most; the copy before stays.
# No signal in between: it would leave no <target>. The older .previous goes only once the new copy is in.
trap '' HUP INT TERM
[ ! -e "$old" ] || mv -- "$old" "$gone"       # empty, or made here (checked above)
if [ -d "$target" ] && ! mv -- "$target" "$old"; then
    [ ! -e "$gone" ] || mv -- "$gone" "$old" || true
    fail "could not move $target aside -- nothing changed"
fi
if ! mv -- "$new" "$target"; then
    [ ! -d "$old" ] || mv -- "$old" "$target" || true
    [ ! -e "$gone" ] || mv -- "$gone" "$old" || true
    fail "could not put the new copy in place -- $target is as before"
fi
rm -rf -- "$gone"
trap - EXIT HUP INT TERM
[ -n "$admin" ] || echo "deploy.sh: no --admin: the shield's own pages, the log and learning are closed for everyone" >&2
echo "deploy.sh: $target is the new showcase (host $host${admin:+, admin $admin}) -- check that https://$host/var/secret is not served"
