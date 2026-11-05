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
use Neos\Eel\FlowQuery\FlowQuery;
use Neos\Flow\Annotations as Flow;
use Neos\Neos\Domain\Repository\SiteRepository;
use Neos\Neos\Domain\Service\ContentContextFactory;
use Neos\Neos\Service\PublishingService;
use Shel\Neos\WorkspaceModule\ViewModel\ChangeItem;
use Shel\Neos\WorkspaceModule\ViewModel\ChangeItems;
use Shel\Neos\WorkspaceModule\ViewModel\DocumentChanges;
use Shel\Neos\WorkspaceModule\ViewModel\DocumentItem;
use Shel\Neos\WorkspaceModule\ViewModel\PendingChanges;
use Shel\Neos\WorkspaceModule\ViewModel\SiteChanges;

/**
 * The unpublished changes of a workspace, grouped by the document they belong to.
 *
 * The grouping is the expensive part of the calculation - it loads every unpublished node and
 * walks up to its document - so it is kept per workspace for the lifetime of the request. The
 * list of the documents is cheap, the diffs of one document are computed when that document
 * is opened.
 */
#[Flow\Scope('singleton')]
final class WorkspaceChangesService
{
    /**
     * The unpublished nodes of a workspace, grouped by the document they belong to.
     *
     * @var array<string, array<string, SiteChanges>> $groupedChangesCache
     */
    private array $groupedChangesCache = [];

    public function __construct(
        private readonly PublishingService $publishingService,
        private readonly ContentContextFactory $contextFactory,
        private readonly SiteRepository $siteRepository,
        private readonly NodeTreeService $nodeTreeService,
        private readonly ChangeDescriptionService $changeDescriptionService,
        private readonly DocumentDescriptionService $documentDescriptionService,
        private readonly RelativeDateService $relativeDateService,
    ) {
    }

    /**
     * The documents of the workspace that have changes, grouped by their site.
     *
     * @param array<string, list<string>> $dimensionValues the variants to show, empty for all
     * @return array<string, array{siteNodeName: string, label: string, documents: list<DocumentItem>}>
     */
    public function getDocumentsWithChanges(Workspace $workspace, array $dimensionValues = []): array
    {
        $sites = [];
        $isFirstDocument = true;
        foreach ($this->groupChangesByDocument($workspace, $dimensionValues) as $siteNodeName => $siteChanges) {
            $siteKey = $siteNodeName === '' ? '__withoutSite' : $siteNodeName;
            if (!isset($sites[$siteKey])) {
                $sites[$siteKey] = [
                    'siteNodeName' => $siteNodeName,
                    'label' => $siteChanges->site?->getName() ?? '',
                    'documents' => [],
                ];
            }
            foreach ($siteChanges->documents as $documentChanges) {
                $sites[$siteKey]['documents'][] = $this->describeDocumentInList(
                    $documentChanges->document,
                    $documentChanges->changes,
                    $sites[$siteKey]['documents'],
                    $isFirstDocument,
                );
                $isFirstDocument = false;
            }
        }

        return $sites;
    }

    /**
     * The description of a document of the list, with its place in the hierarchy of the
     * documents.
     *
     * @param list<NodeInterface> $changes
     * @param list<DocumentItem> $documentsOfSite the documents of the site that are already described
     * @param bool $isFirst whether the document is the first one of the whole list
     */
    private function describeDocumentInList(
        NodeInterface $document,
        array $changes,
        array $documentsOfSite,
        bool $isFirst,
    ): DocumentItem {
        $description = $this->documentDescriptionService->describeDocument($document, $changes);

        $listedDocuments = [];
        foreach ($documentsOfSite as $documentOfSite) {
            $listedDocuments[$documentOfSite->contextPath] = true;
        }

        $ancestors = $this->nodeTreeService->documentAncestors($document);
        $level = 0;
        $nearestListedAncestor = null;
        foreach (array_reverse($ancestors) as $ancestor) {
            if (isset($listedDocuments[$ancestor->getContextPath()])) {
                $level++;
                $nearestListedAncestor = $ancestor->getContextPath();
            }
        }
        $parentIsInList = $ancestors !== []
            && isset($listedDocuments[$ancestors[0]->getContextPath()]);

        return new DocumentItem(
            contextPath: $description->contextPath,
            label: $description->label,
            icon: $description->icon,
            dimensionLabel: $description->dimensionLabel,
            pendingChanges: $description->pendingChanges,
            kind: $description->kind,
            isNew: $description->isNew,
            isDeleted: $description->isDeleted,
            isAccessible: $description->isAccessible,
            level: $level,
            parent: $nearestListedAncestor,
            parentIsInList: $parentIsInList,
            breadcrumb: $description->breadcrumb,
            baseContextPath: $description->baseContextPath,
            isFirst: $isFirst,
        );
    }

