<?php

declare(strict_types=1);

namespace Polidog\UsePhpBearModule\Tests;

use PHPUnit\Framework\TestCase;
use Polidog\UsePhp\Runtime\ComponentState;
use Polidog\UsePhp\Runtime\RenderContext;
use Polidog\UsePhp\Runtime\Snapshot;
use Polidog\UsePhp\Storage\StorageFactory;
use Polidog\UsePhp\UsePHP;
use Polidog\UsePhpBearModule\Tests\Fixtures\Resource\Page\HookCounter;
use Polidog\UsePhpBearModule\UsePhpActionResponder;
use Polidog\UsePhpBearModule\UsePhpRenderer;

class UsePhpActionResponderTest extends TestCase
{
    private string $templateDir;
    private string $cacheDir;

    protected function setUp(): void
    {
        $this->templateDir = __DIR__ . '/Fixtures/templates';
        $this->cacheDir = \sys_get_temp_dir() . '/psx-responder-test-' . \uniqid();
        // Static caches in usePHP runtime classes can leak between tests in
        // the same process. Reset before each case.
        ComponentState::clearInstances();
        RenderContext::clearApp();
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
        StorageFactory::reset();
    }

    public function testConstructorRejectsRendererWithoutApp(): void
    {
        $renderer = new UsePhpRenderer($this->templateDir, $this->cacheDir);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Tier 3 mode');
        new UsePhpActionResponder($renderer);
    }

    public function testReturnsNullWhenNoActionPosted(): void
    {
        $responder = $this->buildResponder();

        $ro = new HookCounter();
        $ro->onGet();

        self::assertNull($responder->handle($ro, []));
        self::assertNull($responder->handle($ro, ['_usephp_action' => '{"type":"setState"}']), 'missing _usephp_component is not enough');
    }

    public function testReturnsNullOnMalformedActionJson(): void
    {
        $responder = $this->buildResponder();

        $ro = new HookCounter();
        $ro->onGet();

        $partial = $responder->handle($ro, [
            '_usephp_action' => '{not json',
            '_usephp_component' => 'hook-counter#0',
        ]);
        self::assertNull($partial);
    }

    public function testReturnsNullOnSnapshotSignatureMismatch(): void
    {
        $responder = $this->buildResponder('correct-secret');

        // Forge a snapshot signed with a DIFFERENT secret.
        $foreignApp = (new UsePHP())->setSnapshotSecret('attacker-secret');
        $foreignSnapshot = $foreignApp->getSnapshotSerializer()->serialize(
            new Snapshot(componentName: 'fake-fc', key: 'hook-counter', state: [99])
        );

        $ro = new HookCounter();
        $ro->onGet();

        $partial = $responder->handle($ro, [
            '_usephp_action' => \json_encode([
                'type' => 'setState',
                'payload' => ['index' => 0, 'value' => 100],
                'componentId' => 'hook-counter#0',
                'storageType' => 'snapshot',
            ]),
            '_usephp_component' => 'hook-counter#0',
            '_usephp_snapshot' => $foreignSnapshot,
        ]);

        self::assertNull($partial, 'Snapshots signed with a different secret must be rejected');
    }

    public function testReturnsNullWhenSnapshotMissing(): void
    {
        // Without `_usephp_snapshot` a client could bootstrap arbitrary
        // state on the server with a setState — the snapshot is the only
        // thing tying the request to a previously signed state.
        $responder = $this->buildResponder();

        $ro = new HookCounter();
        $ro->onGet();

        $partial = $responder->handle($ro, [
            '_usephp_action' => \json_encode([
                'type' => 'setState',
                'payload' => ['index' => 0, 'value' => 42],
                'componentId' => 'hook-counter#0',
                'storageType' => 'snapshot',
            ]),
            '_usephp_component' => 'hook-counter#0',
            // _usephp_snapshot intentionally omitted
        ]);

        self::assertNull($partial);
    }

    public function testReturnsNullForNonSnapshotStorageType(): void
    {
        // The responder is snapshot-only — Renderer::renderPartial below
        // serialises a snapshot. Letting a session-storage action through
        // would mutate state in one storage and serialise a different one
        // back to the client.
        $responder = $this->buildResponder();

        $ro = new HookCounter();
        $ro->onGet();

        $partial = $responder->handle($ro, [
            '_usephp_action' => \json_encode([
                'type' => 'setState',
                'payload' => ['index' => 0, 'value' => 1],
                'componentId' => 'hook-counter#0',
                'storageType' => 'session',
            ]),
            '_usephp_component' => 'hook-counter#0',
            '_usephp_snapshot' => 'unused-because-rejected-first',
        ]);

        self::assertNull($partial);
    }

