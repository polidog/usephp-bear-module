<?php

declare(strict_types=1);

namespace Polidog\UsePhpBearModule\Tests\Fixtures\Resource;

use BEAR\Resource\ResourceObject;

/**
 * Minimal resource standing in for the app's `/_defer/...` catch resource.
 * {@see \Polidog\UsePhpBearModule\UsePhpDeferredResponder} resolves the
 * component from the defer registry, not from this resource, so it only
 * needs to be a ResourceObject carrying headers/code/view.
 */
final class DeferEndpoint extends ResourceObject
{
    public function onGet(): static
    {
        return $this;
    }
}
