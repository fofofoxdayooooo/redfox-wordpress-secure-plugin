=== Admin Ajax Blocker ===
Plugin Name: Admin Ajax Blocker(dev ver)
Description: 非ログインユーザーによる「wp-admin/admin-ajax.php」へのアクセスをグローバルにブロックし、サーバー負荷とセキュリティリスクを軽減します。
Plugin URI: https://p-fox.jp/
Stable tag: 0.9
Author: Red Fox(team Red Fox)
Author URI: https://p-fox.jp/
Contributors: teamredfox
License: GPLv2 or later
License URI: http://www.gnu.org/licenses/gpl-2.0.html
Text Domain: admin-ajax-blocker
Domain Path: /languages
Requires PHP: 7.4
Requires at least: 6.8
Tested up to: 6.8

wp-admin/admin-ajax.phpへの匿名アクセスを検知し、管理画面で設定したカスタムエラーレスポンスで処理を即座に終了させます。

== Description ==

このプラグインは、WordPressの重要なアクセスポイントである wp-admin/admin-ajax.php への、非ログインユーザーによる不要なリクエストをシステムレベルで遮断するために開発されました。

admin-ajax.phpは、WordPressが内部でAJAX通信を処理するために使用されますが、悪意のあるボットや不適切な外部スクリプトがこのエンドポイントを叩くことで、サーバーリソースの浪費や、セキュリティ上の懸念が生じる場合があります。

このプラグインは、セキュリティ強化とサーバー負荷軽減を目的とし、非ログインユーザーからの全てのリクエストを検知し、WordPressのメイン処理に入る前にブロックします。

主な機能
グローバルブロック: 非ログインユーザーによるadmin-ajax.phpへのアクセスを、一つのチェックボックスでグローバルに無効化できます。
カスタムエラーレスポンス: ブロック時に返却する HTTPステータスコード (403/401など)、JSONエラーコード、JSONエラーメッセージを自由に設定できます。これにより、フロントエンドや監視ツールでのエラー処理が容易になります。
ログインユーザーの保護: ログイン中のユーザー（管理者や編集者など）からのAJAXリクエストは通常通り処理され、影響を受けません。

サイトで非ログインユーザー向けのAJAX機能（無限スクロール、一部の動的なフォームなど）を使用していない場合は、セキュリティ強化のためにこの設定の有効化を強く推奨します。

== Installation ==

ZIPファイルをダウンロードし、WordPressの管理画面の「プラグイン」メニューから「新規追加」>「プラグインのアップロード」に進み、インストールします。

または、ダウンロードしたファイルを解凍し、中身を /wp-content/plugins/ ディレクトリにアップロードします。

WordPressの管理画面の「プラグイン」メニューで「Admin Ajax Blocker」を有効化します。

「設定」 > 「ディスカッション」 のページ最下部にある 「Admin AJAX ブロック設定」 セクションに移動し、ブロックを有効化し、エラー設定を調整してください。

== Frequently Asked Questions ==

= このプラグインを有効化すると、非ログインユーザー向けの公開AJAX機能はすべて停止しますか？ =

はい、停止します。このプラグインは、admin-ajax.phpへの非ログインユーザーからのアクセスをアクション名に関わらずすべて強制的にブロックします。したがって、無限スクロール、テーマやプラグインが提供する動的なフォーム送信など、未ログイン状態で行われる全てのAJAX処理が動作しなくなります。

= ログインしているユーザーには影響がありますか？ =

いいえ、影響しません。is_user_logged_in()でログイン状態を確認しているため、ログインユーザー（管理者、編集者、購読者など）は、引き続きadmin-ajax.phpを利用できます。

= HTTPステータスコードをカスタマイズできるのはなぜですか？ =

サーバーのログや外部のセキュリティ監視ツールが、ブロックされたリクエストを適切に識別できるようにするためです。たとえば、「認証が必要」を示す401 Unauthorizedや、「アクセス禁止」を示す403 Forbiddenなど、サイトの運用ポリシーに合わせて選択できます。

== Screenshots ==

The 'Admin AJAX ブロック設定' section added to the 'Settings' -> 'Discussion' page in the admin area.

The settings panel allowing customization of HTTP status code, JSON error code, and error message.

== Changelog ==

= 1.0.0 =

初版リリース。

非ログインユーザーのadmin-ajax.phpへのアクセスを一括ブロックする機能を追加。
HTTPステータスコード、JSONエラーコード、JSONエラーメッセージのカスタマイズ機能を追加。

== Upgrade Notice ==

= 1.0.0 =

Initial release. No special upgrade notice is needed for this version.