    /**
     * The changes of one document, in the order of the content tree and with the changes they
     * depend on.
     *
     * The order is the one the changes have in the content tree: the document first, if it has a
     * change itself, then the changes below it, ordered by the index they have among their
     * siblings - the way the nodes of a tree are sorted.
     *
     * A node can only be published if its parents exist in the target workspace. The parents of
     * a node that was created in this workspace are created here as well, and they are part of
     * the changes of the document: such a parent belongs to the checkbox of its child, so that
     * a child is never published without the parent it needs.
     *
     * @param array<string, list<string>> $dimensionValues the variants to show, empty for all
     */
    public function getChangesForDocument(Workspace $workspace, NodeInterface $document, array $dimensionValues = []): ChangeItems
    {
        $changes = $this->getChangesOfDocument($workspace, $document, $dimensionValues);

        $changesByContextPath = [];
        foreach ($changes as $change) {
            $changesByContextPath[$change->getContextPath()] = true;
        }

        $entries = [];
        foreach ($changes as $change) {
            $ancestors = $this->nodeTreeService->ancestorsOf($change, $document);
            $entries[] = [
                'change' => $change,
                'ancestors' => $ancestors,
                'sortKey' => $this->sortKeyOf($change, $ancestors),
                'requiredParent' => $this->requiredParentOf($ancestors, $changesByContextPath),
            ];
        }

        usort($entries, static function (array $left, array $right): int {
            return self::compareSortKeys($left['sortKey'], $right['sortKey']);
        });

        $described = [];
        foreach ($entries as $entry) {
            $change = $this->changeDescriptionService->describeChange($entry['change'], $entry['ancestors']);
            $requiredParent = $entry['requiredParent'];
            $described[] = new ChangeItem(
                contextPath: $change->contextPath,
                label: $change->label,
                icon: $change->icon,
                nodeTypeLabel: $change->nodeTypeLabel,
                type: $change->type,
                lastModificationDateTime: $change->lastModificationDateTime,
                relativeDateTimeLabel: $this->relativeDateService->label($change->lastModificationDateTime),
                contentChanges: $change->contentChanges,
                requiredParents: $requiredParent instanceof NodeInterface
                    ? [$requiredParent->getContextPath()]
                    : [],
                ancestors: $change->ancestors,
                depth: $change->depth,
            );
        }

        return ChangeItems::fromArray($described);
    }

    /**
     * The document of the review, addressed by its context path.
     *
     * The document is looked up in the changes of the workspace and not in the content
     * repository, because a document whose change is a removal does not exist any more in the
     * workspace - it is only there as a removed node, which the content repository hides.
     *
     * @param array<string, list<string>> $dimensionValues the variants to show, empty for all
     */
    public function findDocumentInChanges(Workspace $workspace, string $documentContextPath, array $dimensionValues = []): ?NodeInterface
    {
        foreach ($this->groupChangesByDocument($workspace, $dimensionValues) as $siteChanges) {
            foreach ($siteChanges->documents as $documentChanges) {
                if ($documentChanges->document->getContextPath() === $documentContextPath) {
                    return $documentChanges->document;
                }
            }
        }

        return null;
    }

    /**
     * The unpublished nodes of a document - the changes the review shows for it.
     *
     * @param array<string, list<string>> $dimensionValues the variants to show, empty for all
     * @return list<NodeInterface>
     */
    public function getChangesOfDocument(Workspace $workspace, NodeInterface $document, array $dimensionValues = []): array
    {
        foreach ($this->groupChangesByDocument($workspace, $dimensionValues) as $siteChanges) {
            foreach ($siteChanges->documents as $documentChanges) {
                if ($documentChanges->document->getContextPath() !== $document->getContextPath()) {
                    continue;
                }

                return $documentChanges->changes;
            }
        }

        return [];
    }

