# redfox-wordpress-secure-plugin
team redfox. wordpress-secure-plugin

# このプラグインは？
多くの公式プラグインは、残念ながらあやしい広告や機能ばかりついていて、特に役立たなかったり、必ずしも望むコードがありません。
そこで自作したのが、小回りの利くようなプラグイン集です。一部は公式プラグインとして掲載されています（rest api xml rpc blockerなど）。

# 対象となるWordPress
6.8.3以降であれば動作します。

# bug fix
基本的にPCPでエラーが出ないか、クリティカルにならない程度に抑えていますが一部はPCP走査するとエラーで引っかかります。
ただし、ほぼすべてにおいてその後サニタイズ（無害化）を行っています。またセキュリティ上で問題ないか開発者ツールであえて脆弱なコードを生成し実行しています。

# どのようなプラグインがありますか？
- REST API Blocker
- API Write Blocker
- upload file limiter
- file rename
- admin-ajax.phpの動作制御
などを取りそろえています。

# プラグインの方向性
- WordPressの制御を目的とする
- CVE発覚時にWordPress単体で最小限の被害に食い止める
- POST,PUT,DELETEといった攻撃から守る
ことを方向性としています。平たくいえば「WordPressを制御する」ためのものです。

# それぞれの主機能
* admin-ajax-blockerは、wp-admin/admin-ajax.phpの非ログインユーザーからのアクセスを防ぎます。
* feed-access-controllerは、/feedや/blog/feedへのアクセスを禁止します（ホワイトリスト追加可能）。
* media-file-limiterは、投稿できるファイルの容量を任意の容量に制限し、ホスティングサービスの容量ひっ迫を禁止すると同時に、mime type検証で不正なファイルの投稿を禁止します（プラグイン経由を含む）。
* move-file-nameは、投稿されたファイル（スクリーンショット-2025-10-10.png）を強制的に日付ベースにリネームします（簡易拡張子制限つき）。
* page-comment-controllerは誤って固定ページにコメント欄を開けた場合でもそれを無効にします。
* remove-image-exif-dateは、WordPressで投稿された画像ファイルからEXIF情報（メタデータ）を消し去ります（GD依存）。
* rest-api-shield-xml-rpc-blockerは、細かい経路のREST APIアクセスを禁止し、明示的にホワイトリストに追加しないプラグイン以外のアクセスを禁止するものです。（/wp-json/wp/v2/usersなど）。
* uploads-security-managerは、/wp-content/*/uploadsに.htaccessを置く簡易的なプラグインです（filesディレクティブで想定されるペイロードをテキスト化します）。
* gutenberg-hack-target-blankは、Gutenberg使用時に外部のaタグを一括してtarget blankにしてくれると同時に全画像を一括でリンクしてくれます（クラシックエディタでも利用可能）。
* media-access-restrictorは、管理者以外の複数人管理サイト向けで、管理者以外の利用者は別の利用者の投稿ファイルを見ることができなくなります。編集者は許可制で閲覧可能（情報漏洩対策）。
* REST API and XML Blockerはhttps://wordpress.org/plugins/rest-api-shield-xml-rpc-blocker/ からダウンロードしてください（規約の関係上）。
* api-write-blockerは、REST APIやadmin-ajax.phpの動作を制限します（POST,PUT.DELETEなど）。そのためContact Form 7などは明示的にホワイトリストに追加する必要があります。https://wordpress.org/plugins/api-write-blocker/ からダウンロードしてください（規約の関係上）。

# 組み合わせて使うと？
組み合わせて使うことを主目的としています。単体だけではなく、これらすべてを有効にしてデータを多層防御することが目的です。

# なぜこのようなプラグインを？
WordPressは、標準では様々な機能を公開状態で提供しています。しかし、REST APIに代表されるような情報を本来第三者に開示するべきではありません。そのため、積極的にデータを隠匿するために作りました。

# redfox-wordpress-secure-plugin
team redfox. wordpress-secure-plugin

# What is this plugin?
Unfortunately, many official plugins come bundled with suspicious ads or features, often proving useless or lacking the code you actually need.
That's why I created this collection of nimble plugins. Some are listed as official plugins (like rest api xml rpc blocker).

# Compatible WordPress Versions
Works with 6.8.3 and later.

# Bug Fixes
We generally ensure PCP scans don't flag errors or critical issues, though some may trigger warnings.
However, nearly all are sanitized afterward. We also deliberately generate and execute vulnerable code using developer tools to verify security integrity.

# What Plugins Are Included?
- REST API Blocker
- API Write Blocker
- Upload File Limiter
- File Rename
- Control over admin-ajax.php operations
and others are available.

# Plugin Direction
- Aims to control WordPress
- Minimizes damage to WordPress itself when CVEs are discovered
- Protects against attacks like POST, PUT, DELETE
In simple terms, it's for “controlling WordPress.”

# Primary Functions of Each Plugin
* admin-ajax-blocker prevents non-logged-in users from accessing wp-admin/admin-ajax.php.
* api-write-blocker restricts operations on the REST API and admin-ajax.php (POST, PUT, DELETE, etc.). Therefore, plugins like Contact Form 7 must be explicitly added to the whitelist.
* feed-access-controller prohibits access to /feed and /blog/feed (whitelisting possible).
* media-file-limiter restricts uploadable file sizes to any specified limit, preventing hosting service capacity strain, while also blocking invalid file uploads via MIME type validation (including via plugins).
* move-file-name forcibly renames uploaded files (e.g., screenshot-2025-10-10.png) to a date-based format (with basic extension restrictions).
* page-comment-controller disables comment sections on static pages, even if accidentally enabled.
* remove-image-exif-date removes EXIF information (metadata) from image files uploaded to WordPress (GD dependency).
* rest-api-shield-xml-rpc-blocker blocks REST API access to specific paths, prohibiting access from plugins not explicitly added to the whitelist (e.g., /wp-json/wp/v2/users).
* uploads-security-manager is a simple plugin that places an .htaccess file in /wp-content/*/uploads (it converts payloads expected by the files directive into plain text).
* gutenberg-hack-target-blank automatically sets all external a tags to target=blank when using Gutenberg and simultaneously links all images (also usable with the Classic Editor).
* media-access-restrictor is designed for multi-author sites where non-admin users cannot view other users' uploaded files. Editors can view files only with permission (information leak prevention).

# What about using them together?
Their primary purpose is to be used in combination. The goal is not just standalone use, but to enable all of them for multi-layered data protection.

# Why create such plugins?
WordPress, by default, exposes various functions publicly. However, information like that represented by the REST API should not be disclosed to third parties. Therefore, these were created to actively conceal data.
