# Polidog.UsePhpBearModule

English | [日本語](README.ja.md)

Render BEAR.Resource `ResourceObject`s using [polidog/use-php](https://github.com/polidog/usePHP)'s PSX (TSX-like) templates.

A drop-in `BEAR\Resource\RenderInterface` adapter — your BEAR resources stay stateless and BEAR-idiomatic, but their HTML representations are authored as `<div>{$count}</div>` instead of nested `H::div(children: [...])` calls.

## Installation

```bash
composer require polidog/usephp-bear-module
```

PHP 8.5+. Requires `bear/resource ^1.20` and `polidog/use-php ^0.6.0`.

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
- **State / interactivity** = stateless by default (Tier 1). Hooks + snapshot (`useState`, form actions) are opt-in (Tier 3) — pass a `UsePHP` instance to the renderer and use `UsePhpActionResponder` from `onPost`. CDN-friendly partial hydration is available via `UsePhpDeferredResponder` (see [Deferred rendering](#deferred-rendering)).

## Deferred rendering

usePHP ≥ 0.2 (stabilised through 0.6) supports **deferred rendering** —
CDN-friendly partial hydration. A per-user component (logged-in name, cart
count, A/B bucket) is split in two: the cacheable page renders only a
fallback, and the real component is fetched after load via a separate
`GET /_defer/{name}`. The page HTML stays user-independent and edge-cacheable;
only the small deferred fetch is per-user. See usePHP's docs for the template
side (`fc(..., defer: new Defer(...))` / `#[Defer]`), the opt-in localStorage
client cache (`Defer::$localCache`), and explicit reload (`Defer::$reloadable`).

The framework hook for the fetch is `UsePHP::handleDeferred()`. This package
wraps it in `UsePhpDeferredResponder`, mirroring `UsePhpActionResponder`:

```php
use Polidog\UsePhpBearModule\UsePhpDeferredResponder;

// A single resource catching the whole /_defer/... path:
final class Defer extends ResourceObject
{
    public function __construct(private UsePhpDeferredResponder $responder) {}

    public function onGet(): static
    {
        $html = $this->responder->handle($this);
        if ($html === null) {
            $this->code = 404;   // not a defer route
            return $this;
        }
        $this->view = $html;
        return $this;
    }
}
```

`handle()` builds the request from globals by default (pass a
`Polidog\UsePhp\Router\RequestContext` to override), copies usePHP's
per-endpoint `Cache-Control` onto `$ro->headers`, and maps an error status
(400/404/500) onto `$ro->code` — so the response travels through BEAR's
pipeline instead of raw `header()` / `http_response_code()` calls.

The deferred registry must be populated on the **same** `UsePHP` instance the
renderer was built with (the responder derives it from the renderer). Three
ways, in order of convenience:
- `loadComponentManifest()` — auto-loads the `deferred-manifest.php` sidecar
  that `vendor/bin/usephp compile` writes for any `fc(..., defer: ...)`.
- `registerDeferred($name, $fqcn, $cacheControl)` — explicit.
- `register(MyDeferredComponent::class)` — for `#[Defer]` class components.

This responder requires the renderer to be in Tier 3 mode (constructed with a
`UsePHP` instance); it throws otherwise.

## Tier in the BEAR + usePHP integration

- **Tier 1 — stateless templates (default).** PSX as a pure template engine:
  no `useState`, no hooks, no form actions. The plain `UsePhpRenderer` /
  `UsePhpRendererModule` path. This is the BEAR-idiomatic baseline.
- **Tier 3 — hooks + snapshot (opt-in).** Pass a `UsePHP` instance to the
  renderer so `fc()` / `useState` templates serialise state into a signed
  snapshot, and use `UsePhpActionResponder` from `onPost` to apply
  `_usephp_action` submissions and return the updated fragment.
- **Deferred rendering (opt-in).** `UsePhpDeferredResponder` serves the
  `/_defer/{name}` endpoints described above — orthogonal to Tier 1/3, it
  only needs the renderer's `UsePHP` instance and a populated defer registry.

Tier 1 stays the default because hooks/actions collide with BEAR's
resource-oriented model unless you opt in deliberately.

## License

MIT
