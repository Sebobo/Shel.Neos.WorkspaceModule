<?php

/*
 * This file is part of the Shel.Neos.WorkspaceModule package.
 *
 * This package is Open Source Software. For the full copyright and license
 * information, please view the LICENSE file which was distributed with this
 * source code.
 */

declare(strict_types=1);

namespace Shel\Neos\WorkspaceModule\Service;

use Neos\ContentRepository\Domain\Model\NodeInterface;
use Neos\Flow\Annotations as Flow;
use Neos\Neos\Service\PublishingService;

/**
 * The publishing and the discarding of the changes of a workspace.
 *
 * A node can only be published if its parents exist in the workspace it is published to, and a
 * node that is discarded must not leave its children behind.
 */
#[Flow\Scope('singleton')]
final class WorkspacePublishingService
{
    public function __construct(
        private readonly PublishingService $publishingService,
        private readonly NodeTreeService $nodeTreeService,
    ) {
    }

    /**
     * Publishes nodes together with the ancestors they need.
     *
     * A node can only be published if its parents exist in the workspace it is published to. The
     * parents that were created in the workspace are published with it - otherwise the node
     * would end up below a parent that does not exist.
     *
     * @param list<NodeInterface> $nodes
     */
    public function publishNodesWithTheirAncestors(array $nodes): void
    {
        $nodesToPublish = [];
        foreach ($nodes as $node) {
            foreach ($this->nodeTreeService->unpublishedAncestorsOf($node) as $ancestor) {
                $nodesToPublish[$ancestor->getContextPath()] = $ancestor;
            }
            $nodesToPublish[$node->getContextPath()] = $node;
        }

        // The publishing service sorts the nodes, so that the parents come first.
        $this->publishingService->publishNodes(array_values($nodesToPublish));
    }

    /**
     * Discards a node without leaving its children behind.
     *
     * A node that was created in the workspace is removed when it is discarded - and its
     * children are removed with it, because a child whose parent is gone is an orphan that
     * the content repository cannot do anything with any more.
     *
     * A node that exists in the base workspace is only reverted: its children stay where they
     * are, because the node stays as well.
     */
    public function discardNodeWithItsChildren(NodeInterface $node): void
    {
        if (!$this->nodeTreeService->isNewInBaseWorkspace($node)) {
            $this->publishingService->discardNode($node);

            return;
        }

        $nodes = $this->nodeTreeService->unpublishedDescendantsOf($node);
        $nodes[] = $node;
        $this->publishingService->discardNodes($nodes);
    }
}
