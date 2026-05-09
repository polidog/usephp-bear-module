<?php

declare(strict_types=1);

namespace Polidog\UsePhpBearModule;

use BEAR\Resource\ResourceObject;
use Polidog\UsePhp\Runtime\Action;
use Polidog\UsePhp\Runtime\ComponentState;
use Polidog\UsePhp\Runtime\Element;
use Polidog\UsePhp\Runtime\RenderContext;
use Polidog\UsePhp\Runtime\Renderer;
use Polidog\UsePhp\Snapshot\SnapshotVerificationException;
use Polidog\UsePhp\Storage\StorageFactory;
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
 *     // The same prop bag onGet would have set for the post-action render
 *     // — useState picks up state from the snapshot, but anything OTHER
 *     // than state (precomputed next/prev, server-derived flags, etc.)
 *     // still needs to come through props.
 *     $props = $this->buildProps($newCount);
 *     $this->body = $props;
 *
 *     $partial = $this->responder->handle($this, $_POST, $props);
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

        // The responder operates exclusively in snapshot mode — that's the
        // contract Renderer::renderPartial below assumes (it always builds a
        // snapshot-backed Renderer and serialises a Snapshot). Allowing a
        // session/memory action through would mutate state in one storage
        // and serialise a different one back to the client. Reject early.
        if ($action->storageType !== null && $action->storageType !== StorageType::Snapshot) {
            return null;
        }

        // A signed snapshot is mandatory. Without one, a client could
        // bootstrap arbitrary state on the server simply by POSTing a
        // setState — the snapshot is the only thing tying the request to a
        // previously rendered (and signed) state.
        if (!\is_string($snapshotJson) || $snapshotJson === '') {
            return null;
        }

        $serializer = $this->app->getSnapshotSerializer();

        // Restore state from the client-supplied snapshot. We do this BEFORE
        // setting RenderContext so the restored state is in the static cache
        // when fc() looks it up by instanceId during the re-render below.
        try {
            $snapshot = $serializer->deserialize($snapshotJson);
        } catch (SnapshotVerificationException) {
            return null;
        }

        // The signature only proves we minted this snapshot — it doesn't
        // tell us WHICH component it was for. A signed snapshot from
        // /counter (state=[5]) replayed against /todo's wrapper would
        // otherwise pass and silently overwrite the wrong component's
        // state. Bind the snapshot to the posted instanceId.
        if ($snapshot->getInstanceId() !== $instanceId) {
            return null;
        }

        ComponentState::fromSnapshot($snapshot);

        // Apply the submitted action. Today only setState is supported —
        // the same scope the standalone usePHP runtime handles. Always
        // target the posted instance id (validated above) under snapshot
        // storage (validated above).
        if ($action->type === 'setState') {
            $state = ComponentState::getInstance($instanceId, StorageType::Snapshot);
            $index = (int) ($action->payload['index'] ?? 0);
            $state->setState($index, $action->payload['value'] ?? null);
        }

        // Re-render the template under a fresh RenderContext. fc() will pick
        // up the restored & mutated state via ComponentState::getInstance().
        RenderContext::setApp($this->app);
        try {
            RenderContext::beginRender();

            $template = $this->resolveTemplateOrFail($ro);
            $callable = $this->renderer->loadTemplate($template);
            $element = $callable($props);

            return $this->renderPartial($element, $instanceId);
        } finally {
            RenderContext::clearApp();
            // Snapshot state is now back on the wire; keeping it in the
            // process-wide ComponentState / SnapshotStorage caches would
            // leak across requests in long-running workers (Swoole,
            // RoadRunner, FrankenPHP, …) and across renders inside one
            // request. Drop it here so each render is a clean slate.
            ComponentState::clearInstances();
            StorageFactory::reset();
        }
    }

    /**
     * Same path resolution `UsePhpRenderer::render()` uses, but exposed via
     * the renderer's public helpers and re-emitting the same "template not
     * found" message so callers see consistent diagnostics whether they
     * arrived through the GET path or this responder.
     */
    private function resolveTemplateOrFail(ResourceObject $ro): string
    {
        $template = $this->renderer->resolveTemplate($ro);
        if (!\is_file($template)) {
            throw new \RuntimeException(
                'PSX template not found for ' . $ro::class . ': ' . $template
                . '. Either create the .psx file, set #[Template(\'...\')] on the resource class, '
                . 'or pass a custom templateResolver to UsePhpRenderer.'
            );
        }
        return $template;
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
     *
     * Returns `null` when the wrapper isn't in the rendered tree at all.
     * Falling back to "render the whole element" in that case would risk
     * leaking the entire page (e.g. `<html>…</html>`) into a partial
     * response, which would corrupt the live DOM that usephp.js drops the
     * fragment into.
     */
    private function renderPartial(Element $element, string $instanceId): ?string
    {
        $wrapper = $this->findWrapper($element, $instanceId);
        if ($wrapper === null) {
            return null;
        }

        $serializer = $this->app->getSnapshotSerializer();
        $renderer = new Renderer($instanceId, $serializer, StorageType::Snapshot);

        $inner = '';
        foreach ($wrapper->children as $child) {
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
