<?php

declare(strict_types=1);

/**
 * This file is part of the Shel.Neos.WorkspaceModule package.
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

#[Flow\Aspect]
class SharedWorkspaceAccessAspect
{
    #[Flow\Inject]
    protected WorkspaceDetailsRepository $workspaceDetailsRepository;

    #[Flow\Inject]
    protected PersistenceManagerInterface $persistenceManager;

    /**
     * Adjust the check whether the current user may read a shared workspace
     */
    #[Flow\Around("method(Neos\Neos\Domain\Service\UserService->currentUserCanReadWorkspace())")]
    public function currentUserCanManageSharedWorkspace(JoinPointInterface $joinPoint): bool
    {
        return $this->userCanManageSharedWorkspace($joinPoint);
    }

    /**
     * Adjust the check whether the current user may publish to a shared workspace
     */
    #[Flow\Around("method(Neos\Neos\Domain\Service\UserService->currentUserCanPublishToWorkspace())")]
    public function currentUserCanManageSharedWorkspaceForPublishing(JoinPointInterface $joinPoint): bool
    {
        return $this->userCanManageSharedWorkspace($joinPoint);
    }

    /**
     * Whether the current user may work with the workspace of the advised method.
     *
     * A workspace that is shared with the user may be worked with, even when the privilege
     * of the core does not allow it.
     */
    private function userCanManageSharedWorkspace(JoinPointInterface $joinPoint): bool
    {
        $result = $joinPoint->getAdviceChain()->proceed($joinPoint);

        if ($result) {
            return true;
        }

        /** @var UserService $userService */
        $userService = $joinPoint->getProxy();
        /** @var User|null $currentUser */
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
     * @return array<string, array{name: string, title: string, description: string|null, readonly: bool}>
     * @throws PropertyNotAccessibleException
     */
    #[Flow\Around("method(Neos\Neos\Ui\ContentRepository\Service\WorkspaceService->getAllowedTargetWorkspaces())")]
    public function getAllowedTargetWorkspacesIncludingSharedOnes(JoinPointInterface $joinPoint): array
    {
        /** @var WorkspaceService $workspaceService */
        $workspaceService = $joinPoint->getProxy();
        /** @var UserService $userService */
        $userService = ObjectAccess::getProperty($workspaceService, 'domainUserService', true);
        $workspaceRepository = ObjectAccess::getProperty($workspaceService, 'workspaceRepository', true);
        /** @var User|null $user */
        $user = $userService->getCurrentUser();
        $sharedWorkspaceNames = $user ? $this->workspaceDetailsRepository->findAllowedWorkspaceNamesForUser($user) : [];

        $workspacesArray = [];
        /** @var Workspace $workspace */
        foreach ($workspaceRepository->findAll() as $workspace) {
            // Skip personal workspaces and private workspace not shared with the current user
            if (!in_array($workspace->getName(), $sharedWorkspaceNames)
                && (
                    ($workspace->getOwner() !== null && $workspace->getOwner() !== $user)
                    || $workspace->isPersonalWorkspace()
                )
            ) {
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
