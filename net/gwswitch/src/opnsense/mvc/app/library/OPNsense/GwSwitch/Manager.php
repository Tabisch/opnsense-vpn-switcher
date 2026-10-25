<?php

/*
 * Copyright (C) 2026 Tabisch
 * All rights reserved.
 *
 * Redistribution and use in source and binary forms, with or without
 * modification, are permitted provided that the following conditions are met:
 *
 * 1. Redistributions of source code must retain the above copyright notice,
 *    this list of conditions and the following disclaimer.
 *
 * 2. Redistributions in binary form must reproduce the above copyright
 *    notice, this list of conditions and the following disclaimer in the
 *    documentation and/or other materials provided with the distribution.
 *
 * THIS SOFTWARE IS PROVIDED ``AS IS'' AND ANY EXPRESS OR IMPLIED WARRANTIES,
 * INCLUDING, BUT NOT LIMITED TO, THE IMPLIED WARRANTIES OF MERCHANTABILITY
 * AND FITNESS FOR A PARTICULAR PURPOSE ARE DISCLAIMED. IN NO EVENT SHALL THE
 * AUTHOR BE LIABLE FOR ANY DIRECT, INDIRECT, INCIDENTAL, SPECIAL, EXEMPLARY,
 * OR CONSEQUENTIAL DAMAGES (INCLUDING, BUT NOT LIMITED TO, PROCUREMENT OF
 * SUBSTITUTE GOODS OR SERVICES; LOSS OF USE, DATA, OR PROFITS; OR BUSINESS
 * INTERRUPTION) HOWEVER CAUSED AND ON ANY THEORY OF LIABILITY, WHETHER IN
 * CONTRACT, STRICT LIABILITY, OR TORT (INCLUDING NEGLIGENCE OR OTHERWISE)
 * ARISING IN ANY WAY OUT OF THE USE OF THIS SOFTWARE, EVEN IF ADVISED OF THE
 * POSSIBILITY OF SUCH DAMAGE.
 */

namespace OPNsense\GwSwitch;

use OPNsense\Core\Config;
use OPNsense\Firewall\Alias;
use OPNsense\Firewall\Filter;
use OPNsense\Routing\Gateways;

/**
 * Keeps the firewall objects owned by the gateway switcher (aliases, filter rules and
 * source NAT rules) in line with the configured profiles and client assignments.
 *
 * Every object this class creates carries MARKER in its description, everything else is left alone.
 */
class Manager
{
    public const MARKER = '[gwswitch]';
    public const ALIAS_PREFIX = 'gwsw_';
    public const LOCAL_ALIAS = 'gwsw_local';
    public const TAG_PREFIX = 'GWSW_';
    public const LOCAL_NETWORKS = [
        '10.0.0.0/8',
        '172.16.0.0/12',
        '192.168.0.0/16',
        '100.64.0.0/10',
        '169.254.0.0/16',
        '224.0.0.0/4',
        '255.255.255.255/32',
        'fc00::/7',
        'fe80::/10',
        'ff00::/8',
    ];

    /**
     * @var GwSwitch
     */
    private $mdl;

    /**
     * @var string[] messages collected during the last sync
     */
    private $messages = [];

    public function __construct($mdl = null)
    {
        $this->mdl = $mdl ?? new GwSwitch();
    }

    /**
     * @param string $profile_name profile name
     * @return string firewall alias holding the clients of this profile
     */
    public static function aliasName($profile_name)
    {
        return self::ALIAS_PREFIX . strtolower($profile_name);
    }

    /**
     * @param string $profile_name profile name
     * @return string packet tag set on traffic routed by this profile
     */
    public static function tagName($profile_name)
    {
        return self::TAG_PREFIX . strtoupper($profile_name);
    }

    /**
     * @param string $description object description
     * @return bool object is owned by this plugin
     */
    public static function isManaged($description)
    {
        return strpos((string)$description, self::MARKER) === 0;
    }

