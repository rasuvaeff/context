<?php

declare(strict_types=1);

namespace Rasuvaeff\Context\Internal;

use Rasuvaeff\Context\TerminationCause;

/**
 * One cancellable node. States form a DAG: a joined context has two parents.
 *
 * @internal
 */
final class ContextState
{
    /** @var array<int, \Closure(TerminationCause): void> */
    private array $listeners = [];

    /** @var array<int, self> keyed by spl_object_id() */
    private array $children = [];

    /** @var array<int, self> keyed by spl_object_id() */
    private array $parents = [];

    private ?TerminationCause $cause = null;

    private ?TerminationCause $pending = null;

    /**
     * Requests not yet applied, process-wide. While it is zero a check skips
     * the walk over its ancestors. A request left on a state that is garbage
     * collected unobserved keeps it above zero — that costs speed, never
     * correctness.
     */
    private static int $pendingRequests = 0;

    /** @throws \Throwable */
    public function addChild(self $child): void
    {
        // A child already terminated through its other parent must not be
        // retained by this one.
        if ($child->cause instanceof TerminationCause) {
            return;
        }

        if ($this->cause instanceof TerminationCause) {
            $child->terminate($this->cause);

            return;
        }

        $child->parents[spl_object_id($this)] = $this;
        $this->children[spl_object_id($child)] = $child;
    }

    public function cause(): ?TerminationCause
    {
        return $this->cause;
    }

    /**
     * Records a termination without running anything: the next observation
     * of this node or a descendant terminates it ({@see resolvePending()}).
     * Safe from a signal handler. The first request wins.
     */
    public function requestTermination(TerminationCause $cause): void
    {
        if ($this->cause instanceof TerminationCause || $this->pending instanceof TerminationCause) {
            return;
        }

        $this->pending = $cause;
        self::$pendingRequests++;
    }

    /**
     * Applies requested terminations, ancestors first, so the topmost
     * requesting node terminates and cascades over this one.
     *
     * @throws \Throwable
     */
    public function resolvePending(): void
    {
        if (self::$pendingRequests > 0) {
            $visited = [];
            $this->resolvePendingFrom($visited);
        }
    }

    /**
     * @param \Closure(TerminationCause): void $listener
     *
     * @return \Closure(): void unregisters the listener; a no-op once it ran
     *
     * @throws \Throwable
     */
    public function addListener(\Closure $listener): \Closure
    {
        if ($this->cause instanceof TerminationCause) {
            $listener($this->cause);

            return static function (): void {};
        }

        $this->listeners[] = $listener;
        $id = array_key_last($this->listeners);

        return function () use ($id): void {
            unset($this->listeners[$id]);
        };
    }

    /** @throws \Throwable */
    public function terminate(TerminationCause $cause): void
    {
        if ($this->cause instanceof TerminationCause) {
            return;
        }

        $this->cause = $cause;
        if ($this->pending instanceof TerminationCause) {
            $this->pending = null;
            self::$pendingRequests--;
        }

        // A terminated state no longer needs its parents and they no longer
        // need it: detaching keeps a long-lived parent from accumulating
        // every per-request child it ever derived or joined.
        foreach ($this->parents as $parent) {
            $parent->removeChild($this);
        }
        $this->parents = [];

        $listeners = $this->listeners;
        $children = $this->children;
        $this->listeners = [];
        $this->children = [];
        $errors = [];

        foreach ($listeners as $listener) {
            try {
                $listener($cause);
            } catch (\Throwable $error) {
                $errors[] = $error;
            }
        }

        foreach ($children as $child) {
            try {
                $child->terminate($cause);
            } catch (\Throwable $error) {
                $errors[] = $error;
            }
        }

        if ($errors !== []) {
            throw $this->aggregate($errors);
        }
    }

    /**
     * @param array<int, true> $visited states already walked: a joined
     *        context shares ancestors, and walking them twice is wasted work
     *
     * @throws \Throwable
     */
    private function resolvePendingFrom(array &$visited): void
    {
        $visited[spl_object_id($this)] = true;

        foreach ($this->parents as $id => $parent) {
            if (!$this->cause instanceof TerminationCause && !isset($visited[$id])) {
                $parent->resolvePendingFrom($visited);
            }
        }

        if (!$this->cause instanceof TerminationCause && $this->pending instanceof TerminationCause) {
            $this->terminate($this->pending);
        }
    }

    private function removeChild(self $child): void
    {
        unset($this->children[spl_object_id($child)]);
    }

    /** @param non-empty-list<\Throwable> $errors */
    private function aggregate(array $errors): \Throwable
    {
        if (count($errors) === 1) {
            return $errors[0];
        }

        $chain = array_pop($errors);
        foreach (array_reverse($errors) as $error) {
            $chain = new \RuntimeException(
                'Context termination listener failed',
                previous: $chain,
            );
        }

        return new \RuntimeException(
            'One or more context termination listeners failed',
            previous: $chain,
        );
    }
}
