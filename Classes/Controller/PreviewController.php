<?php

/*
 * This file is part of the Shel.Neos.WorkspaceModule package.
 *
 * This package is Open Source Software. For the full copyright and license
 * information, please view the LICENSE file which was distributed with this
 * source code.
 */

declare(strict_types=1);

namespace Shel\Neos\WorkspaceModule\Controller;

use Neos\ContentRepository\Domain\Model\NodeInterface;
use Neos\ContentRepository\Domain\Service\ContextFactoryInterface;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\Mvc\Controller\ActionController;
use Neos\Neos\Controller\Exception\NodeNotFoundException;
use Neos\Neos\Domain\Service\ContentContext;
use Neos\Neos\View\FusionView;
use Shel\Neos\WorkspaceModule\Service\PreviewDocumentService;

/**
 * Renders the preview of a document of the review.
 *
 * The preview is rendered on the host the review is opened on, not on the domain of the site
 * the document belongs to. That keeps the preview of a document of another site on the host of
 * the review: the frames need no second host, the session of the backend needs no handover, and
 * the frames are on the same origin as the review, so their scroll position can be read from
 * them.
 *
 * The site the document belongs to is taken from the path of the node, not from the host of the
 * request - the frontend would otherwise render the page of the site the host belongs to. The
 * node itself is addressed by its path, so it is the same node in every dimension.
 */
final class PreviewController extends ActionController
{
    /**
     * The view of the frontend renders the node of the request.
     *
     * @var class-string
     */
    protected $defaultViewObjectName = FusionView::class;

    #[Flow\Inject]
    protected PreviewDocumentService $previewDocumentService;

    #[Flow\Inject]
    protected ContextFactoryInterface $contextFactory;

    /**
     * Renders the document of the given context path.
     *
     * @param string $node the context path of the document, for example
     *        "/sites/examplecom/homepage@user-johndoe;language=de&country=global"
     */
    public function previewAction(string $node): void
    {
        $document = $this->previewDocumentService->resolveNode($node);
        if (!$document instanceof NodeInterface) {
            throw new NodeNotFoundException(
                sprintf('The node with the context path "%s" could not be resolved', $node),
                1763180631
            );
        }

        $this->view->assign('value', $this->withoutHiddenChildren($document));
        $this->response->setHttpHeader('Cache-Control', 'no-cache');

        $view = $this->view;
        if ($view instanceof FusionView && !$view->canRenderWithNodeAndPath()) {
            $view->setFusionPath('rawContent');
        }
    }

    /**
     * The document without its hidden and removed children.
     *
     * A document that is visible itself is rendered as the frontend renders it in a preview:
     * the hidden and the removed children below it are not shown. A document that is not
     * visible is rendered as it is - the preview of it is the preview of a document that is
     * hidden.
     */
    private function withoutHiddenChildren(NodeInterface $document): NodeInterface
    {
        $context = $document->getContext();
        if (!$context instanceof ContentContext) {
            return $document;
        }
        if (!$document->isVisible() || !$context->getCurrentRenderingMode()->isPreview()) {
            return $document;
        }

        $context = $this->contextFactory->create(array_merge($context->getProperties(), [
            'invisibleContentShown' => false,
            'removedContentShown' => false,
        ]));
        $node = $context->getNodeByIdentifier($document->getIdentifier());

        return $node instanceof NodeInterface ? $node : $document;
    }
}
