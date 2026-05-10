# Polidog.UsePhpBearModule

English | [日本語](README.ja.md)

Render BEAR.Resource `ResourceObject`s using [polidog/use-php](https://github.com/polidog/usePHP)'s PSX (TSX-like) templates.

A drop-in `BEAR\Resource\RenderInterface` adapter — your BEAR resources stay stateless and BEAR-idiomatic, but their HTML representations are authored as `<div>{$count}</div>` instead of nested `H::div(children: [...])` calls.

## Installation

```bash
composer require polidog/usephp-bear-module
```

PHP 8.5+. Requires `bear/resource ^1.20` and `polidog/use-php` (currently `dev-main` until the next tagged release).

## Quick start

### 1. Author a PSX template per resource

Mirror your resource class structure under a templates root. For a resource at `src/Resource/Page/Counter.php` you place its template at `templates/Page/Counter.psx`:

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

The template is a callable that takes the resource's `$body` (as `array $props`) and returns an `Element`.

### 2. Wire the module in your BEAR app

```php
use Polidog\UsePhpBearModule\Module\UsePhpRendererModule;

protected function configure(): void
{
    // ... your other bindings ...

    $appMeta = $this->meta;  // or however you obtain the AppMeta
    $this->install(new UsePhpRendererModule(
        templateDir: $appMeta->appDir . '/templates',
        cacheDir:    $appMeta->tmpDir . '/psx',
    ));
}
```

The module binds `RenderInterface` to `UsePhpRenderer`. Existing renderers (Twig etc.) get replaced for the whole app — only install where you want PSX as the default.

### 3. Resources stay BEAR-idiomatic

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

`onGet` populates `$this->body`. The renderer resolves the matching `templates/Page/Counter.psx`, compiles it on first use, and invokes it with `$body` as props.

## Compile workflow

The renderer reuses the cache convention from `polidog/use-php`. Two modes:

```bash
# Production / CI: pre-compile templates as part of the build
./vendor/bin/usephp compile templates/ --cache=var/tmp/psx
./vendor/bin/usephp compile templates/ --cache=var/tmp/psx --check
```

```php
// Dev: let the renderer compile on demand (default)
new UsePhpRenderer(
    templateDir: __DIR__ . '/../templates',
    cacheDir:    __DIR__ . '/../var/tmp/psx',
    autoCompile: true,  // default; set to false for production
);
```

`vendor/bin/usephp compile` and the renderer use the same hashing (`sha1(realpath(template)).php`), so a single pre-compile pass populates the cache the renderer reads from.

`.gitignore`:

```gitignore
**/var/cache/psx/
```

## Overriding the template per resource

Convention is FQCN-based, but you can pin a specific template via the `#[Template]` attribute — same idea as BEAR's other declarative attributes (`#[Embed]`, `#[Link]`, `#[Cacheable]` …).

```php
use Polidog\UsePhpBearModule\Annotation\Template;

#[Template('shared/Counter.psx')]
final class Counter extends ResourceObject { ... }
```

Resolution order:
1. Custom `templateResolver` closure (when configured on the renderer or module — see "Conventions" below)
2. `#[Template]` on the resource class
3. FQCN convention (`<templateDir>/<rest-after-Resource\>.psx`)

Paths in the attribute are resolved relative to `templateDir`. Absolute paths are used as-is.

The attribute is **class-level only**. `BEAR\Resource\RenderInterface::render($ro)` doesn't tell the renderer which `on*` method was invoked, so a method-level attribute (e.g. one `#[Template]` on `onGet` and a different one on `onPost`) can't be resolved reliably. If you need different templates per HTTP verb, expose distinct resources.

## Conventions

- **Template path** = `<templateDir>/<everything-after-`\Resource\`-in-class-FQN>.psx`. Example: `MyApp\Resource\Page\Foo\Bar` → `<templateDir>/Page/Foo/Bar.psx`. Override per-resource with `#[Template]` (above).
- **Custom resolution** — pass a `templateResolver` closure to `UsePhpRenderer` (or `UsePhpRendererModule`) to bypass the default `#[Template]` + FQCN logic entirely. The closure receives the `ResourceObject` and returns a path (relative to `templateDir` or absolute):

  ```php
  $this->install(new UsePhpRendererModule(
      templateDir: $appMeta->appDir . '/templates',
      cacheDir:    $appMeta->tmpDir . '/psx',
      templateResolver: static function (\BEAR\Resource\ResourceObject $ro): string {
          // e.g. database lookup, manifest, format suffix, ...
          return $ro instanceof MyApp\Resource\Page\Counter ? 'shared/Counter.psx' : 'default.psx';
      },
  ));
  ```

  When set, the resolver fully replaces both `#[Template]` and the FQCN convention. Useful when you need a database-driven or context-aware mapping. `UsePhpRenderer` itself is `final` — extension is via this hook, not subclassing.
- **Props** = `$ro->body` if it's already an array; `['body' => $ro->body]` otherwise; `[]` if null.
- **Return type** = the template callable must return an `Element` or a string. Anything else throws.
- **State / interactivity** = NOT supported in this Tier. Templates run statelessly. For `useState` / form actions inside BEAR you would need a different renderer that bridges `onPost` (out of scope here).

## Tier in the BEAR + usePHP integration

This package is **Tier 1** — PSX as a stateless template engine. It deliberately does not use `useState`, hooks, or usePHP's form-action plumbing, because those collide with BEAR's resource-oriented model.

If you need full usePHP interactivity inside BEAR, you'd write a Tier 3 renderer that:
- Inspects `$_POST['_usephp_action']` from `onPost`
- Re-runs the same template with updated state
- Manages snapshots / CSRF

That's a meaningful amount of glue and is best left to a dedicated package.

## License

MIT
