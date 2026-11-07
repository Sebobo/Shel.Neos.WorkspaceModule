<?php

/*
 * This file is part of the Shel.Neos.WorkspaceModule package.
 *
 * This package is Open Source Software. For the full copyright and license
 * information, please view the LICENSE file which was distributed with this
 * source code.
 */

declare(strict_types=1);

namespace Shel\Neos\WorkspaceModule\ViewModel;

use Neos\Flow\Annotations as Flow;

/**
 * The changes of a document.
 *
 * The items are a public property, because the view cannot call a method of an object: the
 * expressions of the fusion are evaluated in a context that does not allow it.
 *
 * @implements \IteratorAggregate<int, ChangeItem>
 * @internal for communication within the workspace module only
 */
#[Flow\Proxy(false)]
final readonly class ChangeItems implements \IteratorAggregate, \Countable
{
    /**
     * @param list<ChangeItem> $items
     */
    private function __construct(
        public array $items,
    ) {
    }

    /**
     * @param list<ChangeItem> $items
     */
    public static function fromArray(array $items): self
    {
        return new self($items);
    }

    public function getIterator(): \Traversable
    {
        yield from $this->items;
    }

    public function count(): int
    {
        return count($this->items);
    }
}
