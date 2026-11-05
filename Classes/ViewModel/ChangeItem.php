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
 * A change of a node in the workspace.
 *
 * @internal for communication within the workspace module only
 */
#[Flow\Proxy(false)]
final readonly class ChangeItem
{
    /**
     * @param list<string> $requiredParents the context paths of the changes that have to be published or discarded with this change
     * @param list<AncestorItem> $ancestors the containers the change sits in, from the topmost one down to the nearest
     */
    public function __construct(
        public string $contextPath,
        public string $label,
        public ?string $icon,
        public string $nodeTypeLabel,
        public string $type,
        public ?\DateTimeInterface $lastModificationDateTime,
        public ?string $relativeDateTimeLabel,
        public ContentChangeItems $contentChanges,
        public array $requiredParents,
        public array $ancestors,
        public int $depth,
    ) {
    }
}
