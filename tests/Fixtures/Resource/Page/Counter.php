<?php

declare(strict_types=1);

namespace Polidog\UsePhpBearModule\Tests\Fixtures\Resource\Page;

use BEAR\Resource\ResourceObject;

final class Counter extends ResourceObject
{
    public function onGet(int $initial = 0): static
    {
        $this->body = ['count' => $initial, 'label' => 'Count'];
        return $this;
    }
}
