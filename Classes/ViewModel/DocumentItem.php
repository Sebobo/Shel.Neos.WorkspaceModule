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
 * A document of the list of the documents that have changes.
 *
 * @internal for communication within the workspace module only
 */
#[Flow\Proxy(false)]
final readonly class DocumentItem
{
    /**
     * @param list<string> $breadcrumb the documents the document sits below, from the site down to its parent
     */
    public function __construct(
        public string $contextPath,
        public string $label,
        public ?string $icon,
        public string $dimensionLabel,
        public PendingChanges $pendingChanges,
        public string $kind,
        public bool $isNew,
        public bool $isDeleted,
        public int $level,
        public ?string $parent,
        public bool $parentIsInList,
        public array $breadcrumb,
        public string $baseContextPath,
        public bool $isFirst,
    ) {
    }
}
