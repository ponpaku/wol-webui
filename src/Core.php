<?php
declare(strict_types=1);

namespace WolWebui;

use PDO;
use RuntimeException;
use Throwable;

final class Network
{
    public static function mac(string $value): string
    {
        $hex = strtoupper(preg_replace('/[:-]/', '', trim($value)) ?? '');
        if (!preg_match('/^[0-9A-F]{12}$/D', $hex)) {
            throw new RuntimeException('MACアドレスは12桁の16進数で入力してください。');
        }
        $bytes = hex2bin($hex);
        if ($bytes === false || (ord($bytes[0]) & 1) || $hex === '000000000000') {
            throw new RuntimeException('ユニキャストMACアドレスを指定してください。');
        }
        return implode(':', str_split($hex, 2));
    }

    public static function cidr(string $cidr): array
    {
        if (!preg_match('~^([0-9.]+)/([0-9]{1,2})$~D', $cidr, $m)) {
            throw new RuntimeException('ネットワーク設定のCIDRが不正です。');
        }
        $prefix = (int) $m[2];
        if ($prefix < 1 || $prefix > 30 || !filter_var($m[1], FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            throw new RuntimeException('ネットワーク設定のCIDRが不正です。');
        }
        $ip = ip2long($m[1]);
        $mask = (0xffffffff << (32 - $prefix)) & 0xffffffff;
        if (($ip & $mask) !== $ip) {
            throw new RuntimeException('CIDRにはネットワークアドレスを指定してください。');
        }
        return ['cidr' => $cidr, 'network' => $ip, 'mask' => $mask,
            'broadcast' => long2ip($ip | (~$mask & 0xffffffff))];
    }

    public static function contains(array $net, string $ip, bool $hostOnly = false): bool
    {
        if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return false;
        }
        $address = ip2long($ip);
        if (($address & $net['mask']) !== $net['network']) {
            return false;
        }
        if ($hostOnly && ($address === $net['network'] || $ip === $net['broadcast'])) {
            return false;
        }
        return true;
    }

    public static function networks(array $config): array
    {
        $out = [];
        foreach (($config['networks'] ?? []) as $entry) {
            if (!is_array($entry) || !isset($entry['cidr'])) {
                throw new RuntimeException('ネットワーク設定が不正です。');
            }
            $net = self::cidr((string) $entry['cidr']);
            $net['label'] = trim((string) ($entry['label'] ?? $net['cidr']));
            $net['bind_ip'] = trim((string) ($entry['bind_ip'] ?? ''));
            if ($net['bind_ip'] !== '' && !self::contains($net, $net['bind_ip'], true)) {
                throw new RuntimeException('bind_ipは指定ネットワークのホストIPである必要があります。');
            }
            if (isset($out[$net['cidr']])) {
                throw new RuntimeException('CIDRの重複があります。');
            }
            $out[$net['cidr']] = $net;
        }
        if (!$out) {
            throw new RuntimeException('少なくとも1つの送信先ネットワークを設定してください。');
        }
        return $out;
    }

    public static function machine(array $input, array $nets): array
    {
        $name = trim((string) ($input['name'] ?? ''));
        if ($name === '' || strlen($name) > 120 || preg_match('/[\x00-\x1F\x7F]/', $name)) {
            throw new RuntimeException('名前は1〜120バイトの文字列で入力してください。');
        }
        $mac = self::mac((string) ($input['mac'] ?? ''));
        $cidr = (string) ($input['network_cidr'] ?? '');
        if (!isset($nets[$cidr])) {
            throw new RuntimeException('許可されているネットワークを選択してください。');
        }
        $ip = trim((string) ($input['ip'] ?? ''));
        if ($ip !== '' && !self::contains($nets[$cidr], $ip, true)) {
            throw new RuntimeException('IPアドレスが選択したネットワークの範囲外です。');
        }
        $mode = (string) ($input['mode'] ?? 'both');
        if (!in_array($mode, ['broadcast', 'unicast', 'both'], true)) {
            throw new RuntimeException('送信方式を選択してください。');
        }
        if ($ip === '' && $mode !== 'broadcast') {
            $mode = 'broadcast';
        }
        $portText = (string) ($input['port'] ?? '9');
        if (!preg_match('/^[0-9]{1,5}$/D', $portText) || (int) $portText < 1 || (int) $portText > 65535) {
            throw new RuntimeException('ポート番号は1〜65535で指定してください。');
        }
        return ['name' => $name, 'mac' => $mac, 'ip' => $ip === '' ? null : $ip,
            'network_cidr' => $cidr, 'mode' => $mode, 'port' => (int) $portText];
    }

