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
 * A value object: the postal address of a node.
 *
 * The value objects are stored in the properties of the nodes as JSON - the content
 * repository reads them as objects again.
 */
#[Flow\Proxy(false)]
final readonly class PostalAddress implements \JsonSerializable
{
    public function __construct(
        public string $streetAddress,
        public string $postalCode,
        public string $addressLocality,
        public string $addressCountry,
    ) {
    }

    /**
     * @param array<string, string> $array
     */
    public static function fromArray(array $array): self
    {
        return new self(
            (string)$array['streetAddress'],
            (string)$array['postalCode'],
            (string)$array['addressLocality'],
            (string)$array['addressCountry'],
        );
    }

    /**
     * @return array<string, string>
     */
    public function jsonSerialize(): array
    {
        return [
            'streetAddress' => $this->streetAddress,
            'postalCode' => $this->postalCode,
            'addressLocality' => $this->addressLocality,
            'addressCountry' => $this->addressCountry,
        ];
    }
}
