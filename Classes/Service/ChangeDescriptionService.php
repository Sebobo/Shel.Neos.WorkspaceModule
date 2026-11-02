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
    public function __construct(
        private readonly ContentContextFactory $contextFactory,
        private readonly NodeLabelService $nodeLabelService,
        private readonly NodeTreeService $nodeTreeService,
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

        return new ChangeItem(
            contextPath: $node->getContextPath(),
            label: $node->getLabel(),
            icon: $this->nodeLabelService->iconOf($node),
            nodeTypeLabel: $this->nodeLabelService->nodeTypeLabel($node),
            type: $type,
            lastModificationDateTime: $node instanceof Node
                ? $node->getLastModificationDateTime()
                : null,
            contentChanges: $this->renderContentChanges($node),
            requiredParents: [],
            ancestors: $this->describeAncestors($ancestors),
            depth: max(count($ancestors) - 1, 0),
        );
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

        $renderer = new HtmlArrayRenderer();

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

            $originalPropertyValue = $originalNode?->getProperty($propertyName);

            if ($changedPropertyValue === $originalPropertyValue) {
                continue;
            }

            if (!is_object($originalPropertyValue) && !is_object($changedPropertyValue)) {
                $originalSlimmedDownContent = $this->renderSlimmedDownContent($originalPropertyValue);
                $changedSlimmedDownContent = $this->renderSlimmedDownContent($changedPropertyValue);

                $diff = new Diff(
                    explode("\n", $originalSlimmedDownContent),
                    explode("\n", $changedSlimmedDownContent),
                    ['context' => 1]
                );
                /** @var list<list<array<string, mixed>>> $diffArray */
                $diffArray = $this->markEmptyDiffSides($diff->render($renderer));

                if (count($diffArray) > 0) {
                    $contentChanges[$propertyName] = new ContentChangeItem(
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
            } elseif (
                ($originalPropertyValue instanceof \Neos\Media\Domain\Model\ImageInterface || $originalPropertyValue === null)
                && ($changedPropertyValue instanceof \Neos\Media\Domain\Model\ImageInterface || $changedPropertyValue === null)
            ) {
                $contentChanges[$propertyName] = new ContentChangeItem(
                    properties: new ContentChangeProperties(
                        type: 'image',
                        propertyLabel: $this->nodeLabelService->propertyLabel($propertyName, $changedNode),
                    ),
                    changes: new ImageContentChange(
                        original: $originalPropertyValue,
                        changed: $changedPropertyValue,
                    ),
                );
            } elseif (
                $originalPropertyValue instanceof AssetInterface
                || $changedPropertyValue instanceof AssetInterface
            ) {
                $contentChanges[$propertyName] = new ContentChangeItem(
                    properties: new ContentChangeProperties(
                        type: 'asset',
                        propertyLabel: $this->nodeLabelService->propertyLabel($propertyName, $changedNode),
                    ),
                    changes: new AssetContentChange(
                        original: $originalPropertyValue,
                        changed: $changedPropertyValue,
                    ),
                );
            } elseif ($originalPropertyValue instanceof \DateTime || $changedPropertyValue instanceof \DateTime) {
                $changed = false;
                if (!$changedPropertyValue instanceof \DateTime || !$originalPropertyValue instanceof \DateTime) {
                    $changed = true;
                } elseif ($changedPropertyValue->getTimestamp() !== $originalPropertyValue->getTimestamp()) {
                    $changed = true;
                }
                if ($changed) {
                    $contentChanges[$propertyName] = new ContentChangeItem(
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

}
