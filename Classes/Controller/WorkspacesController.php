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

use Neos\ContentRepository\Domain\Model\NodeInterface;
use Neos\ContentRepository\TypeConverter\NodeConverter;
use Neos\ContentRepository\Utility;
use Neos\Flow\Annotations as Flow;
use Neos\ContentRepository\Domain\Model\Workspace;
use Neos\Error\Messages\Message;
use Neos\Flow\Mvc\View\JsonView;
use Neos\Flow\Persistence\Exception\IllegalObjectTypeException;
use Neos\Flow\Property\Exception as PropertyException;
use Neos\Flow\Property\TypeConverter\PersistentObjectConverter;
use Neos\Flow\Security\Authorization\PrivilegeManagerInterface;
use Neos\Flow\Security\Exception as SecurityException;
use Neos\Neos\Utility\User as UserUtility;
use Shel\Neos\WorkspaceModule\Domain\Model\WorkspaceDetails;
use Shel\Neos\WorkspaceModule\Domain\Repository\WorkspaceDetailsRepository;

class WorkspacesController extends \Neos\Neos\Controller\Module\Management\WorkspacesController
{
    protected $viewFormatToObjectNameMap = [
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

    /**
     * @Flow\Inject
     * @var PrivilegeManagerInterface
     */
    protected $privilegeManager;

    public function indexAction(): void
    {
        $currentAccount = $this->securityContext->getAccount();

        /** @var Workspace $userWorkspace */
        $userWorkspace = $this->workspaceRepository->findOneByName(UserUtility::getPersonalWorkspaceNameForUsername($currentAccount->getAccountIdentifier()));

        $workspaceData = array_reduce($this->workspaceRepository->findAll()->toArray(),
            function (array $carry, Workspace $workspace) {
                if ($workspace->isInternalWorkspace() || $this->userService->currentUserCanManageWorkspace($workspace)) {
                    $carry[$workspace->getName()] = $this->getWorkspaceInfo($workspace);
                }
                return $carry;
            }, [$userWorkspace->getName() => $this->getWorkspaceInfo($userWorkspace)]);

        $this->view->assignMultiple([
            'userWorkspace' => $userWorkspace,
            'baseWorkspaceOptions' => $this->prepareBaseWorkspaceOptions(),
            'userCanManageInternalWorkspaces' => $this->privilegeManager->isPrivilegeTargetGranted('Neos.Neos:Backend.Module.Management.Workspaces.ManageInternalWorkspaces'),
            'ownerOptions' => $this->prepareOwnerOptions(),
            'workspaces' => $workspaceData,
            'csrfToken' => $this->securityContext->getCsrfProtectionToken(),
            'validation' => $this->settings['validation'],
        ]);
    }

    public function getChangesAction(): void
    {
        $currentAccount = $this->securityContext->getAccount();

        /** @var Workspace $userWorkspace */
        $userWorkspace = $this->workspaceRepository->findOneByName(UserUtility::getPersonalWorkspaceNameForUsername($currentAccount->getAccountIdentifier()));

        $workspaces = $this->workspaceRepository->findAll()->toArray();

        $changesByWorkspace = array_reduce($workspaces, function ($carry, Workspace $workspace) {
            if ($workspace->isInternalWorkspace() || $this->userService->currentUserCanManageWorkspace($workspace)) {
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
    public function deleteAction(Workspace $workspace): void
    {
        $success = false;
        /** @var Workspace[] $rebasedWorkspaces */
        $rebasedWorkspaces = [];

        if ($workspace->isPersonalWorkspace()) {
            $this->addFlashMessage('The workspace ' . $workspace->getTitle() . ' is personal and cannot be deleted', '',
                Message::SEVERITY_ERROR);
        } else {
            $liveWorkspace = $this->workspaceRepository->findByIdentifier('live');

            // Fetch and delete dependent workspaces for target workspace
            /** @var Workspace[] $dependentWorkspaces */
            $dependentWorkspaces = $this->workspaceRepository->findByBaseWorkspace($workspace);
            foreach ($dependentWorkspaces as $dependentWorkspace) {
                $dependentWorkspace->setBaseWorkspace($liveWorkspace);
                $this->workspaceRepository->update($dependentWorkspace);
                $this->addFlashMessage('Workspace "' . $dependentWorkspace->getTitle() . '" has been rebased to "live" as it depends on workspace "' . $workspace->getTitle() . '"',
                    '', Message::SEVERITY_WARNING);
                $rebasedWorkspaces[] = $dependentWorkspace;
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
            $this->addFlashMessage('The workspace "' . $workspace->getTitle() . '" has been removed, ' . count($unpublishedNodes) . ' changes have been discarded and ' . count($dependentWorkspaces) . ' dependent workspaces have been rebased');
            $success = true;
        }

        $this->view->assign('value', [
            'success' => $success,
            'messages' => $this->controllerContext->getFlashMessageContainer()->getMessagesAndFlush(),
            'rebasedWorkspaces' => array_map(static function ($workspace) {
                return $workspace->getName();
            }, $rebasedWorkspaces),
        ]);
    }

    protected function getWorkspaceInfo(Workspace $workspace): array
    {
        $workspaceActivity = $this->workspaceDetailsRepository->findOneByWorkspaceName($workspace->getName());

        $creator = $lastChangedDate = $lastChangedBy = $lastChangedTimestamp = $isStale = null;
        if ($workspaceActivity) {
            if ($workspaceActivity->getCreator()) {
                $creatorUser = $this->userService->getUser($workspaceActivity->getCreator());
                $creator = $creatorUser ? $creatorUser->getLabel() : $workspaceActivity->getCreator();
            }
            $isStale = $workspaceActivity->getLastChangedDate() && $workspaceActivity->getLastChangedDate()->getTimestamp() < time() - $this->staleTime;

            if ($workspaceActivity->getLastChangedBy()) {
                $lastChangedByUser = $this->userService->getUser($workspaceActivity->getLastChangedBy());
                $lastChangedBy = $lastChangedByUser ? $lastChangedByUser->getLabel() : $workspaceActivity->getLastChangedBy();
            }
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
            'changesCounts' => null, // Will be retrieved async by the UI to speed up module loading time
            'isPersonal' => $workspace->isPersonalWorkspace(),
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

    /**
     * Create action from Neos WorkspacesController but creates a new WorkspaceDetails object after workspace creation
     *
     * @Flow\Validate(argumentName="title", type="\Neos\Flow\Validation\Validator\NotEmptyValidator")
     * @param string $title Human friendly title of the workspace, for example "Christmas Campaign"
     * @param Workspace $baseWorkspace Workspace the new workspace should be based on
     * @param string $visibility Visibility of the new workspace, must be either "internal" or "shared"
     * @param string $description A description explaining the purpose of the new workspace
     */
    public function createAction($title, Workspace $baseWorkspace, $visibility, $description = ''): void
    {
        $success = true;

        $workspace = $this->workspaceRepository->findOneByTitle($title);
        if ($workspace instanceof Workspace) {
            $this->addFlashMessage($this->translator->translateById('workspaces.workspaceWithThisTitleAlreadyExists',
                [], null, null, 'Modules', 'Neos.Neos'), '', Message::SEVERITY_WARNING);
            $success = false;
        } else {
            $workspaceName = Utility::renderValidNodeName($title) . '-' . substr(base_convert(microtime(false), 10, 36),
                    -5, 5);
            while ($this->workspaceRepository->findOneByName($workspaceName) instanceof Workspace) {
                $workspaceName = Utility::renderValidNodeName($title) . '-' . substr(base_convert(microtime(false), 10,
                        36), -5, 5);
            }

            if ($visibility === 'private') {
                $owner = $this->userService->getCurrentUser();
            } else {
                $owner = null;
            }

            $workspace = new Workspace($workspaceName, $baseWorkspace, $owner);
            $workspace->setTitle($title);
            $workspace->setDescription($description);

            $this->workspaceRepository->add($workspace);

            // Create a new WorkspaceDetails object
            $workspaceDetails = new WorkspaceDetails($workspace->getName(),
                $this->securityContext->getAccount()->getAccountIdentifier());
            $this->workspaceDetailsRepository->add($workspaceDetails);

            // Persist the workspace and related data or the generated workspace info will be incomplete
            $this->persistenceManager->persistAll();

            $this->addFlashMessage('The workspace "' . $workspaceName . '" has been created');
        }

        $this->view->assign('value', [
            'success' => $success,
            'messages' => $this->controllerContext->getFlashMessageContainer()->getMessagesAndFlush(),
            'workspace' => $this->getWorkspaceInfo($workspace),
            // Include a new list of base workspace options which might contain the new workspace depending on its visibility
            'baseWorkspaceOptions' => $this->prepareBaseWorkspaceOptions(),
        ]);
    }

    /**
     * @inheritDoc
     */
    protected function prepareBaseWorkspaceOptions(Workspace $excludedWorkspace = null): array
    {
        $options = parent::prepareBaseWorkspaceOptions($excludedWorkspace);
        asort($options, SORT_FLAG_CASE | SORT_NATURAL);
        return $options;
    }

    /**
     * @inheritDoc
     */
    protected function prepareOwnerOptions(): array
    {
        $options = parent::prepareOwnerOptions();
        asort($options, SORT_FLAG_CASE | SORT_NATURAL);
        return $options;
    }

    /**
     * @inheritDoc
     */
    protected function initializeUpdateAction(): void
    {
        parent::initializeUpdateAction();
        $workspaceConfiguration = $this->arguments['workspace']->getPropertyMappingConfiguration();
        $workspaceConfiguration->allowAllProperties();
        $workspaceConfiguration->setTypeConverterOption(PersistentObjectConverter::class,
            PersistentObjectConverter::CONFIGURATION_MODIFICATION_ALLOWED, true);
    }

    /**
     * @inheritDoc
     */
    public function updateAction(Workspace $workspace): void
    {
        if ($workspace->getTitle() === '') {
            $workspace->setTitle($workspace->getName());
        }

        $this->workspaceRepository->update($workspace);
        $this->view->assign('value', $this->getWorkspaceInfo($workspace));
    }

    /**
     * @inheritDoc
     *
     * @param array<NodeInterface> $nodes
     * @param string $action
     * @param Workspace|null $selectedWorkspace
     * @throws \Exception|PropertyException|SecurityException
     */
    public function publishOrDiscardNodesAction(array $nodes, $action, Workspace $selectedWorkspace = null): void
    {
        $propertyMappingConfiguration = $this->propertyMapper->buildPropertyMappingConfiguration();
        $propertyMappingConfiguration->setTypeConverterOption(NodeConverter::class,
            NodeConverter::REMOVED_CONTENT_SHOWN, true);
        foreach ($nodes as $key => $node) {
            $nodes[$key] = $this->propertyMapper->convert($node, NodeInterface::class, $propertyMappingConfiguration);
        }

        switch ($action) {
            case 'publish':
                foreach ($nodes as $node) {
                    $this->publishingService->publishNode($node);
                }
                $this->addFlashMessage(
                    $this->translator->translateById('workspaces.selectedChangesHaveBeenPublished', [], null, null,
                        'Modules', 'Neos.Neos')
                );
                break;
            case 'discard':
                $this->publishingService->discardNodes($nodes);
                $this->addFlashMessage(
                    $this->translator->translateById('workspaces.selectedChangesHaveBeenDiscarded', [], null, null,
                        'Modules', 'Neos.Neos')
                );
                break;
            default:
                throw new \RuntimeException('Invalid action "' . htmlspecialchars($action) . '" given.', 1652703800);
        }

        $this->redirect('show', null, null, ['workspace' => $selectedWorkspace]);
    }

}
