# Polidog.UsePhpBearModule

[English](README.md) | 日本語

[polidog/use-php](https://github.com/polidog/usePHP) の PSX (TSX ライク) テンプレートを使って BEAR.Resource の `ResourceObject` をレンダリングします。

`BEAR\Resource\RenderInterface` のドロップインアダプタです。BEAR リソースはステートレスかつ BEAR らしい書き方を保ったまま、HTML 表現は `H::div(children: [...])` のような入れ子呼び出しではなく `<div>{$count}</div>` で記述できます。

## インストール

```bash
composer require polidog/usephp-bear-module
```

PHP 8.5 以上が必要です。`bear/resource ^1.20` および `polidog/use-php` (次のタグリリースまでは `dev-main`) に依存します。

## クイックスタート

### 1. リソースごとに PSX テンプレートを書く

リソースクラスの構造をテンプレートルート配下にミラーします。`src/Resource/Page/Counter.php` のリソースであれば、テンプレートは `templates/Page/Counter.psx` に配置します:

```php
<?php
// templates/Page/Counter.psx
declare(strict_types=1);

use Polidog\UsePhp\Html\H;
use Polidog\UsePhp\Runtime\Element;

return function (array $props): Element {
    $count = (int) ($props['count'] ?? 0);

    return <div className="counter">
        <h1>Counter</h1>
        <p>Count is {$count}</p>
    </div>;
};
```

テンプレートはリソースの `$body` を `array $props` として受け取り、`Element` を返す callable です。

### 2. BEAR アプリにモジュールを組み込む

```php
use Polidog\UsePhpBearModule\Module\UsePhpRendererModule;

protected function configure(): void
{
    // ... ほかのバインディング ...

    $appMeta = $this->meta;  // または AppMeta を取得する任意の方法
    $this->install(new UsePhpRendererModule(
        templateDir: $appMeta->appDir . '/templates',
        cacheDir:    $appMeta->tmpDir . '/psx',
    ));
}
```

このモジュールは `RenderInterface` を `UsePhpRenderer` にバインドします。既存のレンダラ (Twig など) はアプリ全体で置き換えられるため、PSX をデフォルトにしたい場所だけにインストールしてください。

### 3. リソースは BEAR らしいまま

```php
namespace MyApp\Resource\Page;

use BEAR\Resource\ResourceObject;

final class Counter extends ResourceObject
{
    public function onGet(int $initial = 0): static
    {
        $this->body = ['count' => $initial];
        return $this;
    }
}
```

`onGet` で `$this->body` を設定します。レンダラは対応する `templates/Page/Counter.psx` を解決し、初回利用時にコンパイルしたうえで `$body` を props として渡して呼び出します。

## コンパイルワークフロー

レンダラは `polidog/use-php` のキャッシュ規約をそのまま使います。2 つのモードがあります:

```bash
# 本番 / CI: ビルドの一部としてテンプレートを事前コンパイル
./vendor/bin/usephp compile templates/ --cache=var/tmp/psx
./vendor/bin/usephp compile templates/ --cache=var/tmp/psx --check
```

```php
// 開発: レンダラに任せてオンデマンドにコンパイル (デフォルト)
new UsePhpRenderer(
    templateDir: __DIR__ . '/../templates',
    cacheDir:    __DIR__ . '/../var/tmp/psx',
    autoCompile: true,  // デフォルト。本番では false に
);
```

`vendor/bin/usephp compile` とレンダラは同じハッシュ (`sha1(realpath(template)).php`) を使うので、事前コンパイルを 1 回回せばレンダラが読むキャッシュが揃います。

`.gitignore`:

```gitignore
**/var/cache/psx/
```

## リソースごとにテンプレートを差し替える

規約は FQCN ベースですが、`#[Template]` 属性で個別に固定できます。BEAR のほかの宣言的属性 (`#[Embed]`、`#[Link]`、`#[Cacheable]` など) と同じ発想です。

```php
use Polidog\UsePhpBearModule\Annotation\Template;

#[Template('shared/Counter.psx')]
final class Counter extends ResourceObject { ... }
```

解決順序:
1. カスタム `templateResolver` クロージャ (レンダラまたはモジュールに設定したとき。後述「規約」を参照)
2. リソースクラスに付いた `#[Template]`
3. FQCN 規約 (`<templateDir>/<Resource\ 以降>.psx`)

属性に書いたパスは `templateDir` 相対で解決されます。絶対パスはそのまま使われます。

この属性は **クラスレベル専用** です。`BEAR\Resource\RenderInterface::render($ro)` はどの `on*` メソッドが呼ばれたかをレンダラに伝えないため、メソッドレベルの属性 (例えば `onGet` と `onPost` で別々の `#[Template]` を付ける) は確実に解決できません。HTTP メソッドごとに違うテンプレートを使いたい場合はリソースを分けてください。

## 規約

- **テンプレートパス** = `<templateDir>/<クラス FQN の \Resource\ 以降>.psx`。例: `MyApp\Resource\Page\Foo\Bar` → `<templateDir>/Page/Foo/Bar.psx`。リソース単位の上書きは `#[Template]` で (上記参照)。
- **カスタム解決** — `templateResolver` クロージャを `UsePhpRenderer` (または `UsePhpRendererModule`) に渡すと、デフォルトの `#[Template]` + FQCN ロジックを完全に置き換えられます。クロージャは `ResourceObject` を受け取り、パス (`templateDir` 相対または絶対) を返します:

  ```php
  $this->install(new UsePhpRendererModule(
      templateDir: $appMeta->appDir . '/templates',
      cacheDir:    $appMeta->tmpDir . '/psx',
      templateResolver: static function (\BEAR\Resource\ResourceObject $ro): string {
          // 例: DB ルックアップ、マニフェスト、フォーマットサフィックスなど
          return $ro instanceof MyApp\Resource\Page\Counter ? 'shared/Counter.psx' : 'default.psx';
      },
  ));
  ```

  設定すると、リゾルバは `#[Template]` と FQCN 規約の両方を完全に置き換えます。DB 駆動やコンテキスト依存のマッピングが必要なときに便利です。`UsePhpRenderer` 自体は `final` であり、拡張はサブクラス化ではなくこのフックで行います。
- **Props** = `$ro->body` が配列ならそのまま、それ以外は `['body' => $ro->body]`、`null` なら `[]`。
- **戻り値の型** = テンプレート callable は `Element` または文字列を返さなければなりません。それ以外は例外を投げます。
- **状態 / インタラクティビティ** = この Tier ではサポートしません。テンプレートはステートレスに実行されます。BEAR の中で `useState` やフォームアクションを使いたい場合は、`onPost` をブリッジする別のレンダラが必要です (本パッケージのスコープ外)。

## BEAR + usePHP 統合における Tier

このパッケージは **Tier 1** — ステートレスなテンプレートエンジンとしての PSX です。`useState`、フック、usePHP のフォームアクション機構は意図的に使っていません。これらは BEAR のリソース指向モデルと衝突するためです。

BEAR の中で usePHP のインタラクティビティをフルに使いたい場合は、以下を行う Tier 3 レンダラを書くことになります:
- `onPost` から `$_POST['_usephp_action']` を取り出す
- 同じテンプレートを更新後の状態で再実行する
- スナップショット / CSRF を管理する

このグルーは無視できない量になるため、専用パッケージに切り出すのが向いています。

## ライセンス

MIT
