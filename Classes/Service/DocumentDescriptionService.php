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
use Neos\ContentRepository\Domain\Model\Workspace;
use Neos\Flow\Annotations as Flow;
use Shel\Neos\WorkspaceModule\ViewModel\DocumentItem;
use Shel\Neos\WorkspaceModule\ViewModel\PendingChanges;

/**
 * The description of a document of the list of the documents that have changes.
 */
#[Flow\Scope('singleton')]
final class DocumentDescriptionService
{
    public function __construct(
        private readonly NodeLabelService $nodeLabelService,
        private readonly ContentDimensionService $contentDimensionService,
        private readonly NodeTreeService $nodeTreeService,
    ) {
    }

    /**
     * The description of a document, with the kind of its change and the documents it sits
     * below.
     *
     * @param list<NodeInterface> $changes the unpublished nodes of the document
     */
    public function describeDocument(NodeInterface $document, array $changes = []): DocumentItem
    {
        $kind = $this->kindOfDocument($document, $changes);

        return new DocumentItem(
            contextPath: $document->getContextPath(),
            label: $document->getLabel(),
            icon: $this->nodeLabelService->iconOf($document),
            dimensionLabel: implode(', ', $this->contentDimensionService->describeDimensions($document)),
            pendingChanges: $this->countChanges($changes),
            kind: $kind,
            isNew: $kind === 'created',
            isDeleted: $kind === 'deleted',
            level: 0,
            parent: null,
            parentIsInList: false,
            breadcrumb: $this->breadcrumb($document),
            baseContextPath: $this->nodeTreeService->contextPathInBaseWorkspace($document),
            isFirst: false,
        );
    }

    /**
     * The kind of the change of a document, for the colour of the bar at the left of its row.
     *
     * A document that was deleted is red, a document that was created is green, and a
     * document that is changed - or that has changed content - is orange.
     *
     * A document that only lost something below it is changed as well: the content of the
     * document is not the same as the published one any more.
     *
     * @param list<NodeInterface> $changes
     */
    public function kindOfDocument(NodeInterface $document, array $changes): string
    {
        if ($document->isRemoved()) {
            return 'deleted';
        }
        if ($this->nodeTreeService->isNewInBaseWorkspace($document)) {
            return 'created';
        }

        return 'edited';
    }

    /**
     * The number of the changes of a document, by their kind.
     *
     * @param list<NodeInterface> $changes
     */
    public function countChanges(array $changes): PendingChanges
    {
        $new = 0;
        $changed = 0;
        $removed = 0;
        foreach ($changes as $change) {
            if ($change->isRemoved()) {
                $removed++;
            } elseif ($this->nodeTreeService->isNewInBaseWorkspace($change)) {
                $new++;
            } else {
                $changed++;
            }
        }

        return new PendingChanges(new: $new, changed: $changed, removed: $removed);
    }

    /**
     * The documents a document sits below, from the site down to its parent.
     *
     * @return list<string>
     */
    public function breadcrumb(NodeInterface $document): array
    {
        $labels = [];
        foreach (array_reverse($this->nodeTreeService->documentAncestors($document)) as $ancestor) {
            $labels[] = $ancestor->getLabel();
        }

        return $labels;
    }
}
