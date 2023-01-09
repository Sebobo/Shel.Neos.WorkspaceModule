<?php

declare(strict_types=1);

/**
 * This file is part of the Shel.Neos.WorkspaceModule package.
 *
 * (c) 2022 Sebastian Helzle
 *
 * This package is Open Source Software. For the full copyright and license
 * information, please view the LICENSE file which was distributed with this
 * source code.
 */

namespace Shel\Neos\WorkspaceModule\Aspect;

use Neos\ContentRepository\Domain\Model\Workspace;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\Aop\JoinPointInterface;
use Neos\Flow\Persistence\PersistenceManagerInterface;
use Neos\Neos\Domain\Model\User;
use Neos\Neos\Domain\Service\UserService;
use Neos\Neos\Ui\ContentRepository\Service\WorkspaceService;
use Neos\Utility\Exception\PropertyNotAccessibleException;
use Neos\Utility\ObjectAccess;
use Shel\Neos\WorkspaceModule\Domain\Repository\WorkspaceDetailsRepository;

/**
 * @Flow\Aspect
 */
class SharedWorkspaceAccessAspect
{
    /**
     * @Flow\Inject
     * @var WorkspaceDetailsRepository
     */
    protected $workspaceDetailsRepository;

    /**
     * @Flow\Inject
     * @var PersistenceManagerInterface
     */
    protected $persistenceManager;

    /**
     * Adjust workspace permission check for shared workspaces
     *
     * @Flow\Around("method(Neos\Neos\Domain\Service\UserService->currentUserCanReadWorkspace())")
     * @Flow\Around("method(Neos\Neos\Domain\Service\UserService->currentUserCanPublishToWorkspace())")
     */
    public function currentUserCanManageSharedWorkspace(JoinPointInterface $joinPoint): bool
    {
        $result = $joinPoint->getAdviceChain()->proceed($joinPoint);

        if ($result) {
            return true;
        }

        /** @var UserService $userService */
        $userService = $joinPoint->getProxy();
        $currentUser = $userService->getCurrentUser();
        $workspace = $joinPoint->getMethodArgument('workspace');

        return $currentUser
            && $workspace->isPrivateWorkspace()
            && $workspace->getOwner() !== $currentUser
            && $this->isWorkspaceSharedWithUser($workspace, $currentUser);
    }

    /**
     * Adjust workspace permission check for shared workspaces in the Neos UI
     *
     * @Flow\Around("method(Neos\Neos\Ui\ContentRepository\Service\WorkspaceService->getAllowedTargetWorkspaces())")
     * @return Workspace[]
     * @throws PropertyNotAccessibleException
     */
    public function getAllowedTargetWorkspacesIncludingSharedOnes(JoinPointInterface $joinPoint): array
    {
        /** @var WorkspaceService $workspaceService */
        $workspaceService = $joinPoint->getProxy();
        /** @var UserService $userService */
        $userService = ObjectAccess::getProperty($workspaceService, 'domainUserService', true);
        $workspaceRepository = ObjectAccess::getProperty($workspaceService, 'workspaceRepository', true);
        $user = $userService->getCurrentUser();

        $workspacesArray = [];
        /** @var Workspace $workspace */
        foreach ($workspaceRepository->findAll() as $workspace) {
            // Skip personal workspaces and private workspace not shared with the current user
            if ((($workspace->getOwner() !== null && $workspace->getOwner() !== $user) || $workspace->isPersonalWorkspace())
                && !$this->isWorkspaceSharedWithUser($workspace, $user)) {
                continue;
            }

            $workspaceArray = [
                'name' => $workspace->getName(),
                'title' => $workspace->getTitle(),
                'description' => $workspace->getDescription(),
                'readonly' => !$userService->currentUserCanPublishToWorkspace($workspace)
            ];
            $workspacesArray[$workspace->getName()] = $workspaceArray;
        }

        return $workspacesArray;
    }

    /**
     * Checks whether the given workspace is shared with the given user.
     */
    protected function isWorkspaceSharedWithUser(Workspace $workspace, User $user): bool
    {
        $workspaceDetails = $this->workspaceDetailsRepository->findOneByWorkspace($workspace);
        if (!$workspaceDetails) {
            return false;
        }
        $allowedUsers = array_map(fn($user) => $this->persistenceManager->getIdentifierByObject($user),
            $workspaceDetails->getAcl());
        return in_array($this->persistenceManager->getIdentifierByObject($user), $allowedUsers, true);
    }
}
