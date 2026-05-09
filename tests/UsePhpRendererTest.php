<?php

declare(strict_types=1);

namespace Polidog\UsephpBearRenderer\Tests;

use PHPUnit\Framework\TestCase;
use Polidog\UsePhp\Psx\CompileCommand;
use BEAR\Resource\ResourceObject;
use Polidog\UsePhp\Runtime\ComponentState;
use Polidog\UsePhp\Runtime\RenderContext;
use Polidog\UsePhp\Storage\StorageFactory;
use Polidog\UsePhp\UsePHP;
use Polidog\UsephpBearRenderer\Tests\Fixtures\Resource\Page\Counter;
use Polidog\UsephpBearRenderer\Tests\Fixtures\Resource\Page\CustomCounter;
use Polidog\UsephpBearRenderer\Tests\Fixtures\Resource\Page\HookCounter;
use Polidog\UsephpBearRenderer\UsePhpRenderer;

class UsePhpRendererTest extends TestCase
{
    private string $templateDir;
    private string $cacheDir;

    protected function setUp(): void
    {
        $this->templateDir = __DIR__ . '/Fixtures/templates';
        $this->cacheDir = \sys_get_temp_dir() . '/psx-bear-renderer-test-' . \uniqid();
        // Static caches in usePHP runtime classes can leak across tests
        // (e.g. a Tier 3 case that follows a responder test would observe
        // state populated by the responder's render). Reset before each
        // case so every test sees a pristine runtime.
        ComponentState::clearInstances();
        RenderContext::clearApp();
        // SnapshotStorage is a process-wide singleton inside StorageFactory,
        // so clearing ComponentState alone leaves stored state values from
        // earlier components in place. Reset the factory too.
        StorageFactory::reset();
    }

    protected function tearDown(): void
    {
        if (\is_dir($this->cacheDir)) {
            foreach (\glob($this->cacheDir . '/*') ?: [] as $f) {
                @\unlink($f);
            }
            @\rmdir($this->cacheDir);
        }
        ComponentState::clearInstances();
        RenderContext::clearApp();
        // SnapshotStorage is a process-wide singleton inside StorageFactory,
        // so clearing ComponentState alone leaves stored state values from
        // earlier components in place. Reset the factory too.
        StorageFactory::reset();
    }

    public function testRendersPsxTemplateUsingResourceBody(): void
    {
        $renderer = new UsePhpRenderer($this->templateDir, $this->cacheDir);

        $ro = new Counter();
        $ro->onGet(initial: 5);
        $html = $renderer->render($ro);

        self::assertStringContainsString('Count is 5', $html);
        self::assertStringContainsString('<h1>Count</h1>', $html);
        // usePHP currently emits className= verbatim — that's its convention,
        // and we just confirm the attribute survives the renderer.
        self::assertStringContainsString('counter', $html);
    }

    public function testAssignsRenderedHtmlToResourceView(): void
    {
        $renderer = new UsePhpRenderer($this->templateDir, $this->cacheDir);

        $ro = new Counter();
        $ro->onGet(initial: 3);
        $html = $renderer->render($ro);

        self::assertSame($html, $ro->view);
    }

    public function testCompilesTemplateOnFirstRenderToCacheDir(): void
    {
        $renderer = new UsePhpRenderer($this->templateDir, $this->cacheDir);

        $ro = new Counter();
        $ro->onGet();
        $renderer->render($ro);

        $expected = CompileCommand::cachePathFor(
            $this->cacheDir,
            $this->templateDir . '/Page/Counter.psx',
        );
        self::assertFileExists($expected);
    }

    public function testRecompilesWhenSourceIsNewerThanCache(): void
    {
        $renderer = new UsePhpRenderer($this->templateDir, $this->cacheDir);

        $ro = new Counter();
        $ro->onGet(initial: 1);
        $renderer->render($ro);

        $cachePath = CompileCommand::cachePathFor(
            $this->cacheDir,
            $this->templateDir . '/Page/Counter.psx',
        );
        $cacheBefore = \file_get_contents($cachePath);

        // Force a stale-cache state and render again — should recompile.
        \touch($cachePath, \time() - 3600);
        \touch($this->templateDir . '/Page/Counter.psx', \time());

        $renderer->render($ro);
        $cacheAfter = \file_get_contents($cachePath);

        self::assertSame($cacheBefore, $cacheAfter, 'Recompiled output should be deterministic for unchanged source');
        self::assertGreaterThanOrEqual(
            \time() - 5,
            \filemtime($cachePath),
            'Cache file should have been rewritten',
        );
    }

    public function testThrowsWhenAutoCompileDisabledAndCacheMissing(): void
    {
        $renderer = new UsePhpRenderer($this->templateDir, $this->cacheDir, autoCompile: false);

        $ro = new Counter();
        $ro->onGet();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Compiled PSX template missing');
        $renderer->render($ro);
    }

    public function testTemplateAttributeOverridesConvention(): void
    {
        $renderer = new UsePhpRenderer($this->templateDir, $this->cacheDir);
        $ro = new CustomCounter();
        $ro->onGet(initial: 7);
        $html = $renderer->render($ro);

        // The class is `Page\CustomCounter` (would default to
        // Page/CustomCounter.psx) but #[Template('shared/Counter.psx')]
        // redirects to the shared template.
        self::assertStringContainsString('SHARED-COUNTER', $html);
        self::assertStringContainsString('<p>7</p>', $html);
    }

