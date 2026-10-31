<?php

declare(strict_types=1);

namespace Shel\Neos\WorkspaceModule\Service;

use Doctrine\ORM\EntityNotFoundException;
use Doctrine\Persistence\Proxy as DoctrineProxy;
use Neos\ContentRepository\Domain\Model\Node;
use Neos\ContentRepository\Domain\Model\NodeInterface;
use Neos\ContentRepository\Domain\Model\Workspace;
use Neos\ContentRepository\Domain\Repository\NodeDataRepository;
use Neos\ContentRepository\Domain\Service\ContentDimensionPresetSourceInterface;
use Neos\ContentRepository\Domain\Service\Context;
use Neos\Diff\Diff;
use Neos\Diff\Renderer\Html\HtmlArrayRenderer;
use Neos\Eel\FlowQuery\FlowQuery;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\I18n\Translator;
use Neos\Media\Domain\Model\AssetInterface;
use Neos\Media\Domain\Model\ResourceBasedInterface;
use Neos\Neos\Domain\Model\Site;
use Neos\Neos\Domain\Repository\SiteRepository;
use Neos\Neos\Domain\Service\ContentContextFactory;
use Neos\Neos\Service\IconNameMappingService;
use Neos\Neos\Service\PublishingService;

/**
 * The unpublished changes of a workspace and the diff of a single change.
 *
 * This is the calculation the review view is built on, extracted from the Neos core workspace
 * module so that it can be used without rendering the whole review at once: the list of documents
 * with changes is cheap, the diffs of one document are computed when that document is opened.
 *
 * The data is shaped like the view models of the Neos 9 workspace user interface, so that the
 * rendering does not have to know how a change was calculated.
 */
#[Flow\Scope('singleton')]
final class WorkspaceChangesService
{
    /**
     * The unpublished nodes of a workspace, grouped by the document they belong to.
     *
     * The grouping is the expensive part of the calculation - it loads every unpublished node and
     * walks up to its document - so it is kept per workspace for the lifetime of the request.
     *
     * @var array<string, array<string, array{site: Site|null, documents: list<array{document: NodeInterface, dimension: list<string>, changes: list<NodeInterface>}>}>> $groupedChangesCache
     */
    private array $groupedChangesCache = [];

    /**
     * The nodes of the live workspace, cached for the lifetime of the request.
     *
     * Whether a change is new and whether it was moved are asked for every time a change is
     * described, and every question is a question to the database.
     *
     * @var array<string, NodeInterface|null>
     */
    private array $baseNodeCache = [];

    /**
     * The nodes the changes of a document sit below, cached for the lifetime of the request.
     *
     * @var array<string, NodeInterface|null>
     */
    private array $ancestorCache = [];

    public function __construct(
        private readonly PublishingService $publishingService,
        private readonly ContentContextFactory $contextFactory,
        private readonly SiteRepository $siteRepository,
        private readonly Translator $translator,
        private readonly IconNameMappingService $iconNameMappingService,
        private readonly ContentDimensionPresetSourceInterface $contentDimensionPresetSource,
        private readonly NodeDataRepository $nodeDataRepository,
    ) {
    }

    /**
     * The content dimensions of the installation and the presets that can be chosen for them.
     *
     * An installation without content dimensions has no configuration at all, and the review
     * shows no switcher then.
     *
     * @param array<string, string> $selection the selected preset per dimension
     * @return array<string, array<string,mixed>>
     */
    public function getDimensionConfiguration(array $selection = []): array
    {
        $dimensions = [];
        foreach ($this->contentDimensionPresetSource->getAllPresets() as $dimensionName => $dimensionConfiguration) {
            $dimensionName = (string)$dimensionName;
            $selectedPreset = $selection[$dimensionName] ?? '';
            $presets = [
                [
                    'id' => '',
                    'label' => $this->translateLabel('Shel.Neos.WorkspaceModule:Main:review.dimension.all'),
                    'values' => [],
                    'selected' => $selectedPreset === '',
                    // The selection that the choice of this preset leads to.
                    'selection' => $this->selectionWith($selection, $dimensionName, ''),
                ],
            ];
            foreach ($dimensionConfiguration['presets'] ?? [] as $presetIdentifier => $presetConfiguration) {
                $presetIdentifier = (string)$presetIdentifier;
                $presets[] = [
                    'id' => $presetIdentifier,
                    'label' => $this->translateLabel((string)($presetConfiguration['label'] ?? $presetIdentifier)),
                    'values' => array_values((array)($presetConfiguration['values'] ?? [])),
                    'selected' => $selectedPreset === $presetIdentifier,
                    'selection' => $this->selectionWith($selection, $dimensionName, $presetIdentifier),
                ];
            }
            $dimensions[$dimensionName] = [
                'name' => $dimensionName,
                'label' => $this->translateLabel((string)($dimensionConfiguration['label'] ?? $dimensionName)),
                'icon' => $this->iconNameMappingService->convert((string)($dimensionConfiguration['icon'] ?? '')),
                'selectedPreset' => $selectedPreset,
                // The label of the preset that is shown, the one of all variants when nothing
                // is selected.
                'selectedLabel' => $this->labelOfSelectedPreset($presets, $selectedPreset),
                'presets' => $presets,
            ];
        }

        return $dimensions;
    }

    /**
     * The selection that results from choosing a preset of a dimension.
     *
     * A dimension without a preset is not part of the selection: it shows every variant.
     *
     * @param array<string, string> $selection the selected preset per dimension
     * @return string the "dimension:preset" pairs, separated by a comma
     */
    private function selectionWith(array $selection, string $dimensionName, string $presetIdentifier): string
    {
        $selection[$dimensionName] = $presetIdentifier;
        $parts = [];
        foreach ($selection as $name => $identifier) {
            if ((string)$identifier === '') {
                continue;
            }
            $parts[] = $name . ':' . $identifier;
        }

        return implode(',', $parts);
    }

