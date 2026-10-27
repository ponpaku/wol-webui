# Ubuntu 24.04 / Apache 配置と動作確認

1. **別の新規Ubuntu 24.04環境を準備**する。旧CT 119には手を加えない。Ubuntuの通常のセキュリティ更新を適用する。
2. `README.md` の通りApache・PHP・SQLite拡張を導入。`php8.3 -m`とApache側の `phpinfo()` の代わりに `sudo -u www-data php8.3 -r 'var_dump(extension_loaded("pdo_sqlite"),extension_loaded("sockets"));'` で確認。Apacheでモジュール版PHP 8.3を使う。
3. `/var/www/wol-webui/config/local.php` を設定する。`database` は `/var/lib/wol-webui/wol.sqlite`、`networks` は直接接続可能なCIDR。`bind_ip` に各セグメントに属する**新WOLサーバー側**IPv4を設定すると複数NIC環境で送信元を明示できる。設定ファイルを変更した場合、登録機器の `network_cidr` と一致していることを確認。
4. WebのDocumentRootは**`public/`だけ**。`config/`、`src/`、DBをWeb公開範囲に入れない。`/var/lib/wol-webui` の権限は `0700 www-data:www-data`。`config/local.php` は `0640 root:www-data`。リポジトリ全体をWebユーザーの書き込み可能にしない。
5. `ip -4 addr`, `ip -4 route get 192.168.10.255`, `ip -4 route get 192.168.101.255` などで送信NIC／ルートを調べる。CIDRと実LAN/VLAN構成が一致している必要がある。`bind_ip`は経路そのものを変更しない点に注意。必要ならOSルーティングを修正する。
6. 新サーバーのIPへLANからアクセスし、初期画面が「マシンはまだ登録されていません」と表示されることを確認する。管理画面で**ダミーではなく手元の対象PC**のMAC・IP・ネットワークを登録する。
7. テストでは電源オフのPCに送信し、画面に「UDP Magic Packetを送信しました」と表示されること、履歴に宛先と `UDP送信` が記録されること、そのうえで**PCが実際に起動すること**を確認する。UDP送信成功だけでは移設完了ではない。
8. どちらのLANからも操作するなら、192.168.10.0/24側と192.168.101.0/24側を個別に実機検証する。Tailscale経由ではWebの疎通と、WOLサーバーからの送信・実機起動をそれぞれ確認する。
9. Apacheの公開経路・家庭用ルーターのポート転送・プロキシを確認し、**WAN公開されていない**ことを確認。ファイアウォールの例としてUFW使用時は先にSSH許可を確実に設定し、必要な管理端末・Tailscale側からのHTTP/HTTPSだけを許可する。ネットワーク全体への全許可はしない。
10. 新環境での起動が各対象PCで実証できてから旧CT停止を検討。削除は別途バックアップ確認後とする。

## バックアップ

DBはSQLiteです。ホットバックアップには `sqlite3` コマンドを導入し、`sudo sqlite3 /var/lib/wol-webui/wol.sqlite ".backup '/root/wol-backup.sqlite'"` を使う（rootのみが読める保存先に保管）。`config/local.php` も別途保管。バックアップには実MAC/IPと履歴が含まれるためパブリックリポジトリへ追加しない。復元はApache停止中にDBを置換し、所有者と権限を再設定してから起動してください。

## トラブルシューティング

- `pdo_sqlite 拡張が必要`：`sudo apt install php8.3-sqlite3`。Apacheをreload/restartしてPHPモジュールの読み込みを確認。
- `DBディレクトリがありません`：ディレクトリ作成・所有者・Apacheのユーザー・`open_basedir`の設定を確認。
- `IP取得できません`：MACと選択LANの確認、`cat /proc/net/arp` をサーバー側で参照。別のVLANにある端末やOFFの端末はARPに載らない場合がある。
- 送信成功だが起動しない：PCのUEFI/BIOS WOL設定、OS NICの電源管理、同一L2のルート・VLAN、実際のNICと対象MAC、IP/マスク、スイッチのブロードキャスト転送を確認。Wi-Fiだけの端末には一般的な有線WOLが使えないことが多い。
- 履歴の `UDP送信` は `socket_sendto` の戻り値判定。電源ON/OS起動や疎通確認ではない。
