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

function gwswitch_enabled()
{
    global $config;

    return !empty($config['OPNsense']['gwswitch']['general']['enabled']);
}

function gwswitch_services()
{
    $services = [];

    if (gwswitch_enabled()) {
        $services[] = [
            'description' => gettext('Gateway switcher address watcher'),
            'configd' => [
                'start' => ['gwswitch start'],
                'restart' => ['gwswitch restart'],
                'stop' => ['gwswitch stop'],
            ],
            'name' => 'gwswitch',
            'pidfile' => '/var/run/gwswitch.pid',
        ];
    }

    return $services;
}

function gwswitch_xmlrpc_sync()
{
    $result = [];

    $result[] = [
        'description' => gettext('Gateway switcher'),
        'section' => 'OPNsense.gwswitch',
        'id' => 'gwswitch',
        'services' => ['gwswitch'],
    ];

    return $result;
}
