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

use Neos\ContentRepository\Domain\Model\NodeInterface;
use Neos\Flow\Annotations as Flow;

/**
 * A document of a workspace with the changes it has.
 *
 * @internal for communication within the workspace module only
 */
#[Flow\Proxy(false)]
final readonly class DocumentChanges
{
    /**
     * @param list<NodeInterface> $changes
     */
    public function __construct(
        public NodeInterface $document,
        public array $changes,
    ) {
    }
}
