<?php

declare(strict_types=1);

namespace Rasuvaeff\Context\Internal;

use Rasuvaeff\Context\TerminationCause;

/**
 * @internal
 */
final class ContextState
{
    /** @var array<int, \Closure(TerminationCause): void> */
    private array $listeners = [];

    /** @var array<int, self> keyed by spl_object_id() */
    private array $children = [];

    private ?self $parent = null;

    private ?TerminationCause $cause = null;

    /** @throws \Throwable */
    public function addChild(self $child): void
    {
        if ($this->cause instanceof TerminationCause) {
            $child->terminate($this->cause);

            return;
        }

        $child->parent = $this;
        $this->children[spl_object_id($child)] = $child;
    }

    public function cause(): ?TerminationCause
    {
        return $this->cause;
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

        // A terminated state no longer needs its parent and the parent no
        // longer needs it: detaching keeps a long-lived parent from
        // accumulating every per-request child it ever derived.
        $this->parent?->removeChild($this);
        $this->parent = null;

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
