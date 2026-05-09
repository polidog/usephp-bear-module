<?php

declare(strict_types=1);

namespace Polidog\UsephpBearRenderer;

use BEAR\Resource\RenderInterface;
use BEAR\Resource\ResourceObject;
use Polidog\UsePhp\Psx\CompileCommand;
use Polidog\UsePhp\Psx\Compiler;
use Polidog\UsePhp\Runtime\ComponentState;
use Polidog\UsePhp\Runtime\Element;
use Polidog\UsePhp\Runtime\RenderContext;
use Polidog\UsePhp\Runtime\Renderer;
use Polidog\UsePhp\Storage\StorageType;
use Polidog\UsePhp\UsePHP;
use Polidog\UsephpBearRenderer\Annotation\Template;

/**
 * Render BEAR.Resource ResourceObjects via polidog/use-php's PSX templates.
 *
 * Convention:
 * - For a resource class like `MyApp\Resource\Page\Counter`, the template is
 *   resolved to `<templateDir>/Page/Counter.psx` (everything up to and
 *   including `\Resource\` is stripped).
 * - Override the convention with the `#[Template('relative/Path.psx')]`
 *   attribute on the resource class. Absolute paths in the attribute are
 *   used as-is.
 * - The compiled cache lives at `<cacheDir>/<sha1(realpath(template))>.php`,
 *   matching the convention `polidog/use-php` itself uses, so a single
 *   `vendor/bin/usephp compile` populates the same cache.
 *
 * The PSX template returns a callable `fn(array $props): Element`. The
 * resource's `$body` (or its array conversion) is passed as `$props`. The
 * resulting Element is rendered to HTML by usePHP's stateless `Renderer`
 * — no useState, no form actions, just markup.
 *
 * Tier 3 (hooks + snapshot) is opt-in: pass a `UsePHP` instance to the
 * constructor and the renderer will set the RenderContext before invoking
 * the template, so `useState`/`fc()` templates can serialise state into a
 * snapshot embedded in the rendered HTML. Tier 1 (stateless) behaviour is
 * preserved when no `UsePHP` is provided.
 */
final class UsePhpRenderer implements RenderInterface
{
    private const RESOURCE_NAMESPACE_DELIMITER = '\\Resource\\';

    /** @var array<class-string, string|null> Cached `#[Template]` paths per resource class */
    private array $attributePathCache = [];

    /**
     * @param (\Closure(ResourceObject): string)|null $templateResolver
     *        Optional custom resolver. Receives the ResourceObject and must
     *        return either a path relative to `$templateDir` or an absolute
     *        path. Bypasses both the `#[Template]` attribute and the FQCN
     *        convention. Useful when the default rules don't fit (e.g. you
     *        want a database-driven mapping).
     * @param UsePHP|null $app
     *        When provided, the renderer enters Tier 3: `RenderContext::setApp`
     *        + `beginRender` are called before invoking the template, so
     *        `fc()`/`useState` can serialise state into a snapshot via
     *        `UsePHP::getSnapshotSerializer()`. The template is expected to
     *        return an `fc()`-wrapped callable (or a callable that uses
     *        `useState` directly). Without this, Tier 1 stateless rendering
     *        is used.
     */
    public function __construct(
        private readonly string $templateDir,
        private readonly string $cacheDir,
        private readonly bool $autoCompile = true,
        private readonly ?\Closure $templateResolver = null,
        private readonly ?UsePHP $app = null,
    ) {}

    public function render(ResourceObject $ro): string
    {
        $template = $this->resolveTemplatePath($ro);
        if (!\is_file($template)) {
            throw new \RuntimeException(
                'PSX template not found for ' . $ro::class . ': ' . $template
                . '. Either create the .psx file, set #[Template(\'...\')] on the resource class, '
                . 'or pass a custom templateResolver to UsePhpRenderer.'
            );
        }

        $callable = $this->loadCompiled($template);
        $props = $this->normaliseBody($ro->body);

        $html = $this->app !== null
            ? $this->renderWithHooks($callable, $props)
            : $this->renderElement($callable($props));

        // BEAR convention: assign the rendered string to $ro->view as well so
        // ResourceObject::__toString() sees it.
        $ro->view = $html;
        return $html;
    }

