<?php
declare(strict_types=1);
require dirname(__DIR__) . '/src/Core.php';

use WolWebui\Network;
use WolWebui\Resolver;
use WolWebui\Sender;
use WolWebui\Store;

$tests = 0;
function verify(bool $ok, string $why): void
{
    global $tests;
    $tests++;
    if (!$ok) { throw new RuntimeException('FAIL: ' . $why); }
}
function fails(callable $f): bool
{
    try { $f(); } catch (RuntimeException) { return true; }
    return false;
}
$nets = Network::networks(['networks' => [
    ['cidr' => '192.168.10.0/24', 'label' => 'LAN'],
    ['cidr' => '192.168.101.0/24', 'label' => 'VLAN 101'],
]]);
verify($nets['192.168.10.0/24']['broadcast'] === '192.168.10.255', 'broadcast /24');
verify(Network::cidr('10.2.0.0/20')['broadcast'] === '10.2.15.255', 'broadcast /20');
verify(fails(fn() => Network::cidr('192.168.10.1/24')), 'reject non-network address');
verify(Network::contains($nets['192.168.101.0/24'], '192.168.101.22', true), 'valid LAN host');
verify(!Network::contains($nets['192.168.101.0/24'], '192.168.10.22', true), 'deny other subnet');
verify(!Network::contains($nets['192.168.101.0/24'], '192.168.101.255', true), 'deny broadcast host');
verify(Network::mac('02-aa-bb-cc-dd-ee') === '02:AA:BB:CC:DD:EE', 'MAC normalize');
verify(fails(fn() => Network::mac('01:00:5E:00:00:01')), 'deny multicast MAC');
verify(fails(fn() => Network::mac('invalid')), 'reject MAC');
$packet = Network::packet('02:AA:BB:CC:DD:EE');
verify(strlen($packet) === 102 && substr($packet, 0, 6) === str_repeat("\xff", 6), 'magic packet header');
verify(substr($packet, 6, 6) === hex2bin('02aabbccddee'), 'magic packet payload');
$input = ['name' => '  Test node  ', 'mac' => '02:AA:BB:CC:DD:EE', 'ip' => '192.168.10.21',
    'network_cidr' => '192.168.10.0/24', 'mode' => 'both', 'port' => '9'];
$data = Network::machine($input, $nets);
verify($data['name'] === 'Test node' && $data['port'] === 9, 'machine normalized');
verify(fails(fn() => Network::machine(array_merge($input, ['ip' => '1.1.1.1']), $nets)), 'deny foreign network');
verify(fails(fn() => Network::machine(array_merge($input, ['port' => '0']), $nets)), 'deny port 0');
verify(Network::machine(array_merge($input, ['ip' => '']), $nets)['mode'] === 'broadcast', 'MAC-only fallback');
$tmp = tempnam(sys_get_temp_dir(), 'wol-arp-test');
file_put_contents($tmp, "IP address HW type Flags HW address Mask Device\n192.168.10.21 0x1 0x2 02:aa:bb:cc:dd:ee * eth0\n192.168.101.22 0x1 0x0 02:aa:bb:cc:dd:ee * eth1\n");
verify(Resolver::find($data['mac'], $nets['192.168.10.0/24'], $tmp) === '192.168.10.21', 'ARP lookup');
verify(Resolver::find($data['mac'], $nets['192.168.101.0/24'], $tmp) === null, 'ARP ignores incomplete');
unlink($tmp);
// Socket smoke test without real wakeable devices; send only to loopback.
if (extension_loaded('sockets')) {
    $server = socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);
    socket_bind($server, '127.0.0.1', 0);
    socket_getsockname($server, $address, $port);
    $loop = Network::cidr('127.0.0.0/8');
    $loop['bind_ip'] = '';
    $result = (new Sender())->send(['mac' => $data['mac'], 'ip' => '127.0.0.1', 'mode' => 'unicast', 'port' => $port], $loop);
    socket_set_option($server, SOL_SOCKET, SO_RCVTIMEO, ['sec' => 1, 'usec' => 0]);
    $from = ''; $fromPort = 0; $recv = '';
    $bytes = socket_recvfrom($server, $recv, 200, 0, $from, $fromPort);
    verify($result['sent'] && $bytes === 102 && $recv === $packet, 'UDP socket delivery');
    socket_close($server);
}
if (in_array('sqlite', PDO::getAvailableDrivers(), true)) {
    $dir = sys_get_temp_dir() . '/wol-test-' . bin2hex(random_bytes(5));
    mkdir($dir, 0700);
    $store = new Store($dir . '/db.sqlite');
    verify(count($store->all()) === 0, 'empty registration');
    $store->save(null, $data);
    $rows = $store->all();
    verify(count($rows) === 1, 'save machine');
    $id = (int) $rows[0]['id'];
    $store->save($id, array_merge($data, ['name' => 'Renamed']));
    verify($store->one($id)['name'] === 'Renamed', 'edit machine');
    $reservation = $store->reserve($store->one($id));
    verify(fails(fn() => $store->reserve($store->one($id))), 'rate limit');
    $store->complete($reservation, ['sent' => true, 'destination' => '192.168.10.255', 'detail' => '2/2 packets sent']);
    verify(count($store->history()) === 1 && $store->history()[0]['outcome'] === 'sent', 'history');
    $store->delete($id);
    verify($store->all() === [] && count($store->history()) === 1, 'delete preserves history');
    $store->clearHistory();
    verify($store->history() === [], 'clear history');
    unlink($dir . '/db.sqlite'); rmdir($dir);
} else {
    fwrite(STDOUT, "SKIP: database tests (pdo_sqlite not installed)\n");
}
fwrite(STDOUT, "OK: {$tests} assertions\n");