    /**
     * The label of the preset that is selected, the label of all variants when there is none.
     *
     * @param list<array<string,mixed>> $presets
     */
    private function labelOfSelectedPreset(array $presets, string $selectedPreset): string
    {
        foreach ($presets as $preset) {
            if ($preset['id'] === $selectedPreset) {
                return (string)$preset['label'];
            }
        }

        return (string)($presets[0]['label'] ?? '');
    }

    /**
     * The presets that are selected, read from the request.
     *
     * The selection is a list of "dimension:preset" pairs, so that it survives in the url of
     * the review and can be shared.
     *
     * @return array<string, string> the identifier of the preset per dimension
     */
    public function parseDimensionSelection(string $selection): array
    {
        $selected = [];
        foreach (explode(',', $selection) as $part) {
            if (!str_contains($part, ':')) {
                continue;
            }
            [$dimensionName, $presetIdentifier] = explode(':', $part, 2);
            $selected[$dimensionName] = $presetIdentifier;
        }

        return $selected;
    }

    /**
     * The dimension values of the selected presets, as the content context expects them.
     *
     * A dimension without a selected preset has no values, and the context shows every variant
     * of the content then.
     *
     * @param array<string, string> $selection
     * @return array<string, list<string>>
     */
    public function getDimensionValues(array $selection): array
    {
        $configuration = $this->contentDimensionPresetSource->getAllPresets();
        $values = [];
        foreach ($selection as $dimensionName => $presetIdentifier) {
            $preset = $configuration[$dimensionName]['presets'][$presetIdentifier] ?? null;
            if (!is_array($preset)) {
                continue;
            }
            $values[$dimensionName] = array_values((array)($preset['values'] ?? []));
        }

        return $values;
    }

    /**
     * The documents of the workspace that have changes, without their diffs.
     *
     * @param array<string, list<string>> $dimensionValues the variants to show, empty for all
     * @return list<array<string,mixed>>
     */
    public function getDocumentsWithChanges(Workspace $workspace, array $dimensionValues = []): array
    {
        $sites = [];
        $isFirstDocument = true;
        foreach ($this->groupChangesByDocument($workspace, $dimensionValues) as $siteNodeName => $siteData) {
            $siteKey = $siteNodeName === '' ? '__withoutSite' : $siteNodeName;
            if (!isset($sites[$siteKey])) {
                $sites[$siteKey] = [
                    'siteNodeName' => $siteNodeName,
                    'label' => $siteData['site']?->getName() ?? '',
                    'documents' => [],
                ];
            }
            // The documents of the site, so that the place of a document in the hierarchy of
            // the documents can be told: a document is indented below the document it sits
            // below, and the documents in between are named.
            $listedDocuments = [];
            foreach ($siteData['documents'] as $documentData) {
                $listedDocuments[$documentData['document']->getContextPath()] = true;
            }

            // The documents of the site in the order of the content tree, so that a document
            // comes after the document it sits below.
            $documents = $siteData['documents'];
            usort(
                $documents,
                static fn (array $left, array $right): int => strcmp($left['document']->getPath(), $right['document']->getPath())
            );

            foreach ($documents as $documentData) {
                $document = $documentData['document'];
                $listedAncestors = 0;
                $nearestListedAncestor = '';
                $missingAncestors = [];
                // The ancestors from the topmost one down to the nearest, so that the missing
                // ones are named in the order they are above the document.
                foreach (array_reverse($this->documentAncestors($document)) as $ancestor) {
                    if (isset($listedDocuments[$ancestor->getContextPath()])) {
                        $listedAncestors++;
                        $nearestListedAncestor = $ancestor->getContextPath();
                        continue;
                    }
                    $missingAncestors[] = [
                        'label' => $ancestor->getLabel(),
                        'icon' => $this->iconOf($ancestor),
                    ];
                }

                $sites[$siteKey]['documents'][] = [
                    'identifier' => $document->getContextPath(),
                    'path' => $document->getPath(),
                    'label' => $document->getLabel(),
                    'icon' => $this->iconOf($document),
                    'dimension' => $documentData['dimension'],
                    // The variant of the document, so that the variants of a document can be
                    // told apart in the list.
                    'dimensionLabel' => implode(', ', $documentData['dimension']),
                    'counts' => $this->countChanges($documentData['changes']),
                    // The kind of the change of the document, for the colour of the bar at the
                    // left of its row: a deleted document is red, a created one green, and
                    // everything that is changed orange.
                    'kind' => $this->kindOfDocument($document, $documentData['changes']),
                    // How many documents of the list the document sits below - that is how far
                    // it is indented.
                    'level' => $listedAncestors,
                    // The document it sits below, so that the documents below a document can
                    // be selected with it.
                    'parent' => $nearestListedAncestor,
                    // The documents between the site and the document that have no changes of
                    // their own - they are not in the list, so they are named.
                    'missingAncestors' => $missingAncestors,
                    // The first document of the whole list is opened when the tree arrives.
                    'isFirst' => $isFirstDocument,
                ];
                $isFirstDocument = false;
            }
        }

        return array_values($sites);
    }

    /**
     * The changes of one document, addressed by its identifier.
     *
     * The document is looked up in the changes of the workspace and not in the content
     * repository, because a document whose change is a removal does not exist any more in the
     * workspace - it is only there as a removed node, which the content repository hides.
     *
     * @return array{document: NodeInterface|null, label: string, icon: string, breadcrumb: list<string>, dimensionLabel: string, isNew: bool, isDeleted: bool, baseContextPath: string, changes: list<array<string,mixed>>}
     */
    /**
     * @param array<string, list<string>> $dimensionValues the variants to show, empty for all
     * @return array{document: NodeInterface|null, label: string, icon: string, breadcrumb: list<string>, dimensionLabel: string, isNew: bool, isDeleted: bool, baseContextPath: string, changes: list<array<string,mixed>>}
     */
    public function getDocumentWithChanges(Workspace $workspace, string $documentContextPath, array $dimensionValues = []): array
    {
        foreach ($this->groupChangesByDocument($workspace, $dimensionValues) as $siteNodeName => $siteData) {
            foreach ($siteData['documents'] as $documentData) {
                if ($documentData['document']->getContextPath() !== $documentContextPath) {
                    continue;
                }
                $documentNode = $documentData['document'];
                $changes = $this->describeChangesOfDocument($documentData['changes'], $documentNode);
                $description = $this->describeDocument($documentNode);

                return [
                    'document' => $documentNode,
                    'label' => $description['label'],
                    'icon' => $description['icon'],
                    'breadcrumb' => $description['breadcrumb'],
                    'dimensionLabel' => implode(', ', $description['dimensions']),
                    'isNew' => $description['isNew'],
                    'isDeleted' => $description['isDeleted'],
                    'baseContextPath' => $description['baseContextPath'],
                    'changes' => $changes,
                ];
            }
        }

        return [
            'document' => null,
            'label' => '',
            'icon' => '',
            'breadcrumb' => [],
            'dimensionLabel' => '',
            'isNew' => false,
            'isDeleted' => false,
            'baseContextPath' => '',
            'changes' => [],
        ];
    }