    /**
     * Tier 3 path: set up the usePHP RenderContext before invoking the
     * template so `fc()`/`useState` can find the active app (for snapshot
     * serialisation) and produce a `<div data-usephp data-usephp-snapshot>`
     * wrapper. The template MUST return a callable that, when invoked with
     * props, yields an `Element` (typically via `fc()`).
     *
     * @param callable                $callable Template entry callable.
     * @param array<string, mixed>    $props    Resource body / props.
     */
    private function renderWithHooks(callable $callable, array $props): string
    {
        \assert($this->app !== null);

        RenderContext::setApp($this->app);
        try {
            RenderContext::beginRender();
            $result = $callable($props);
            return $this->renderElement($result);
        } finally {
            RenderContext::clearApp();
        }
    }

    /**
     * Internal helper used by an action responder (or partial rendering
     * pipeline) to re-render the template after restoring component state
     * from a snapshot. Returns the rendered HTML for the template's root
     * element. The caller is responsible for setting up `RenderContext`,
     * restoring state, and applying the action — this method just renders.
     *
     * @param ResourceObject       $ro
     * @param array<string, mixed> $props
     */
    public function renderTemplateOnly(ResourceObject $ro, array $props): string
    {
        $template = $this->resolveTemplatePath($ro);
        if (!\is_file($template)) {
            throw new \RuntimeException("PSX template not found: $template");
        }
        $callable = $this->loadCompiled($template);
        return $this->renderElement($callable($props));
    }

    /**
     * Resolve the PSX template path for a ResourceObject. Public so the
     * action responder can locate templates without re-implementing the
     * convention/attribute resolution logic.
     */
    public function resolveTemplate(ResourceObject $ro): string
    {
        return $this->resolveTemplatePath($ro);
    }

    /**
     * Load and return the compiled callable for a template path. Public so
     * the action responder can invoke the template under a custom render
     * pipeline (e.g. inside `Renderer::renderPartial`).
     */
    public function loadTemplate(string $templatePath): callable
    {
        return $this->loadCompiled($templatePath);
    }

    public function getApp(): ?UsePHP
    {
        return $this->app;
    }

    /**
     * Map a ResourceObject to its `.psx` template path.
     *
     * Resolution order:
     * 1. Custom `$templateResolver` callable from the constructor (if any).
     * 2. `#[Template('...')]` attribute on the resource class.
     * 3. Convention: `MyApp\Resource\Page\Counter` →
     *    `<templateDir>/Page/Counter.psx`. Falls back to the bare class
     *    basename when no `\Resource\` segment is present.
     *
     * Method-level overrides are intentionally not supported: BEAR's
     * RenderInterface::render($ro) doesn't expose which `on*` method was
     * invoked, so the renderer can't reliably select between multiple
     * method-level attributes.
     */
    private function resolveTemplatePath(ResourceObject $ro): string
    {
        if ($this->templateResolver !== null) {
            return $this->absolutiseTemplatePath(($this->templateResolver)($ro));
        }

        $override = $this->resolveAttributePath($ro);
        if ($override !== null) {
            return $this->absolutiseTemplatePath($override);
        }

        $class = $ro::class;
        $idx = \strpos($class, self::RESOURCE_NAMESPACE_DELIMITER);
        if ($idx !== false) {
            $relative = \substr($class, $idx + \strlen(self::RESOURCE_NAMESPACE_DELIMITER));
        } else {
            $relative = (string) (\strrchr($class, '\\') ?: $class);
            $relative = \ltrim($relative, '\\');
        }
        $relative = \str_replace('\\', \DIRECTORY_SEPARATOR, $relative);
        return $this->absolutiseTemplatePath($relative . '.psx');
    }

    private function resolveAttributePath(ResourceObject $ro): ?string
    {
        $class = $ro::class;
        if (\array_key_exists($class, $this->attributePathCache)) {
            return $this->attributePathCache[$class];
        }

        $attrs = (new \ReflectionClass($class))->getAttributes(Template::class);
        if ($attrs === []) {
            return $this->attributePathCache[$class] = null;
        }
        /** @var Template $template */
        $template = $attrs[0]->newInstance();
        return $this->attributePathCache[$class] = $template->path;
    }

