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

use GuzzleHttp\Psr7\Uri;
use Neos\ContentRepository\Domain\Model\NodeInterface;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\Mvc\Routing\Dto\ResolveContext;
use Neos\Flow\Mvc\Routing\Dto\RouteParameters;
use Neos\Flow\Mvc\Routing\RouterInterface;

/**
 * The URI of the preview of a document.
 *
 * The preview is loaded from the domain of the site the document belongs to: the frontend
 * finds its site by the host of the request, so a preview that is loaded from the domain
 * of another site shows the page of that other site - or no page at all.
 */
#[Flow\Scope('singleton')]
final class PreviewUriService
{
    public function __construct(
        private readonly RouterInterface $router,
        private readonly SiteDomainService $siteDomainService,
        private readonly SessionHandoverService $sessionHandoverService,
    ) {
    }

    /**
     * The URI of the preview of the document with the given context path - the path of a
     * document in a workspace.
     *
     * Null when the site of the document has no domain it could be reached on.
     */
    public function uriFor(NodeInterface $document, string $contextPath): ?string
    {
        $domain = $this->siteDomainService->domainOf($document);
        if ($domain === null) {
            return null;
        }

        // The frontend routes are resolved for the host of the site the document belongs
        // to - the frontend finds its site by the host of the request.
        $routeParameters = RouteParameters::createEmpty()
            ->withParameter('requestUriHost', $domain->getHostname());

        $resolveContext = new ResolveContext(
            new Uri($this->siteDomainService->baseUriOf($domain)),
            [
                '@package' => 'Neos.Neos',
                '@controller' => 'Frontend\\Node',
                '@action' => 'preview',
                '@format' => 'html',
                'node' => $contextPath,
            ],
            true,
            '',
            $routeParameters
        );

        return (string)$this->router->resolve($resolveContext);
    }

    /**
     * The url of the handover of the session of the review to the domain the preview is
     * loaded from.
     *
     * The controller that hands the cookie over is reached on that domain, and the browser
     * is only sent there when the domain is not the one the review is opened on - the
     * session of the review is valid there already.
     *
     * Null when the site of the document has no domain, the handover is not needed, or the
     * review has no session to hand over.
     */
    public function handoverUrlFor(NodeInterface $document): ?string
    {
        $domain = $this->siteDomainService->domainOf($document);
        if ($domain === null || $this->siteDomainService->isCurrentHost($domain)) {
            return null;
        }

        return $this->sessionHandoverService->handoverUrlFor($this->siteDomainService->baseUriOf($domain));
    }
}