    public function testReturnsNullOnSnapshotInstanceIdMismatch(): void
    {
        // A signed snapshot proves we minted it, not which component it was
        // for. The responder must reject snapshots whose embedded
        // componentId doesn't match `_usephp_component`, otherwise an
        // attacker could replay a /counter snapshot against a /todo
        // wrapper and quietly overwrite state.
        $secret = 'shared-secret';
        $usePhp = (new UsePHP())->setSnapshotSecret($secret);
        $renderer = new UsePhpRenderer($this->templateDir, $this->cacheDir, app: $usePhp);
        $responder = new UsePhpActionResponder($renderer);

        // Mint a valid snapshot for a DIFFERENT component, signed with the
        // SAME secret (so signature verification passes).
        $foreignSnapshot = $usePhp->getSnapshotSerializer()->serialize(
            new Snapshot(componentName: 'OtherFC', key: 'other-counter', state: [99])
        );

        $partial = $responder->handle(new HookCounter(), [
            '_usephp_action' => \json_encode([
                'type' => 'setState',
                'payload' => ['index' => 0, 'value' => 100],
                'componentId' => 'hook-counter#0',
                'storageType' => 'snapshot',
            ]),
            '_usephp_component' => 'hook-counter#0',
            '_usephp_snapshot' => $foreignSnapshot,
        ]);

        self::assertNull($partial);
    }

    public function testReturnsNullOnComponentIdMismatch(): void
    {
        // The action's componentId must match _usephp_component — otherwise
        // the responder would mutate state on the wrong component while
        // serialising the snapshot of another (silent loss + tampering vector).
        $responder = $this->buildResponder();

        $ro = new HookCounter();
        $ro->onGet();

        $partial = $responder->handle($ro, [
            '_usephp_action' => \json_encode([
                'type' => 'setState',
                'payload' => ['index' => 0, 'value' => 7],
                'componentId' => 'a-different-component',
                'storageType' => 'snapshot',
            ]),
            '_usephp_component' => 'hook-counter#0',
        ]);

        self::assertNull($partial);
    }

