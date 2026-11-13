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
 *
 * The core publishes and discards the content of a document with the document. A change that
 * is published or discarded twice is an error in the content repository: the second attempt
 * removes a node that is not there any more. The changes that the core takes care of are
 * therefore left out of the list of the nodes that are published or discarded.
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
        $this->publishingService->publishNodes(
            array_values($this->withoutTheContentOfTheDocuments($nodesToPublish))
        );
    }

    /**
     * Discards a list of changes, without leaving orphans behind.
     *
     * A node that was created in the workspace is removed when it is discarded - and its
     * children are removed with it, because a child whose parent is gone is an orphan that
     * the content repository cannot do anything with any more.
     *
     * A node that exists in the base workspace is only reverted: its children stay where they
     * are, because the node stays as well.
     *
     * @param list<NodeInterface> $nodes
     */
    public function discardNodesWithTheirChildren(array $nodes): void
    {
        foreach ($this->nodesToDiscard($nodes) as $node) {
            $this->discardNodeWithItsChildren($node);
        }
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

    /**
     * The nodes that have to be discarded.
     *
     * A node that was created in the workspace is discarded with all of its children, and the
     * core discards the content of a document with the document. A change that is covered by
     * another change is therefore not discarded on its own.
     *
     * @param list<NodeInterface> $nodes
     * @return list<NodeInterface>
     */
    private function nodesToDiscard(array $nodes): array
    {
        $nodesToDiscard = [];
        foreach ($nodes as $node) {
            $nodesToDiscard[$node->getIdentifier()] = $node;
        }

        // The nodes that are removed with the node they sit below.
        $removedWithTheParent = [];
        foreach ($nodesToDiscard as $node) {
            if (!$this->nodeTreeService->isNewInBaseWorkspace($node)) {
                continue;
            }
            foreach ($this->nodeTreeService->unpublishedDescendantsOf($node) as $descendant) {
                $removedWithTheParent[$descendant->getIdentifier()] = true;
            }
        }
        $nodesToDiscard = array_filter(
            $nodesToDiscard,
            static fn (NodeInterface $node): bool => !isset($removedWithTheParent[$node->getIdentifier()])
        );

        return array_values($this->withoutTheContentOfTheDocuments($nodesToDiscard));
    }

    /**
     * The nodes of the list, without the ones that the core takes care of.
     *
     * The core publishes and discards the content of a document with the document. A node that
     * is published or discarded twice is an error in the content repository: the second
     * attempt removes a node that is not there any more.
     *
     * @param array<string, NodeInterface> $nodes
     * @return array<string, NodeInterface>
     */
    private function withoutTheContentOfTheDocuments(array $nodes): array
    {
        $takenCareOf = [];
        foreach ($nodes as $node) {
            if (!$this->publishesItsContentWithIt($node)) {
                continue;
            }
            foreach ($this->nodeTreeService->unpublishedDescendantsOf($node) as $descendant) {
                if ($descendant->getNodeType()->isOfType('Neos.Neos:Document')) {
                    // The core does not take the documents below a document with it.
                    continue;
                }
                $takenCareOf[$descendant->getIdentifier()] = true;
            }
        }

        return array_filter(
            $nodes,
            static fn (NodeInterface $node): bool => !isset($takenCareOf[$node->getIdentifier()])
        );
    }

    /**
     * Whether the core publishes and discards the content of the node with the node.
     *
     * The core does it for the documents and for the nodes that have child nodes of their own.
     */
    private function publishesItsContentWithIt(NodeInterface $node): bool
    {
        $nodeType = $node->getNodeType();

        return $nodeType->isOfType('Neos.Neos:Document') || $nodeType->hasConfiguration('childNodes');
    }
}
