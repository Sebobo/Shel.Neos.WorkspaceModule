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

use Neos\ContentRepository\Domain\Model\Node;
use Neos\ContentRepository\Domain\Model\NodeInterface;
use Neos\Diff\Diff;
use Neos\Diff\Renderer\Html\HtmlArrayRenderer;
use Neos\Flow\Annotations as Flow;
use Neos\Media\Domain\Model\AssetInterface;
use Neos\Media\Domain\Model\ImageInterface;
use Neos\Neos\Domain\Service\ContentContextFactory;
use Shel\Neos\WorkspaceModule\ViewModel\AncestorItem;
use Shel\Neos\WorkspaceModule\ViewModel\ChangeItem;
use Shel\Neos\WorkspaceModule\ViewModel\ContentChangeItem;
use Shel\Neos\WorkspaceModule\ViewModel\ContentChangeItems;
use Shel\Neos\WorkspaceModule\ViewModel\ContentChangeProperties;
use Shel\Neos\WorkspaceModule\ViewModel\ContentChanges\AssetContentChange;
use Shel\Neos\WorkspaceModule\ViewModel\ContentChanges\DateTimeContentChange;
use Shel\Neos\WorkspaceModule\ViewModel\ContentChanges\ImageContentChange;
use Shel\Neos\WorkspaceModule\ViewModel\ContentChanges\TextContentChange;

/**
 * The description of a change: what it is and how the content of the node differs from the
 * published version.
 */
#[Flow\Scope('singleton')]
final class ChangeDescriptionService
{
    /**
     * The prefix of a property that holds the link to another node as a string.
     */
    private const NODE_REFERENCE_PREFIX = 'node://';

    public function __construct(
        private readonly ContentContextFactory $contextFactory,
        private readonly NodeLabelService $nodeLabelService,
        private readonly NodeTreeService $nodeTreeService,
        private readonly ValueDescriptionService $valueDescriptionService,
        private readonly RelativeDateService $relativeDateService,
    ) {
    }

    /**
     * A single change with its diff.
     *
     * @param list<NodeInterface> $ancestors the containers the change sits in, the nearest one
     *                                        first
     */
    public function describeChange(NodeInterface $node, array $ancestors = []): ChangeItem
    {
        $nodeStateChanged = $this->nodeStateDifferences($node);
        $type = 'changed';
        if ($node->isRemoved()) {
            $type = 'deleted';
        } elseif ($this->nodeTreeService->isNewInBaseWorkspace($node)) {
            $type = 'created';
        } elseif (isset($nodeStateChanged['hidden'])) {
            $type = 'hidden';
        } elseif ($this->nodeTreeService->isMovedInBaseWorkspace($node)) {
            $type = 'moved';
        }

        // A move and a rename are both a change of the path. The path in the base workspace
        // tells where the change came from and what it was called there.
        $baseNode = $type === 'moved' ? $this->nodeTreeService->baseNode($node) : null;
        $movedFrom = null;
        $previousLabel = null;
        $previousName = null;
        if ($baseNode !== null) {
            if ($baseNode->getParentPath() !== $node->getParentPath()) {
                $movedFrom = $this->breadcrumbOf($this->nodeTreeService->baseAncestorsOf($node));
            }
            if ($baseNode->getLabel() !== $node->getLabel()) {
                $previousLabel = $baseNode->getLabel();
            }
            if ($baseNode->getName() !== $node->getName()) {
                $previousName = $baseNode->getName();
            }
        }

        return new ChangeItem(
            contextPath: $node->getContextPath(),
            label: $node->getLabel(),
            icon: $this->nodeLabelService->iconOf($node),
            nodeTypeLabel: $this->nodeLabelService->nodeTypeLabel($node),
            type: $type,
            lastModificationDateTime: $node instanceof Node
                ? $node->getLastModificationDateTime()
                : null,
            relativeDateTimeLabel: $node instanceof Node
                ? $this->relativeDateService->label($node->getLastModificationDateTime())
                : null,
            contentChanges: $this->renderContentChanges($node),
            requiredParents: [],
            ancestors: $this->describeAncestors($ancestors),
            movedFrom: $movedFrom,
            previousLabel: $previousLabel,
            previousName: $previousName,
        );
    }

