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
 * A container a change sits in - the section, the column or the collection of a page.
 *
 * @internal for communication within the workspace module only
 */
#[Flow\Proxy(false)]
final readonly class AncestorItem
{
    public function __construct(
        public string $label,
        public ?string $icon,
    ) {
    }
}
