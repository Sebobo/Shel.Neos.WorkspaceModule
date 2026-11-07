<?php

declare(strict_types=1);

namespace Shel\Neos\WorkspaceModule;

/**
 * This file is part of the Shel.Neos.WorkspaceModule package.
 *
 * This package is Open Source Software. For the full copyright and license
 * information, please view the LICENSE file which was distributed with this
 * source code.
 */

use Neos\Flow\Annotations as Flow;
use Neos\Cache\CacheAwareInterface;
use Neos\Flow\Persistence\PersistenceManagerInterface;
use Neos\Neos\Domain\Model\User;
use Neos\Neos\Domain\Service\UserService;
use Shel\Neos\WorkspaceModule\Domain\Repository\WorkspaceDetailsRepository;

#[Flow\Scope('singleton')]
class WorkspaceDetailsContext implements CacheAwareInterface
{
    #[Flow\Inject]
    protected UserService $userDomainService;

    #[Flow\Inject]
    protected PersistenceManagerInterface $persistenceManager;

    #[Flow\Inject]
    protected WorkspaceDetailsRepository $workspaceDetailsRepository;

    protected ?string $cacheEntryIdentifier = null;

    /**
     * @return list<string>
     */
    public function getSharedWorkspaces(): array
    {
        /** @var User|null $user */
        $user = $this->userDomainService->getCurrentUser();

        if (!$user) {
            return [];
        }

        return $this->workspaceDetailsRepository->findAllowedWorkspaceNamesForUser($user);
    }

    public function getCacheEntryIdentifier(): string
    {
        if ($this->cacheEntryIdentifier === null) {
            $this->cacheEntryIdentifier = implode('_', $this->getSharedWorkspaces());
        }
        return $this->cacheEntryIdentifier;
    }
}
