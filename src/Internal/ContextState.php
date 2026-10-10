<?php

declare(strict_types=1);

namespace Rasuvaeff\Context\Internal;

use Rasuvaeff\Context\TerminationCause;

/** @internal */
final class ContextState
{
    /** @var list<callable> */
    private array $listeners = [];

    /** @var list<self> */
    private array $children = [];

    private ?TerminationCause $cause = null;

    public function addChild(self $child): void
    {
        $this->children[] = $child;

        if ($this->cause instanceof \Rasuvaeff\Context\TerminationCause) {
            $child->terminate($this->cause);
        }
    }

    public function cause(): ?TerminationCause
    {
        return $this->cause;
    }

    /** @throws \Throwable */
    public function addListener(\Closure $listener): void
    {
        if ($this->cause instanceof \Rasuvaeff\Context\TerminationCause) {
            $listener($this->cause);

            return;
        }

        $this->listeners[] = $listener;
    }

    /** @throws \Throwable */
    public function terminate(TerminationCause $cause): void
    {
        if ($this->cause instanceof \Rasuvaeff\Context\TerminationCause) {
            return;
        }

        $this->cause = $cause;
        $errors = [];

        foreach ($this->listeners as $listener) {
            try {
                $listener($cause);
            } catch (\Throwable $error) {
                $errors[] = $error;
            }
        }

        foreach ($this->children as $child) {
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

    /** @param list<\Throwable> $errors */
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
