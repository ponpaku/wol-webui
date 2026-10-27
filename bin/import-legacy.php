<?php
declare(strict_types=1);

use WolWebui\Network;
use WolWebui\Store;

if (PHP_SAPI !== 'cli') { exit(1); }
require dirname(__DIR__) . '/src/Core.php';
if ($argc !== 2 || !is_file($argv[1])) {
    fwrite(STDERR, "Usage: php bin/import-legacy.php /secure/location/old-config.php\n");
    exit(2);
}
$configPath = getenv('WOL_CONFIG') ?: dirname(__DIR__) . '/config/local.php';
$config = require $configPath;
$nets = Network::networks($config);
$store = new Store($config['database']);
// The legacy config is executable PHP. ONLY run this on a trusted private copy.
$pc_list = null;
require $argv[1];
if (!is_array($pc_list)) { fwrite(STDERR, "Legacy config has no pc_list array.\n"); exit(2); }
$existing = [];
foreach ($store->all() as $m) { $existing[$m['mac']] = true; }
$added = 0; $skipped = 0;
foreach ($pc_list as $entry) {
    try {
        if (!is_array($entry)) { throw new RuntimeException('Not a machine object'); }
        $ip = (string) ($entry['ip'] ?? '');
        $matching = array_filter($nets, fn($n) => Network::contains($n, $ip, true));
        if (count($matching) !== 1) { throw new RuntimeException('No unique matching subnet'); }
        $data = Network::machine(['name' => $entry['hostname'] ?? '', 'ip' => $ip,
            'mac' => $entry['mac'] ?? '', 'network_cidr' => array_key_first($matching),
            'mode' => 'both', 'port' => '9'], $nets);
        if (isset($existing[$data['mac']])) { $skipped++; continue; }
        $store->save(null, $data);
        $existing[$data['mac']] = true;
        $added++;
    } catch (Throwable $e) {
        $skipped++;
        fwrite(STDERR, 'Skipped legacy entry (check subnet/MAC/name). Reason: ' . $e->getMessage() . "\n");
    }
}
fwrite(STDOUT, "Imported {$added}; skipped {$skipped}. Existing entries were not modified.\n");
