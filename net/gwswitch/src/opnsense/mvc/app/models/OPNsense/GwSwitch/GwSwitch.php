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

use OPNsense\Base\BaseModel;
use OPNsense\Base\Messages\Message;

class GwSwitch extends BaseModel
{
    /**
     * {@inheritdoc}
     */
    public function performValidation($validateFullModel = false)
    {
        $messages = parent::performValidation($validateFullModel);

        foreach ($this->profiles->profile->iterateItems() as $profile) {
            if (!$validateFullModel && !$profile->isFieldChanged()) {
                continue;
            }
            $ref = $profile->__reference;
            if ($profile->ipv6_mode->isEqual('route') && $profile->gateway6->isEmpty()) {
                $messages->appendMessage(new Message(
                    gettext('Routing IPv6 needs an IPv6 gateway. Choose one, or reject IPv6 for this profile.'),
                    $ref . '.gateway6'
                ));
            }
        }

        return $messages;
    }

    /**
     * enabled profiles indexed by uuid
     * @return array
     */
    public function activeProfiles()
    {
        $result = [];
        foreach ($this->profiles->profile->iterateItems() as $uuid => $profile) {
            if ($profile->enabled->isEqual('1')) {
                $result[$uuid] = [
                    'uuid' => $uuid,
                    'name' => $profile->name->getValue(),
                    'gateway4' => $profile->gateway4->getValue(),
                    'gateway6' => $profile->gateway6->getValue(),
                    'ipv6_mode' => $profile->ipv6_mode->getValue(),
                    'nat' => $profile->nat->isEqual('1'),
                    'description' => $profile->description->getValue(),
                ];
            }
        }
        return $result;
    }

    /**
     * find a client entry by MAC address
     * @param string $mac MAC address, any case
     * @return \OPNsense\Base\FieldTypes\ArrayField|null
     */
    public function getClientByMac($mac)
    {
        $mac = strtolower(trim($mac));
        foreach ($this->clients->client->iterateItems() as $client) {
            if (strtolower($client->mac->getValue()) === $mac) {
                return $client;
            }
        }
        return null;
    }

    /**
     * find a profile by name
     * @param string $name profile name
     * @return \OPNsense\Base\FieldTypes\ArrayField|null
     */
    public function getProfileByName($name)
    {
        foreach ($this->profiles->profile->iterateItems() as $profile) {
            if ($profile->name->isEqual($name)) {
                return $profile;
            }
        }
        return null;
    }
}
