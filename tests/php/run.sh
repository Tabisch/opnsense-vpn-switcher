#!/bin/sh
# Run the PHP checks against the OPNsense core MVC framework.
# Needs php-cli with SimpleXML and DOM. Usage: tests/php/run.sh [core git ref]
set -e

HERE=$(cd "$(dirname "$0")" && pwd)
REPO=$(cd "$HERE/../.." && pwd)
REF=${1:-master}
CACHE=${GWSW_CACHE:-$REPO/.cache}
CORE=$CACHE/core-$REF

if [ ! -d "$CORE/src/opnsense/mvc/app" ]; then
    rm -rf "$CORE"
    git clone --quiet --depth 1 --branch "$REF" --filter=blob:none --sparse https://github.com/opnsense/core "$CORE"
    git -C "$CORE" sparse-checkout set src/opnsense/mvc
fi

WORK=$(mktemp -d)
trap 'rm -rf "$WORK"' EXIT
mkdir -p "$WORK/conf" "$WORK/tmp"
cp -R "$CORE/src/opnsense/mvc/app" "$WORK/app"
cp -R "$REPO/net/gwswitch/src/opnsense/mvc/app/." "$WORK/app/"
cp "$HERE/fixture-config.xml" "$WORK/conf/config.xml"

GWSW_WORK=$WORK php "$HERE/ManagerTest.php"
