<?php

declare(strict_types=1);

namespace Polidog\UsephpBearRenderer;

use BEAR\Resource\ResourceObject;
use Polidog\UsePhp\Runtime\Action;
use Polidog\UsePhp\Runtime\ComponentState;
use Polidog\UsePhp\Runtime\Element;
use Polidog\UsePhp\Runtime\RenderContext;
use Polidog\UsePhp\Runtime\Renderer;
use Polidog\UsePhp\Snapshot\SnapshotVerificationException;
use Polidog\UsePhp\Storage\StorageType;
use Polidog\UsePhp\UsePHP;

/**
 * Handles `_usephp_action` POST submissions for resources rendered via
 * {@see UsePhpRenderer} in Tier 3 (hooks + snapshot) mode.
 *
 * Typical usage from a Page resource's `onPost`:
 *
 * ```php
 * public function onPost(): static
 * {
 *     $partial = $this->responder->handle($this, $_POST);
 *     if ($partial !== null) {
 *         $this->view = $partial;
 *     }
 *     return $this;
 * }
 * ```
 *
 * The responder:
 *  1. Restores the component state from `_usephp_snapshot`.
 *  2. Applies the submitted `_usephp_action` (currently `setState`).
 *  3. Re-invokes the resource's PSX template under `RenderContext` so
 *     `fc()`/`useState` see the restored & mutated state.
 *  4. Returns the HTML fragment that `usephp.js` injects into the
 *     `[data-usephp]` element on the client, plus a hidden snapshot field
 *     with the updated state for the next round-trip.
 *
 * The fragment intentionally does NOT include the outer `<div data-usephp>`
 * wrapper — that element already exists in the live DOM, and `usephp.js`
 * replaces only its `innerHTML`.
 */
final class UsePhpActionResponder
{
    private readonly UsePHP $app;

    /**
     * The responder always operates on the same {@see UsePHP} instance the
     * renderer was configured with — sharing the snapshot serializer is
     * critical, otherwise signature verification on the way in and snapshot
     * embedding on the way out can disagree in subtle ways. We therefore
     * derive the app from the renderer rather than letting the caller pass
     * a second one.
     */
    public function __construct(
        private readonly UsePhpRenderer $renderer,
    ) {
        $app = $renderer->getApp();
        if ($app === null) {
            throw new \InvalidArgumentException(
                'UsePhpActionResponder requires a renderer constructed with a UsePHP instance '
                . '(Tier 3 mode). Pass `app: $usePhp` to UsePhpRenderer\'s constructor.'
            );
        }
        $this->app = $app;
    }

    /**
     * Apply the submitted action and return the partial HTML, or `null` if
     * the request is not a usePHP action POST.
     *
     * @param array<string, mixed> $post  Typically `$_POST`.
     * @param array<string, mixed> $props Optional template props (the same
     *                                    body the resource's `onGet` would
     *                                    have set).
     */
    public function handle(ResourceObject $ro, array $post, array $props = []): ?string
    {
        $actionJson = $post['_usephp_action'] ?? null;
        $instanceId = $post['_usephp_component'] ?? null;
        $snapshotJson = $post['_usephp_snapshot'] ?? null;
        if (!\is_string($actionJson) || !\is_string($instanceId)) {
            return null;
        }

        try {
            /** @var array<string, mixed> $actionData */
            $actionData = \json_decode($actionJson, true, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }
        $action = Action::fromArray($actionData);

        // The action's componentId, if present, MUST match `_usephp_component`.
        // Otherwise a client could mutate state on a different component than
        // the one whose snapshot we just rebuilt — the resulting setState
        // would write to an instance whose state we then never serialise back
        // (silent loss + cross-component tampering).
        if ($action->componentId !== null && $action->componentId !== $instanceId) {
            return null;
        }

        $serializer = $this->app->getSnapshotSerializer();

        // Restore state from the client-supplied snapshot. We do this BEFORE
        // setting RenderContext so the restored state is in the static cache
        // when fc() looks it up by instanceId during the re-render below.
        if (\is_string($snapshotJson) && $snapshotJson !== '') {
            try {
                $snapshot = $serializer->deserialize($snapshotJson);
                ComponentState::fromSnapshot($snapshot);
            } catch (SnapshotVerificationException) {
                return null;
            }
        }

        // Apply the submitted action. Today only setState is supported —
        // the same scope the standalone usePHP runtime handles. Always
        // target the posted instance id (validated above).
        if ($action->type === 'setState') {
            $state = ComponentState::getInstance($instanceId, $action->storageType ?? StorageType::Snapshot);
            $index = (int) ($action->payload['index'] ?? 0);
            $state->setState($index, $action->payload['value'] ?? null);
        }

        // Re-render the template under a fresh RenderContext. fc() will pick
        // up the restored & mutated state via ComponentState::getInstance().
        RenderContext::setApp($this->app);
        try {
            RenderContext::beginRender();

            $template = $this->renderer->resolveTemplate($ro);
            $callable = $this->renderer->loadTemplate($template);
            $element = $callable($props);

            return $this->renderPartial($element, $instanceId);
        } finally {
            RenderContext::clearApp();
        }
    }

    /**
     * Render the inner fragment of the data-usephp wrapper produced by
     * `fc()`, plus the hidden snapshot-update field that `usephp.js` reads
     * back into `dataset.usephpSnapshot` after replacing innerHTML.
     *
     * The template may wrap the component in arbitrary outer markup (e.g.
     * `<html><body>{$widget($props)}</body></html>`); we walk the tree and
     * extract the matching `[data-usephp="$instanceId"]` subtree, then emit
     * only its children — usephp.js replaces innerHTML on the existing
     * wrapper, so the wrapper itself must NOT be re-emitted.
     */
    private function renderPartial(Element $element, string $instanceId): string
    {
        $serializer = $this->app->getSnapshotSerializer();
        $renderer = new Renderer($instanceId, $serializer, StorageType::Snapshot);

        $wrapper = $this->findWrapper($element, $instanceId) ?? $element;
        $children = $wrapper->type === 'div' && isset($wrapper->props['data-usephp'])
            ? $wrapper->children
            : [$wrapper];

        $inner = '';
        foreach ($children as $child) {
            $inner .= $renderer->renderElement($child);
        }

        // Append the new snapshot so the client can update its dataset for
        // the next action cycle. usephp.js looks for the
        // [data-usephp-snapshot-update] hidden field on the response.
        $state = ComponentState::getInstance($instanceId, StorageType::Snapshot);
        $snapshotJson = $serializer->serialize($state->createSnapshot());
        $inner .= \sprintf(
            '<input type="hidden" name="_usephp_snapshot" value="%s" data-usephp-snapshot-update />',
            \htmlspecialchars($snapshotJson, \ENT_QUOTES, 'UTF-8')
        );

        return $inner;
    }

    /**
     * Depth-first search for the `<div data-usephp="$instanceId">` element
     * within the rendered tree. Returns null if the template didn't include
     * the matching component (which shouldn't happen for a well-formed PSX
     * page template that embeds an `fc()` widget keyed to `$instanceId`).
     */
    private function findWrapper(Element $element, string $instanceId): ?Element
    {
        if (
            $element->type === 'div'
            && ($element->props['data-usephp'] ?? null) === $instanceId
        ) {
            return $element;
        }
        foreach ($element->children as $child) {
            if ($child instanceof Element) {
                $found = $this->findWrapper($child, $instanceId);
                if ($found !== null) {
                    return $found;
                }
            }
        }
        return null;
    }
}
