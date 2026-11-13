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
 * The properties of a changed property - its name and the kind of its value.
 *
 * @internal for communication within the workspace module only
 */
#[Flow\Proxy(false)]
final readonly class ContentChangeProperties
{
    public function __construct(
        public string $type,
        public string $propertyLabel,
    ) {
    }
}