    /**
     * The labels of a chain of nodes as one breadcrumb, from the topmost node down.
     *
     * @param list<NodeInterface> $ancestors the ancestors, the nearest one first
     */
    private function breadcrumbOf(array $ancestors): ?string
    {
        $labels = array_map(
            static fn (NodeInterface $ancestor): string => $ancestor->getLabel(),
            array_reverse($ancestors),
        );

        return $labels === [] ? null : implode(' / ', $labels);
    }

    /**
     * The containers a change sits in, from the topmost one down to the nearest.
     *
     * @param list<NodeInterface> $ancestors the ancestors of the change, the nearest one first
     * @return list<AncestorItem>
     */
    public function describeAncestors(array $ancestors): array
    {
        $described = [];
        // The document is the last ancestor of every change and is not part of the chain
        // inside the document.
        $insideDocument = array_slice($ancestors, 0, max(count($ancestors) - 1, 0));
        foreach (array_reverse($insideDocument) as $ancestor) {
            $described[] = new AncestorItem(
                label: $ancestor->getLabel(),
                icon: $this->nodeLabelService->iconOf($ancestor),
            );
        }

        return $described;
    }

    /**
     * Renders the difference between the original and the changed content of the given node.
     */
    private function renderContentChanges(NodeInterface $changedNode): ContentChangeItems
    {
        $contentChanges = [];

        $originalNode = $this->getOriginalNode($changedNode);
        $changeNodePropertiesDefaults = $changedNode->getNodeType()->getDefaultValuesForProperties();

        foreach ($changedNode->getProperties() as $propertyName => $changedPropertyValue) {
            if (
                ($originalNode === null && empty($changedPropertyValue))
                || (
                    isset($changeNodePropertiesDefaults[$propertyName])
                    && $changedPropertyValue === $changeNodePropertiesDefaults[$propertyName]
                )
            ) {
                continue;
            }

            $change = $this->describePropertyChange($changedNode, $originalNode, $propertyName, $changedPropertyValue);
            if ($change !== null) {
                $contentChanges[$propertyName] = $change;
            }
        }

        foreach ($this->nodeStateDifferences($changedNode) as $stateName => $difference) {
            $isDate = $difference['changed'] instanceof \DateTimeInterface
                || $difference['original'] instanceof \DateTimeInterface;
            $contentChanges[$stateName] = new ContentChangeItem(
                properties: new ContentChangeProperties(
                    type: $isDate ? 'datetime' : 'boolean',
                    propertyLabel: $this->nodeLabelService->translateLabel('Shel.Neos.WorkspaceModule:Main:review.nodeState.' . $stateName),
                ),
                changes: new DateTimeContentChange(
                    original: $difference['original'],
                    changed: $difference['changed'],
                ),
            );
        }

        return ContentChangeItems::fromArray($contentChanges);
    }

    /**
     * The difference of one property of a node, if it changed.
     */
    private function describePropertyChange(
        NodeInterface $changedNode,
        ?NodeInterface $originalNode,
        string $propertyName,
        mixed $changedPropertyValue
    ): ?ContentChangeItem {
        $originalPropertyValue = $originalNode?->getProperty($propertyName);

        // A reference to another node is described by the label of the node it points to,
        // so it is compared before the values are compared.
        if ($this->isNodeReference($changedPropertyValue) || $this->isNodeReference($originalPropertyValue)) {
            return $this->describeReferenceChange($changedNode, $originalNode, $propertyName, $originalPropertyValue, $changedPropertyValue);
        }

        if ($this->isSameValue($originalPropertyValue, $changedPropertyValue)) {
            return null;
        }

        if ($this->isImage($originalPropertyValue, $changedPropertyValue)) {
            return new ContentChangeItem(
                properties: new ContentChangeProperties(
                    type: 'image',
                    propertyLabel: $this->nodeLabelService->propertyLabel($propertyName, $changedNode),
                ),
                changes: new ImageContentChange(
                    original: $originalPropertyValue,
                    changed: $changedPropertyValue,
                ),
            );
        }

        if ($this->isAsset($originalPropertyValue, $changedPropertyValue)) {
            return new ContentChangeItem(
                properties: new ContentChangeProperties(
                    type: 'asset',
                    propertyLabel: $this->nodeLabelService->propertyLabel($propertyName, $changedNode),
                ),
                changes: new AssetContentChange(
                    original: $originalPropertyValue,
                    changed: $changedPropertyValue,
                ),
            );
        }

        if ($originalPropertyValue instanceof \DateTimeInterface && $changedPropertyValue instanceof \DateTimeInterface) {
            return new ContentChangeItem(
                properties: new ContentChangeProperties(
                    type: 'datetime',
                    propertyLabel: $this->nodeLabelService->propertyLabel($propertyName, $changedNode),
                ),
                changes: new DateTimeContentChange(
                    original: $originalPropertyValue,
                    changed: $changedPropertyValue,
                ),
            );
        }

        return $this->describeTextChange($changedNode, $propertyName, $originalPropertyValue, $changedPropertyValue);
    }

