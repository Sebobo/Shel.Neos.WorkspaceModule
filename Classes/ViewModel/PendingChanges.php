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
 * The number of the changes of a workspace or of a document, by their kind.
 *
 * @internal for communication within the workspace module only
 */
#[Flow\Proxy(false)]
final readonly class PendingChanges
{
    public int $total;

    public function __construct(
        public int $new,
        public int $changed,
        public int $removed,
    ) {
        $this->total = $this->new + $this->changed + $this->removed;
    }
}
