<?php
declare(strict_types=1);

namespace Shel\Neos\WorkspaceModule\Controller;

use Neos\ContentRepository\Domain\Model\Workspace;
use Neos\Flow\Mvc\View\JsonView;
use Neos\Neos\Utility\User as UserUtility;

class WorkspacesController extends \Neos\Neos\Controller\Module\Management\WorkspacesController
{
    protected $viewFormatToObjectNameMap = [
        //'html' => FusionView::class,
        'json' => JsonView::class,
    ];

    public function indexAction(): void
    {
        $currentAccount = $this->securityContext->getAccount();

        /** @var Workspace $userWorkspace */
        $userWorkspace = $this->workspaceRepository->findOneByName(UserUtility::getPersonalWorkspaceNameForUsername($currentAccount->getAccountIdentifier()));

        $workspaceData = array_reduce($this->workspaceRepository->findAll()->toArray(), function (array $carry, Workspace $workspace) {
            if ($workspace->isInternalWorkspace() || $this->userService->currentUserCanManageWorkspace($workspace)) {
                $carry[$workspace->getName()] = $this->getWorkspaceInfo($workspace);
            }
            return $carry;
        }, [$userWorkspace->getName() => $this->getWorkspaceInfo($userWorkspace)]);

        $this->view->assign('userWorkspace', $userWorkspace);
        $this->view->assign('workspaces', $workspaceData);
    }

    public function getChangesAction(): void
    {
        $currentAccount = $this->securityContext->getAccount();

        /** @var Workspace $userWorkspace */
        $userWorkspace = $this->workspaceRepository->findOneByName(UserUtility::getPersonalWorkspaceNameForUsername($currentAccount->getAccountIdentifier()));

        $workspaces = $this->workspaceRepository->findAll()->toArray();

        $changesByWorkspace = array_reduce($workspaces, function ($carry, Workspace $workspace) {
            if ($this->userService->currentUserCanManageWorkspace($workspace)) {
                $carry[$workspace->getName()]= $this->computeChangesCount($workspace);
            }
            return $carry;
        }, [
            $userWorkspace->getName() => $this->computeChangesCount($userWorkspace),
        ]);

        $this->view->assign('value', ['changesByWorkspace' => $changesByWorkspace]);
    }

    protected function getWorkspaceInfo(Workspace $workspace): array
    {
        return [
            'name' => $workspace->getName(),
            'title' => $workspace->getTitle(),
            'description' => $workspace->getDescription(),
            'owner' => $workspace->getOwner() ? $workspace->getOwner()->getLabel() : null,
            'baseWorkspace' => $workspace->getBaseWorkspace() ? [
                'name' => $workspace->getBaseWorkspace()->getName(),
                'title' => $workspace->getBaseWorkspace()->getTitle(),
            ] : null,
            'changesCounts' => null, // Will be retrieved separately by the UI
            'isInternal' => $workspace->isInternalWorkspace(),
            'canPublish' => $this->userService->currentUserCanPublishToWorkspace($workspace),
            'canManage' => $this->userService->currentUserCanManageWorkspace($workspace),
            'dependentWorkspacesCount' => count($this->workspaceRepository->findByBaseWorkspace($workspace)),
            'creator' => null, // TODO: implement
            'lastModifiedDate' => null, // TODO: implement
            'lastModifiedBy' => null, // TODO: implement
        ];
    }
}