    /**
     * The kind of the change of a document, for the colour of the bar at the left of its row.
     *
     * A document that was deleted is red, a document that was created is green, and a
     * document that is changed - or that has changed content - is orange.
     *
     * @param list<NodeInterface> $changes the changes of the document
     */
    private function kindOfDocument(NodeInterface $document, array $changes): string
    {
        if ($document->isRemoved()) {
            return 'deleted';
        }
        if ($this->isNewInBaseWorkspace($document)) {
            return 'created';
        }
        foreach ($changes as $change) {
            if (!$change->isRemoved()) {
                return 'edited';
            }
        }

        return '';
    }

    /**
     * The label, the icon and the breadcrumb of a document.
     *
     * The breadcrumb names the documents the document sits below, from the site down to its
     * parent - the path of the document, without the node names the repository uses.
     *
     * @return array{label: string, icon: string, breadcrumb: list<string>, dimensions: list<string>, isNew: bool, isDeleted: bool, baseContextPath: string}
     */
    public function describeDocument(NodeInterface $document): array
    {
        $siteNodeName = $this->siteNodeNameOf($document);
        $icon = $this->iconOf($document);

        return [
            'label' => $document->getLabel(),
            'dimensions' => $this->describeDimensions($document),
            // A node type without an icon is not an error, the icon is simply empty.
            'icon' => $icon,
            'breadcrumb' => $this->breadcrumb($document, $siteNodeName),
            // The document was created in the workspace - there is nothing to compare it
            // with in the base workspace.
            'isNew' => $this->isNewInBaseWorkspace($document),
            // The document is deleted in the workspace - there is nothing left to show.
            'isDeleted' => $document->isRemoved(),
            // The document as it is in the base workspace, for the preview of the published
            // version.
            'baseContextPath' => $this->contextPathInBaseWorkspace($document),
        ];
    }

    /**
     * The context path of a node in the workspace the workspace of the node is based on.
     *
     * The path of a node can be different in the base workspace - a node that was moved has
     * its old path there - so the node is looked up by its identifier.
     *
     * The path is empty when the node does not exist in the base workspace - it was created
     * there.
     */
    public function contextPathInBaseWorkspace(NodeInterface $node): string
    {
        return $this->baseNode($node)?->getContextPath() ?? '';
    }

    /**
     * The labels of the documents the document sits below, from the site down to its parent.
     *
     * @return list<string>
     */
    private function breadcrumb(NodeInterface $document, string $siteNodeName): array
    {
        $labels = [];
        foreach (array_reverse($this->documentAncestors($document)) as $ancestor) {
            $labels[] = $ancestor->getLabel();
        }

        return $labels;
    }

    /**
     * The documents a document sits below, the nearest one first.
     *
     * @return list<NodeInterface>
     */
    public function documentAncestors(NodeInterface $document): array
    {
        if ($document->isRemoved()) {
            return [];
        }

        $sitePath = '/sites/' . $this->siteNodeNameOf($document);
        $ancestors = [];
        // The parent of the root node is null, although the interface does not say so.
        /** @var NodeInterface|null $parent */
        $parent = $document->getParent();
        while ($parent !== null && $parent->getPath() !== $sitePath) {
            if ($parent->getNodeType()->isOfType('Neos.Neos:Document')) {
                $ancestors[] = $parent;
            }
            /** @var NodeInterface|null $parent */
            $parent = $parent->getParent();
        }

        return $ancestors;
    }

    /**
     * The site the document belongs to.
     *
     * A document that sits directly below the sites root belongs to no site - that is what a
     * test run that creates pages without a site leaves behind.
     */
    private function siteNodeNameOf(NodeInterface $document): string
    {
        $pathParts = explode('/', $document->getPath());
        if (count($pathParts) <= 3) {
            return '';
        }

        return $pathParts[2];
    }

    /**
     * The unpublished nodes of a document - the changes the review shows for it.
     *
     * @param array<string, list<string>> $dimensionValues the variants to show, empty for all
     * @return list<NodeInterface>
     */
    public function getChangesOfDocument(Workspace $workspace, NodeInterface $document, array $dimensionValues = []): array
    {
        foreach ($this->groupChangesByDocument($workspace, $dimensionValues) as $siteData) {
            foreach ($siteData['documents'] as $documentData) {
                if ($documentData['document']->getContextPath() !== $document->getContextPath()) {
                    continue;
                }

                return $documentData['changes'];
            }
        }

        return [];
    }

    /**
     * The changes of one document, with their diffs.
     *
     * @return list<array<string,mixed>>
     */
    /**
     * @param array<string, list<string>> $dimensionValues the variants to show, empty for all
     * @return list<array<string,mixed>>
     */
    public function getChangesForDocument(Workspace $workspace, NodeInterface $document, array $dimensionValues = []): array
    {
        foreach ($this->groupChangesByDocument($workspace, $dimensionValues) as $siteData) {
            foreach ($siteData['documents'] as $documentData) {
                if ($documentData['document']->getContextPath() !== $document->getContextPath()) {
                    continue;
                }

                return $this->describeChangesOfDocument($documentData['changes'], $documentData['document']);
            }
        }

        return [];
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
        $this->baseNodeCache = [];
        $this->ancestorCache = [];
    }

