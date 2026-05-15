<?php

declare(strict_types=1);

namespace Polidog\UsePhpBearModule;

use BEAR\Resource\ResourceObject;
use Polidog\UsePhp\Router\RequestContext;
use Polidog\UsePhp\Runtime\ComponentState;
use Polidog\UsePhp\Storage\StorageFactory;
use Polidog\UsePhp\UsePHP;

/**
 * Serves usePHP **deferred component** endpoints (`GET /_defer/{name}`) from
 * inside a BEAR resource, mirroring how {@see UsePhpActionResponder} serves
 * `_usephp_action` POSTs.
 *
 * Deferred rendering (usePHP ≥ 0.2, stabilised through 0.6) splits a
 * per-user component into a cacheable page (which renders only a fallback)
 * and a separate `GET /_defer/{name}` fetch that `usephp.js` issues after
 * load. The framework hook for that fetch is {@see UsePHP::handleDeferred()};
 * this class is the BEAR-side adapter for it.
 *
 * Typical usage from a dedicated defer resource (one resource catching the
 * whole `/_defer/...` path):
 *
 * ```php
 * #[Template(...)] // not needed — the component comes from the defer registry
 * final class Defer extends ResourceObject
 * {
 *     public function __construct(private UsePhpDeferredResponder $responder) {}
 *
 *     public function onGet(): static
 *     {
 *         $html = $this->responder->handle($this);
 *         if ($html === null) {
 *             // Not a defer route — let BEAR 404 / fall through.
 *             $this->code = 404;
 *             return $this;
 *         }
 *         $this->view = $html;
 *         return $this;
 *     }
 * }
 * ```
 *
 * The deferred registry must be populated on the same {@see UsePHP} instance
 * the renderer was built with — either via `loadComponentManifest()` (which
 * auto-loads the `deferred-manifest.php` sidecar `vendor/bin/usephp compile`
 * writes), `registerDeferred()`, or `register()` for `#[Defer]` classes.
 *
 * Header handling: `handleDeferred()` would normally write `Cache-Control`
 * straight to PHP's output via `\header()` and set the status with
 * `\http_response_code()`. Both bypass BEAR's `ResourceObject`. We redirect
 * the header through usePHP's `withHeaderEmitter()` seam and read back
 * `\http_response_code()` so the values land on `$ro->headers` / `$ro->code`
 * and travel through BEAR's normal response pipeline instead.
 */
final class UsePhpDeferredResponder
{
    private readonly UsePHP $app;

    /**
     * The responder operates on the same {@see UsePHP} instance the renderer
     * was configured with — that instance owns the deferred registry and the
     * snapshot serializer, so deriving it from the renderer (rather than
     * accepting a second one) keeps registration and rendering consistent.
     */
    public function __construct(
        private readonly UsePhpRenderer $renderer,
    ) {
        $app = $renderer->getApp();
        if ($app === null) {
            throw new \InvalidArgumentException(
                'UsePhpDeferredResponder requires a renderer constructed with a UsePHP instance '
                . '(Tier 3 mode). Pass `app: $usePhp` to UsePhpRenderer\'s constructor, and '
                . 'register the deferred components on that same instance.'
            );
        }
        $this->app = $app;
    }

    /**
     * Render the deferred component for the current request and return its
     * HTML, or `null` when the request is not a defer route (the caller
     * should then continue with normal routing — typically a 404).
     *
     * `Cache-Control` emitted by usePHP is copied onto `$ro->headers`; an
     * error status (400/404/500) usePHP set is copied onto `$ro->code`. The
     * caller is responsible for assigning the returned string to `$ro->view`
     * (kept symmetric with {@see UsePhpActionResponder::handle()}).
     *
     * @param RequestContext|null $request Defaults to the current request
     *                                     (`RequestContext::fromGlobals()`),
     *                                     matching `UsePHP::handleDeferred()`.
     */
    public function handle(ResourceObject $ro, ?RequestContext $request = null): ?string
    {
        $request ??= RequestContext::fromGlobals();

        /** @var array<string, string> $captured */
        $captured = [];
        $this->app->withHeaderEmitter(static function (string $header) use (&$captured): void {
            $pos = \strpos($header, ':');
            if ($pos === false) {
                return;
            }
            $name = \trim(\substr($header, 0, $pos));
            $value = \trim(\substr($header, $pos + 1));
            if ($name !== '') {
                $captured[$name] = $value;
            }
        });

        try {
            $html = $this->app->handleDeferred($request);
        } finally {
            // Always restore the default \header() behaviour so a later
            // non-defer render on the same app instance isn't redirected.
            $this->app->withHeaderEmitter(null);
            // handleDeferred() resets RenderContext per call; drop the
            // process-wide state caches so a deferred sub-render can't leak
            // into the next request on a long-running worker (Swoole,
            // RoadRunner, FrankenPHP) or the next render in this request.
            ComponentState::clearInstances();
            StorageFactory::reset();
        }

        if ($html === null) {
            // Not a defer route. Don't touch headers/code — the caller owns
            // the fall-through response.
            return null;
        }

        foreach ($captured as $name => $value) {
            $ro->headers[$name] = $value;
        }

        // handleDeferred() signals 400/404/500 via \http_response_code()
        // (not interceptable through the header emitter). Mirror an error
        // status onto the ResourceObject, then reset PHP's global code so it
        // can't poison a later response in the same process. The success
        // path never sets a code, so $ro->code keeps BEAR's default (200).
        $code = \http_response_code();
        if (\is_int($code) && $code >= 400) {
            $ro->code = $code;
            \http_response_code(200);
        }

        return $html;
    }
}
