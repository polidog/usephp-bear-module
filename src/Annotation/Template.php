<?php

declare(strict_types=1);

namespace Polidog\UsephpBearRenderer\Annotation;

use Attribute;

/**
 * Override the convention-based PSX template path on a ResourceObject.
 *
 * Without this attribute, `MyApp\Resource\Page\Counter` is rendered with
 * `<templateDir>/Page/Counter.psx`. Use `#[Template]` when you want a
 * different mapping — e.g., reusing one template for several resources
 * or organising templates outside the `Resource\` namespace mirror.
 *
 *     #[Template('shared/Counter.psx')]
 *     final class Counter extends ResourceObject { ... }
 *
 * The path is resolved relative to the renderer's `templateDir`. Absolute
 * paths are also accepted and used as-is.
 *
 * Class-level only. BEAR's RenderInterface::render($ro) doesn't report
 * which resource method (onGet / onPost / …) was invoked, so a renderer
 * can't reliably resolve a method-level override. If you need different
 * templates per HTTP method, expose distinct resources or split the
 * representation in another way.
 */
#[Attribute(Attribute::TARGET_CLASS)]
final readonly class Template
{
    public function __construct(public string $path)
    {
    }
}
