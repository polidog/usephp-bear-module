<?php

declare(strict_types=1);

namespace Polidog\UsephpBearRenderer\Annotation;

use Attribute;

/**
 * Override the convention-based PSX template path on a ResourceObject.
 *
 * Without this attribute, `MyApp\Resource\Page\Counter` is rendered with
 * `<templateDir>/Page/Counter.psx`. Use `#[Template]` when you want a
 * different mapping — e.g., reusing one template for several resources,
 * splitting templates by HTTP method, or organising templates outside
 * the `Resource\` namespace mirror.
 *
 *     // Class-level: applies to every method.
 *     #[Template('shared/Counter.psx')]
 *     final class Counter extends ResourceObject { ... }
 *
 *     // Method-level: only this method uses the override.
 *     final class Counter extends ResourceObject
 *     {
 *         #[Template('Counter/Show.psx')]
 *         public function onGet(int $initial = 0): static { ... }
 *
 *         #[Template('Counter/Edit.psx')]
 *         public function onPost(int $count): static { ... }
 *     }
 *
 * The path is resolved relative to the renderer's `templateDir`. Absolute
 * paths are also accepted and used as-is.
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD)]
final readonly class Template
{
    public function __construct(public string $path)
    {
    }
}