    /**
     * The number of changes of the workspace, by kind.
     *
     * @return array{new: int, changed: int, removed: int, total: int}
     */
    public function getChangesCount(Workspace $workspace): array
    {
        $counts = ['new' => 0, 'changed' => 0, 'removed' => 0, 'total' => 0];
        foreach ($this->groupChangesByDocument($workspace) as $siteData) {
            foreach ($siteData['documents'] as $documentData) {
                foreach ($documentData['changes'] as $node) {
                    if ($node->isRemoved()) {
                        $counts['removed']++;
                    } elseif ($this->isNewInBaseWorkspace($node)) {
                        $counts['new']++;
                    } else {
                        $counts['changed']++;
                    }
                    $counts['total']++;
                }
            }
        }

        return $counts;
    }

    /**
     * A single change with its diff.
     *
     * @return array<string,mixed>
     */
    public function describeChange(NodeInterface $node): array
    {
        $nodeStateChanged = $this->nodeStateDifferences($node);
        $type = 'changed';
        if ($node->isRemoved()) {
            $type = 'deleted';
        } elseif ($this->isNewInBaseWorkspace($node)) {
            $type = 'created';
        } elseif (isset($nodeStateChanged['hidden'])) {
            $type = 'hidden';
        } elseif ($this->isMovedInBaseWorkspace($node)) {
            $type = 'moved';
        }

        return [
            'identifier' => $node->getIdentifier(),
            'contextPath' => $node->getContextPath(),
            'path' => $node->getPath(),
            'label' => $node->getLabel(),
            'nodeTypeName' => $node instanceof Node
                ? (string)$node->getNodeTypeName()
                : $node->getNodeType()->getName(),
            'nodeTypeLabel' => $this->nodeTypeLabel($node),
            'type' => $type,
            'isNew' => $this->isNewInBaseWorkspace($node),
            'isMoved' => $this->isMovedInBaseWorkspace($node),
            'dimensions' => $this->describeDimensions($node),
            'lastModificationDateTime' => $node instanceof Node
                ? $node->getLastModificationDateTime()
                : null,
            'icon' => $this->iconOf($node),
            'contentChanges' => $this->renderContentChanges($node),
        ];
    }

