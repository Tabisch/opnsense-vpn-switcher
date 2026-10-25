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

use OPNsense\Base\ApiMutableModelControllerBase;

class SettingsController extends ApiMutableModelControllerBase
{
    protected static $internalModelName = 'gwswitch';
    protected static $internalModelClass = 'OPNsense\GwSwitch\GwSwitch';

    public function searchProfileAction()
    {
        return $this->searchBase('profiles.profile');
    }

    public function getProfileAction($uuid = null)
    {
        return $this->getBase('profile', 'profiles.profile', $uuid);
    }

    public function addProfileAction()
    {
        return $this->addBase('profile', 'profiles.profile');
    }

    public function setProfileAction($uuid)
    {
        return $this->setBase('profile', 'profiles.profile', $uuid);
    }

    public function delProfileAction($uuid)
    {
        return $this->delBase('profiles.profile', $uuid);
    }

    public function toggleProfileAction($uuid, $enabled = null)
    {
        return $this->toggleBase('profiles.profile', $uuid, $enabled);
    }

    public function searchClientAction()
    {
        return $this->searchBase('clients.client');
    }

    public function getClientAction($uuid = null)
    {
        return $this->getBase('client', 'clients.client', $uuid);
    }

    public function addClientAction()
    {
        return $this->addBase('client', 'clients.client');
    }

    public function setClientAction($uuid)
    {
        return $this->setBase('client', 'clients.client', $uuid);
    }

    public function delClientAction($uuid)
    {
        return $this->delBase('clients.client', $uuid);
    }

    public function toggleClientAction($uuid, $enabled = null)
    {
        return $this->toggleBase('clients.client', $uuid, $enabled);
    }

    /**
     * store MAC addresses in lower case, the alias resolver matches on lower case
     */
    protected function setBaseHook($node)
    {
        if (isset($node->mac)) {
            $node->mac = strtolower($node->mac->getValue());
        }
    }
}
