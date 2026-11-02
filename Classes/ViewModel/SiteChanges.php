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
use Neos\Neos\Domain\Model\Site;

/**
 * A site of a workspace with the documents that have changes.
 *
 * @internal for communication within the workspace module only
 */
#[Flow\Proxy(false)]
final readonly class SiteChanges
{
    /**
     * @param list<DocumentChanges> $documents
     */
    public function __construct(
        public ?Site $site,
        public array $documents,
    ) {
    }
}
