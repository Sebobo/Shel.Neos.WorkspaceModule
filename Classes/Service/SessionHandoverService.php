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

use Neos\Flow\Cache\CacheManager;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\Session\SessionManagerInterface;
use Neos\Flow\Utility\Algorithms;

/**
 * The session of the review, handed over to the domain of a site.
 *
 * The preview of a document of the review is loaded from the domain of the site it belongs
 * to. The session of the backend is only valid on the host it was created on: a preview
 * that is loaded from the domain of another site sees no user, and is answered with the
 * login page.
 *
 * The service hands the session over the way the backend does it when it switches to
 * another site: a token names the session, and the controller on the other domain looks
 * the session up with the token and gives that host the cookie of it. After the handover,
 * the frames of the preview are authenticated - and the review itself stays where it is.
 */
#[Flow\Scope('singleton')]
final class SessionHandoverService
{
    public function __construct(
        private readonly SessionManagerInterface $sessionManager,
        private readonly CacheManager $cacheManager,
    ) {
    }

    /**
     * Creates a token that names the session of the review, and returns the url of the
     * handover: a request to the domain of the site the preview is loaded from that starts
     * with the token.
     *
     * Null when the review has no session - and then there is nothing to hand over.
     */
    public function handoverUrlFor(string $targetBaseUri): ?string
    {
        $session = $this->sessionManager->getCurrentSession();
        if (!$session->isStarted()) {
            return null;
        }

        $token = Algorithms::generateRandomToken(32);
        $this->cacheManager->getCache('Shel_Neos_WorkspaceModule_SessionHandover')->set($token, $session->getId());

        return sprintf(
            '%s/neos/workspace-review/preview-session?token=%s',
            $targetBaseUri,
            $token
        );
    }

    /**
     * The session a token names, or null when no token names it.
     *
     * A token acts for a few minutes, and within that time it can be used again - the
     * handover only ever re-establishes the session it was created for.
     */
    public function sessionFor(string $token): ?string
    {
        $sessionId = $this->cacheManager->getCache('Shel_Neos_WorkspaceModule_SessionHandover')->get($token);

        return is_string($sessionId) && $sessionId !== '' ? $sessionId : null;
    }
}