    /**
     * @return string[] messages collected during the last call
     */
    public function getMessages()
    {
        return $this->messages;
    }

    /**
     * MAC addresses per profile uuid, only enabled clients assigned to an enabled profile
     * @return array
     */
    public function clientsByProfile()
    {
        $result = [];
        $profiles = $this->mdl->activeProfiles();
        foreach ($this->mdl->clients->client->iterateItems() as $client) {
            $profile = $client->profile->getValue();
            if ($client->enabled->isEqual('1') && isset($profiles[$profile])) {
                $result[$profile][] = strtolower($client->mac->getValue());
            }
        }
        foreach ($result as $uuid => $macs) {
            $macs = array_unique($macs);
            sort($macs);
            $result[$uuid] = $macs;
        }
        return $result;
    }

    /**
     * names of all aliases owned by this plugin for the active profiles
     * @return string[]
     */
    public function profileAliasNames()
    {
        $result = [];
        if ($this->mdl->general->enabled->isEqual('1')) {
            foreach ($this->mdl->activeProfiles() as $profile) {
                $result[] = self::aliasName($profile['name']);
            }
        }
        return $result;
    }

    /**
     * Fast path used when a client moves: only rewrite the content of the profile aliases.
     * The aliases have to exist already (created by syncFirewall()).
     * @return array ['changed' => string[] alias names with new content, 'missing' => string[]]
     */
    public function syncAliasContents()
    {
        $this->messages = [];
        $aliasMdl = new Alias();
        $changed = [];
        $missing = [];
        $clients = $this->clientsByProfile();
        $enabled = $this->mdl->general->enabled->isEqual('1');

        foreach ($this->mdl->activeProfiles() as $uuid => $profile) {
            $name = self::aliasName($profile['name']);
            $alias = $aliasMdl->getByName($name);
            if ($alias === null || !self::isManaged($alias->description->getValue())) {
                $missing[] = $name;
                continue;
            }
            $content = $enabled ? implode("\n", $clients[$uuid] ?? []) : '';
            if ($alias->content->getValue() !== $content) {
                $alias->content = $content;
                $changed[] = $name;
            }
        }

        if (!empty($changed) && $this->validate([$aliasMdl])) {
            $aliasMdl->serializeToConfig(false, true);
            Config::getInstance()->save();
        } elseif (!empty($changed)) {
            $changed = [];
        }
        if (!empty($missing)) {
            $this->messages[] = sprintf(
                gettext('Aliases %s do not exist yet, apply the configuration first.'),
                implode(', ', $missing)
            );
        }

        return ['changed' => $changed, 'missing' => $missing];
    }

    /**
     * Full sync: aliases, filter rules, source NAT and the global "skip rules when gateway is down" option.
     * @return bool configuration changed
     */
    public function syncFirewall()
    {
        $this->messages = [];
        $enabled = $this->mdl->general->enabled->isEqual('1');
        $profiles = $enabled ? $this->mdl->activeProfiles() : [];

        /*
         * Three steps, serialized in memory one after the other: new aliases first (rules are validated
         * against the aliases in the configuration), then rules and NAT, then aliases nobody uses anymore.
         */
        $aliasMdl = new Alias();
        $changed = $this->syncAliases($aliasMdl, $profiles, false);
        if ($changed) {
            if (!$this->validate([$aliasMdl])) {
                return false;
            }
            $aliasMdl->serializeToConfig(false, true);
        }

        $filterMdl = new Filter();
        $rules_changed = $this->syncRules($filterMdl, $profiles);
        $rules_changed = $this->syncSourceNat($filterMdl, $profiles) || $rules_changed;
        if ($rules_changed) {
            if (!$this->validate([$filterMdl])) {
                Config::getInstance()->forceReload();
                return false;
            }
            $filterMdl->serializeToConfig(false, true);
            $changed = true;
        }

        $aliasMdl = new Alias();
        if ($this->syncAliases($aliasMdl, $profiles, true)) {
            $aliasMdl->serializeToConfig(false, true);
            $changed = true;
        }

        $cfg = Config::getInstance()->object();
        if (
            $enabled && $this->mdl->general->skip_rules_gw_down->isEqual('1') &&
            empty((string)$cfg->system->skip_rules_gw_down)
        ) {
            $cfg->system->skip_rules_gw_down = '1';
            $this->messages[] = gettext('Enabled "Skip rules when gateway is down" so a dead tunnel cannot fall back to WAN.');
            $changed = true;
        }

        if ($changed) {
            Config::getInstance()->save();
        }

        return $changed;
    }