    public static function packet(string $mac): string
    {
        $bytes = hex2bin(str_replace(':', '', self::mac($mac)));
        if ($bytes === false) {
            throw new RuntimeException('MACアドレスが不正です。');
        }
        return str_repeat("\xff", 6) . str_repeat($bytes, 16);
    }
}

final class Store
{
    private PDO $pdo;

    public function __construct(string $path)
    {
        $dir = dirname($path);
        if (!is_dir($dir) || !is_writable($dir)) {
            throw new RuntimeException('DBディレクトリがありません。設置手順を確認してください。');
        }
        if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            throw new RuntimeException('pdo_sqlite 拡張が必要です。');
        }
        $this->pdo = new PDO('sqlite:' . $path, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_TIMEOUT => 5,
        ]);
        $this->pdo->exec('PRAGMA busy_timeout=5000');
        $this->pdo->exec('PRAGMA foreign_keys=ON');
        $this->pdo->exec('CREATE TABLE IF NOT EXISTS machines (
          id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL,
          mac TEXT NOT NULL, ip TEXT, network_cidr TEXT NOT NULL,
          mode TEXT NOT NULL, port INTEGER NOT NULL, created_at TEXT NOT NULL,
          updated_at TEXT NOT NULL)');
        $this->pdo->exec('CREATE TABLE IF NOT EXISTS history (
          id INTEGER PRIMARY KEY AUTOINCREMENT, machine_id INTEGER,
          machine_name TEXT NOT NULL, mac TEXT NOT NULL, ip TEXT,
          destination TEXT NOT NULL, mode TEXT NOT NULL, port INTEGER NOT NULL,
          outcome TEXT NOT NULL, detail TEXT NOT NULL, created_at TEXT NOT NULL)');
        $this->pdo->exec('CREATE INDEX IF NOT EXISTS history_created ON history(created_at DESC)');
        $this->pdo->exec('CREATE INDEX IF NOT EXISTS history_machine ON history(machine_id, created_at DESC)');
    }

    public function all(): array
    {
        return $this->pdo->query('SELECT * FROM machines ORDER BY name COLLATE NOCASE, id')->fetchAll();
    }

    public function one(int $id): ?array
    {
        $s = $this->pdo->prepare('SELECT * FROM machines WHERE id = ?');
        $s->execute([$id]);
        return $s->fetch() ?: null;
    }

    public function save(?int $id, array $data): void
    {
        $now = gmdate('Y-m-d\TH:i:s\Z');
        if ($id === null) {
            $s = $this->pdo->prepare('INSERT INTO machines (name,mac,ip,network_cidr,mode,port,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?)');
            $s->execute([$data['name'], $data['mac'], $data['ip'], $data['network_cidr'], $data['mode'], $data['port'], $now, $now]);
        } else {
            $s = $this->pdo->prepare('UPDATE machines SET name=?,mac=?,ip=?,network_cidr=?,mode=?,port=?,updated_at=? WHERE id=?');
            $s->execute([$data['name'], $data['mac'], $data['ip'], $data['network_cidr'], $data['mode'], $data['port'], $now, $id]);
            if ($s->rowCount() === 0 && $this->one($id) === null) {
                throw new RuntimeException('機器が見つかりません。');
            }
        }
    }

    public function updateIp(int $id, string $ip): void
    {
        $s = $this->pdo->prepare('UPDATE machines SET ip=?,updated_at=? WHERE id=?');
        $s->execute([$ip, gmdate('Y-m-d\TH:i:s\Z'), $id]);
    }

    public function delete(int $id): void
    {
        $s = $this->pdo->prepare('DELETE FROM machines WHERE id=?');
        $s->execute([$id]);
    }

    public function history(): array
    {
        return $this->pdo->query('SELECT * FROM history ORDER BY id DESC LIMIT 100')->fetchAll();
    }

    public function clearHistory(): void
    {
        $this->pdo->exec('DELETE FROM history');
    }

    /** Reserve the action atomically so concurrent clicks cannot bypass throttling. */
    public function reserve(array $machine): int
    {
        $this->pdo->exec('BEGIN IMMEDIATE');
        try {
            $recent = $this->pdo->prepare("SELECT COUNT(*) FROM history WHERE machine_id=? AND created_at>=?");
            $recent->execute([(int) $machine['id'], gmdate('Y-m-d\TH:i:s\Z', time() - 5)]);
            $global = $this->pdo->prepare('SELECT COUNT(*) FROM history WHERE created_at>=?');
            $global->execute([gmdate('Y-m-d\TH:i:s\Z', time() - 60)]);
            if ((int) $recent->fetchColumn() > 0 || (int) $global->fetchColumn() >= 20) {
                throw new RuntimeException('連続送信制限中です。少し時間を空けて再試行してください。');
            }
            $s = $this->pdo->prepare('INSERT INTO history (machine_id,machine_name,mac,ip,destination,mode,port,outcome,detail,created_at) VALUES (?,?,?,?,?,?,?,?,?,?)');
            $s->execute([$machine['id'], $machine['name'], $machine['mac'], $machine['ip'], '', $machine['mode'], $machine['port'], 'pending', '送信処理中', gmdate('Y-m-d\TH:i:s\Z')]);
            $id = (int) $this->pdo->lastInsertId();
            $this->pdo->commit();
            return $id;
        } catch (Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    public function complete(int $id, array $result): void
    {
        $s = $this->pdo->prepare('UPDATE history SET destination=?,outcome=?,detail=? WHERE id=?');
        $s->execute([$result['destination'], $result['sent'] ? 'sent' : 'error', $result['detail'], $id]);
    }
}

final class Sender
{
    public function send(array $machine, array $net): array
    {
        $targets = [];
        if (in_array($machine['mode'], ['broadcast', 'both'], true)) {
            $targets[] = $net['broadcast'];
        }
        if (in_array($machine['mode'], ['unicast', 'both'], true) && !empty($machine['ip'])) {
            $targets[] = $machine['ip'];
        }
        $targets = array_unique($targets);
        if (!$targets) {
            throw new RuntimeException('送信先IPがありません。');
        }
        $packet = Network::packet($machine['mac']);
        $successful = 0;
        $errors = [];
        foreach ($targets as $target) {
            $socket = @socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);
            if ($socket === false) {
                $errors[] = $target . ': socket_create failed';
                continue;
            }
            try {
                if (!@socket_set_option($socket, SOL_SOCKET, SO_BROADCAST, 1)) {
                    $errors[] = $target . ': SO_BROADCAST failed';
                    continue;
                }
                if ($net['bind_ip'] !== '' && !@socket_bind($socket, $net['bind_ip'], 0)) {
                    $errors[] = $target . ': source IP bind failed';
                    continue;
                }
                for ($i = 0; $i < 2; $i++) {
                    $bytes = @socket_sendto($socket, $packet, strlen($packet), 0, $target, (int) $machine['port']);
                    if ($bytes === strlen($packet)) {
                        $successful++;
                    } else {
                        $errors[] = $target . ': UDP送信失敗';
                    }
                }
            } finally {
                socket_close($socket);
            }
        }
        $destinations = implode(', ', $targets);
        return ['sent' => $successful > 0, 'destination' => $destinations,
            'detail' => $successful . '/' . (count($targets) * 2) . ' packets sent' . ($errors ? ' / ' . implode('; ', $errors) : '')];
    }
}

final class Resolver
{
    /** Local kernel ARP cache only: cannot discover unknown/offline IP addresses. */
    public static function find(string $mac, array $net, string $arpFile = '/proc/net/arp'): ?string
    {
        if (!is_readable($arpFile)) {
            return null;
        }
        $lines = @file($arpFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if (!is_array($lines)) {
            return null;
        }
        foreach (array_slice($lines, 1) as $line) {
            $fields = preg_split('/\s+/', trim($line));
            if (!is_array($fields) || count($fields) < 6) {
                continue;
            }
            [$ip, , $flags, $entryMac] = $fields;
            if ((hexdec($flags) & 0x2) !== 0 && strtolower($entryMac) === strtolower($mac)
                && Network::contains($net, $ip, true)) {
                return $ip;
            }
        }
        return null;
    }
}
