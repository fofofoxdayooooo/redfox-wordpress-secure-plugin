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