    public function testCustomTemplateResolverBypassesAttributeAndConvention(): void
    {
        $renderer = new UsePhpRenderer(
            $this->templateDir,
            $this->cacheDir,
            templateResolver: static fn(ResourceObject $ro): string => 'shared/Counter.psx',
        );

        // (a) Convention path: Counter has no #[Template], so the resolver
        //     wins over the FQCN convention (which would pick Page/Counter.psx).
        $ro = new Counter();
        $ro->onGet(initial: 11);
        $html = $renderer->render($ro);
        self::assertStringContainsString('SHARED-COUNTER', $html);
        self::assertStringContainsString('<p>11</p>', $html);

        // (b) Attribute path: CustomCounter HAS #[Template('shared/Counter.psx')],
        //     so the resolver and the attribute happen to agree — but the
        //     point is that the resolver is consulted first. Use a lambda
        //     that returns a SEPARATE template to confirm the resolver wins
        //     over the attribute.
        $rendererB = new UsePhpRenderer(
            $this->templateDir,
            $this->cacheDir,
            templateResolver: static fn(ResourceObject $ro): string => 'Page/Counter.psx',
        );
        $custom = new CustomCounter();
        $custom->onGet(initial: 99);
        $htmlB = $rendererB->render($custom);
        // Resolver pointed at Page/Counter.psx (which uses the body's label
        // — CustomCounter sets label='Custom' — and shows "{$label} is {$count}"),
        // not the attribute's shared/Counter.psx (which renders "SHARED-COUNTER").
        self::assertStringContainsString('Custom is 99', $htmlB);
        self::assertStringNotContainsString('SHARED-COUNTER', $htmlB);
    }

    public function testAbsoluteResolverPathIsUsedAsIs(): void
    {
        $absolute = $this->templateDir . '/shared/Counter.psx';
        $renderer = new UsePhpRenderer(
            $this->templateDir . '/no-such-dir',
            $this->cacheDir,
            templateResolver: static fn(ResourceObject $ro): string => $absolute,
        );

        $ro = new Counter();
        $ro->onGet(initial: 22);
        $html = $renderer->render($ro);
        self::assertStringContainsString('SHARED-COUNTER', $html);
    }

    public function testAttributeResolutionIsCachedPerClass(): void
    {
        // Build two CustomCounter instances; the renderer should hit
        // ReflectionClass once (per class), not per render.
        $renderer = new UsePhpRenderer($this->templateDir, $this->cacheDir);
        $renderer->render((new CustomCounter())->onGet(initial: 1));
        $renderer->render((new CustomCounter())->onGet(initial: 2));

        // Coarse assertion: two renders both produce the same template's
        // output. The cache itself is private — the test ensures that
        // repeated calls don't break behaviour, and serves as a guard
        // against accidentally clearing the cache during refactors.
        $first = $renderer->render((new CustomCounter())->onGet(initial: 3));
        self::assertStringContainsString('SHARED-COUNTER', $first);
    }

    public function testThrowsWhenTemplateMissing(): void
    {
        $renderer = new UsePhpRenderer(
            $this->templateDir . '/no-such-dir',
            $this->cacheDir,
        );

        $ro = new Counter();
        $ro->onGet();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('PSX template not found');
        $renderer->render($ro);
    }

    public function testTier3RenderEmitsDataUsePhpWrapperAndSnapshot(): void
    {
        // Tier 3 needs RenderContext and ComponentState pristine — the
        // static caches on those are session-scoped, so other tests that
        // happen to call useState (none today) could otherwise leak in.
        ComponentState::clearInstances();
        RenderContext::clearApp();
        // SnapshotStorage is a process-wide singleton inside StorageFactory,
        // so clearing ComponentState alone leaves stored state values from
        // earlier components in place. Reset the factory too.
        StorageFactory::reset();

        $usePhp = (new UsePHP())->setSnapshotSecret('test-secret');
        $renderer = new UsePhpRenderer(
            templateDir: $this->templateDir,
            cacheDir: $this->cacheDir,
            app: $usePhp,
        );

        $ro = new HookCounter();
        $ro->onGet(count: 5, next: 6);
        $html = $renderer->render($ro);

        // The fc() wrapper should have produced a data-usephp wrapper with
        // a signed snapshot the responder can later verify.
        self::assertStringContainsString('data-usephp="', $html);
        self::assertStringContainsString('data-usephp-snapshot=', $html);
        self::assertStringContainsString('hook-counter', $html, 'snapshot wrapper should mention the fc() key');

        // The current state value renders into the inner content.
        self::assertStringContainsString('data-test="count">5<', $html);

        // The +1 button's wire:click action carries the precomputed `next`
        // (6) — confirms props made it through fc()'s render pass.
        self::assertStringContainsString('&quot;value&quot;:6', $html);

        self::assertSame($renderer->getApp(), $usePhp, 'getApp() should expose the configured UsePHP instance');
    }

    public function testTier1RemainsTheDefaultWhenAppIsNotProvided(): void
    {
        $renderer = new UsePhpRenderer($this->templateDir, $this->cacheDir);

        self::assertNull($renderer->getApp(), 'No app means stateless / Tier 1 mode');

        $ro = new Counter();
        $ro->onGet(initial: 5);
        $html = $renderer->render($ro);

        // No snapshot wrapper for Tier 1.
        self::assertStringNotContainsString('data-usephp-snapshot', $html);
    }
}
