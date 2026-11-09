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
use Neos\Flow\Annotations as Flow;
use Neos\Flow\Core\Bootstrap;
use Neos\Flow\Http\HttpRequestHandlerInterface;
use Neos\Flow\Mvc\Routing\Dto\ResolveContext;
use Neos\Flow\Mvc\Routing\Dto\RouteParameters;
use Neos\Flow\Mvc\Routing\RouterInterface;
use Psr\Http\Message\UriInterface;

/**
 * The URI of the preview of a document.
 *
 * The preview is rendered on the host the review is opened on, by the action of this package.
 * The site the document belongs to is taken from the path of the document there, so the page
 * is rendered with the layout of its own site - no matter which host the review is opened on.
 *
 * The preview therefore needs no second host: the frames of the preview are on the same origin
 * as the review, the session of the backend is valid on them, and their scroll position can be
 * read from them.
 */
#[Flow\Scope('singleton')]
final class PreviewUriService
{
    public function __construct(
        private readonly RouterInterface $router,
        private readonly Bootstrap $bootstrap,
    ) {
    }

    /**
     * The URI of the preview of the document with the given context path - the path of a
     * document in a workspace.
     *
     * Null when the review is not opened over http - there is no host the preview could be
     * loaded from then.
     */
    public function uriFor(string $contextPath): ?string
    {
        $currentUri = $this->currentUri();
        if ($currentUri === null) {
            return null;
        }

        // The route is resolved for the host of the request, so that the preview is loaded
        // from the host the review is opened on.
        $routeParameters = RouteParameters::createEmpty()
            ->withParameter('requestUriHost', $currentUri->getHost());

        $resolveContext = new ResolveContext(
            $currentUri->withPath('')->withQuery('')->withFragment(''),
            [
                '@package' => 'Shel.Neos.WorkspaceModule',
                '@controller' => 'Preview',
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
     * The URI of the request the review is opened with.
     *
     * Null when the review is not opened over http.
     */
    private function currentUri(): ?UriInterface
    {
        $requestHandler = $this->bootstrap->getActiveRequestHandler();
        if (!$requestHandler instanceof HttpRequestHandlerInterface) {
            return null;
        }

        return $requestHandler->getHttpRequest()->getUri();
    }
}
