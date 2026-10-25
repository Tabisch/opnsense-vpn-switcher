#!/bin/sh
# Copy the plugin sources straight onto an OPNsense firewall for testing, without building a package.
# Usage: scripts/install-dev.sh root@192.168.1.1
set -e

TARGET=${1:?usage: $0 root@firewall}
SRC=$(cd "$(dirname "$0")/../net/gwswitch/src" && pwd)

tar -C "$SRC" -cf - . | ssh "$TARGET" '
    tar -C /usr/local -xf - &&
    chmod +x /usr/local/etc/rc.d/gwswitch /usr/local/opnsense/scripts/gwswitch/*.py &&
    rm -f /tmp/opnsense_menu_cache.xml /tmp/opnsense_acl_cache.json &&
    service configd restart &&
    echo "installed, open Services > Gateway switcher"
'
