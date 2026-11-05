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
 * The last change of a workspace: when something was changed, and who made it.
 *
 * The relative label is the date as it meets on the street for dates of the last week
 * ("5 minutes ago") - for older dates the rendered date is formatted on its own.
 *
 * @internal for communication within the workspace module only
 */
#[Flow\Proxy(false)]
final readonly class ActivityItem
{
    public function __construct(
        public \DateTimeInterface $dateTime,
        public ?string $relativeLabel,
        public ?string $author,
    ) {
    }
}
