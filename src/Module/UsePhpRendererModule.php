<?php

declare(strict_types=1);

namespace Polidog\UsePhpBearModule\Module;

use BEAR\Resource\RenderInterface;
use Polidog\UsePhpBearModule\UsePhpRenderer;
use Ray\Di\AbstractModule;
use Ray\Di\Scope;

/**
 * Wires UsePhpRenderer as the default RenderInterface implementation.
 *
 * Usage in your BEAR Module:
 *
 *     $this->install(new UsePhpRendererModule(
 *         templateDir: $appMeta->appDir . '/src/Resource',
 *         cacheDir:    $appMeta->tmpDir . '/psx',
 *     ));
 *
 * `templateDir` is the root of your `.psx` files, mirroring the resource
 * class structure under `\Resource\`. `cacheDir` is where compiled
 * sibling files are written (matches `vendor/bin/usephp compile --cache=`).
 *
 * `autoCompile=true` (default) compiles missing/stale templates on first
 * render — convenient for local dev. Pass `false` for production where
 * the build step has already populated the cache.
 */
final class UsePhpRendererModule extends AbstractModule
{
    /**
     * @param (\Closure(\BEAR\Resource\ResourceObject): string)|null $templateResolver
     *        See UsePhpRenderer::__construct for semantics.
     */
    public function __construct(
        private readonly string $templateDir,
        private readonly string $cacheDir,
        private readonly bool $autoCompile = true,
        private readonly ?\Closure $templateResolver = null,
        ?AbstractModule $module = null,
    ) {
        parent::__construct($module);
    }

    protected function configure(): void
    {
        $this->bind(UsePhpRenderer::class)
            ->toConstructor(
                UsePhpRenderer::class,
                'templateDir=template_dir,cacheDir=cache_dir,autoCompile=auto_compile,templateResolver=template_resolver',
            )
            ->in(Scope::SINGLETON);

        $this->bind()->annotatedWith('template_dir')->toInstance($this->templateDir);
        $this->bind()->annotatedWith('cache_dir')->toInstance($this->cacheDir);
        $this->bind()->annotatedWith('auto_compile')->toInstance($this->autoCompile);
        $this->bind()->annotatedWith('template_resolver')->toInstance($this->templateResolver);

        $this->bind(RenderInterface::class)->to(UsePhpRenderer::class);
    }
}