    /**
     * The number of the changes of a workspace, by their kind.
     *
     * @param array<string, list<string>> $dimensionValues the variants to show, empty for all
     */
    public function getChangesCount(Workspace $workspace, array $dimensionValues = []): PendingChanges
    {
        $new = 0;
        $changed = 0;
        $removed = 0;
        foreach ($this->groupChangesByDocument($workspace, $dimensionValues) as $siteChanges) {
            foreach ($siteChanges->documents as $documentChanges) {
                foreach ($documentChanges->changes as $node) {
                    if ($node->isRemoved()) {
                        $removed++;
                    } elseif ($this->nodeTreeService->isNewInBaseWorkspace($node)) {
                        $new++;
                    } else {
                        $changed++;
                    }
                }
            }
        }

        return new PendingChanges(new: $new, changed: $changed, removed: $removed);
    }

    /**
     * The document a node belongs to, which is the closest document above it.
     */
    public function findClosestDocument(NodeInterface $node): ?NodeInterface
    {
        $flowQuery = new FlowQuery([$node]);
        // closest() is a magic method of the flow query that resolves ClosestOperation
        /** @phpstan-ignore method.notFound */
        $document = $flowQuery->closest('[instanceof Neos.Neos:Document]')->get(0);

        return $document instanceof NodeInterface ? $document : null;
    }

    /**
     * A node of the workspace, addressed by its identifier.
     */
    public function findNodeInWorkspace(Workspace $workspace, string $identifier): ?NodeInterface
    {
        $context = $this->contextFactory->create(['workspaceName' => $workspace->getName()]);
        $node = $context->getNodeByIdentifier($identifier);

        return $node instanceof NodeInterface ? $node : null;
    }

    /**
     * Forgets the grouped changes, so that a change that was just published or discarded is
     * not calculated from the state before.
     */
    public function clearCache(): void
    {
        $this->groupedChangesCache = [];
        $this->nodeTreeService->clearCache();
    }

    /**
     * The key a change is ordered by: the index every node of its path has among its siblings,
     * from the document down to the node itself.
     *
     * The key of the document is the beginning of the key of every change below it, so that the
     * document comes first and the changes below it follow the order of the tree.
     *
     * @param list<NodeInterface> $ancestors the ancestors of the node, nearest first
     * @return list<int>
     */
    private function sortKeyOf(NodeInterface $node, array $ancestors): array
    {
        $sortKey = [];
        foreach (array_reverse($ancestors) as $ancestor) {
            $sortKey[] = $this->indexOf($ancestor);
        }
        $sortKey[] = $this->indexOf($node);

        return $sortKey;
    }

    /**
     * The index a node has among its siblings.
     */
    private function indexOf(NodeInterface $node): int
    {
        return (int)$node->getIndex();
    }

    /**
     * @param list<int> $left
     * @param list<int> $right
     */
    private static function compareSortKeys(array $left, array $right): int
    {
        $length = min(count($left), count($right));
        for ($position = 0; $position < $length; $position++) {
            if ($left[$position] !== $right[$position]) {
                return $left[$position] <=> $right[$position];
            }
        }

        // The shorter key belongs to the node that is higher up in the tree.
        return count($left) <=> count($right);
    }

    /**
     * The change that has to be published together with a change: the nearest ancestor that does
     * not exist in the published version, because a node can only be published if its parents
     * exist there.
     *
     * @param list<NodeInterface> $ancestors the ancestors of the node, nearest first
     * @param array<string, bool> $changesByContextPath the changes of the document
     */
    private function requiredParentOf(array $ancestors, array $changesByContextPath): ?NodeInterface
    {
        foreach ($ancestors as $ancestor) {
            if (!$this->nodeTreeService->isNewInBaseWorkspace($ancestor)) {
                continue;
            }
            if (!isset($changesByContextPath[$ancestor->getContextPath()])) {
                // The ancestor is not one of the changes of the document, so there is no
                // checkbox that could be selected with the change.
                continue;
            }

            return $ancestor;
        }

        return null;
    }

