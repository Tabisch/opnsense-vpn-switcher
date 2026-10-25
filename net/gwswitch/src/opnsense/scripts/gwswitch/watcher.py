#!/usr/local/bin/python3

"""
    Copyright (C) 2026 Tabisch
    All rights reserved.

    Redistribution and use in source and binary forms, with or without
    modification, are permitted provided that the following conditions are met:

    1. Redistributions of source code must retain the above copyright notice,
       this list of conditions and the following disclaimer.

    2. Redistributions in binary form must reproduce the above copyright
       notice, this list of conditions and the following disclaimer in the
       documentation and/or other materials provided with the distribution.

    THIS SOFTWARE IS PROVIDED ``AS IS'' AND ANY EXPRESS OR IMPLIED WARRANTIES,
    INCLUDING, BUT NOT LIMITED TO, THE IMPLIED WARRANTIES OF MERCHANTABILITY
    AND FITNESS FOR A PARTICULAR PURPOSE ARE DISCLAIMED. IN NO EVENT SHALL THE
    AUTHOR BE LIABLE FOR ANY DIRECT, INDIRECT, INCIDENTAL, SPECIAL, EXEMPLARY,
    OR CONSEQUENTIAL DAMAGES (INCLUDING, BUT NOT LIMITED TO, PROCUREMENT OF
    SUBSTITUTE GOODS OR SERVICES; LOSS OF USE, DATA, OR PROFITS; OR BUSINESS
    INTERRUPTION) HOWEVER CAUSED AND ON ANY THEORY OF LIABILITY, WHETHER IN
    CONTRACT, STRICT LIABILITY, OR TORT (INCLUDING NEGLIGENCE OR OTHERWISE)
    ARISING IN ANY WAY OUT OF THE USE OF THIS SOFTWARE, EVEN IF ADVISED OF THE
    POSSIBILITY OF SUCH DAMAGE.

    Address watcher: clients get new addresses all the time (IPv6 privacy addresses, DHCP changes).
    The MAC aliases only pick them up on their next refresh, so this loop adds new addresses of
    switched clients to their alias right away and drops states created before that, which went
    out through the default gateway.
"""
import os
import syslog
import time
from lib import CONFIG_FILE, read_config, list_hosts, pf_table, pf_table_add, kill_states


class Watcher:
    def __init__(self, config_file=CONFIG_FILE):
        self.config_file = config_file
        self.config_mtime = None
        self.config = read_config(config_file)

    def reload(self):
        try:
            mtime = os.stat(self.config_file).st_mtime
        except OSError:
            mtime = None
        if mtime != self.config_mtime:
            self.config_mtime = mtime
            self.config = read_config(self.config_file)

    def tick(self, hosts=None, table_reader=pf_table, table_adder=pf_table_add, state_killer=kill_states):
        """ one pass, returns {alias: [new addresses]} """
        self.reload()
        added = {}
        clients = self.config['clients']
        if not clients:
            return added
        hosts = list_hosts() if hosts is None else hosts
        tables = {}
        for mac, alias in clients.items():
            host = hosts.get(mac)
            if host is None:
                continue
            if alias not in tables:
                tables[alias] = table_reader(alias)
            new = (host['ipv4'] | host['ipv6']) - tables[alias]
            if not new:
                continue
            table_adder(alias, new)
            tables[alias] |= new
            added.setdefault(alias, []).extend(sorted(new))
            killed = 0
            if self.config['kill_states']:
                for address in new:
                    killed += state_killer(address)
            syslog.syslog(syslog.LOG_NOTICE, 'gwswitch: added %s of %s to %s, dropped %d states' % (
                ', '.join(sorted(new)), mac, alias, killed
            ))
        return added


if __name__ == '__main__':
    syslog.openlog('gwswitch', facility=syslog.LOG_LOCAL4)
    watcher = Watcher()
    syslog.syslog(syslog.LOG_NOTICE, 'gwswitch: watcher started')
    while True:
        try:
            watcher.tick()
        except Exception as e:
            syslog.syslog(syslog.LOG_ERR, 'gwswitch: watcher error %s' % e)
        time.sleep(watcher.config['interval'])
