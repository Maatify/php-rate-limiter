<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Tests\Support;

/** Distinct handles for logical test stores; real layout/atomicity conformance is verified on Redis. */
trait E1View
{
    private bool $isAccountView = false;
    /** @var static|null */
    private ?self $accountView = null;

    protected function initializeAccountView(object $view): void {}

    public function e1Keyspace(): static
    {
        if ($this->isAccountView) {
            return $this;
        }
        if ($this->accountView === null) {
            $view = clone $this;
            $view->isAccountView = true;
            $view->accountView = null;
            // Fault switches and recorded observations belong to the laboratory, not to either layout.
            foreach ((new \ReflectionObject($this))->getProperties(\ReflectionProperty::IS_PUBLIC) as $property) {
                if (! $property->isStatic() && ! $property->isReadOnly() && $property->isInitialized($this)) {
                    $name = $property->getName();
                    $view->{$name} = & $this->{$name};
                }
            }
            $this->initializeAccountView($view);
            $this->accountView = $view;
        }

        return $this->accountView;
    }
}
