<?php

declare(strict_types=1);

namespace Polidog\UsephpBearRenderer\Tests;

use PHPUnit\Framework\TestCase;
use Polidog\UsePhp\Psx\CompileCommand;
use Polidog\UsephpBearRenderer\Tests\Fixtures\Resource\Page\Counter;
use Polidog\UsephpBearRenderer\Tests\Fixtures\Resource\Page\CustomCounter;
use Polidog\UsephpBearRenderer\Tests\Fixtures\Resource\Page\MethodOverride;
use Polidog\UsephpBearRenderer\UsePhpRenderer;

class UsePhpRendererTest extends TestCase
{
    private string $templateDir;
    private string $cacheDir;

    protected function setUp(): void
    {
        $this->templateDir = __DIR__ . '/Fixtures/templates';
        $this->cacheDir = \sys_get_temp_dir() . '/psx-bear-renderer-test-' . \uniqid();
    }

    protected function tearDown(): void
    {
        if (\is_dir($this->cacheDir)) {
            foreach (\glob($this->cacheDir . '/*') ?: [] as $f) {
                @\unlink($f);
            }
            @\rmdir($this->cacheDir);
        }
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

    public function testMethodLevelTemplateAttributeBeatsClassLevel(): void
    {
        $renderer = new UsePhpRenderer($this->templateDir, $this->cacheDir);
        $ro = new MethodOverride();
        $ro->onGet();
        $html = $renderer->render($ro);

        // class-level says `class-level.psx` (which doesn't exist) but the
        // method-level attribute on onGet wins, picking shared/Counter.psx.
        self::assertStringContainsString('SHARED-COUNTER', $html);
        self::assertStringContainsString('<p>99</p>', $html);
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
}
