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
use Neos\Flow\Annotations as Flow;
use Neos\Flow\Core\Bootstrap;
use Neos\Flow\Http\HttpRequestHandlerInterface;
use Neos\Neos\Domain\Model\Domain;
use Neos\Neos\Domain\Model\Site;
use Neos\Neos\Domain\Repository\SiteRepository;
use Psr\Http\Message\UriInterface;

/**
 * The domain of the site a document belongs to.
 *
 * A page is only rendered correctly on the domain of its own site: the frontend finds
 * its site by the host of the request, so a page that is loaded from the domain of
 * another site shows the page of that other site - or no page at all. The preview of a
 * document is therefore loaded from the domain of the site the document belongs to.
 *
 * The session of the backend is only valid on the host it was created on, so the review
 * has to be opened on the domain of the site whose documents are previewed.
 */
#[Flow\Scope('singleton')]
final class SiteDomainService
{
    public function __construct(
        private readonly SiteRepository $siteRepository,
        private readonly NodeTreeService $nodeTreeService,
        private readonly Bootstrap $bootstrap,
    ) {
    }

    /**
     * The domain the site of the document is reached on: the primary domain of the site,
     * or the first domain of the site that is active.
     *
     * Null when the site has no domain it could be reached on.
     */
    public function domainOf(NodeInterface $document): ?Domain
    {
        $site = $this->siteRepository->findOneByNodeName($this->nodeTreeService->siteNodeNameOf($document));
        if (!$site instanceof Site) {
            return null;
        }
        $domain = $site->getFirstActiveDomain();
        if (!$domain instanceof Domain || $domain->getHostname() === '') {
            return null;
        }

        // The primary domain of the site is the one it is reached on - unless it is not
        // active, and then the first active domain is the one.
        $primaryDomain = $site->getPrimaryDomain();
        if ($primaryDomain->getActive()) {
            $domain = $primaryDomain;
        }

        return $domain;
    }

    /**
     * The base URI of a domain.
     *
     * The scheme of the domain is used when the domain has one - a site that is only
     * reachable over https is not reached over http just because the review is. Without a
     * scheme the scheme of the request is used.
     */
    public function baseUriOf(Domain $domain): string
    {
        $port = $domain->getPort();

        return sprintf(
            '%s://%s%s',
            $domain->getScheme() ?: $this->currentScheme(),
            $domain->getHostname(),
            $port && !in_array($port, [80, 443], true) ? ':' . $port : ''
        );
    }

    /**
     * Whether the domain is the host the review is opened on.
     *
     * The session of the backend is only valid on the host it was created on, and the
     * scroll positions of the two previews can only be read when they are on the same
     * host as the review.
     */
    public function isCurrentHost(Domain $domain): bool
    {
        $host = $this->currentHost();

        return $host !== null && strtolower($host) === strtolower($domain->getHostname());
    }

    /**
     * The host of the request the review is opened with.
     */
    private function currentHost(): ?string
    {
        return $this->currentUri()?->getHost();
    }

    /**
     * The scheme of the request the review is opened with.
     */
    private function currentScheme(): string
    {
        return $this->currentUri()?->getScheme() ?: 'https';
    }

    /**
     * The URI of the request the review is opened with.
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
