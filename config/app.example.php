<?php
/** Copy to config/local.php; NEVER place the database or this file under public/. */
return [
    'database' => '/var/lib/wol-webui/wol.sqlite',
    // Optional: requires fping, probes at most /24 on user-requested IP lookup.
    'active_discovery' => false,
    // Add only directly reachable LANs. A broadcast packet is not forwarded by Tailscale.
    // bind_ip can be left empty for OS routing, or set to the WOL server's IP on that LAN.
    'networks' => [
        ['cidr' => '192.168.10.0/24', 'label' => 'LAN', 'bind_ip' => ''],
        ['cidr' => '192.168.101.0/24', 'label' => 'VLAN 101', 'bind_ip' => ''],
    ],
];