    /**
     * validate changed fields of the given models, collect problems as messages
     * @param \OPNsense\Base\BaseModel[] $models models to validate
     * @return bool all valid
     */
    private function validate($models)
    {
        $valid = true;
        foreach ($models as $model) {
            foreach ($model->performValidation() as $msg) {
                $this->messages[] = sprintf('%s: %s', $msg->getField(), $msg->getMessage());
                $valid = false;
            }
        }
        if (!$valid) {
            array_unshift($this->messages, gettext('Nothing was changed, the generated firewall objects did not validate.'));
        }
        return $valid;
    }

    /**
     * @param Alias $aliasMdl alias model
     * @param array $profiles active profiles
     * @param bool $cleanup only remove managed aliases that are no longer needed
     * @return bool changed
     */
    private function syncAliases($aliasMdl, $profiles, $cleanup)
    {
        $changed = false;
        $clients = $this->clientsByProfile();
        $wanted = [];
        if (!empty($profiles)) {
            $wanted[self::LOCAL_ALIAS] = [
                'type' => 'network',
                'content' => implode("\n", self::LOCAL_NETWORKS),
                'description' => self::MARKER . ' local and multicast networks, never routed to a tunnel',
            ];
        }
        foreach ($profiles as $uuid => $profile) {
            $wanted[self::aliasName($profile['name'])] = [
                'type' => 'mac',
                'content' => implode("\n", $clients[$uuid] ?? []),
                'description' => self::MARKER . ' clients of profile ' . $profile['name'],
            ];
        }

        if ($cleanup) {
            foreach ($aliasMdl->aliases->alias->iterateItems() as $uuid => $alias) {
                if (
                    self::isManaged($alias->description->getValue()) &&
                    !isset($wanted[$alias->name->getValue()])
                ) {
                    $aliasMdl->aliases->alias->del($uuid);
                    $changed = true;
                }
            }
            return $changed;
        }

        foreach ($wanted as $name => $props) {
            $alias = $aliasMdl->getByName($name);
            if ($alias === null) {
                $alias = $aliasMdl->aliases->alias->add();
                $alias->name = $name;
                $alias->enabled = '1';
                $changed = true;
            } elseif (!self::isManaged($alias->description->getValue())) {
                $this->messages[] = sprintf(
                    gettext('Alias %s exists but was not created by the gateway switcher, it will be taken over.'),
                    $name
                );
            }
            foreach ($props as $field => $value) {
                if ($alias->$field->getValue() !== $value) {
                    $alias->$field = $value;
                    $changed = true;
                }
            }
        }

        return $changed;
    }

