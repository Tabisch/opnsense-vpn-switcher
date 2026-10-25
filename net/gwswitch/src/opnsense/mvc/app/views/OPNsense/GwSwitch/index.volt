{#
 # Copyright (C) 2026 Tabisch
 # All rights reserved.
 #
 # Redistribution and use in source and binary forms, with or without modification,
 # are permitted provided that the following conditions are met:
 #
 # 1. Redistributions of source code must retain the above copyright notice,
 #    this list of conditions and the following disclaimer.
 #
 # 2. Redistributions in binary form must reproduce the above copyright notice,
 #    this list of conditions and the following disclaimer in the documentation
 #    and/or other materials provided with the distribution.
 #
 # THIS SOFTWARE IS PROVIDED ``AS IS'' AND ANY EXPRESS OR IMPLIED WARRANTIES,
 # INCLUDING, BUT NOT LIMITED TO, THE IMPLIED WARRANTIES OF MERCHANTABILITY
 # AND FITNESS FOR A PARTICULAR PURPOSE ARE DISCLAIMED. IN NO EVENT SHALL THE
 # AUTHOR BE LIABLE FOR ANY DIRECT, INDIRECT, INCIDENTAL, SPECIAL, EXEMPLARY,
 # OR CONSEQUENTIAL DAMAGES (INCLUDING, BUT NOT LIMITED TO, PROCUREMENT OF
 # SUBSTITUTE GOODS OR SERVICES; LOSS OF USE, DATA, OR PROFITS; OR BUSINESS
 # INTERRUPTION) HOWEVER CAUSED AND ON ANY THEORY OF LIABILITY, WHETHER IN
 # CONTRACT, STRICT LIABILITY, OR TORT (INCLUDING NEGLIGENCE OR OTHERWISE)
 # ARISING IN ANY WAY OUT OF THE USE OF THIS SOFTWARE, EVEN IF ADVISED OF THE
 # POSSIBILITY OF SUCH DAMAGE.
 #}

<script>
    $(document).ready(function() {
        function esc(value) {
            return $('<div/>').text(value === undefined || value === null ? '' : value).html();
        }

        function loadGateways() {
            ajaxGet('/api/gwswitch/clients/gateways', {}, function(data) {
                const $body = $('#gateway_status tbody').empty();
                if (!data.rows || data.rows.length === 0) {
                    $body.append($('<tr/>').append($('<td colspan="4"/>').text("{{ lang._('No active profiles.') }}")));
                    return;
                }
                data.rows.forEach(function(row) {
                    const good = row.status === 'none';
                    $body.append($('<tr/>')
                        .append($('<td/>').text(row.profile))
                        .append($('<td/>').text(row.gateway))
                        .append($('<td/>').html(
                            '<span class="fa fa-circle ' + (good ? 'text-success' : 'text-danger') + '"></span> ' + esc(row.status_translated)
                        ))
                        .append($('<td/>').text(row.delay + ' / ' + row.loss))
                    );
                });
            });
        }

        function loadClients() {
            ajaxGet('/api/gwswitch/clients/search', {}, function(data) {
                $('#clients_disabled').toggle(!data.enabled);
                const $body = $('#client_list tbody').empty();
                (data.rows || []).forEach(function(row) {
                    const $select = $('<select class="form-control gwswitch-profile" style="width: auto;"/>')
                        .attr('data-mac', row.mac);
                    (data.profiles || []).forEach(function(profile) {
                        $select.append($('<option/>').val(profile.uuid).text(profile.name));
                    });
                    $select.val(row.profile);
                    const name = row.hostname || row.description || '';
                    $body.append($('<tr/>')
                        .toggleClass('text-muted', !row.online)
                        .append($('<td/>').html(
                            '<span class="fa fa-circle ' + (row.online ? 'text-success' : 'text-muted') + '"></span> ' + esc(name)
                        ))
                        .append($('<td/>').text(row.mac))
                        .append($('<td/>').text(row.interface))
                        .append($('<td/>').html(esc(row.ipv4) + (row.ipv6 ? '<br/><small>' + esc(row.ipv6) + '</small>' : '')))
                        .append($('<td/>').append($select))
                    );
                });
            });
        }

        $('#client_list').on('change', '.gwswitch-profile', function() {
            const $select = $(this);
            $select.prop('disabled', true);
            ajaxCall('/api/gwswitch/clients/switch', {'mac': $select.data('mac'), 'profile': $select.val()}, function(data, status) {
                $select.prop('disabled', false);
                if (data.status === 'ok') {
                    let message = "{{ lang._('Switched %s, moved %d addresses and dropped %d connections.') }}"
                        .replace('%s', data.mac)
                        .replace('%d', data.addresses.length)
                        .replace('%d', data.killed_states);
                    if (data.messages && data.messages.length) {
                        message += '<br/>' + data.messages.map(esc).join('<br/>');
                    }
                    $('#switch_result').removeClass('hidden alert-danger').addClass('alert-info').html(message);
                } else {
                    $('#switch_result').removeClass('hidden alert-info').addClass('alert-danger')
                        .text(data.errorMessage || "{{ lang._('Switching failed, see the system log.') }}");
                    loadClients();
                }
                $("#{{formGridClient['table_id']}}").bootgrid('reload');
            });
        });

        $('#reload_clients').click(function() {
            loadClients();
            loadGateways();
        });

        mapDataToFormUI({'frm_general': '/api/gwswitch/settings/get'}).done(function() {
            formatTokenizersUI();
            $('.selectpicker').selectpicker('refresh');
            updateServiceControlUI('gwswitch');
        });

        $("#{{formGridProfile['table_id']}}").UIBootgrid({
            search: '/api/gwswitch/settings/search_profile/',
            get: '/api/gwswitch/settings/get_profile/',
            set: '/api/gwswitch/settings/set_profile/',
            add: '/api/gwswitch/settings/add_profile/',
            del: '/api/gwswitch/settings/del_profile/',
            toggle: '/api/gwswitch/settings/toggle_profile/'
        });

        $("#{{formGridClient['table_id']}}").UIBootgrid({
            search: '/api/gwswitch/settings/search_client/',
            get: '/api/gwswitch/settings/get_client/',
            set: '/api/gwswitch/settings/set_client/',
            add: '/api/gwswitch/settings/add_client/',
            del: '/api/gwswitch/settings/del_client/',
            toggle: '/api/gwswitch/settings/toggle_client/'
        });

        $('#reconfigureAct').SimpleActionButton({
            onPreAction: function() {
                const dfObj = $.Deferred();
                saveFormToEndpoint('/api/gwswitch/settings/set', 'frm_general', dfObj.resolve, true, dfObj.reject);
                return dfObj;
            },
            onAction: function(data) {
                if (data.messages && data.messages.length) {
                    BootstrapDialog.show({
                        type: BootstrapDialog.TYPE_INFO,
                        title: "{{ lang._('Gateway switcher') }}",
                        message: data.messages.map(esc).join('<br/>')
                    });
                }
                updateServiceControlUI('gwswitch');
                loadClients();
                loadGateways();
            }
        });

        loadClients();
        loadGateways();
    });
</script>

<ul class="nav nav-tabs" data-tabs="tabs" id="maintabs">
    <li class="active"><a data-toggle="tab" href="#clients">{{ lang._('Clients') }}</a></li>
    <li><a data-toggle="tab" href="#profiles">{{ lang._('Profiles') }}</a></li>
    <li><a data-toggle="tab" href="#assignments">{{ lang._('Assignments') }}</a></li>
    <li><a data-toggle="tab" href="#general">{{ lang._('Settings') }}</a></li>
</ul>

<div class="tab-content content-box">
    <div id="clients" class="tab-pane fade in active">
        <div class="alert alert-warning" id="clients_disabled" style="display: none; margin: 10px;">
            {{ lang._('The gateway switcher is disabled. Enable it under Settings, add a profile and press Apply.') }}
        </div>
        <div class="alert alert-info hidden" id="switch_result" style="margin: 10px;"></div>
        <table class="table table-condensed" id="gateway_status">
            <thead>
                <tr>
                    <th>{{ lang._('Profile') }}</th>
                    <th>{{ lang._('Gateway') }}</th>
                    <th>{{ lang._('Status') }}</th>
                    <th>{{ lang._('Delay / loss') }}</th>
                </tr>
            </thead>
            <tbody></tbody>
        </table>
        <table class="table table-condensed table-hover table-striped" id="client_list">
            <thead>
                <tr>
                    <th>{{ lang._('Host') }}</th>
                    <th>{{ lang._('MAC address') }}</th>
                    <th>{{ lang._('Interface') }}</th>
                    <th>{{ lang._('Addresses') }}</th>
                    <th>{{ lang._('Gateway') }}</th>
                </tr>
            </thead>
            <tbody></tbody>
            <tfoot>
                <tr>
                    <td colspan="5">
                        <button class="btn btn-xs btn-default" id="reload_clients" type="button">
                            <span class="fa fa-refresh fa-fw"></span> {{ lang._('Refresh') }}
                        </button>
                    </td>
                </tr>
            </tfoot>
        </table>
    </div>
    <div id="profiles" class="tab-pane fade in">
        {{ partial('layout_partials/base_bootgrid_table', formGridProfile) }}
    </div>
    <div id="assignments" class="tab-pane fade in">
        {{ partial('layout_partials/base_bootgrid_table', formGridClient) }}
    </div>
    <div id="general" class="tab-pane fade in">
        {{ partial('layout_partials/base_form', ['fields': generalForm, 'id': 'frm_general']) }}
    </div>
</div>

{{ partial('layout_partials/base_apply_button', {'data_endpoint': '/api/gwswitch/service/reconfigure', 'data_service_widget': 'gwswitch'}) }}
{{ partial('layout_partials/base_dialog', ['fields': formDialogProfile, 'id': formGridProfile['edit_dialog_id'], 'label': lang._('Edit profile')]) }}
{{ partial('layout_partials/base_dialog', ['fields': formDialogClient, 'id': formGridClient['edit_dialog_id'], 'label': lang._('Edit assignment')]) }}
