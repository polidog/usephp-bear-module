<?php

declare(strict_types=1);

namespace Polidog\UsePhpBearModule\Tests\Fixtures\Resource\Page;

use BEAR\Resource\ResourceObject;
use Polidog\UsePhpBearModule\Annotation\Template;

#[Template('shared/Counter.psx')]
final class CustomCounter extends ResourceObject
{
    public function onGet(int $initial = 0): static
    {
        $this->body = ['count' => $initial, 'label' => 'Custom'];
        return $this;
    }
}
