<?php

declare(strict_types=1);

namespace Polidog\UsephpBearRenderer\Tests\Fixtures\Resource\Page;

use BEAR\Resource\ResourceObject;

final class HookCounter extends ResourceObject
{
    public function onGet(int $count = 0, int $next = 1): static
    {
        $this->body = ['count' => $count, 'next' => $next];
        return $this;
    }
}