    /**
     * The ancestors of a node that do not exist in the published version.
     *
     * A node can only be published if its parents exist in the workspace it is published to. The
     * parents that were created in the workspace have to be published with the node, otherwise
     * the node would end up below a parent that does not exist.
     *
     * @return list<NodeInterface>
     */
    public function unpublishedAncestorsOf(NodeInterface $node): array
    {
        $ancestors = [];
        $path = $node->getParentPath();
        while ($path !== '' && $path !== '/') {
            $ancestor = $this->nodeAtPath($path, $node->getContext());
            if (!$ancestor instanceof NodeInterface) {
                break;
            }
            if ($this->isNewInBaseWorkspace($ancestor)) {
                $ancestors[] = $ancestor;
            }
            $path = $ancestor->getParentPath();
        }

        return $ancestors;
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
     * @param list<NodeInterface> $changes the unpublished nodes of the document
     * @param NodeInterface $document the document the changes belong to
     * @return list<array<string,mixed>>
     */
    private function describeChangesOfDocument(array $changes, NodeInterface $document): array
    {
        $changesByContextPath = [];
        foreach ($changes as $change) {
            $changesByContextPath[$change->getContextPath()] = true;
        }

        $entries = [];
        foreach ($changes as $change) {
            $ancestors = $this->ancestorsOf($change, $document);
            $entries[] = [
                'description' => $this->describeChange($change),
                'sortKey' => $this->sortKeyOf($change, $ancestors),
                'ancestors' => $ancestors,
                'requiredParent' => $this->requiredParentOf($ancestors, $changesByContextPath),
            ];
        }

        usort($entries, static function (array $left, array $right): int {
            return self::compareSortKeys($left['sortKey'], $right['sortKey']);
        });

        $described = [];
        foreach ($entries as $entry) {
            $description = $entry['description'];
            $requiredParent = $entry['requiredParent'];
            $description['requiredParents'] = $requiredParent instanceof NodeInterface
                ? [$requiredParent->getContextPath()]
                : [];
            // The containers the change sits in, so that the list can show where it belongs -
            // a text in a column of a multi column element for example.
            $description['ancestors'] = $this->describeAncestors($entry['ancestors']);
            $description['depth'] = count($description['ancestors']);
            $described[] = $description;
        }

        return $described;
    }

    /**
     * The labels and the icons of the containers a change sits in, from the topmost one down
     * to the nearest.
     *
     * The document itself is not part of the chain - it is the heading of the list.
     *
     * @param list<NodeInterface> $ancestors the ancestors of the change, the nearest one
     *                                        first and the document last
     * @return list<array{label: string, icon: string}>
     */
    private function describeAncestors(array $ancestors): array
    {
        if ($ancestors === []) {
            return [];
        }

        // The document is the last ancestor of every change and is not part of the chain
        // inside the document.
        $insideDocument = array_slice($ancestors, 0, count($ancestors) - 1);

        $described = [];
        foreach (array_reverse($insideDocument) as $ancestor) {
            $described[] = [
                'label' => $ancestor->getLabel(),
                'icon' => $this->iconOf($ancestor),
            ];
        }

        return $described;
    }

    /**
     * The ancestors of a node, from its parent up to the document the changes belong to.
     *
     * The ancestors are looked up by their path: the path of a node is known even when the node
     * itself is gone, and the node that is looked up is the one of the workspace the change is
     * in - with the published version as the fallback.
     *
     * @param NodeInterface $node
     * @param NodeInterface $document
     * @return list<NodeInterface>
     */
    private function ancestorsOf(NodeInterface $node, NodeInterface $document): array
    {
        if ($node->getPath() === $document->getPath()) {
            // The document is the node itself - it is not its own ancestor.
            return [];
        }

        $ancestors = [];
        foreach ($this->ancestorPathsOf($node, $document) as $path) {
            $ancestor = $this->nodeAtPath($path, $node->getContext());
            if (!$ancestor instanceof NodeInterface) {
                // The parent does not exist (any more) - the chain ends here.
                break;
            }
            $ancestors[] = $ancestor;
        }
        // The document is the farthest ancestor of every change.
        $ancestors[] = $document;

        return $ancestors;
    }

    /**
     * The paths of the ancestors of a node, from its parent up to the document.
     *
     * @return list<string>
     */
    private function ancestorPathsOf(NodeInterface $node, NodeInterface $document): array
    {
        $relativePath = substr($node->getPath(), strlen($document->getPath()));
        $segments = explode('/', trim($relativePath, '/'));
        // The node itself is not its own ancestor.
        array_pop($segments);

        $paths = [];
        $path = $document->getPath();
        foreach ($segments as $segment) {
            $path .= '/' . $segment;
            $paths[] = $path;
        }

        // The nearest ancestor comes first.
        return array_reverse($paths);
    }

    /**
     * The node at a path, as it is in the workspace of the context.
     *
     * @param string $path
     * @param Context $context
     */
    private function nodeAtPath(string $path, Context $context): ?NodeInterface
    {
        $cacheKey = $context->getWorkspaceName() . '|' . json_encode($context->getDimensions()) . '|' . $path;
        if (!array_key_exists($cacheKey, $this->ancestorCache)) {
            $node = $this->nodeDataRepository->findOneByPathInContext($path, $context);
            $this->ancestorCache[$cacheKey] = $node instanceof NodeInterface ? $node : null;
        }

        return $this->ancestorCache[$cacheKey];
    }

    /**
     * The key a change is ordered by: the index every node of its path has among its siblings,
     * from the document down to the node itself.
     *
     * The key of the document is the beginning of the key of every change below it, so that the
     * document comes first and the changes below it follow the order of the tree.
     *
     * @param NodeInterface $node
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
            if (!$this->isNewInBaseWorkspace($ancestor)) {
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
     * The unpublished nodes of the workspace, grouped by the document they belong to.
     *
     * @param array<string, list<string>> $dimensionValues the variants to show, empty for all
     * @return array<string, array{site: Site|null, documents: list<array{document: NodeInterface, dimension: list<string>, changes: list<NodeInterface>}>}>
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
        /** @var array<string, list<array{document: NodeInterface, dimension: list<string>, changes: list<NodeInterface>}>> $documentsOfSite */
        $documentsOfSite = [];
        foreach ($documents as $documentData) {
            $siteNodeName = $documentData['siteNodeName'];
            $siteDocuments = $documentsOfSite[$siteNodeName] ?? [];
            $siteDocuments[] = [
                'document' => $documentData['document'],
                'dimension' => $this->describeDimensions($documentData['document']),
                'changes' => $documentData['changes'],
            ];
            $documentsOfSite[$siteNodeName] = $siteDocuments;
        }

        /** @var array<string, array{site: Site|null, documents: list<array{document: NodeInterface, dimension: list<string>, changes: list<NodeInterface>}>}> $grouped */
        $grouped = [];
        foreach ($documentsOfSite as $siteNodeName => $siteDocuments) {
            $grouped[$siteNodeName] = [
                'site' => $siteNodeName === '' ? null : $this->siteRepository->findOneByNodeName($siteNodeName),
                'documents' => $siteDocuments,
            ];
        }

        /** @var array<string, array{site: Site|null, documents: list<array{document: NodeInterface, dimension: list<string>, changes: list<NodeInterface>}>}> $grouped */
        $grouped = $this->withoutDeletedContent($grouped);

        /** @var array<string, array{site: Site|null, documents: list<array{document: NodeInterface, dimension: list<string>, changes: list<NodeInterface>}>}> $grouped */
        $this->groupedChangesCache[$cacheKey] = $grouped;

        return $grouped;
    }

    /**
     * A deleted document is one change: the content below it is not reviewed on its own.
     *
     * @param array<string, array{site: Site|null, documents: list<array{document: NodeInterface, dimension: list<string>, changes: list<NodeInterface>}>}> $grouped
     * @return array<string, array{site: Site|null, documents: list<array{document: NodeInterface, dimension: list<string>, changes: list<NodeInterface>}>}>
     */
    private function withoutDeletedContent(array $grouped): array
    {
        $withoutDeletedContent = [];
        foreach ($grouped as $siteNodeName => $siteData) {
            $documents = [];
            foreach ($siteData['documents'] as $documentData) {
                $changes = $documentData['changes'];
                if ($documentData['document']->isRemoved()) {
                    $documentIdentifier = $documentData['document']->getIdentifier();
                    $changes = array_values(array_filter(
                        $changes,
                        static fn (NodeInterface $node): bool => $node->getIdentifier() === $documentIdentifier
                    ));
                }
                $documents[] = [
                    'document' => $documentData['document'],
                    'dimension' => $documentData['dimension'],
                    'changes' => $changes,
                ];
            }
            $withoutDeletedContent[$siteNodeName] = [
                'site' => $siteData['site'],
                'documents' => $documents,
            ];
        }

        /** @var array<string, array{site: Site|null, documents: list<array{document: NodeInterface, dimension: list<string>, changes: list<NodeInterface>}>}> $withoutDeletedContent */
        return $withoutDeletedContent;
    }

    /**
     * The icon of the node type, with the prefix of the font.
     *
     * The node types carry the icon in the older notation ("icon-dollar"), which the core
     * maps when it loads the node type, or in the modern one ("dollar"), which comes without
     * the prefix of the font - the neos.ui adds the default one, and so does this.
     */
    private function iconOf(NodeInterface $node): string
    {
        $icon = $node->getNodeType()->getConfiguration('ui.icon');
        if (!is_string($icon) || $icon === '') {
            return '';
        }
        if (str_contains($icon, ' ')) {
            return $this->iconNameMappingService->convert($icon);
        }

        return $this->iconNameMappingService->convert('icon-' . $icon);
    }

