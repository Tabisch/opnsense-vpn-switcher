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

namespace OPNsense\GwSwitch\Api;

use OPNsense\Base\ApiControllerBase;
use OPNsense\Base\UserException;
use OPNsense\Core\Backend;
use OPNsense\Core\Config;
use OPNsense\GwSwitch\GwSwitch;
use OPNsense\GwSwitch\Manager;

/**
 * Live view of the network: discovered hosts with their current profile, gateway health,
 * and the one-click switch.
 */
class ClientsController extends ApiControllerBase
{
    /**
     * hosts seen on the network (ARP, NDP, Kea leases) merged with the configured clients
     */
    public function searchAction()
    {
        $mdl = new GwSwitch();
        $profiles = $mdl->activeProfiles();
        $hosts = json_decode((new Backend())->configdRun('gwswitch hosts'), true) ?? [];

        $rows = [];
        foreach ($hosts as $mac => $host) {
            $rows[$mac] = [
                'mac' => $mac,
                'hostname' => $host['hostname'] ?? '',
                'interface' => $host['interface'] ?? '',
                'ipv4' => implode(', ', $host['ipv4'] ?? []),
                'ipv6' => implode(', ', $host['ipv6'] ?? []),
                'online' => true,
                'description' => '',
                'profile' => '',
                'profile_name' => '',
            ];
        }
        foreach ($mdl->clients->client->iterateItems() as $client) {
            $mac = strtolower($client->mac->getValue());
            if (!isset($rows[$mac])) {
                $rows[$mac] = [
                    'mac' => $mac, 'hostname' => '', 'interface' => '', 'ipv4' => '', 'ipv6' => '', 'online' => false,
                ];
            }
            $profile = $client->profile->getValue();
            $rows[$mac]['description'] = $client->description->getValue();
            $rows[$mac]['profile'] = $client->enabled->isEqual('1') && isset($profiles[$profile]) ? $profile : '';
            $rows[$mac]['profile_name'] = $rows[$mac]['profile'] !== '' ? $profiles[$profile]['name'] : '';
        }

        $rows = array_values($rows);
        usort($rows, function ($a, $b) {
            return [!$a['online'], strtolower($a['hostname'] ?: $a['mac'])] <=> [!$b['online'], strtolower($b['hostname'] ?: $b['mac'])];
        });

        $choices = [['uuid' => '', 'name' => gettext('Default gateway')]];
        foreach ($profiles as $uuid => $profile) {
            $choices[] = ['uuid' => $uuid, 'name' => $profile['name'], 'description' => $profile['description']];
        }

        return ['rows' => $rows, 'profiles' => $choices, 'enabled' => $mdl->general->enabled->isEqual('1')];
    }

    /**
     * status of the gateways used by the profiles
     */
    public function gatewaysAction()
    {
        $mdl = new GwSwitch();
        $status = json_decode((new Backend())->configdRun('interface gateways status'), true) ?? [];
        $result = [];
        foreach ($mdl->activeProfiles() as $profile) {
            foreach (['gateway4', 'gateway6'] as $field) {
                $name = $profile[$field];
                if (empty($name) || ($field == 'gateway6' && $profile['ipv6_mode'] != 'route')) {
                    continue;
                }
                $result[] = [
                    'profile' => $profile['name'],
                    'gateway' => $name,
                    'status' => $status[$name]['status'] ?? 'unknown',
                    'status_translated' => $status[$name]['status_translated'] ?? gettext('Unknown (gateway group)'),
                    'delay' => $status[$name]['delay'] ?? '~',
                    'loss' => $status[$name]['loss'] ?? '~',
                ];
            }
        }
        return ['rows' => $result];
    }

    /**
     * Move one client to a profile and apply it at once.
     * POST mac, profile (profile uuid or name, empty for the default gateway), optional description
     */
    public function switchAction()
    {
        if (!$this->request->isPost()) {
            return ['status' => 'failed'];
        }
        $this->throwReadOnly();

        $mac = strtolower(trim((string)$this->request->getPost('mac', 'string', '')));
        if (!filter_var($mac, FILTER_VALIDATE_MAC)) {
            throw new UserException(gettext('Invalid MAC address.'), gettext('Gateway switcher'));
        }

        Config::getInstance()->lock();
        $mdl = new GwSwitch();
        $profile_ref = (string)$this->request->getPost('profile', 'string', '');
        $profile_uuid = '';
        if ($profile_ref !== '') {
            $profiles = $mdl->activeProfiles();
            if (isset($profiles[$profile_ref])) {
                $profile_uuid = $profile_ref;
            } elseif (($node = $mdl->getProfileByName($profile_ref)) !== null && isset($profiles[$node->getAttribute('uuid')])) {
                $profile_uuid = $node->getAttribute('uuid');
            } else {
                Config::getInstance()->unlock();
                throw new UserException(gettext('Unknown or disabled profile.'), gettext('Gateway switcher'));
            }
        }

        $client = $mdl->getClientByMac($mac);
        if ($client === null) {
            $client = $mdl->clients->client->add();
            $client->mac = $mac;
        }
        $client->enabled = '1';
        $client->profile = $profile_uuid;
        if ($this->request->hasPost('description')) {
            $client->description = (string)$this->request->getPost('description', 'string', '');
        }

        $messages = $mdl->performValidation();
        if (count($messages) > 0) {
            Config::getInstance()->unlock();
            $errors = [];
            foreach ($messages as $msg) {
                $errors[] = $msg->getMessage();
            }
            throw new UserException(implode("\n", $errors), gettext('Gateway switcher'));
        }
        $mdl->serializeToConfig();
        Config::getInstance()->save();

        $manager = new Manager($mdl);
        $sync = $manager->syncAliasContents();

        $backend = new Backend();
        $backend->configdRun('template reload OPNsense/GwSwitch');
        if (!empty($sync['changed'])) {
            $backend->configdRun('template reload OPNsense/Filter');
        }
        $aliases = $manager->profileAliasNames();
        $output = json_decode(
            $backend->configdpRun('gwswitch refresh', [empty($aliases) ? '-' : implode(',', $aliases), $mac]),
            true
        );

        return [
            'status' => 'ok',
            'mac' => $mac,
            'profile' => $profile_uuid,
            'addresses' => $output['addresses'] ?? [],
            'killed_states' => $output['killed_states'] ?? 0,
            'messages' => $manager->getMessages(),
        ];
    }
}
