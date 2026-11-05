<?php

/*
 * This file is part of the Shel.Neos.WorkspaceModule package.
 *
 * This package is Open Source Software. For the full copyright and license
 * information, please view the LICENSE file which was distributed with this
 * source code.
 */

declare(strict_types=1);

namespace Shel\Neos\WorkspaceModule\Tests\Fixtures\Domain\Model;

use Neos\Flow\Annotations as Flow;

/**
 * A collection of value objects: the addresses of a node.
 *
 * The collection is a value object itself, so that it can be stored in a property of a
 * node as a whole.
 *
 * @implements \IteratorAggregate<int, PostalAddress>
 */
#[Flow\Proxy(false)]
final readonly class PostalAddresses implements \IteratorAggregate, \JsonSerializable
{
    /**
     * @param list<PostalAddress> $addresses
     */
    public function __construct(
        public array $addresses,
    ) {
    }

    /**
     * @param list<array<string, string>> $array
     */
    public static function fromArray(array $array): self
    {
        return new self(array_map(
            static fn (array $address): PostalAddress => PostalAddress::fromArray($address),
            $array
        ));
    }

    /**
     * @return \ArrayIterator<int, PostalAddress>
     */
    public function getIterator(): \ArrayIterator
    {
        return new \ArrayIterator($this->addresses);
    }

    /**
     * @return list<PostalAddress>
     */
    public function jsonSerialize(): array
    {
        return $this->addresses;
    }
}