    /**
     * The difference of a property that points to another node.
     */
    private function describeReferenceChange(
        NodeInterface $changedNode,
        ?NodeInterface $originalNode,
        string $propertyName,
        mixed $originalPropertyValue,
        mixed $changedPropertyValue
    ): ?ContentChangeItem {
        $originalReference = $this->describeNodeReference($originalPropertyValue, $originalNode ?? $changedNode);
        $changedReference = $this->describeNodeReference($changedPropertyValue, $changedNode);

        if ($originalReference === $changedReference) {
            return null;
        }

        return new ContentChangeItem(
            properties: new ContentChangeProperties(
                type: 'text',
                propertyLabel: $this->nodeLabelService->propertyLabel($propertyName, $changedNode),
            ),
            changes: new TextContentChange(
                diff: $this->diffOf($originalReference, $changedReference),
                originalIsNull: $originalReference === '',
                changedIsNull: $changedReference === '',
            ),
        );
    }

    /**
     * The difference of a property that is shown as text.
     *
     * This is the kind of property most of them are: text, numbers, flags, but also the
     * value objects - objects that hold a value of the domain, like the postal address of
     * a node.
     */
    private function describeTextChange(
        NodeInterface $changedNode,
        string $propertyName,
        mixed $originalPropertyValue,
        mixed $changedPropertyValue
    ): ?ContentChangeItem {
        $originalDescription = $this->describeValue($changedNode, $propertyName, $originalPropertyValue);
        $changedDescription = $this->describeValue($changedNode, $propertyName, $changedPropertyValue);

        if ($originalDescription === $changedDescription) {
            return null;
        }

        $diff = new Diff(
            explode("\n", $originalDescription),
            explode("\n", $changedDescription),
            ['context' => 1]
        );
        /** @var list<list<array<string, mixed>>> $diffArray */
        $diffArray = $this->markEmptyDiffSides($diff->render(new HtmlArrayRenderer()));

        if (count($diffArray) === 0) {
            return null;
        }

        return new ContentChangeItem(
            properties: new ContentChangeProperties(
                type: 'text',
                propertyLabel: $this->nodeLabelService->propertyLabel($propertyName, $changedNode),
            ),
            changes: new TextContentChange(
                diff: $diffArray,
                originalIsNull: $originalPropertyValue === null,
                changedIsNull: $changedPropertyValue === null,
            ),
        );
    }

    /**
     * The description of a value, as the review shows it.
     *
     * The value of a select box is shown with the label of the choice, like the editor
     * shows it.
     */
    private function describeValue(NodeInterface $node, string $propertyName, mixed $value): string
    {
        $label = $this->nodeLabelService->selectValueLabel($propertyName, $node, $value);
        if ($label !== null) {
            return $label;
        }

        return $this->valueDescriptionService->describe($value);
    }

    /**
     * Whether two values of a property are the same.
     *
     * The values are compared by what they mean and not by their identity: a value object
     * is read from the content repository as a new object every time, so two instances of
     * the same value are not the same value.
     */
    private function isSameValue(mixed $originalPropertyValue, mixed $changedPropertyValue): bool
    {
        if ($originalPropertyValue === null || $changedPropertyValue === null) {
            return $originalPropertyValue === $changedPropertyValue;
        }
        if (is_object($originalPropertyValue) && is_object($changedPropertyValue)) {
            if ($originalPropertyValue instanceof \DateTimeInterface && $changedPropertyValue instanceof \DateTimeInterface) {
                return $originalPropertyValue->getTimestamp() === $changedPropertyValue->getTimestamp();
            }
            if ($originalPropertyValue instanceof AssetInterface || $changedPropertyValue instanceof AssetInterface) {
                // The media objects are shared by the persistence manager: the same image is
                // the same object.
                return $originalPropertyValue === $changedPropertyValue;
            }

            return $this->valueDescriptionService->describe($originalPropertyValue)
                === $this->valueDescriptionService->describe($changedPropertyValue);
        }
        if (is_array($originalPropertyValue) && is_array($changedPropertyValue)) {
            return $this->valueDescriptionService->describe($originalPropertyValue)
                === $this->valueDescriptionService->describe($changedPropertyValue);
        }

        return $originalPropertyValue === $changedPropertyValue;
    }