    private function absolutiseTemplatePath(string $path): string
    {
        if ($this->isAbsolutePath($path)) {
            return $path;
        }
        return \rtrim($this->templateDir, \DIRECTORY_SEPARATOR)
            . \DIRECTORY_SEPARATOR
            . $path;
    }

    /**
     * Treat as absolute:
     * - POSIX:    leading `/`
     * - Windows:  drive-letter root (`C:\` or `C:/`)
     * - Windows:  rooted path with leading backslash (`\foo`)
     * - Windows:  UNC path (`\\server\share` — also matched by leading backslash check)
     */
    private function isAbsolutePath(string $path): bool
    {
        if ($path === '') {
            return false;
        }
        $first = $path[0];
        if ($first === '/' || $first === '\\') {
            return true;
        }
        return \preg_match('#^[A-Za-z]:[\\\\/]#', $path) === 1;
    }

    private function loadCompiled(string $template): callable
    {
        $cachePath = CompileCommand::cachePathFor($this->cacheDir, $template);

        $needsCompile = !\file_exists($cachePath)
            || (@\filemtime($cachePath) ?: 0) < (@\filemtime($template) ?: 0);

        if ($needsCompile) {
            if (!$this->autoCompile) {
                throw new \RuntimeException(
                    "Compiled PSX template missing or stale for $template (expected $cachePath). "
                    . 'Run `vendor/bin/usephp compile <templates>` to populate the cache, '
                    . 'or pass autoCompile=true to UsePhpRenderer for dev compile-on-demand.'
                );
            }
            $this->ensureCacheDir();
            $source = \file_get_contents($template);
            if ($source === false) {
                throw new \RuntimeException("Failed to read PSX template: $template");
            }
            $compiled = (new Compiler())->compile($source);
            $this->atomicWrite($cachePath, $compiled);
        }

        $callable = require $cachePath;
        if (!\is_callable($callable)) {
            throw new \RuntimeException("PSX template did not return a callable: $template");
        }
        return $callable;
    }

    /**
     * Convert an Element (or string fallback) to HTML using usePHP's
     * `Renderer`. In Tier 3 mode (when `$this->app` is set) the Renderer is
     * given the snapshot serializer so `wire:click` form actions embed the
     * current component's snapshot. In Tier 1 the Renderer is stateless.
     */
    private function renderElement(mixed $result): string
    {
        if (\is_string($result)) {
            return $result;
        }
        if (!$result instanceof Element) {
            throw new \RuntimeException(
                'PSX template must return an Element, got: '
                . (\is_object($result) ? $result::class : \gettype($result))
            );
        }
        if ($this->app !== null) {
            $renderer = new Renderer(
                'bear-resource',
                $this->app->getSnapshotSerializer(),
                StorageType::Snapshot,
            );
            return $renderer->renderElement($result);
        }
        $renderer = new Renderer('bear-resource');
        return $renderer->renderElement($result);
    }

    /**
     * @return array<string, mixed>
     */
    private function normaliseBody(mixed $body): array
    {
        if (\is_array($body)) {
            return $body;
        }
        if ($body === null) {
            return [];
        }
        return ['body' => $body];
    }

    private function ensureCacheDir(): void
    {
        if (!\is_dir($this->cacheDir)) {
            @\mkdir($this->cacheDir, 0o755, true);
        }
    }

    private function atomicWrite(string $destination, string $content): void
    {
        $dir = \dirname($destination);
        $tmp = @\tempnam($dir, 'psx-');
        if ($tmp === false) {
            throw new \RuntimeException("Failed to create temp file in $dir");
        }
        if (\file_put_contents($tmp, $content) === false) {
            @\unlink($tmp);
            throw new \RuntimeException("Failed to write temp file: $tmp");
        }
        if (!@\rename($tmp, $destination)) {
            @\unlink($tmp);
            throw new \RuntimeException("Failed to rename $tmp to $destination");
        }
    }
}