    /**
     * desired filter rules, in evaluation order
     * @param array $profiles active profiles
     * @return array list of field => value maps
     */
    public function desiredRules($profiles)
    {
        $rules = [];
        $log = $this->mdl->general->log->getValue();
        $interfaces = $this->mdl->general->interfaces->getValues();
        $wan_interfaces = $this->mdl->general->wan_interfaces->getValues();

        foreach ($interfaces as $interface) {
            foreach ($profiles as $profile) {
                $alias = self::aliasName($profile['name']);
                $base = [
                    'enabled' => '1',
                    'quick' => '1',
                    'interface' => $interface,
                    'direction' => 'in',
                    'protocol' => 'any',
                    'source_net' => $alias,
                    'destination_net' => self::LOCAL_ALIAS,
                    'destination_not' => '1',
                    'gateway' => '',
                    'tag' => '',
                    'tagged' => '',
                    'log' => '0',
                ];
                $rules[] = array_merge($base, [
                    'action' => 'pass',
                    'ipprotocol' => 'inet',
                    'gateway' => $profile['gateway4'],
                    'tag' => self::tagName($profile['name']),
                    'description' => sprintf('%s %s: IPv4 via %s', self::MARKER, $profile['name'], $profile['gateway4']),
                ]);
                if ($profile['ipv6_mode'] == 'route' && !empty($profile['gateway6'])) {
                    $rules[] = array_merge($base, [
                        'action' => 'pass',
                        'ipprotocol' => 'inet6',
                        'gateway' => $profile['gateway6'],
                        'tag' => self::tagName($profile['name']),
                        'description' => sprintf('%s %s: IPv6 via %s', self::MARKER, $profile['name'], $profile['gateway6']),
                    ]);
                } else {
                    $rules[] = array_merge($base, [
                        'action' => 'reject',
                        'ipprotocol' => 'inet6',
                        'log' => $log,
                        'description' => sprintf('%s %s: reject IPv6', self::MARKER, $profile['name']),
                    ]);
                }
                $rules[] = array_merge($base, [
                    'action' => 'block',
                    'ipprotocol' => 'inet46',
                    'log' => $log,
                    'description' => sprintf('%s %s: block when the gateway is down', self::MARKER, $profile['name']),
                ]);
            }
        }

        if (!empty($wan_interfaces)) {
            foreach ($profiles as $profile) {
                $rules[] = [
                    'enabled' => '1',
                    'quick' => '1',
                    'interface' => implode(',', $wan_interfaces),
                    'direction' => 'out',
                    'protocol' => 'any',
                    'source_net' => 'any',
                    'destination_net' => 'any',
                    'destination_not' => '0',
                    'gateway' => '',
                    'tag' => '',
                    'tagged' => self::tagName($profile['name']),
                    'log' => $log,
                    'action' => 'block',
                    'ipprotocol' => 'inet46',
                    'description' => sprintf('%s %s: never leave via WAN', self::MARKER, $profile['name']),
                ];
            }
        }

        return $rules;
    }

    /**
     * @param Filter $filterMdl filter model
     * @param array $profiles active profiles
     * @return bool changed
     */
    private function syncRules($filterMdl, $profiles)
    {
        $wanted = $this->desiredRules($profiles);
        $changed = $this->replaceManaged($filterMdl->rules->rule, $wanted);
        return $this->placeFirst($filterMdl->rules->rule) || $changed;
    }

    /**
     * desired source NAT rules
     * @param array $profiles active profiles
     * @return array list of field => value maps
     */
    public function desiredSourceNat($profiles)
    {
        $rules = [];
        $gateways = (new Gateways())->gatewaysIndexedByName(true, false, true);
        foreach ($profiles as $profile) {
            if (!$profile['nat']) {
                continue;
            }
            $families = ['inet' => $profile['gateway4']];
            if ($profile['ipv6_mode'] == 'route' && !empty($profile['gateway6'])) {
                $families['inet6'] = $profile['gateway6'];
            }
            foreach ($families as $family => $gateway) {
                if (empty($gateways[$gateway]['interface'])) {
                    $this->messages[] = sprintf(
                        gettext('No outbound NAT for %s: %s is not a single gateway, add NAT by hand.'),
                        $profile['name'],
                        $gateway
                    );
                    continue;
                }
                $rules[] = [
                    'enabled' => '1',
                    'nonat' => '0',
                    'interface' => $gateways[$gateway]['interface'],
                    'ipprotocol' => $family,
                    'protocol' => 'any',
                    'source_net' => self::aliasName($profile['name']),
                    'destination_net' => 'any',
                    'target' => '',
                    'description' => sprintf(
                        '%s %s: NAT %s to the tunnel address',
                        self::MARKER,
                        $profile['name'],
                        $family == 'inet' ? 'IPv4' : 'IPv6'
                    ),
                ];
            }
        }
        return $rules;
    }