    public function testThrowsConsistentErrorWhenTemplateMissing(): void
    {
        // The responder should re-emit the same "PSX template not found"
        // message UsePhpRenderer::render() uses, so the GET path and the
        // POST path are diagnosable consistently.
        $usePhp = (new UsePHP())->setSnapshotSecret('s');
        $renderer = new UsePhpRenderer(
            templateDir: $this->templateDir . '/no-such-dir',
            cacheDir: $this->cacheDir,
            app: $usePhp,
        );
        $responder = new UsePhpActionResponder($renderer);

        // Build a valid snapshot so the responder gets past the
        // signature/instanceId checks and reaches template resolution.
        $instanceId = 'hook-counter#0';
        $snapshotJson = $usePhp->getSnapshotSerializer()->serialize(
            new Snapshot(componentName: 'hook-counter', key: '0', state: [0])
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('PSX template not found');

        $responder->handle(new HookCounter(), [
            '_usephp_action' => \json_encode([
                'type' => 'setState',
                'payload' => ['index' => 0, 'value' => 1],
                'componentId' => $instanceId,
                'storageType' => 'snapshot',
            ]),
            '_usephp_component' => $instanceId,
            '_usephp_snapshot' => $snapshotJson,
        ]);
    }

    public function testReturnsNullWhenWrapperNotFoundInRenderedTree(): void
    {
        // If the template's render output doesn't include a
        // `<div data-usephp="$instanceId">` (e.g. the client lies about the
        // component id), falling back to "render the whole element" would
        // leak the full page into the partial response. Confirm the
        // responder bails instead.
        $secret = 'shared-secret';
        $usePhp = (new UsePHP())->setSnapshotSecret($secret);
        $renderer = new UsePhpRenderer(
            templateDir: $this->templateDir,
            cacheDir: $this->cacheDir,
            app: $usePhp,
        );
        $responder = new UsePhpActionResponder($renderer);

        // Build a snapshot with a componentId that the template will never
        // emit. The serializer signs it correctly so signature verification
        // passes; only findWrapper() will fail.
        $bogusInstanceId = 'NotARealComponent#0';
        $snapshot = new Snapshot(
            componentName: 'NotARealComponent',
            key: '0',
            state: [0],
        );
        $snapshotJson = $usePhp->getSnapshotSerializer()->serialize($snapshot);

        $partial = $responder->handle($ro = new HookCounter(), [
            '_usephp_action' => \json_encode([
                'type' => 'setState',
                'payload' => ['index' => 0, 'value' => 1],
                'componentId' => $bogusInstanceId,
                'storageType' => 'snapshot',
            ]),
            '_usephp_component' => $bogusInstanceId,
            '_usephp_snapshot' => $snapshotJson,
        ], ['count' => 0, 'next' => 1]);

        self::assertNull($partial);
    }

    public function testValidSetStateUpdatesFragmentAndEmitsNewSnapshot(): void
    {
        $secret = 'shared-secret';
        $usePhp = (new UsePHP())->setSnapshotSecret($secret);
        $renderer = new UsePhpRenderer(
            templateDir: $this->templateDir,
            cacheDir: $this->cacheDir,
            app: $usePhp,
        );
        $responder = new UsePhpActionResponder($renderer);

        // Render once to (a) discover the actual instanceId fc() generates
        // and (b) capture a valid snapshot for the round-trip.
        $ro = new HookCounter();
        $ro->onGet(count: 5, next: 6);
        $initialHtml = $renderer->render($ro);

        $instanceId = $this->extractAttr($initialHtml, 'data-usephp');
        $snapshotJson = $this->extractAttr($initialHtml, 'data-usephp-snapshot');
        self::assertNotSame('', $instanceId);
        self::assertNotSame('', $snapshotJson);

        // The renderer's `renderWithHooks` already drops the in-memory
        // state in its finally block, so no manual cleanup is needed
        // between the GET render and the POST handle().

        // Simulate the +1 click: setState(0, 6).
        $partial = $responder->handle($ro, [
            '_usephp_action' => \json_encode([
                'type' => 'setState',
                'payload' => ['index' => 0, 'value' => 6],
                'componentId' => $instanceId,
                'storageType' => 'snapshot',
            ]),
            '_usephp_component' => $instanceId,
            '_usephp_snapshot' => $snapshotJson,
        ], ['count' => 6, 'next' => 7]);

        self::assertNotNull($partial);
        // The fragment is the *inner* HTML of [data-usephp]; usephp.js
        // replaces innerHTML on the existing wrapper, so the wrapper itself
        // must NOT be in the response.
        self::assertStringNotContainsString('data-usephp="', $partial);
        // New count value reflected.
        self::assertStringContainsString('data-test="count">6<', $partial);
        // The +1 button's setState now carries the post-action `next` (7).
        self::assertStringContainsString('&quot;value&quot;:7', $partial);
        // Updated snapshot field is appended for the client to swap in.
        self::assertStringContainsString('data-usephp-snapshot-update', $partial);
    }

    private function buildResponder(string $secret = 'test-secret'): UsePhpActionResponder
    {
        $usePhp = (new UsePHP())->setSnapshotSecret($secret);
        $renderer = new UsePhpRenderer(
            templateDir: $this->templateDir,
            cacheDir: $this->cacheDir,
            app: $usePhp,
        );
        return new UsePhpActionResponder($renderer);
    }

    /**
     * Extract an attribute value from a rendered HTML chunk. The value is
     * un-escaped (htmlspecialchars-decoded) so callers receive raw JSON.
     * Both single- and double-quoted attribute values are supported, since
     * usePHP emits `data-usephp-snapshot='...'` with single quotes.
     */
    private function extractAttr(string $html, string $name): string
    {
        $pattern = '/' . \preg_quote($name, '/') . '="([^"]*)"/';
        if (\preg_match($pattern, $html, $matches) === 1) {
            return \htmlspecialchars_decode($matches[1], \ENT_QUOTES);
        }
        $pattern = '/' . \preg_quote($name, '/') . "='([^']*)'/";
        if (\preg_match($pattern, $html, $matches) === 1) {
            return \htmlspecialchars_decode($matches[1], \ENT_QUOTES);
        }
        return '';
    }
}
