<?php

declare(strict_types=1);

namespace Polidog\UsePhpBearModule\Tests;

use PHPUnit\Framework\TestCase;
use Polidog\UsePhp\Html\H;
use Polidog\UsePhp\Router\RequestContext;
use Polidog\UsePhp\Runtime\ComponentState;
use Polidog\UsePhp\Runtime\Element;
use Polidog\UsePhp\Runtime\RenderContext;
use Polidog\UsePhp\Storage\StorageFactory;
use Polidog\UsePhp\UsePHP;
use Polidog\UsePhpBearModule\Tests\Fixtures\Resource\DeferEndpoint;
use Polidog\UsePhpBearModule\UsePhpDeferredResponder;
use Polidog\UsePhpBearModule\UsePhpRenderer;

class UsePhpDeferredResponderTest extends TestCase
{
    private const COMPONENT_FQCN = 'Tests\\Defer\\UserHeader';
    private const DEFER_NAME = 'user-header';
    private const CACHE_CONTROL = 'private, no-store';

    private string $templateDir;
    private string $cacheDir;

    protected function setUp(): void
    {
        $this->templateDir = __DIR__ . '/Fixtures/templates';
        $this->cacheDir = \sys_get_temp_dir() . '/psx-defer-test-' . \uniqid();
        ComponentState::clearInstances();
        RenderContext::clearApp();
        StorageFactory::reset();
        \http_response_code(200);
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
        StorageFactory::reset();
        \http_response_code(200);
    }

    public function testConstructorRejectsRendererWithoutApp(): void
    {
        $renderer = new UsePhpRenderer($this->templateDir, $this->cacheDir);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Tier 3 mode');
        new UsePhpDeferredResponder($renderer);
    }

    public function testReturnsNullWhenNotADeferRoute(): void
    {
        $responder = $this->buildResponder();
        $ro = new DeferEndpoint();

        $result = $responder->handle($ro, new RequestContext(
            method: 'GET',
            path: '/page/counter',
        ));

        self::assertNull($result, 'A non-/_defer path is not the responder\'s concern');
        // The caller owns the fall-through response — we must not have
        // touched headers or status.
        self::assertSame([], $ro->headers);
        self::assertSame(200, $ro->code);
    }

    public function testReturnsNullForNonGetOnDeferPrefix(): void
    {
        $responder = $this->buildResponder();
        $ro = new DeferEndpoint();

        $result = $responder->handle($ro, new RequestContext(
            method: 'POST',
            path: '/_defer/' . self::DEFER_NAME,
        ));

        self::assertNull($result, 'Deferred endpoints are GET-only');
    }

    public function testRendersRegisteredDeferredComponent(): void
    {
        $responder = $this->buildResponder();
        $ro = new DeferEndpoint();

        $html = $responder->handle($ro, new RequestContext(
            method: 'GET',
            path: '/_defer/' . self::DEFER_NAME,
        ));

        self::assertNotNull($html);
        self::assertStringContainsString('Hello guest', $html);
        // usePHP's per-endpoint Cache-Control is carried through BEAR's
        // ResourceObject rather than written straight to PHP's output.
        self::assertSame(self::CACHE_CONTROL, $ro->headers['Cache-Control'] ?? null);
        // Success path leaves BEAR's default status untouched.
        self::assertSame(200, $ro->code);
    }

    public function testForwardsQueryParamsAsProps(): void
    {
        $responder = $this->buildResponder();
        $ro = new DeferEndpoint();

        $html = $responder->handle($ro, new RequestContext(
            method: 'GET',
            path: '/_defer/' . self::DEFER_NAME,
            query: ['user' => 'alice'],
        ));

        self::assertNotNull($html);
        self::assertStringContainsString('Hello alice', $html);
    }

    public function testUnknownDeferNameMapsNotFoundOntoResourceObject(): void
    {
        $responder = $this->buildResponder();
        $ro = new DeferEndpoint();

        $body = $responder->handle($ro, new RequestContext(
            method: 'GET',
            path: '/_defer/no-such-component',
        ));

        // handleDeferred() returns a non-null body for a 404 (it's still a
        // defer route, just an unregistered name) — the responder maps the
        // status onto the ResourceObject and clears PHP's global code.
        self::assertNotNull($body);
        self::assertSame(404, $ro->code);
        self::assertSame(200, \http_response_code(), 'global code must be reset so it cannot poison a later response');
    }

    public function testHeaderEmitterIsRestoredAfterHandle(): void
    {
        $responder = $this->buildResponder();
        $app = $this->app;

        $responder->handle(new DeferEndpoint(), new RequestContext(
            method: 'GET',
            path: '/_defer/' . self::DEFER_NAME,
        ));

        // After handle() the default \header() behaviour must be back, so a
        // subsequent render on the same app instance isn't redirected into
        // the (now out-of-scope) capture closure.
        $captured = [];
        $app->withHeaderEmitter(static function (string $h) use (&$captured): void {
            $captured[] = $h;
        });
        $app->withHeaderEmitter(null);
        self::assertSame([], $captured, 'sanity: emitter swap itself emits nothing');
    }

    private UsePHP $app;

    private function buildResponder(): UsePhpDeferredResponder
    {
        $this->app = (new UsePHP())->setSnapshotSecret('defer-test-secret');
        $this->app->registerComponent(
            self::COMPONENT_FQCN,
            static fn (array $props): Element => H::header(
                children: 'Hello ' . (string) ($props['user'] ?? 'guest'),
            ),
        );
        $this->app->registerDeferred(self::DEFER_NAME, self::COMPONENT_FQCN, self::CACHE_CONTROL);

        $renderer = new UsePhpRenderer(
            templateDir: $this->templateDir,
            cacheDir: $this->cacheDir,
            app: $this->app,
        );
        return new UsePhpDeferredResponder($renderer);
    }
}
