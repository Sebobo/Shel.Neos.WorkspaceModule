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
use Neos\ContentRepository\Domain\Repository\NodeDataRepository;
use Neos\ContentRepository\Domain\Service\Context;
use Neos\Flow\Annotations as Flow;
use Neos\Neos\Domain\Service\ContentContextFactory;
use Neos\Neos\Service\PublishingService;

/**
 * The place of a node in the content tree and in the workspace it is published to.
 *
 * The ancestors of a node are looked up by their path: the path of a node is known even when
 * the node itself is gone, and the node that is looked up is the one of the workspace the
 * change is in - with the published version as the fallback.
 */
#[Flow\Scope('singleton')]
final class NodeTreeService
{
    /**
     * The nodes of the base workspace, cached for the lifetime of the request.
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
        private readonly ContentContextFactory $contextFactory,
        private readonly NodeDataRepository $nodeDataRepository,
        private readonly PublishingService $publishingService,
    ) {
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
     * The ancestors of a node, from its parent up to the document the changes belong to.
     *
     * @return list<NodeInterface>
     */
    public function ancestorsOf(NodeInterface $node, NodeInterface $document): array
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
     * The site a document belongs to.
     *
     * A document that sits directly below the sites root belongs to no site - that is what a
     * test run that creates pages without a site leaves behind.
     */
    public function siteNodeNameOf(NodeInterface $document): string
    {
        $pathParts = explode('/', $document->getPath());
        if (count($pathParts) <= 3) {
            return '';
        }

        return $pathParts[2];
    }

    /**
     * The node as it is in the workspace the workspace under review is based on.
     *
     * The comparison is made with the base workspace and not with the live workspace: a
     * workspace can be based on another workspace, and a node that was created in that base
     * workspace is not new - it is only changed.
     *
     * The base workspace is the one of the workspace the node is looked at in and not the one
     * of the workspace of the node: a node that was not changed in the workspace under review
     * is inherited from its base workspace, and it is not a change of the workspace under
     * review.
     */
    public function baseNode(NodeInterface $node): ?NodeInterface
    {
        $workspace = $node->getContext()->getWorkspace();
        $baseWorkspaceName = $workspace->getBaseWorkspace()?->getName();
        if ($baseWorkspaceName === null) {
            return null;
        }

        // The variants of a node share their identifier, so the dimensions belong to the key.
        $cacheKey = $baseWorkspaceName . '|' . $node->getIdentifier() . '|' . json_encode($node->getDimensions());
        if (array_key_exists($cacheKey, $this->baseNodeCache)) {
            return $this->baseNodeCache[$cacheKey];
        }

        $baseContext = $this->contextFactory->create([
            'workspaceName' => $baseWorkspaceName,
            'dimensions' => $node->getDimensions(),
        ]);
        $baseNode = $baseContext->getNodeByIdentifier($node->getIdentifier());

        $this->baseNodeCache[$cacheKey] = $baseNode instanceof NodeInterface ? $baseNode : null;

        return $this->baseNodeCache[$cacheKey];
    }

    /**
     * Whether the node was created in the workspace.
     */
    public function isNewInBaseWorkspace(NodeInterface $node): bool
    {
        return $this->baseNode($node) === null;
    }

    /**
     * Whether the node was moved in the workspace.
     */
    public function isMovedInBaseWorkspace(NodeInterface $node): bool
    {
        $baseNode = $this->baseNode($node);
        if ($baseNode === null) {
            return false;
        }

        return $baseNode->getPath() !== $node->getPath();
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
     * The unpublished nodes below a node, the deepest one first.
     *
     * The children of a node that was created in the workspace are created there as well, and
     * they are unpublished with it.
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

    public function clearCache(): void
    {
        $this->baseNodeCache = [];
        $this->ancestorCache = [];
    }
}