    /**
     * The unpublished nodes of a workspace, grouped by the document they belong to.
     *
     * @param array<string, list<string>> $dimensionValues the variants to show, empty for all
     * @return array<string, SiteChanges>
     */
    private function groupChangesByDocument(Workspace $workspace, array $dimensionValues = []): array
    {
        $cacheKey = $workspace->getName() . '|' . json_encode($dimensionValues);
        if (isset($this->groupedChangesCache[$cacheKey])) {
            return $this->groupedChangesCache[$cacheKey];
        }

        // The context of the variants that are shown: a node that belongs to another variant
        // of its document is not part of the review then.
        $variantContext = $dimensionValues === [] ? null : $this->contextFactory->create([
            'workspaceName' => $workspace->getName(),
            'dimensions' => $dimensionValues,
            'removedContentShown' => true,
        ]);

        // The changes of every document, keyed by the context path of the document: the
        // variants of a document share their identifier.
        /** @var array<string, array{document: NodeInterface, siteNodeName: string, changes: list<NodeInterface>}> $documents */
        $documents = [];
        foreach ($this->publishingService->getUnpublishedNodes($workspace) as $node) {
            $skipCollectionChanges = $node->getNodeType()->isOfType('Neos.Neos:ContentCollection')
                && !$node->getNodeType()->isOfType('Neos.Neos:Content');
            if ($skipCollectionChanges) {
                continue;
            }
            if ($variantContext !== null && $variantContext->getNodeByIdentifier($node->getIdentifier()) === null) {
                // The node belongs to another variant of its document.
                continue;
            }
            $pathParts = explode('/', $node->getPath());
            if (count($pathParts) <= 2) {
                continue;
            }
            $siteNodeName = $pathParts[2];
            $document = $this->findClosestDocument($node);
            if ($document === null) {
                continue;
            }
            // A document that sits directly below the sites root - which is what a test run
            // that creates pages without a site leaves behind - belongs to no site at all.
            if (count(explode('/', $document->getPath())) <= 3) {
                $siteNodeName = '';
            }
            $documentKey = (string)$document->getContextPath();
            if (!isset($documents[$documentKey])) {
                $documents[$documentKey] = [
                    'document' => $document,
                    'siteNodeName' => $siteNodeName,
                    'changes' => [],
                ];
            }
            $documents[$documentKey]['changes'][] = $node;
        }

        // The documents of every site.
        /** @var array<string, list<DocumentChanges>> $documentsOfSite */
        $documentsOfSite = [];
        foreach ($documents as $documentData) {
            $siteNodeName = $documentData['siteNodeName'];
            $siteDocuments = $documentsOfSite[$siteNodeName] ?? [];
            $siteDocuments[] = new DocumentChanges(
                document: $documentData['document'],
                changes: $documentData['changes'],
            );
            $documentsOfSite[$siteNodeName] = $siteDocuments;
        }
        foreach ($documentsOfSite as $siteNodeName => $siteDocuments) {
            // The documents in the order of the content tree, so that a document comes after
            // the document it sits below.
            usort(
                $siteDocuments,
                static fn (DocumentChanges $left, DocumentChanges $right): int => strcmp($left->document->getPath(), $right->document->getPath())
            );
            $documentsOfSite[$siteNodeName] = $siteDocuments;
        }

        $withoutDeletedContent = $this->withoutDeletedContent($this->groupBySite($documentsOfSite));

        $this->groupedChangesCache[$cacheKey] = $withoutDeletedContent;

        return $withoutDeletedContent;
    }

    /**
     * The documents of every site, with the site they belong to.
     *
     * @param array<string, list<DocumentChanges>> $documentsOfSite
     * @return array<string, SiteChanges>
     */
    private function groupBySite(array $documentsOfSite): array
    {
        $grouped = [];
        foreach ($documentsOfSite as $siteNodeName => $siteDocuments) {
            $grouped[$siteNodeName] = new SiteChanges(
                site: $siteNodeName === '' ? null : $this->siteRepository->findOneByNodeName($siteNodeName),
                documents: $siteDocuments,
            );
        }

        return $grouped;
    }

    /**
     * A deleted document is one change: the content below it is not reviewed on its own.
     *
     * @param array<string, SiteChanges> $grouped
     * @return array<string, SiteChanges>
     */
    private function withoutDeletedContent(array $grouped): array
    {
        $withoutDeletedContent = [];
        foreach ($grouped as $siteNodeName => $siteChanges) {
            $documents = [];
            foreach ($siteChanges->documents as $documentChanges) {
                $changes = $documentChanges->changes;
                if ($documentChanges->document->isRemoved()) {
                    $documentIdentifier = $documentChanges->document->getIdentifier();
                    $changes = array_values(array_filter(
                        $changes,
                        static fn (NodeInterface $node): bool => $node->getIdentifier() === $documentIdentifier
                    ));
                }
                $documents[] = new DocumentChanges(
                    document: $documentChanges->document,
                    changes: $changes,
                );
            }
            $withoutDeletedContent[$siteNodeName] = new SiteChanges(
                site: $siteChanges->site,
                documents: $documents,
            );
        }

        return $withoutDeletedContent;
    }
}