    /**
     * The label of the node type, the one a human sees in the editor.
     *
     * The name of a node type ("Allcompare.Content.Tables:Content.DescriptionList.Row") is an
     * internal detail; the label is what the node type shows itself as ("Fakt"). A node type
     * that says "i18n" has no label of its own and is translated by its name instead.
     */
    private function nodeTypeLabel(NodeInterface $node): string
    {
        $nodeType = $node->getNodeType();
        $label = trim((string)$nodeType->getLabel());
        if ($label !== '' && $label !== 'i18n' && !str_contains($label, '${')) {
            $name = $nodeType->getName();
            $packageKey = str_contains($name, ':') ? explode(':', $name, 2)[0] : '';

            return $this->translateLabel($label, $packageKey);
        }

        $name = $nodeType->getName();
        // the name of a node type is "Package:Name", and the translations of the node types
        // live in the catalogue of that package
        [$packageKey, $shortName] = str_contains($name, ':') ? explode(':', $name, 2) : ['', $name];
        $translated = $this->translateById('ui.label', $packageKey, 'NodeTypes/' . $shortName);

        return $translated === 'ui.label' ? $name : $translated;
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
     * @param list<NodeInterface> $changes
     * @return array{new: int, changed: int, removed: int, total: int}
     */
    private function countChanges(array $changes): array
    {
        $counts = ['new' => 0, 'changed' => 0, 'removed' => 0, 'total' => 0];
        foreach ($changes as $node) {
            if ($node->isRemoved()) {
                $counts['removed']++;
            } elseif ($this->isNewInBaseWorkspace($node)) {
                $counts['new']++;
            } else {
                $counts['changed']++;
            }
            $counts['total']++;
        }

        return $counts;
    }

    /**
     * The dimensions of a node as the labels the core shows them with.
     *
     * A node carries a list of values per dimension, and every one of them belongs to a preset
     * of its own.
     *
     * @return list<string>
     */
    private function describeDimensions(NodeInterface $node): array
    {
        $dimensions = [];
        foreach ($node->getDimensions() as $dimensionName => $dimensionValues) {
            foreach ((array)$dimensionValues as $dimensionValue) {
                $dimensions[] = $this->dimensionLabel((string)$dimensionName, (string)$dimensionValue);
            }
        }

        return $dimensions;
    }

    /**
     * The label of the preset a dimension value belongs to, the way the core shows them.
     */
    private function dimensionLabel(string $dimensionName, string $dimensionValue): string
    {
        $configuration = $this->contentDimensionPresetSource->getAllPresets();
        foreach ($configuration[$dimensionName]['presets'] ?? [] as $presetIdentifier => $presetConfiguration) {
            $values = (array)($presetConfiguration['values'] ?? []);
            if (in_array($dimensionValue, $values, true)) {
                return $this->translateLabel((string)($presetConfiguration['label'] ?? $presetIdentifier));
            }
        }

        return $dimensionValue;
    }

    /**
     * Whether the node was created in the workspace, which means that it does not exist in the
     * workspace the workspace is based on.
     */
    public function isNewInBaseWorkspace(NodeInterface $node): bool
    {
        return $this->baseNode($node) === null;
    }

    private function isMovedInBaseWorkspace(NodeInterface $node): bool
    {
        $baseNode = $this->baseNode($node);
        if ($baseNode === null) {
            return false;
        }

        return $baseNode->getPath() !== $node->getPath();
    }

    /**
     * The node as it is in the workspace the workspace of the node is based on.
     *
     * The comparison is made with the base workspace and not with the live workspace: a
     * workspace can be based on another workspace, and a node that was created in that base
     * workspace is not new - it is only changed.
     */
    private function baseNode(NodeInterface $node): ?NodeInterface
    {
        $baseWorkspaceName = $node->getWorkspace()->getBaseWorkspace()?->getName();
        if ($baseWorkspaceName === null) {
            return null;
        }

        // The variants of a node share their identifier, so the dimensions belong to the key.
        $cacheKey = $baseWorkspaceName . '|' . $node->getIdentifier() . '|' . json_encode($node->getDimensions());
        if (array_key_exists($cacheKey, $this->baseNodeCache)) {
            return $this->baseNodeCache[$cacheKey];
        }

        // The node of the same variant in the base workspace: the variants of a node share
        // their identifier.
        $baseContext = $this->contextFactory->create([
            'workspaceName' => $baseWorkspaceName,
            'dimensions' => $node->getDimensions(),
        ]);
        $baseNode = $baseContext->getNodeByIdentifier($node->getIdentifier());

        $this->baseNodeCache[$cacheKey] = $baseNode instanceof NodeInterface ? $baseNode : null;

        return $this->baseNodeCache[$cacheKey];
    }

    /**
     * The unpublished nodes below a node, the deepest one first.
     *
     * The children of a node that was created in the workspace are created there as well, and
     * they are unpublished with it. A child of a node that does not exist in the base
     * workspace cannot exist there either.
     *
     * @return list<NodeInterface>
     */
    public function unpublishedDescendantsOf(NodeInterface $node): array
    {
        $pathPrefix = $node->getPath() . '/';
        $descendants = [];
        foreach ($this->publishingService->getUnpublishedNodes($node->getWorkspace()) as $candidate) {
            if (!str_starts_with($candidate->getPath(), $pathPrefix)) {
                continue;
            }
            $descendants[] = $candidate;
        }

        // The deepest node first, so that a node is never removed before its children.
        usort(
            $descendants,
            static fn (NodeInterface $left, NodeInterface $right): int => substr_count($right->getPath(), '/') <=> substr_count($left->getPath(), '/')
        );

        return $descendants;
    }

    /**
     * Discards a node without leaving its children behind.
     *
     * A node that was created in the workspace is removed when it is discarded - and its
     * children are removed with it, because a child whose parent is gone is an orphan that
     * the content repository cannot do anything with any more.
     *
     * A node that exists in the base workspace is only reverted: its children stay where
     * they are.
     */
    public function discardNodeWithItsChildren(NodeInterface $node): void
    {
        if (!$this->isNewInBaseWorkspace($node)) {
            $this->publishingService->discardNode($node);

            return;
        }

        $nodes = $this->unpublishedDescendantsOf($node);
        $nodes[] = $node;
        $this->publishingService->discardNodes($nodes);
    }

    /**
     * The diff of the properties that changed on the node.
     *
     * @return array<string, array{properties: array{propertyLabel: string, type: string}, changes: array<string,mixed>}>
     */
    private function renderContentChanges(NodeInterface $changedNode): array
    {
        $contentChanges = [];
        $originalNode = $this->getOriginalNode($changedNode);
        $propertyDefaults = $changedNode->getNodeType()->getDefaultValuesForProperties();

        foreach ($changedNode->getProperties() as $propertyName => $changedPropertyValue) {
            if ($originalNode === null && empty($changedPropertyValue)) {
                continue;
            }
            if (isset($propertyDefaults[$propertyName]) && $changedPropertyValue === $propertyDefaults[$propertyName]) {
                continue;
            }
            $originalPropertyValue = $originalNode === null ? null : $originalNode->getProperty($propertyName);
            if ($changedPropertyValue === $originalPropertyValue && !$changedNode->isRemoved()) {
                continue;
            }

            // Boolean properties (such as the visibility flags) would be lost in the text
            // branch below: renderSlimmedDownContent() only handles strings, so both true and
            // false render to an empty string and the diff comes out empty.
            if (is_bool($changedPropertyValue) || is_bool($originalPropertyValue)) {
                $contentChanges[$propertyName] = [
                    'properties' => [
                        'propertyLabel' => $this->getPropertyLabel($propertyName, $changedNode),
                        'type' => 'boolean',
                    ],
                    'changes' => [
                        'original' => $originalPropertyValue,
                        'changed' => $changedPropertyValue,
                    ],
                ];
                continue;
            }

            if (!is_object($originalPropertyValue) && !is_object($changedPropertyValue)) {
                $originalSlimmedDownContent = $this->renderSlimmedDownContent($originalPropertyValue);
                $changedSlimmedDownContent = $changedNode->isRemoved()
                    ? ''
                    : $this->renderSlimmedDownContent($changedPropertyValue);

                $diff = new Diff(explode("\n", $originalSlimmedDownContent), explode("\n", $changedSlimmedDownContent), ['context' => 1]);
                $diffArray = $diff->render(new HtmlArrayRenderer());

                if (count($diffArray) > 0) {
                    $contentChanges[$propertyName] = [
                        'properties' => [
                            'propertyLabel' => $this->getPropertyLabel($propertyName, $changedNode),
                            'type' => 'text',
                        ],
                        'changes' => [
                            'diff' => $this->markEmptyDiffSides($diffArray),
                            // A property that was never set and a property that was set to an
                            // empty string are told apart, so that the view can say which of
                            // the two it is.
                            'originalIsNull' => $originalPropertyValue === null,
                            'changedIsNull' => $changedPropertyValue === null,
                        ],
                    ];
                }
            } elseif (
                ($originalPropertyValue instanceof \Neos\Media\Domain\Model\ImageInterface || $originalPropertyValue === null)
                && ($changedPropertyValue instanceof \Neos\Media\Domain\Model\ImageInterface || $changedPropertyValue === null)
            ) {
                $contentChanges[$propertyName] = [
                    'properties' => [
                        'propertyLabel' => $this->getPropertyLabel($propertyName, $changedNode),
                        'type' => 'image',
                    ],
                    'changes' => [
                        'original' => $this->loadAsset($originalPropertyValue),
                        'changed' => $this->loadAsset($changedPropertyValue),
                    ],
                ];
            } elseif ($originalPropertyValue instanceof AssetInterface || $changedPropertyValue instanceof AssetInterface) {
                $contentChanges[$propertyName] = [
                    'properties' => [
                        'propertyLabel' => $this->getPropertyLabel($propertyName, $changedNode),
                        'type' => 'asset',
                    ],
                    'changes' => [
                        'original' => $this->loadAsset($originalPropertyValue),
                        'changed' => $this->loadAsset($changedPropertyValue),
                    ],
                ];
            } elseif ($originalPropertyValue instanceof \DateTime || $changedPropertyValue instanceof \DateTime) {
                $changed = false;
                if (!$changedPropertyValue instanceof \DateTime || !$originalPropertyValue instanceof \DateTime) {
                    $changed = true;
                } elseif ($changedPropertyValue->getTimestamp() !== $originalPropertyValue->getTimestamp()) {
                    $changed = true;
                }
                if ($changed) {
                    $contentChanges[$propertyName] = [
                        'properties' => [
                            'propertyLabel' => $this->getPropertyLabel($propertyName, $changedNode),
                            'type' => 'datetime',
                        ],
                        'changes' => [
                            'original' => $originalPropertyValue,
                            'changed' => $changedPropertyValue,
                        ],
                    ];
                }
            }
        }

        foreach ($this->nodeStateDifferences($changedNode) as $stateName => $difference) {
            $isDate = $difference['changed'] instanceof \DateTimeInterface
                || $difference['original'] instanceof \DateTimeInterface;
            $contentChanges[$stateName] = [
                'properties' => [
                    'propertyLabel' => $this->translateLabel('Shel.Neos.WorkspaceModule:Main:review.nodeState.' . $stateName),
                    'type' => $isDate ? 'datetime' : 'boolean',
                ],
                'changes' => [
                    'original' => $difference['original'],
                    'changed' => $difference['changed'],
                ],
            ];
        }

        return $contentChanges;
    }

    /**
     * Marks the sides of a diff that show nothing.
     *
     * The diff of a text property renders the empty value as an empty cell, which is hard to
     * tell apart from a value that was never set. The view shows a marker instead, and this
     * tells it where.
     *
     * @param array<int, array<int, array<string, mixed>>> $diffGroups
     * @return array<int, array<int, array<string, mixed>>>
     */
    private function markEmptyDiffSides(array $diffGroups): array
    {
        foreach ($diffGroups as $groupIndex => $group) {
            foreach ($group as $blockIndex => $block) {
                $base = $block['base'] ?? null;
                $changed = $block['changed'] ?? null;
                $diffGroups[$groupIndex][$blockIndex]['baseIsEmpty'] = $this->linesAreEmpty(
                    is_array($base) ? ($base['lines'] ?? []) : []
                );
                $diffGroups[$groupIndex][$blockIndex]['changedIsEmpty'] = $this->linesAreEmpty(
                    is_array($changed) ? ($changed['lines'] ?? []) : []
                );
            }
        }

        return $diffGroups;
    }

    /**
     * Whether all the lines of a diff side are empty.
     *
     * The lines of a diff carry the markup of the renderer, so the tags are removed before
     * they are looked at.
     */
    private function linesAreEmpty(mixed $lines): bool
    {
        if (!is_array($lines)) {
            return true;
        }
        foreach ($lines as $line) {
            if (is_string($line) && trim(strip_tags($line)) !== '') {
                return false;
            }
        }

        return true;
    }

    /**
     * The node state that differs from the published version, keyed by state name.
     *
     * The state is not part of the property store - getProperties() returns declared properties
     * only - which is why a node whose change was being hidden had no diff at all.
     *
     * @return array<string, array{original: mixed, changed: mixed}>
     */
    public function nodeStateDifferences(NodeInterface $changedNode): array
    {
        if ($changedNode->isRemoved()) {
            return [];
        }

        $originalNode = $this->getOriginalNode($changedNode);
        if ($originalNode === null) {
            return [];
        }

        $differences = [];
        foreach (['hidden', 'hiddenInIndex'] as $stateName) {
            $changedValue = $this->readBooleanState($changedNode, $stateName);
            $originalValue = $this->readBooleanState($originalNode, $stateName);
            if ($changedValue === $originalValue) {
                continue;
            }
            $differences[$stateName] = ['original' => $originalValue, 'changed' => $changedValue];
        }
        foreach (['hiddenBeforeDateTime', 'hiddenAfterDateTime'] as $stateName) {
            $changedValue = $this->readDateState($changedNode, $stateName);
            $originalValue = $this->readDateState($originalNode, $stateName);
            if ($changedValue?->getTimestamp() === $originalValue?->getTimestamp()) {
                continue;
            }
            $differences[$stateName] = ['original' => $originalValue, 'changed' => $changedValue];
        }

        return $differences;
    }

    private function readBooleanState(NodeInterface $node, string $stateName): bool
    {
        return match ($stateName) {
            'hidden' => $node->isHidden(),
            'hiddenInIndex' => $node->isHiddenInIndex(),
            default => throw new \InvalidArgumentException('Not a boolean node state: ' . $stateName),
        };
    }

    private function readDateState(NodeInterface $node, string $stateName): ?\DateTimeInterface
    {
        return match ($stateName) {
            'hiddenBeforeDateTime' => $node->getHiddenBeforeDateTime(),
            'hiddenAfterDateTime' => $node->getHiddenAfterDateTime(),
            default => throw new \InvalidArgumentException('Not a date node state: ' . $stateName),
        };
    }

    /**
     * The node as it is in the published version the workspace is based on.
     */
    private function getOriginalNode(NodeInterface $modifiedNode): ?NodeInterface
    {
        $baseWorkspaceName = $modifiedNode->getWorkspace()->getBaseWorkspace()?->getName();
        if ($baseWorkspaceName === null) {
            return null;
        }
        $contextProperties = $modifiedNode->getContext()->getProperties();
        $contextProperties['workspaceName'] = $baseWorkspaceName;
        $contentContext = $this->contextFactory->create($contextProperties);

        $originalNode = $contentContext->getNodeByIdentifier($modifiedNode->getIdentifier());

        return $originalNode instanceof NodeInterface ? $originalNode : null;
    }

    /**
     * @param mixed $propertyValue
     */
    private function renderSlimmedDownContent($propertyValue): string
    {
        $content = '';
        if (is_string($propertyValue)) {
            $contentSnippet = (string)preg_replace('/<br[^>]*>/', "\n", $propertyValue);
            $contentSnippet = (string)preg_replace('/<[^>]*>/', ' ', $contentSnippet);
            $contentSnippet = str_replace('&nbsp;', ' ', $contentSnippet);
            $content = trim((string)preg_replace('/ {2,}/', ' ', $contentSnippet));
        } elseif (is_int($propertyValue) || is_float($propertyValue)) {
            $content = (string)$propertyValue;
        }

        return $content;
    }

    private function loadAsset(ResourceBasedInterface|null $assetOrNull): ResourceBasedInterface|string|null
    {
        if ($assetOrNull instanceof DoctrineProxy) {
            try {
                $assetOrNull->__load();
            } catch (EntityNotFoundException $exception) {
                return $exception->getMessage();
            }
        }

        return $assetOrNull;
    }

    private function getPropertyLabel(string $propertyName, NodeInterface $changedNode): string
    {
        $properties = $changedNode->getNodeType()->getProperties();
        if (!isset($properties[$propertyName]) || !isset($properties[$propertyName]['ui']['label'])) {
            return $propertyName;
        }

        return $this->translateLabel($properties[$propertyName]['ui']['label']);
    }

    /**
     * The labels of the properties and of the node state are translation ids in the extended
     * form "Package:Source:Id", which the translator only resolves when it is told which part
     * is which.
     */
    private function translateLabel(string $label, string $defaultPackageKey = ''): string
    {
        $parts = explode(':', $label);
        if (count($parts) !== 3) {
            return $this->translateById($label, $defaultPackageKey);
        }

        return (string)$this->translator->translateById(
            $parts[2],
            [],
            null,
            null,
            str_replace('.', '/', $parts[1]),
            $parts[0]
        );
    }

    /**
     * Translates an id, and returns it unchanged when there is no translation for it.
     */
    private function translateById(string $id, string $packageKey = '', string $sourceName = 'Main'): string
    {
        $translated = (string)$this->translator->translateById($id, [], null, null, $sourceName, $packageKey);

        return $translated === '' ? $id : $translated;
    }
}
