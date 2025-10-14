=== Page Comment Controller ===
Plugin Name: Page Comment Controller
Description: Enable global settings to control comment posting for all static pages (Page) and reduce spam risk.
Plugin URI: https://p-fox.jp/blog/
Stable tag: 1.0
Author: Red Fox(team Red Fox)
Author URI: https://p-fox.jp/
Contributors: teamredfox
License: GPLv2 or later
License URI: http://www.gnu.org/licenses/gpl-2.0.html
Text Domain: page-comment-controller
Domain Path: /languages
Requires PHP: 7.4
Requires at least: 6.8
Tested up to: 6.8

固定ページ（Page）を狙ったスパムコメント投稿をシステムレベルで遮断します。固定ページのコメント機能が必要ないサイトで、セキュリティとパフォーマンスを向上させるための必須プラグインです。

== Description ==

このプラグインは、WordPressの**固定ページ（Page）**に対するコメント投稿機能を、システムレベルで一括管理するために開発されました。

一般的に、固定ページは会社概要やプライバシーポリシーなど、静的な情報表示に利用され、コメントは不要です。しかし、テーマの設定ミスなどでコメント機能が技術的に「オン」になっている場合、コメントフォームが表示されていなくても、悪意のあるボットは直接wp-comments-post.phpエンドポイントを叩いて大量のスパム投稿を試みるリスクがあります。

このプラグインを有効化し、設定を「無効」にすることで、固定ページからのコメント送信をWordPressのコアフィルター（comments_open）を用いて強制的にブロックします。

主な機能
グローバル制御: すべての固定ページ（post type: page）のコメント投稿を、一つのチェックボックスで一括「閉じる」状態にできます。

スパムリスク低減: テーマがコメントフォームを非表示にしていても、バックエンドでコメント投稿機能が残っていることによるスパム投稿のリスクを完全に排除します。

恒久的な対策: テーマの更新や変更に影響されないプラグインとして動作するため、一度設定すれば恒久的にセキュリティを維持できます。

固定ページにコメント機能が必要ないサイトでは、セキュリティを強化するためにこの設定の有効化を強く推奨します。

== Installation ==

ZIPファイルをダウンロードし、WordPressの管理画面の「プラグイン」メニューから「新規追加」>「プラグインのアップロード」に進み、インストールします。

または、ダウンロードしたファイルを解凍し、中身を /wp-content/plugins/ ディレクトリにアップロードします。

WordPressの管理画面の「プラグイン」メニューで「固定ページコメントコントローラー」を有効化します。

「設定」 > 「ディスカッション」 のページ最下部にある 「固定ページコメント設定」 セクションに移動し、設定を調整してください。

== Frequently Asked Questions ==

= なぜコメントフォームが見えないのに、コメント投稿を制限する必要があるのですか？ =

テーマがコメントフォームを非表示にしていても、WordPressのデータベース上でコメント機能が「オン」になっている限り、ボットはフォームを使わず、直接コメント処理用のURL（wp-comments-post.php）を狙って大量のスパムデータを送信できます。このプラグインは、このシステム的な受付を停止します。

= この設定は「投稿」（Post）にも影響しますか？ =

いいえ、影響しません。このプラグインは、投稿タイプが**page（固定ページ）**であることのみを検出して処理を実行します。「投稿」やカスタム投稿タイプ（custom post type）のコメント投稿機能は、WordPressのデフォルト設定のまま動作します。

= 設定を有効化すると、固定ページのコメント設定はグレーアウトしますか？ =

個別の固定ページ編集画面にある「ディスカッション」設定は操作可能ですが、このプラグインが有効化されていると、その設定が「オン」になっていてもコメント投稿は強制的にブロックされます。このプラグインの設定が最優先されます。

== Screenshots ==

The '固定ページコメント設定' section added to the 'Settings' -> 'Discussion' page in the admin area.

The checkbox to globally disable comment posting on all Pages.

== Changelog ==

= 1.0.0 =

初版リリース。

すべての固定ページに対するコメント投稿の一括制御機能（「設定」->「ディスカッション」に追加）を追加。

== Upgrade Notice ==

= 1.0.0 =
Initial release. No special upgrade notice is needed for this version.