    /**
     * @param Filter $filterMdl filter model
     * @param array $profiles active profiles
     * @return bool changed
     */
    private function syncSourceNat($filterMdl, $profiles)
    {
        $wanted = $this->desiredSourceNat($profiles);
        $changed = $this->replaceManaged($filterMdl->snatrules->rule, $wanted);
        $changed = $this->placeFirst($filterMdl->snatrules->rule) || $changed;

        if (!empty($wanted)) {
            $mode = $filterMdl->settings->nat->snat_mode->getValue();
            if ($mode == 'automatic') {
                $filterMdl->settings->nat->snat_mode = 'hybrid';
                $this->messages[] = gettext('Switched outbound NAT to hybrid mode so the tunnel NAT rules are used.');
                $changed = true;
            } elseif ($mode == 'disabled') {
                $this->messages[] = gettext('Outbound NAT is disabled, the tunnel NAT rules have no effect.');
            }
        }

        return $changed;
    }

    /**
     * Replace the managed items in $container with $wanted, unless they are equal already.
     * Order of $wanted is kept through the sequence field.
     * @param \OPNsense\Base\FieldTypes\ArrayField $container rule container
     * @param array $wanted list of field => value maps
     * @return bool changed
     */
    private function replaceManaged($container, $wanted)
    {
        $current = [];
        foreach ($container->sortedBy(['sequence']) as $uuid => $node) {
            if (self::isManaged($node->description->getValue())) {
                $current[(string)$node->getAttribute('uuid')] = $node;
            }
        }

        if (count($current) == count($wanted)) {
            $equal = true;
            foreach (array_values($current) as $idx => $node) {
                foreach ($wanted[$idx] as $field => $value) {
                    if ($node->$field->getValue() !== (string)$value) {
                        $equal = false;
                        break 2;
                    }
                }
            }
            if ($equal) {
                return false;
            }
        }

        foreach (array_keys($current) as $uuid) {
            $container->del($uuid);
        }
        foreach ($wanted as $idx => $fields) {
            $node = $container->add();
            foreach ($fields as $field => $value) {
                $node->$field = (string)$value;
            }
            $node->sequence = (string)($idx + 1);
        }

        return true;
    }

    /**
     * Make sure managed items are evaluated before any other item on the same interface(s).
     * Managed items use sequence 1..n, other items sharing an interface with them are moved up
     * as a block when needed, so their relative order stays the same.
     * @param \OPNsense\Base\FieldTypes\ArrayField $container rule container
     * @return bool changed
     */
    private function placeFirst($container)
    {
        $managed_count = 0;
        $managed_ifs = [];
        $others = [];
        foreach ($container->iterateItems() as $node) {
            $ifs = $node->interface->getValues();
            if (self::isManaged($node->description->getValue())) {
                $managed_count++;
                $managed_ifs[implode(',', $ifs)] = true;
            } else {
                $others[] = $node;
            }
        }

        $min_sequence = null;
        $affected = [];
        foreach ($others as $node) {
            if (isset($managed_ifs[implode(',', $node->interface->getValues())])) {
                $affected[] = $node;
                $sequence = $node->sequence->asInt();
                $min_sequence = $min_sequence === null ? $sequence : min($min_sequence, $sequence);
            }
        }

        if ($min_sequence === null || $min_sequence > $managed_count) {
            return false;
        }

        $shift = $managed_count - $min_sequence + 1;
        foreach ($affected as $node) {
            $node->sequence = (string)($node->sequence->asInt() + $shift);
        }
        $this->messages[] = sprintf(
            gettext('Moved %d existing rules down so the gateway switcher rules are evaluated first.'),
            count($affected)
        );

        return true;
    }
}
