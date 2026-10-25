# os-gwswitch: per-client gateway switcher for OPNsense

An OPNsense plugin that lets you choose, per device, which gateway its internet traffic uses. For example, you can send one laptop through a WireGuard VPN while the rest of the network keeps using WAN. Switching is one dropdown in the GUI or one API call.

- **No static IPs.** Clients are matched by MAC address through OPNsense MAC aliases, so they keep using DHCP (Kea) and SLAAC.
- **IPv6 handled per profile.** IPv6 is either routed through the tunnel's IPv6 gateway (with NAT66) or rejected for the switched client only, so apps fall back to IPv4 at once.
- **No leaks when the tunnel is down.** A switched client loses internet instead of falling back to WAN.
- **Takes effect at once.** Open connections are dropped on switch and reconnect through the new gateway. A watcher picks up new addresses (IPv6 privacy addresses, DHCP changes) within a few seconds.

The design is written up in the [design document](https://claude.ai/artifact/GEqrpP6RT8MfmTjZxaC5f2).

Targets OPNsense 26.7 (MVC firewall rules, Kea DHCP).

## How it works

You define **profiles** (for example `vpn1` → gateway `WG_VPN_GW`) and **assign clients** to them. On *Apply*, the plugin creates and maintains these firewall objects. Each one's description starts with `[gwswitch]`, and the plugin leaves every other object alone:

| Object | Purpose |
| --- | --- |
| Alias `gwsw_<profile>` (type MAC) | MAC addresses of the clients in the profile. OPNsense resolves it to their current IPv4 and IPv6 addresses. |
| Alias `gwsw_local` (networks) | Private, link-local and multicast ranges. This traffic is never sent into a tunnel. |
| LAN rule: pass, gateway = profile gateway, tag `GWSW_<PROFILE>` | Policy-routes the client's IPv4 (and IPv6 in `route` mode). |
| LAN rule: reject IPv6 | Only in `reject` mode. Suppresses IPv6 for these clients. |
| LAN rule: block | Kill switch. Matches when the gateway is down and its pass rule is skipped. |
| WAN rule: block out, tagged `GWSW_<PROFILE>` | A second safety net. Tunnel traffic can never leave through WAN. |
| Outbound NAT on the tunnel interface | Translates clients to the tunnel address, for IPv4 and NAT66 for IPv6. |

The plugin also turns on *Firewall → Settings → Advanced → Skip rules when gateway is down* (you can opt out) and switches outbound NAT from automatic to hybrid mode when needed. The LAN rules are placed before your existing rules on the same interface. If necessary, your rules are moved down as a block, so their order relative to each other stays the same.

Switching a client only rewrites the alias contents, never the rules. It then reloads the affected pf tables and drops the client's states.

## Install

### Build a package (recommended)

On the firewall (or any OPNsense build host):

```sh
opnsense-code plugins            # checks out /usr/plugins
fetch -o - https://github.com/Tabisch/opnsense-vpn-switcher/archive/refs/heads/main.tar.gz | tar -xf - -C /tmp
cp -R /tmp/opnsense-vpn-switcher-main/net/gwswitch /usr/plugins/net/
cd /usr/plugins/net/gwswitch && make package
pkg add work/pkg/os-gwswitch-*.pkg
```

### Quick install for testing

From a machine with SSH access to the firewall:

```sh
scripts/install-dev.sh root@192.168.1.1
```

This copies the files into `/usr/local` and restarts configd. To remove it, delete the same files again. A package is cleaner.

## Setup

1. Create the WireGuard instance and peer as usual, assign the `wg` interface, and add a gateway for it (*System → Gateways*), with monitoring enabled. Do the same for IPv6 if your provider gives you an IPv6 tunnel address. In that case, add `::/0` to the peer's allowed IPs.
2. Open *Services → Gateway switcher*:
   - **Settings**: enable it, pick the client interfaces (LAN, VLANs) and the WAN interfaces.
   - **Profiles**: add one per tunnel. Pick the IPv4 gateway, and choose whether IPv6 is routed (pick the IPv6 gateway) or rejected.
   - Press **Apply**.
3. On the **Clients** tab, pick a profile for any device from its dropdown. The change is live immediately.

Devices with private Wi-Fi MAC addresses work as long as the private address stays fixed for your network. That is the default on current iOS, Android and Windows. Turn off "rotating" or "non-persistent" modes for that network.

## API

Everything in the GUI goes through the API, so you can switch from scripts or Home Assistant with an OPNsense API key that has the *Services: Gateway switcher* privilege:

```sh
# move a client into profile vpn1 (name or uuid)
curl -u "$KEY:$SECRET" -X POST https://fw/api/gwswitch/clients/switch \
     -H 'Content-Type: application/json' -d '{"mac": "aa:bb:cc:dd:ee:01", "profile": "vpn1"}'

# back to the default gateway
curl -u "$KEY:$SECRET" -X POST https://fw/api/gwswitch/clients/switch \
     -H 'Content-Type: application/json' -d '{"mac": "aa:bb:cc:dd:ee:01", "profile": ""}'

curl -u "$KEY:$SECRET" https://fw/api/gwswitch/clients/search     # hosts and their profile
curl -u "$KEY:$SECRET" https://fw/api/gwswitch/clients/gateways   # gateway health per profile
```

## Limitations

- **IPv6 privacy addresses.** A device creates a new temporary IPv6 address about once a day. Traffic from that address goes out the default gateway until the watcher adds it to the alias, which takes up to the watcher interval (5 s by default). Turn off temporary addresses on devices that must never leak, or put them on their own VLAN.
- **MAC matching is not a security boundary.** A device can change its MAC and leave the profile.
- **Your own floating rules run first.** Floating rules are evaluated before interface rules, so a floating `pass quick` rule you created yourself can take precedence.
- **DNS.** Clients keep using the firewall's resolver, which resolves over WAN. Point them at the provider's DNS if that matters to you.
- **Gateway groups** can be used as the profile gateway, but outbound NAT then has to be added by hand.

## Development

```text
net/gwswitch/                 plugin in the opnsense/plugins layout (Makefile, pkg-descr, src/)
  src/opnsense/mvc/app/       model, API and GUI controllers, Manager (firewall object sync), view
  src/opnsense/scripts/       refresh.py (switch fast path), hosts.py (host list), watcher.py (daemon)
  src/opnsense/service/       configd actions and templates
  src/etc/                    rc.d script for the watcher, plugin hooks
tests/                        Python unit tests, PHP checks against the real OPNsense models
```

```sh
python3 -m unittest discover -s tests      # backend scripts and templates (needs jinja2)
tests/php/run.sh                           # Manager against opnsense/core master (needs php-cli)
```

`tests/php/run.sh` checks out the OPNsense MVC framework and runs the Manager against the real Alias and Filter models. It also renders the resulting pf rules with OPNsense's own rule generator and checks their order, the kill switch and the NAT.
