# wol-webui

軽量な **Wake-on-LAN Webコンソール**。Ubuntu 24.04 LTS / Apache / PHP 8.3 向け。外部CDN、Composer、JavaScriptフレームワーク、認証システムは不要です。

> **LAN内専用。** パスワード認証はありません。インターネットへ公開しないでください。Tailscaleから使う場合も、アクセス制御はTailscale ACL／ホストファイアウォールで実施してください。

## 機能

- 登録した機器のみ一覧表示。**初期データはゼロ件**。
- 名前・MAC・IPv4・ネットワーク・WOL方式・UDPポートをWeb画面から追加／編集／削除。
- **Magic Packet（102バイト）**をUDP送信。ネットワークCIDRに基づきブロードキャストアドレスを計算し、送信インターフェースのソースIPを指定可能。
- Broadcast / Unicast / Both に対応（各送信先へ2パケット）。IP未設定時はBroadcast。
- **MACからIP取得**：ローカルLinuxの `/proc/net/arp` を検索。既知のIPv4アドレスがなければ「取得不可」と表示（停止PCや別セグメントの未知IPを探索する機能ではない）。
- SQLiteに履歴を記録（JST表示、最新100件、全消去可能）。送信成否は**UDP送信処理**の成否であり、PCの起動状態ではありません。
- CSRF対策、出力エスケープ、入力バリデーション、送信レート制限（同一機器5秒以内・全体1分20操作）、保存先のWebルート分離。認証はなし。

## 必要環境

Ubuntu 24.04 / Apache 2.4 / PHP 8.3 (`pdo_sqlite`, `sockets` 拡張) / LinuxのARPキャッシュ。IPv4のWOLを対象とします。

## 導入（Ubuntu 24.04）

```bash
sudo apt update
sudo apt install -y apache2 libapache2-mod-php8.3 php8.3-cli php8.3-sqlite3 php8.3-common
php8.3 -m | grep -Ei 'pdo_sqlite|sockets'
# このリポジトリを取得
sudo git clone https://github.com/ponpaku/wol-webui.git /var/www/wol-webui
cd /var/www/wol-webui
sudo cp config/app.example.php config/local.php
sudoedit config/local.php  # 送信先CIDRと必要に応じてbind_ipを設定
sudo install -d -o www-data -g www-data -m 0700 /var/lib/wol-webui
sudo chown -R root:root /var/www/wol-webui
sudo chown root:www-data /var/www/wol-webui/config/local.php
sudo chmod 0640 /var/www/wol-webui/config/local.php
```

Apacheのサイト設定例（`/etc/apache2/sites-available/wol-webui.conf`）：

```apache
<VirtualHost *:80>
    ServerName wol.lan
    DocumentRoot /var/www/wol-webui/public
    <Directory /var/www/wol-webui/public>
        Options -Indexes -MultiViews
        AllowOverride None
        Require all granted
    </Directory>
    ErrorLog ${APACHE_LOG_DIR}/wol-webui-error.log
    CustomLog ${APACHE_LOG_DIR}/wol-webui-access.log combined
</VirtualHost>
```

```bash
sudo a2ensite wol-webui.conf
# この専用CTで他サイトが不要な場合に限り：sudo a2dissite 000-default.conf
sudo apache2ctl configtest
sudo systemctl reload apache2
```

ApacheにアクセスできるLAN／Tailscale送信元を**ファイアウォールで限定**してください。例では`Require all granted`でも、WANへのポート転送やパブリックリバースプロキシへの登録はしません。HTTPではCSRF対策があってもLAN内で盗聴が可能なため、必要なら内部TLS・Tailscale Serveを使用します。初回アクセス時にDBが自動生成されます。ログ・DB・`config/local.php` は公開されません。

より詳しい手順は [docs/DEPLOYMENT.md](docs/DEPLOYMENT.md)、旧CTからの移行は [docs/MIGRATION.md](docs/MIGRATION.md) を参照してください。

## ネットワークに関する注意

- Broadcastは**サーバーと対象が同じL2セグメント**上にあることを原則とします。複数NIC／VLAN構成では各ネットワークに適切な`bind_ip`を設定し、ルートを検証してください。
- Tailscaleは通常L3通信です。Web UIのアクセスには使えますが、遠隔端末からブロードキャストを直接送る仕組みではありません。WOLサーバー自体が対象LANに接続されている必要があります。
- ARPキャッシュは消えることがあります。電源OFFのPCのIPは取得保証できません。必要に応じてDHCP予約またはIPの手入力を推奨します。
- Unicastは対象PCのオフライン時にARP解決に失敗することがあります。基本はBroadcastまたはBoth推奨。

## 開発・テスト

```bash
php8.3 tests/run.php
find . -name '*.php' -print0 | xargs -0 -n1 php8.3 -l
```

DB関連テストを実行するには`pdo_sqlite`が必要です。ネットワークテストはLoopback宛に送るため、実PCは起動しません。**実機起動検証は別途必要**です。

## セキュリティとライセンス

LANアクセス権がWOL操作権になる設計です。公開LAN・ゲストLAN・WANでは利用しないでください。管理者向けに [docs/SECURITY.md](docs/SECURITY.md) に設計・制約をまとめています。MIT License。
