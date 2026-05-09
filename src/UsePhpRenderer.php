<?php

declare(strict_types=1);

namespace Polidog\UsephpBearRenderer;

use BEAR\Resource\RenderInterface;
use BEAR\Resource\ResourceObject;
use Polidog\UsePhp\Psx\CompileCommand;
use Polidog\UsePhp\Psx\Compiler;
use Polidog\UsePhp\Runtime\Element;
use Polidog\UsePhp\Runtime\Renderer;

/**
 * Render BEAR.Resource ResourceObjects via polidog/use-php's PSX templates.
 *
 * Convention:
 * - For a resource class like `MyApp\Resource\Page\Counter`, the template is
 *   resolved to `<templateDir>/Page/Counter.psx` (everything up to and
 *   including `\Resource\` is stripped).
 * - The compiled cache lives at `<cacheDir>/<sha1(realpath(template))>.php`,
 *   matching the convention `polidog/use-php` itself uses, so a single
 *   `vendor/bin/usephp compile` populates the same cache.
 *
 * The PSX template returns a callable `fn(array $props): Element`. The
 * resource's `$body` (or its array conversion) is passed as `$props`. The
 * resulting Element is rendered to HTML by usePHP's stateless `Renderer`
 * — no useState, no form actions, just markup.
 *
 * For interactivity (Tier 3 of the BEAR/usePHP integration spectrum) you
 * would write a different renderer that hooks into `onPost`. This class
 * intentionally stays in Tier 1.
 */
final class UsePhpRenderer implements RenderInterface
{
    private const RESOURCE_NAMESPACE_DELIMITER = '\\Resource\\';

    public function __construct(
        private readonly string $templateDir,
        private readonly string $cacheDir,
        private readonly bool $autoCompile = true,
    ) {}

    public function render(ResourceObject $ro): string
    {
        $template = $this->resolveTemplatePath($ro);
        if (!\is_file($template)) {
            throw new \RuntimeException(
                'PSX template not found for ' . $ro::class . ': ' . $template
                . '. Either create the .psx file or override resolveTemplatePath.'
            );
        }

        $callable = $this->loadCompiled($template);
        $props = $this->normaliseBody($ro->body);

        $result = $callable($props);
        $html = $this->renderElement($result);

        // BEAR convention: assign the rendered string to $ro->view as well so
        // ResourceObject::__toString() sees it.
        $ro->view = $html;
        return $html;
    }

    /**
     * Map a ResourceObject class name to a `.psx` template path.
     *
     * `MyApp\Resource\Page\Counter` → `<templateDir>/Page/Counter.psx`.
     * Falls back to the bare class basename when no `Resource` segment is
     * present.
     */
    private function resolveTemplatePath(ResourceObject $ro): string
    {
        $class = $ro::class;
        $idx = \strpos($class, self::RESOURCE_NAMESPACE_DELIMITER);
        if ($idx !== false) {
            $relative = \substr($class, $idx + \strlen(self::RESOURCE_NAMESPACE_DELIMITER));
        } else {
            $relative = (string) (\strrchr($class, '\\') ?: $class);
            $relative = \ltrim($relative, '\\');
        }
        $relative = \str_replace('\\', \DIRECTORY_SEPARATOR, $relative);
        return \rtrim($this->templateDir, \DIRECTORY_SEPARATOR)
            . \DIRECTORY_SEPARATOR
            . $relative
            . '.psx';
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
     * Convert an Element (or string fallback) to HTML using usePHP's stateless
     * Renderer. The dummy component id keeps the snapshot/data-* wrapper out
     * of the way — this is Tier 1, no state.
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
