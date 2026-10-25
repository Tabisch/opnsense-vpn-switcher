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

    Shared helpers for the gateway switcher backend scripts.
"""
import configparser
import ipaddress
import json
import re
import subprocess

CONFIG_FILE = '/usr/local/etc/gwswitch/gwswitch.conf'
LIST_HOSTS = '/usr/local/opnsense/scripts/interfaces/list_hosts.py'
KEA_LEASES = '/usr/local/opnsense/scripts/kea/get_kea_leases.py'
UPDATE_TABLES = '/usr/local/opnsense/scripts/filter/update_tables.py'
UPDATE_TABLES_LOCK = '/tmp/filter_update_tables.lock'
PFCTL = '/sbin/pfctl'
MAC_RE = re.compile(r'^([0-9a-f]{2}:){5}[0-9a-f]{2}$')
ALIAS_RE = re.compile(r'^gwsw_[a-z0-9_]{1,24}$')


def run(cmd):
    """ run a command, return (stdout, stderr), never raise on a non zero exit """
    sp = subprocess.run(cmd, capture_output=True, text=True)
    return sp.stdout, sp.stderr


def is_mac(value):
    return MAC_RE.match(value or '') is not None


def is_alias(value):
    return ALIAS_RE.match(value or '') is not None


def read_config(filename=CONFIG_FILE):
    """ parse the generated configuration
        :return: dict with general settings and clients (mac -> alias)
    """
    parser = configparser.ConfigParser(delimiters=('=',), interpolation=None)
    parser.optionxform = str
    result = {'enabled': False, 'interval': 5, 'kill_states': True, 'clients': {}}
    try:
        with open(filename) as handle:
            parser.read_file(handle)
    except (OSError, configparser.Error):
        return result
    if parser.has_section('general'):
        general = parser['general']
        result['enabled'] = general.get('enabled', '0') == '1'
        try:
            result['interval'] = max(2, int(general.get('interval', '5')))
        except ValueError:
            pass
        result['kill_states'] = general.get('kill_states', '1') == '1'
    if parser.has_section('clients'):
        for mac, alias in parser['clients'].items():
            mac = mac.strip().lower()
            alias = alias.strip()
            if is_mac(mac) and is_alias(alias):
                result['clients'][mac] = alias
    return result


def parse_hosts(payload):
    """ convert list_hosts.py output into mac -> {interface, ipv4, ipv6}
        :param payload: json string produced by list_hosts.py
    """
    result = {}
    try:
        rows = json.loads(payload).get('rows', [])
    except (ValueError, AttributeError):
        return result
    for row in rows:
        if len(row) < 3 or not is_mac(str(row[1]).lower()):
            continue
        mac = row[1].lower()
        try:
            address = ipaddress.ip_address(str(row[2]).split('%', 1)[0])
        except ValueError:
            continue
        if address.is_link_local:
            # never routed off the network, no use in a gateway alias
            continue
        host = result.setdefault(mac, {'interface': row[0], 'ipv4': set(), 'ipv6': set()})
        host['ipv4' if address.version == 4 else 'ipv6'].add(str(address))
    return result


def list_hosts():
    """ current ARP and NDP entries (or host discovery data when enabled) """
    stdout, _ = run([LIST_HOSTS, '-n'])
    return parse_hosts(stdout)


def parse_leases(payload):
    """ convert get_kea_leases.py output into mac -> hostname """
    result = {}
    try:
        records = json.loads(payload).get('records', []) if payload.strip() else []
    except (ValueError, AttributeError):
        return result
    for record in records:
        mac = str(record.get('hwaddr', '')).lower()
        if is_mac(mac) and record.get('hostname'):
            result[mac] = record['hostname'].rstrip('.')
    return result


def lease_hostnames():
    stdout, _ = run([KEA_LEASES, '--proto', 'inet'])
    return parse_leases(stdout)


def pf_table(name):
    """ addresses currently loaded in pf table name """
    stdout, _ = run([PFCTL, '-t', name, '-T', 'show'])
    return set(line.strip() for line in stdout.splitlines() if line.strip())


def pf_table_add(name, addresses):
    if addresses:
        run([PFCTL, '-t', name, '-T', 'add'] + sorted(addresses))


def parse_killed(output):
    """ number of states pfctl -k reports as killed """
    match = re.search(r'killed (\d+) states', output or '')
    return int(match.group(1)) if match else 0


def kill_states(address):
    """ drop all states originating from address
        :return: number of states killed
    """
    stdout, stderr = run([PFCTL, '-k', address])
    return parse_killed(stdout + stderr)


def update_tables(aliases):
    """ targeted alias refresh, waits for a running full refresh to finish """
    if aliases:
        run(['/usr/local/bin/flock', '-w', '60', UPDATE_TABLES_LOCK, UPDATE_TABLES, '--aliases', ','.join(aliases)])
