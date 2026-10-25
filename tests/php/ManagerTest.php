<?php

/*
 * Checks the firewall objects the gateway switcher generates, against the real OPNsense
 * models (run.sh fetches opnsense/core). configd and the gateway list are stubbed in bootstrap.php.
 */

require __DIR__ . '/bootstrap.php';

use OPNsense\Core\Config;
use OPNsense\Firewall\Alias;
use OPNsense\Firewall\Filter;
use OPNsense\Firewall\FilterRule;
use OPNsense\GwSwitch\GwSwitch;
use OPNsense\GwSwitch\Manager;

$failures = 0;

function check($condition, $label)
{
    global $failures;
    echo ($condition ? 'ok   ' : 'FAIL ') . $label . "\n";
    if (!$condition) {
        $failures++;
    }
}

function render_rules()
{
    $ifmap = [
        'wan' => ['if' => 'vtnet0', 'descr' => 'WAN'],
        'lan' => ['if' => 'vtnet1', 'descr' => 'LAN'],
        'opt1' => ['if' => 'wg0', 'descr' => 'WG'],
    ];
    $gwmap = [
        'WG_GW' => ['logic' => 'route-to ( wg0 10.64.0.1 )', 'interface' => 'wg0', 'gateway' => '10.64.0.1', 'proto' => 'inet'],
        'WG_GW6' => ['logic' => 'route-to ( wg0 fc00:bbbb::1 )', 'interface' => 'wg0', 'gateway' => 'fc00:bbbb::1', 'proto' => 'inet6'],
    ];
    $lines = [];
    foreach ((new Filter())->rules->rule->sortedBy(['sequence']) as $rule) {
        $content = array_merge(['skip_rules_gw_down' => true], $rule->serialize());
        foreach (explode("\n", trim((string)(new FilterRule($ifmap, $gwmap, $content)))) as $line) {
            $lines[] = $line;
        }
    }
    return $lines;
}

function aliases()
{
    $result = [];
    foreach ((new Alias())->aliases->alias->iterateItems() as $alias) {
        $result[$alias->name->getValue()] = $alias->content->getValue();
    }
    return $result;
}

function index_of($lines, $needle)
{
    foreach ($lines as $idx => $line) {
        if (strpos($line, $needle) !== false) {
            return $idx;
        }
    }
    return -1;
}

/* first apply */
$manager = new Manager();
check($manager->syncFirewall() === true, 'first sync changes the configuration');
$cfg = Config::getInstance()->object();
check((string)$cfg->system->skip_rules_gw_down === '1', 'skip rules when gateway is down is enabled');
check((string)$cfg->nat->outbound->mode === 'hybrid', 'outbound NAT moved to hybrid');

$aliases = aliases();
check(isset($aliases['gwsw_local']), 'local networks alias exists');
check(($aliases['gwsw_vpn1'] ?? null) === 'aa:bb:cc:dd:ee:01', 'vpn1 alias holds the laptop MAC');
check(($aliases['gwsw_vpn6'] ?? null) === '', 'vpn6 alias is empty');

$lines = render_rules();
$route = index_of($lines, 'route-to ( wg0 10.64.0.1 ) inet from $gwsw_vpn1 to !$gwsw_local');
$reject = index_of($lines, 'block return in quick on vtnet1 inet6 from $gwsw_vpn1');
$kill = index_of($lines, 'block in quick on vtnet1 inet from $gwsw_vpn1');
$route6 = index_of($lines, 'route-to ( wg0 fc00:bbbb::1 ) inet6 from $gwsw_vpn6');
$default = index_of($lines, 'Default allow LAN to any');
check($route >= 0, 'vpn1 IPv4 is routed to the tunnel');
check($reject > $route, 'vpn1 IPv6 is rejected after the route rule');
check($kill > $reject, 'vpn1 kill switch follows');
check($route6 >= 0, 'vpn6 IPv6 is routed to the IPv6 gateway');
check($default > $kill && $default > $route6, 'existing LAN allow rule is evaluated after the switcher rules');
check(index_of($lines, 'block out quick on vtnet0 inet from {any} to {any} tagged GWSW_VPN1') >= 0, 'tagged traffic is blocked on WAN');

$snat = [];
foreach ((new Filter())->snatrules->rule->iterateItems() as $rule) {
    $snat[] = $rule->interface->getValue() . ' ' . $rule->ipprotocol->getValue() . ' ' . $rule->source_net->getValue();
}
sort($snat);
check($snat === ['opt1 inet gwsw_vpn1', 'opt1 inet gwsw_vpn6', 'opt1 inet6 gwsw_vpn6'], 'NAT rules on the tunnel interface');

/* second apply is a no-op */
$manager = new Manager();
check($manager->syncFirewall() === false, 'second sync changes nothing');

/* move the tv into vpn6 through the fast path */
Config::getInstance()->lock();
$mdl = new GwSwitch();
$mdl->getClientByMac('AA:BB:CC:DD:EE:02')->profile = 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb';
$mdl->serializeToConfig();
Config::getInstance()->save();
$sync = (new Manager($mdl))->syncAliasContents();
check($sync['changed'] === ['gwsw_vpn6'], 'fast path only rewrites the vpn6 alias');
check(aliases()['gwsw_vpn6'] === 'aa:bb:cc:dd:ee:02', 'vpn6 alias holds the tv MAC');

/* drop a profile: its alias, rules and NAT go away */
Config::getInstance()->lock();
$mdl = new GwSwitch();
$mdl->profiles->profile->del('bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb');
$mdl->getClientByMac('aa:bb:cc:dd:ee:02')->profile = '';
$mdl->serializeToConfig();
Config::getInstance()->save();
check((new Manager())->syncFirewall() === true, 'removing a profile changes the configuration');
check(!isset(aliases()['gwsw_vpn6']), 'vpn6 alias removed');
check(index_of(render_rules(), 'gwsw_vpn6') === -1, 'vpn6 rules removed');

/* disable: everything managed goes away, the user's rules stay */
Config::getInstance()->lock();
$mdl = new GwSwitch();
$mdl->general->enabled = '0';
$mdl->serializeToConfig();
Config::getInstance()->save();
(new Manager())->syncFirewall();
$lines = render_rules();
check(index_of($lines, 'gwswitch') === -1, 'disabled: no managed rules left');
check(index_of($lines, 'Default allow LAN to any') >= 0, 'disabled: user rules kept');
check(count(array_filter(array_keys(aliases()), function ($n) {
    return strpos($n, 'gwsw_') === 0;
})) === 0, 'disabled: no managed aliases left');

echo $failures === 0 ? "\nall checks passed\n" : "\n$failures checks failed\n";
exit($failures === 0 ? 0 : 1);
