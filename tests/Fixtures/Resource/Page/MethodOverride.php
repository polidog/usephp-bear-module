<?php

declare(strict_types=1);

namespace Polidog\UsephpBearRenderer\Tests\Fixtures\Resource\Page;

use BEAR\Resource\ResourceObject;
use Polidog\UsephpBearRenderer\Annotation\Template;

#[Template('class-level.psx')]
final class MethodOverride extends ResourceObject
{
    #[Template('shared/Counter.psx')]
    public function onGet(): static
    {
        $this->body = ['count' => 99, 'label' => 'Method'];
        return $this;
    }
}
