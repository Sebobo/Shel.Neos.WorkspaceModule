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
use Shel\Neos\WorkspaceModule\ViewModel\ContentChanges\AssetContentChange;
use Shel\Neos\WorkspaceModule\ViewModel\ContentChanges\DateTimeContentChange;
use Shel\Neos\WorkspaceModule\ViewModel\ContentChanges\ImageContentChange;
use Shel\Neos\WorkspaceModule\ViewModel\ContentChanges\TextContentChange;

/**
 * The difference of one changed property of a node.
 *
 * @internal for communication within the workspace module only
 */
#[Flow\Proxy(false)]
final readonly class ContentChangeItem
{
    public function __construct(
        public ContentChangeProperties $properties,
        public AssetContentChange|DateTimeContentChange|ImageContentChange|TextContentChange $changes,
    ) {
    }
}