    /**
     * Whether the values of a property are an image - or nothing, so that the image can be
     * removed.
     */
    private function isImage(mixed $originalPropertyValue, mixed $changedPropertyValue): bool
    {
        return ($originalPropertyValue === null || $originalPropertyValue instanceof ImageInterface)
            && ($changedPropertyValue === null || $changedPropertyValue instanceof ImageInterface);
    }

    /**
     * Whether the values of a property are an asset - or nothing, so that the asset can be
     * removed.
     */
    private function isAsset(mixed $originalPropertyValue, mixed $changedPropertyValue): bool
    {
        return ($originalPropertyValue === null || $originalPropertyValue instanceof AssetInterface)
            && ($changedPropertyValue === null || $changedPropertyValue instanceof AssetInterface);
    }

    /**
     * Whether a value points to another node.
     *
     * A property of the type "reference" holds the node it points to, a property of the type
     * "references" holds a list of them. A property that holds the link to a node as a string
     * - the canonical link of a page for example - holds it with the prefix "node://".
     */
    private function isNodeReference(mixed $value): bool
    {
        if ($value instanceof NodeInterface) {
            return true;
        }
        if (is_array($value)) {
            foreach ($value as $entry) {
                if ($entry instanceof NodeInterface) {
                    return true;
                }
            }

            return false;
        }

        return is_string($value) && str_starts_with($value, self::NODE_REFERENCE_PREFIX);
    }

    /**
     * The description of a value that points to another node.
     *
     * The identifier of a node does not tell which node it is - the label does. The node is
     * looked up in the workspace of the node the value belongs to, so that the label is the
     * one of the version that is looked at.
     */
    private function describeNodeReference(mixed $value, NodeInterface $node): string
    {
        if (is_array($value)) {
            $descriptions = [];
            foreach ($value as $entry) {
                $descriptions[] = $this->describeNodeReference($entry, $node);
            }

            return implode(', ', array_filter($descriptions));
        }

        $referencedNode = $this->resolveReferencedNode($value, $node);
        if (!$referencedNode instanceof NodeInterface) {
            // The node is gone - the identifier is all that is left of it.
            return is_string($value) ? $value : '';
        }

        return $referencedNode->getLabel() . ' (' . $referencedNode->getIdentifier() . ')';
    }

    /**
     * The node a value points to, if it points to one.
     */
    private function resolveReferencedNode(mixed $value, NodeInterface $node): ?NodeInterface
    {
        if ($value instanceof NodeInterface) {
            return $value;
        }
        if (!is_string($value) || !str_starts_with($value, self::NODE_REFERENCE_PREFIX)) {
            return null;
        }

        $identifier = substr($value, strlen(self::NODE_REFERENCE_PREFIX));

        return $node->getContext()->getNodeByIdentifier($identifier);
    }

    /**
     * The difference between two texts.
     *
     * @return list<list<array<string, mixed>>>
     */
    private function diffOf(string $original, string $changed): array
    {
        $diff = new Diff(
            explode("\n", $original),
            explode("\n", $changed),
            ['context' => 1]
        );

        /** @var list<list<array<string, mixed>>> $diffArray */
        $diffArray = $this->markEmptyDiffSides($diff->render(new HtmlArrayRenderer()));

        return $diffArray;
    }

    /**
     * Marks the sides of a diff that show nothing.
     *
     * The diff of a text property renders the empty value as an empty cell, which is hard to
     * tell apart from a value that was never set. The view shows a marker instead, and this
     * tells it where.
     *
     * @param list<list<array<string, mixed>>> $diffGroups
     * @return list<list<array<string, mixed>>>
     */
    private function markEmptyDiffSides(array $diffGroups): array
    {
        foreach ($diffGroups as $groupIndex => $group) {
            foreach ($group as $blockIndex => $block) {
                $base = $block['base'] ?? null;
                $changed = $block['changed'] ?? null;
                $diffGroups[$groupIndex][$blockIndex]['baseIsEmpty'] = $this->linesAreEmpty(
                    is_array($base) ? $base['lines'] : []
                );
                $diffGroups[$groupIndex][$blockIndex]['changedIsEmpty'] = $this->linesAreEmpty(
                    is_array($changed) ? $changed['lines'] : []
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
}
