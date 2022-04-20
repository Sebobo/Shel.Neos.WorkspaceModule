<?php
declare(strict_types=1);

namespace Shel\Neos\WorkspaceModule\Controller;

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
use Neos\ContentRepository\Domain\Model\Workspace;
use Neos\Error\Messages\Message;
use Neos\Flow\Mvc\View\JsonView;
use Neos\Flow\Persistence\Exception\IllegalObjectTypeException;
use Neos\Neos\Utility\User as UserUtility;
use Shel\Neos\WorkspaceModule\Domain\Repository\WorkspaceDetailsRepository;

class WorkspacesController extends \Neos\Neos\Controller\Module\Management\WorkspacesController
{
    protected $viewFormatToObjectNameMap = [
        //'html' => FusionView::class,
        'json' => JsonView::class,
    ];

    /**
     * @Flow\Inject
     * @var WorkspaceDetailsRepository
     */
    protected $workspaceDetailsRepository;

    /**
     * @Flow\InjectConfiguration(path="staleTime")
     * @var int
     */
    protected $staleTime;

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
                $carry[$workspace->getName()] = $this->computeChangesCount($workspace);
            }
            return $carry;
        }, [
            $userWorkspace->getName() => $this->computeChangesCount($userWorkspace),
        ]);

        $this->view->assign('value', ['changesByWorkspace' => $changesByWorkspace]);
    }

    /**
     * Delete a workspace and all contained unpublished changes.
     * Descendent workspaces will be rebased on the live workspace.
     *
     * @throws IllegalObjectTypeException
     * @Flow\SkipCsrfProtection
     */
    public function forceDeleteAction(Workspace $workspace): void
    {
        $success = false;
        if ($workspace->isPersonalWorkspace()) {
            $this->addFlashMessage('The workspace ' . $workspace->getTitle() . ' is personal and cannot be deleted', '',
                Message::SEVERITY_ERROR);
        } else {
            $liveWorkspace = $this->workspaceRepository->findByIdentifier('live');

            // Fetch and delete dependent workspaces for target workspace
            $dependentWorkspaces = $this->workspaceRepository->findByBaseWorkspace($workspace);
            /** @var Workspace $dependentWorkspace */
            foreach ($dependentWorkspaces as $dependentWorkspace) {
                $dependentWorkspace->setBaseWorkspace($liveWorkspace);
                $this->workspaceRepository->update($dependentWorkspace);
                $this->addFlashMessage('Workspace "' . $dependentWorkspace->getTitle() . '" has been rebased to "live" as it depends on workspace "' . $workspace->getTitle() . '"',
                    '', Message::SEVERITY_WARNING);
            }

            // Fetch and discard unpublished nodes in target workspace
            $unpublishedNodes = [];
            try {
                $unpublishedNodes = $this->publishingService->getUnpublishedNodes($workspace);
            } catch (\Exception $exception) {
            }

            if ($unpublishedNodes) {
                $this->publishingService->discardNodes($unpublishedNodes);
            }

            $this->workspaceRepository->remove($workspace);
            $this->addFlashMessage('The workspace "' . $workspace->getTitle() . '" has been removed, ' . count($unpublishedNodes) . ' changes have been discarded and ' . count($dependentWorkspaces) . ' dependent workspaces have been rebased',
                '', Message::SEVERITY_WARNING);
            $success = true;
        }

        $this->view->assign('value', [
            'success' => $success,
            'messages' => $this->controllerContext->getFlashMessageContainer()->getMessagesAndFlush(),
        ]);
    }

    protected function getWorkspaceInfo(Workspace $workspace): array
    {
        $workspaceActivity = $this->workspaceDetailsRepository->findOneByWorkspaceName($workspace->getName());

        $creator = $lastChangedDate = $lastChangedBy = $lastChangedTimestamp = $isStale = null;
        if ($workspaceActivity) {
            $creator = $workspaceActivity->getCreator();
            $isStale = $workspaceActivity->getLastChangedDate() && $workspaceActivity->getLastChangedDate()->getTimestamp() < time() - $this->staleTime;
            $lastChangedBy = $workspaceActivity->getLastChangedBy();
            $lastChangedDate = $workspaceActivity->getLastChangedDate() ? $workspaceActivity->getLastChangedDate()->format('c') : null;
            $lastChangedTimestamp = $workspaceActivity->getLastChangedDate() ? $workspaceActivity->getLastChangedDate()->getTimestamp() : null;
        }

        return [
            'name' => $workspace->getName(),
            'title' => $workspace->getTitle(),
            'description' => $workspace->getDescription(),
            'owner' => $workspace->getOwner() ? $workspace->getOwner()->getLabel() : null,
            'baseWorkspace' => $workspace->getBaseWorkspace() ? [
                'name' => $workspace->getBaseWorkspace()->getName(),
                'title' => $workspace->getBaseWorkspace()->getTitle(),
            ] : null,
            'nodeCount' => $workspace->getNodeCount(),
            'changesCounts' => null, // Will be retrieved separately by the UI
            'isInternal' => $workspace->isInternalWorkspace(),
            'isStale' => $isStale,
            'canPublish' => $this->userService->currentUserCanPublishToWorkspace($workspace),
            'canManage' => $this->userService->currentUserCanManageWorkspace($workspace),
            'dependentWorkspacesCount' => count($this->workspaceRepository->findByBaseWorkspace($workspace)),
            'creator' => $creator,
            'lastChangedDate' => $lastChangedDate,
            'lastChangedTimestamp' => $lastChangedTimestamp,
            'lastChangedBy' => $lastChangedBy,
        ];
    }
}
