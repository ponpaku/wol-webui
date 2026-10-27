<?php
declare(strict_types=1);

use WolWebui\Network;
use WolWebui\Resolver;
use WolWebui\Sender;
use WolWebui\Store;

require dirname(__DIR__) . '/src/Core.php';

ini_set('display_errors', '0');
ini_set('log_errors', '1');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: no-referrer');
header('Cache-Control: no-store');
header("Content-Security-Policy: default-src 'none'; style-src 'self'; script-src 'self'; img-src 'self'; form-action 'self'; base-uri 'none'; frame-ancestors 'none'");

session_name('wol_session');
session_set_cookie_params(['lifetime' => 0, 'path' => '/', 'secure' => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'), 'httponly' => true, 'samesite' => 'Strict']);
ini_set('session.use_strict_mode', '1');
session_start();
$_SESSION['csrf'] ??= bin2hex(random_bytes(32));

function esc(mixed $value): string { return htmlspecialchars((string) ($value ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function url(string $path = ''): string { return './' . $path; }
function redirect(string $message, string $type = 'ok'): never
{
    $_SESSION['flash'] = [$message, $type];
    header('Location: ./', true, 303);
    exit;
}
function intId(mixed $value): int
{
    if (!is_string($value) && !is_int($value)) { throw new RuntimeException('機器IDが不正です。'); }
    $id = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if ($id === false) { throw new RuntimeException('機器IDが不正です。'); }
    return (int) $id;
}

$error = null;
$nets = [];
$machines = [];
$history = [];
$store = null;
$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);
$view = in_array($_GET['view'] ?? '', ['history', 'new', 'edit', 'delete', 'clear'], true) ? $_GET['view'] : 'home';
$editing = null;
$defaults = ['name' => '', 'mac' => '', 'ip' => '', 'network_cidr' => '', 'mode' => 'both', 'port' => 9];
try {
    $configPath = getenv('WOL_CONFIG') ?: dirname(__DIR__) . '/config/local.php';
    if (!is_file($configPath)) { throw new RuntimeException('config/local.php がありません。READMEの導入手順を参照してください。'); }
    $config = require $configPath;
    if (!is_array($config)) { throw new RuntimeException('設定ファイルが不正です。'); }
    $nets = Network::networks($config);
    $defaults['network_cidr'] = array_key_first($nets);
    $store = new Store((string) ($config['database'] ?? ''));
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!hash_equals((string) $_SESSION['csrf'], (string) ($_POST['csrf'] ?? ''))) {
            throw new RuntimeException('画面の有効期限が切れました。再読み込みしてください。');
        }
        $action = (string) ($_POST['action'] ?? '');
        if ($action === 'save') {
            $id = ($_POST['id'] ?? '') === '' ? null : intId($_POST['id']);
            if ($id !== null && $store->one($id) === null) { throw new RuntimeException('機器が見つかりません。'); }
            $store->save($id, Network::machine($_POST, $nets));
            redirect($id ? '機器情報を更新しました。' : '機器を登録しました。');
        }
        if (in_array($action, ['delete', 'wake', 'resolve'], true)) {
            $id = intId($_POST['id'] ?? null);
            $machine = $store->one($id);
            if ($machine === null || !isset($nets[$machine['network_cidr']])) {
                throw new RuntimeException('機器が見つからないか、ネットワーク設定が変更されています。');
            }
            if ($action === 'delete') {
                $store->delete($id);
                redirect('登録を削除しました。');
            }
            if ($action === 'resolve') {
                $ip = Resolver::find($machine['mac'], $nets[$machine['network_cidr']]);
                if ($ip === null) { redirect('IPを取得できませんでした。ARPキャッシュに記録がありません。', 'warning'); }
                $store->updateIp($id, $ip);
                redirect('ARPキャッシュからIP ' . $ip . ' を取得しました。');
            }
            // Re-validate stored addresses before sending, e.g. after changing allowed networks.
            $machine = array_merge($machine, Network::machine($machine, $nets));
            $reservation = $store->reserve($machine);
            try {
                $result = (new Sender())->send($machine, $nets[$machine['network_cidr']]);
            } catch (Throwable $e) {
                error_log('WOL send: ' . $e->getMessage());
                $result = ['sent' => false, 'destination' => '', 'detail' => '送信処理に失敗しました。'];
            }
            $store->complete($reservation, $result);
            redirect($result['sent'] ? 'UDP Magic Packetを送信しました（PCの起動は未確認）。' : 'UDP送信に失敗しました。履歴を確認してください。', $result['sent'] ? 'ok' : 'warning');
        }
        if ($action === 'clear') {
            $store->clearHistory();
            redirect('送信履歴を消去しました。');
        }
        throw new RuntimeException('操作が不正です。');
    }
    if (in_array($view, ['edit', 'delete'], true)) {
        $editing = $store->one(intId($_GET['id'] ?? null));
        if ($editing === null) { throw new RuntimeException('機器が見つかりません。'); }
    }
    $machines = $store->all();
    if ($view === 'history') { $history = $store->history(); }
} catch (Throwable $e) {
    $error = $e->getMessage();
    // Infrastructure details belong in Apache's error log, not the web response.
    if (!($e instanceof RuntimeException)) { error_log('WOL error: ' . $e); $error = '処理に失敗しました。サーバーログを確認してください。'; }
    http_response_code($_SERVER['REQUEST_METHOD'] === 'POST' ? 400 : 500);
}
?>
<!doctype html>
<html lang="ja"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="color-scheme" content="light"><title>WOL — Wake on LAN</title><link rel="stylesheet" href="assets/app.css"></head>
<body>
<div class="shell">
<header class="site-header">
 <a href="./" class="brand" aria-label="WOL ホーム"><span class="brand-mark">↗</span><span>WOL <small>WEB CONSOLE</small></span></a>
 <nav aria-label="メインメニュー"><a class="<?= $view === 'history' ? 'active' : '' ?>" href="?view=history">送信履歴</a><a class="<?= $view !== 'history' ? 'active' : '' ?>" href="./">マシン一覧</a></nav>
</header>
<main>
 <?php if ($error !== null): ?><div class="notice danger" role="alert"><?= esc($error) ?></div><?php endif; ?>
 <?php if ($flash !== null): ?><div class="notice <?= $flash[1] === 'ok' ? 'success' : 'warn' ?>" role="status"><?= esc($flash[0]) ?></div><?php endif; ?>
 <?php if ($view === 'history'): ?>
  <div class="page-heading"><div><div class="eyebrow">ACTIVITY</div><h1>送信履歴</h1><p>直近100件の送信処理を表示します。送信成功は起動確認ではありません。</p></div><a class="button secondary" href="./">一覧へ戻る</a></div>
  <section class="surface"><div class="table-scroll"><table><thead><tr><th>日時 (JST)</th><th>マシン</th><th>送信先</th><th>方式</th><th>結果</th></tr></thead><tbody>
  <?php foreach ($history as $item): ?><tr><td class="nowrap"><?= esc((new DateTimeImmutable($item['created_at']))->setTimezone(new DateTimeZone('Asia/Tokyo'))->format('Y/m/d H:i:s')) ?></td><td><strong><?= esc($item['machine_name']) ?></strong><div class="muted code"><?= esc($item['mac']) ?></div></td><td class="code small"><?= esc($item['destination'] ?: '—') ?>:<?= esc($item['port']) ?></td><td><?= esc($item['mode']) ?></td><td><span class="state <?= $item['outcome'] === 'sent' ? 'state-ok' : ($item['outcome'] === 'error' ? 'state-error' : '') ?>"><?= esc(['sent' => 'UDP送信', 'error' => '失敗', 'pending' => '処理中'][$item['outcome']] ?? $item['outcome']) ?></span><div class="muted small"><?= esc($item['detail']) ?></div></td></tr><?php endforeach; ?>
  <?php if (!$history): ?><tr><td colspan="5" class="empty-cell">履歴はまだありません。</td></tr><?php endif; ?></tbody></table></div></section>
  <?php if ($history): ?><div class="footer-actions"><a href="?view=clear" class="text-link danger-text">履歴を消去</a></div><?php endif; ?>
 <?php elseif ($view === 'clear'): ?>
  <div class="page-heading"><div><div class="eyebrow">ACTIVITY</div><h1>履歴の消去</h1><p>送信履歴がすべて消去されます。この操作は取り消せません。</p></div></div>
  <section class="surface form-surface"><form method="post"><input type="hidden" name="csrf" value="<?= esc($_SESSION['csrf']) ?>"><input type="hidden" name="action" value="clear"><div class="form-actions"><a class="button secondary" href="?view=history">キャンセル</a><button class="button destructive" type="submit">すべて消去する</button></div></form></section>
 <?php elseif ($view === 'delete' && $editing): ?>
  <div class="page-heading"><div><div class="eyebrow">MANAGE</div><h1>マシンを削除</h1><p>「<?= esc($editing['name']) ?>」の登録を削除します。過去の履歴は残ります。</p></div></div>
  <section class="surface form-surface"><form method="post"><input type="hidden" name="csrf" value="<?= esc($_SESSION['csrf']) ?>"><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= esc($editing['id']) ?>"><div class="form-actions"><a class="button secondary" href="./">キャンセル</a><button class="button destructive" type="submit">削除する</button></div></form></section>
 <?php elseif ($view === 'new' || ($view === 'edit' && $editing)): ?>
  <?php $form = $editing ?? $defaults; ?>
  <div class="page-heading"><div><div class="eyebrow">MANAGE</div><h1><?= $editing ? 'マシンを編集' : 'マシンを追加' ?></h1><p>IPは空欄でも登録できます。その場合はブロードキャストのみ送信します。</p></div></div>
  <section class="surface form-surface"><form method="post"><input type="hidden" name="csrf" value="<?= esc($_SESSION['csrf']) ?>"><input type="hidden" name="action" value="save"><input type="hidden" name="id" value="<?= esc($editing['id'] ?? '') ?>">
   <div class="form-grid"><label class="field full"><span>マシン名 <i>必須</i></span><input name="name" maxlength="120" required value="<?= esc($form['name']) ?>" placeholder="例: Desktop PC" autofocus></label>
   <label class="field"><span>MACアドレス <i>必須</i></span><input name="mac" spellcheck="false" required value="<?= esc($form['mac']) ?>" placeholder="AA:BB:CC:DD:EE:02"><small>コロン・ハイフン区切り、または12桁</small></label>
   <label class="field"><span>IPアドレス <em>任意</em></span><input name="ip" inputmode="decimal" spellcheck="false" value="<?= esc($form['ip']) ?>" placeholder="192.168.10.20"></label>
   <label class="field full"><span>ネットワーク <i>必須</i></span><select name="network_cidr" required><?php foreach ($nets as $net): ?><option value="<?= esc($net['cidr']) ?>" <?= $form['network_cidr'] === $net['cidr'] ? 'selected' : '' ?>><?= esc($net['label']) ?> (<?= esc($net['cidr']) ?>)</option><?php endforeach; ?></select><small>対象PCと同じL2セグメントにWOLサーバーのNICが必要です。</small></label>
   <label class="field"><span>送信方式</span><select name="mode"><option value="both" <?= $form['mode'] === 'both' ? 'selected' : '' ?>>Broadcast + Unicast</option><option value="broadcast" <?= $form['mode'] === 'broadcast' ? 'selected' : '' ?>>Broadcastのみ</option><option value="unicast" <?= $form['mode'] === 'unicast' ? 'selected' : '' ?>>Unicastのみ</option></select></label>
   <label class="field"><span>UDPポート</span><input name="port" type="number" min="1" max="65535" required value="<?= esc($form['port']) ?>"><small>通常は9</small></label></div>
   <div class="form-actions"><a class="button secondary" href="./">キャンセル</a><button class="button primary" type="submit"><?= $editing ? '変更を保存' : 'マシンを登録' ?></button></div>
  </form></section>
 <?php else: ?>
  <div class="page-heading"><div><div class="eyebrow">DEVICES / <?= count($machines) ?> REGISTERED</div><h1>マシン一覧</h1><p>登録したマシンだけを表示します。起動操作はMagic Packetの送信です。</p></div><a class="button primary" href="?view=new"><span aria-hidden="true">＋</span> マシンを追加</a></div>
  <?php if (!$machines): ?><div class="surface empty"><div class="empty-icon">⌁</div><h2>マシンはまだ登録されていません</h2><p>MACアドレスとネットワークを指定して、最初のマシンを追加してください。</p><a class="button primary" href="?view=new">最初のマシンを追加</a></div>
  <?php else: ?><section class="machine-list" aria-label="登録済みマシン">
  <?php foreach ($machines as $machine): ?>
    <article class="machine"><div class="machine-top"><div class="machine-avatar" aria-hidden="true">⌘</div><div class="machine-info"><h2><?= esc($machine['name']) ?></h2><div class="network-label"><?= esc($nets[$machine['network_cidr']]['label'] ?? $machine['network_cidr']) ?> <span>·</span> <?= esc($machine['network_cidr']) ?></div></div></div>
    <div class="machine-fields"><div><span class="metadata-label">IP ADDRESS</span><div class="value code"><?= esc($machine['ip'] ?: '未設定') ?></div></div><div><span class="metadata-label">MAC ADDRESS</span><div class="value code"><?= esc($machine['mac']) ?></div></div></div>
    <div class="machine-bottom"><div class="machine-tools"><a href="?view=edit&amp;id=<?= esc($machine['id']) ?>">編集</a><a href="?view=delete&amp;id=<?= esc($machine['id']) ?>">削除</a></div><div class="machine-actions"><form method="post"><input type="hidden" name="csrf" value="<?= esc($_SESSION['csrf']) ?>"><input type="hidden" name="action" value="resolve"><input type="hidden" name="id" value="<?= esc($machine['id']) ?>"><button type="submit" class="button secondary compact" title="LinuxのARPキャッシュからIPを探索">IP取得</button></form><form method="post"><input type="hidden" name="csrf" value="<?= esc($_SESSION['csrf']) ?>"><input type="hidden" name="action" value="wake"><input type="hidden" name="id" value="<?= esc($machine['id']) ?>"><button type="submit" class="button primary compact"><span aria-hidden="true">◉</span> 起動</button></form></div></div>
    </article>
  <?php endforeach; ?></section><?php endif; ?>
  <p class="hint">IP取得はWOLサーバーのARPキャッシュを参照します。未知のIPや停止中のPCは見つからない場合があります。</p>
 <?php endif; ?>
</main>
<footer class="site-footer"><span>WOL WEB CONSOLE</span><span>Local network only · No external dependencies</span></footer>
</div></body></html>
