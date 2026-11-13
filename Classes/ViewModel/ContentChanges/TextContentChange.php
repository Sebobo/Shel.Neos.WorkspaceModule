<?php

/*
 * This file is part of the Shel.Neos.WorkspaceModule package.
 *
 * This package is Open Source Software. For the full copyright and license
 * information, please view the LICENSE file which was distributed with this
 * source code.
 */

declare(strict_types=1);

namespace Shel\Neos\WorkspaceModule\ViewModel\ContentChanges;

use Neos\Flow\Annotations as Flow;

/**
 * The difference of a property that holds text.
 *
 * @internal for communication within the workspace module only
 */
#[Flow\Proxy(false)]
final readonly class TextContentChange
{
    /**
     * @param list<list<array<string, mixed>>> $diff
     */
    public function __construct(
        public array $diff,
        public bool $originalIsNull,
        public bool $changedIsNull,
    ) {
    }
}
