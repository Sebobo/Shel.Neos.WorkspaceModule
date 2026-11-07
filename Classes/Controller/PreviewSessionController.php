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

use Neos\Flow\Annotations as Flow;
use Neos\Flow\Http\Cookie;
use Neos\Flow\Mvc\Controller\ActionController;
use Neos\Flow\Session\SessionInterface;
use Neos\Flow\Session\SessionManagerInterface;
use Neos\Neos\Exception as NeosException;
use Shel\Neos\WorkspaceModule\Service\SessionHandoverService;

/**
 * Hands the session of the review over to the domain of a site.
 *
 * The preview of a document is loaded from the domain of its site, and the session of the
 * backend is only valid on the host it was created on. The controller takes a token that
 * names the session of the review, resumes that session and gives this host the cookie of
 * it - after that, the frames of the preview are authenticated.
 *
 * The controller is reached without a user: the request carries the token instead.
 */
final class PreviewSessionController extends ActionController
{
    /**
     * @Flow\InjectConfiguration(package="Neos.Flow", path="session.name")
     * @var string
     */
    protected string $sessionName;

    public function __construct(
        private readonly SessionHandoverService $sessionHandoverService,
        private readonly SessionManagerInterface $sessionManager,
    ) {
    }

    /**
     * Resumes the session a token names and gives this host its cookie.
     *
     * The page is loaded into a hidden frame of the review before the frames of the preview
     * are set: it carries the cookie that the requests of the preview are sent with
     * afterwards.
     */
    public function startSessionAction(string $token): string
    {
        $sessionId = $this->sessionHandoverService->sessionFor($token);
        if ($sessionId === null) {
            throw new NeosException('The session of the review could not be handed over: the token is not valid.', 1763180622);
        }

        $session = $this->sessionManager->getSession($sessionId);
        if (!$session instanceof SessionInterface) {
            throw new NeosException('The session of the review could not be handed over: it is gone.', 1763180623);
        }

        if ($session->canBeResumed()) {
            $session->resume();
        }
        if (!$session->isStarted()) {
            throw new NeosException('The session of the review could not be handed over: it has expired.', 1763180624);
        }

        // The browser sends this cookie with the requests of the preview afterwards.
        $this->response->setCookie(new Cookie($this->sessionName, $session->getId()));

        return '<!DOCTYPE html><html lang="en"><body></body></html>';
    }
}
