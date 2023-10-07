<?php

declare(strict_types=1);

namespace Shel\Neos\WorkspaceModule;

/**
 * This file is part of the Shel.Neos.WorkspaceModule package.
 *
 * (c) 2022 Sebastian Helzle
 *
 * This package is Open Source Software. For the full copyright and license
 * information, please view the LICENSE file which was distributed with this
 * source code.
 */

use Neos\Flow\Annotations as Flow;
use Neos\Cache\CacheAwareInterface;
use Neos\Flow\Persistence\PersistenceManagerInterface;
use Neos\Neos\Domain\Service\UserService;
//use Shel\Neos\WorkspaceModule\Domain\Repository\WorkspaceDetailsRepository;

#[Flow\Scope('singleton')]
class WorkspaceDetailsContext implements CacheAwareInterface
{
    #[Flow\Inject]
    protected UserService $userDomainService;

    #[Flow\Inject]
    protected PersistenceManagerInterface $persistenceManager;

//    #[Flow\Inject]
//    protected WorkspaceDetailsRepository $workspaceDetailsRepository;

    protected string $cacheEntryIdentifier;

    /**
     * @return string[]
     */
    public function getSharedWorkspaces(): array
    {
        return [];

//        if (!$this->userDomainService) {
//            return [];
//        }
//
//        $user = $this->userDomainService->getCurrentUser();
//
//        if (!$user) {
//            return [];
//        }
//
//        return $this->workspaceDetailsRepository->findAllowedWorkspaceNamesForUser($user);
    }

    public function getCacheEntryIdentifier(): string
    {
        if ($this->cacheEntryIdentifier === null) {
            $this->cacheEntryIdentifier = implode('_', $this->getSharedWorkspaces());
        }
        return $this->cacheEntryIdentifier;
    }
}
