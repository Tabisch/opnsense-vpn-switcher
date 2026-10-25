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

    Reload the gateway switcher aliases after a change and move the given clients over at once:
    their current addresses are added to the alias of their new profile and their states are dropped,
    so open connections reconnect through the new gateway.
"""
import argparse
import json
import syslog
from lib import read_config, is_alias, is_mac, list_hosts, pf_table, pf_table_add, kill_states, update_tables


if __name__ == '__main__':
    parser = argparse.ArgumentParser()
    parser.add_argument('--aliases', help='comma separated aliases to reload, - for none', default='-')
    parser.add_argument('--macs', help='comma separated MAC addresses of switched clients, - for none', default='-')
    inputargs = parser.parse_args()

    aliases = [x for x in inputargs.aliases.split(',') if is_alias(x)]
    macs = [x.lower() for x in inputargs.macs.split(',') if is_mac(x.lower())]
    config = read_config()
    result = {'aliases': aliases, 'addresses': [], 'killed_states': 0}

    update_tables(aliases)

    if macs:
        hosts = list_hosts()
        tables = {}
        for mac in macs:
            host = hosts.get(mac)
            if host is None:
                continue
            addresses = host['ipv4'] | host['ipv6']
            result['addresses'].extend(sorted(addresses))
            alias = config['clients'].get(mac)
            if alias:
                if alias not in tables:
                    tables[alias] = pf_table(alias)
                pf_table_add(alias, addresses - tables[alias])
            if config['kill_states']:
                for address in addresses:
                    result['killed_states'] += kill_states(address)
            syslog.syslog(syslog.LOG_NOTICE, 'gwswitch: %s moved to %s (%s)' % (
                mac, alias or 'default gateway', ', '.join(sorted(addresses)) or 'no known addresses'
            ))

    print(json.dumps(result))
