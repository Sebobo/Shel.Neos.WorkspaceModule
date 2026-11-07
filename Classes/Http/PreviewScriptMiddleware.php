<?php

/*
 * This file is part of the Shel.Neos.WorkspaceModule package.
 *
 * This package is Open Source Software. For the full copyright and license
 * information, please view the LICENSE file which was distributed with this
 * source code.
 */

declare(strict_types=1);

namespace Shel\Neos\WorkspaceModule\Http;

use Neos\Flow\Annotations as Flow;
use Neos\Flow\Http\ServerRequestAttributes;
use Neos\Flow\ResourceManagement\ResourceManager;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * The script of the scroll sync of the preview, injected into the pages the preview loads.
 *
 * The frames of the preview show a page of a site: the frames are on the domain of the
 * site the document belongs to, and when that is not the domain the review is opened on,
 * the page in a frame cannot be reached by the review. The page in a frame tells the
 * review about its scrolls, and scrolls when the review tells it to - that script is what
 * it talks with.
 *
 * The middleware injects the script into the response of the frontend preview of
 * Neos.Neos, so the review does not depend on the sites carrying a script of it in their
 * backend rendering: the script is transported with the pages, whatever site they belong
 * to.
 */
#[Flow\Scope('singleton')]
final class PreviewScriptMiddleware implements MiddlewareInterface
{
    private const SCRIPT_URI = 'resource://Shel.Neos.WorkspaceModule/Public/Scripts/PreviewSync.js';

    /**
     * The uri of the script of the page, so that it is never injected twice into a page.
     */
    private ?string $scriptUri = null;

    public function __construct(
        private readonly ResourceManager $resourceManager,
    ) {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $next): ResponseInterface
    {
        $response = $next->handle($request);

        if (!$this->isFrontendPreview($request) || !$this->isHtml($response)) {
            return $response;
        }

        $html = $response->getBody()->getContents();
        $response->getBody()->rewind();
        if ($html === '' || \str_contains($html, 'data-workspace-review-preview')) {
            return $response;
        }

        return $response
            ->withBody(\GuzzleHttp\Psr7\Utils::streamFor(\str_replace(
                '</body>',
                '<script src="' . $this->scriptUri() . '" data-workspace-review-preview defer="defer"></script></body>',
                $html,
            )))
            ->withoutHeader('Content-Length');
    }

    /**
     * Whether the request is the frontend preview of a document: the route the frames of
     * the preview are loaded with, for the variant of the review and for the published one.
     */
    private function isFrontendPreview(ServerRequestInterface $request): bool
    {
        $routingResults = $request->getAttribute(ServerRequestAttributes::ROUTING_RESULTS);
        if (!\is_array($routingResults)) {
            return false;
        }

        return ($routingResults['@package'] ?? '') === 'Neos.Neos'
            && ($routingResults['@controller'] ?? '') === 'Frontend\Node'
            && ($routingResults['@action'] ?? '') === 'preview';
    }

    /**
     * Whether the response carries a page as its body.
     */
    private function isHtml(ResponseInterface $response): bool
    {
        return \str_contains($response->getHeaderLine('Content-Type'), 'text/html');
    }

    /**
     * The path of the script of the preview, with the moment it was changed last, so that
     * an editor does not scroll the pages of the preview with a stale one.
     */
    private function scriptUri(): string
    {
        if ($this->scriptUri !== null) {
            return $this->scriptUri;
        }
        $this->scriptUri = $this->resourceManager->getPublicPackageResourceUriByPath(self::SCRIPT_URI);

        return $this->scriptUri;
    }
}
