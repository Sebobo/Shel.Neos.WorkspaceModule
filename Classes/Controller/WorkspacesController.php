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
use Neos\Neos\Domain\Model\User;
use Neos\Neos\Domain\Repository\UserRepository;
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

    /**
     * @Flow\Inject
     * @var UserRepository
     */
    protected $userRepository;

    public function indexAction(): void
    {
        $currentAccount = $this->securityContext->getAccount();

        /** @var Workspace $userWorkspace */
        $userWorkspace = $this->workspaceRepository->findOneByName(UserUtility::getPersonalWorkspaceNameForUsername($currentAccount->getAccountIdentifier()));

        $workspaceData = array_reduce($this->workspaceRepository->findAll()->toArray(),
            function (array $carry, Workspace $workspace) {
                if ($this->userCanAccessWorkspace($workspace)) {
                    $carry[$workspace->getName()] = $this->getWorkspaceInfo($workspace);
                }
                return $carry;
            }, [$userWorkspace->getName() => $this->getWorkspaceInfo($userWorkspace)]);

        $this->view->assignMultiple([
            'userWorkspace' => $userWorkspace,
            'baseWorkspaceOptions' => $this->prepareBaseWorkspaceOptions(),
            'userCanManageInternalWorkspaces' => $this->privilegeManager->isPrivilegeTargetGranted('Neos.Neos:Backend.Module.Management.Workspaces.ManageInternalWorkspaces'),
            'userList' => $this->prepareOwnerOptions(),
            'workspaces' => $workspaceData,
            'csrfToken' => $this->securityContext->getCsrfProtectionToken(),
            'validation' => $this->settings['validation'],
            'flashMessages' => $this->controllerContext->getFlashMessageContainer()->getMessagesAndFlush(),
        ]);
    }

    public function getChangesAction(): void
    {
        $currentAccount = $this->securityContext->getAccount();

        /** @var Workspace $userWorkspace */
        $userWorkspace = $this->workspaceRepository->findOneByName(UserUtility::getPersonalWorkspaceNameForUsername($currentAccount->getAccountIdentifier()));

        $workspaces = $this->workspaceRepository->findAll()->toArray();

        $changesByWorkspace = array_reduce($workspaces, function ($carry, Workspace $workspace) {
            if ($this->userCanAccessWorkspace($workspace)) {
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
            $this->addFlashMessage(
                $this->translateById('message.workspaceIsPersonal', ['workspaceName' => $workspace->getTitle()]),
                '',
                Message::SEVERITY_ERROR
            );
        } else {
            $liveWorkspace = $this->workspaceRepository->findByIdentifier('live');

            // Fetch and delete dependent workspaces for target workspace
            /** @var Workspace[] $dependentWorkspaces */
            $dependentWorkspaces = $this->workspaceRepository->findByBaseWorkspace($workspace);
            foreach ($dependentWorkspaces as $dependentWorkspace) {
                $dependentWorkspace->setBaseWorkspace($liveWorkspace);
                $this->workspaceRepository->update($dependentWorkspace);
                $this->addFlashMessage(
                    $this->translateById('message.workspaceRebased',
                        [
                            'dependentWorkspaceName' => $dependentWorkspace->getTitle(),
                            'workspaceName' => $workspace->getTitle(),
                        ]
                    )
                    , '', Message::SEVERITY_WARNING);
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

            $workspaceDetails = $this->workspaceDetailsRepository->findOneByWorkspace($workspace);

            if ($workspaceDetails) {
                $this->workspaceDetailsRepository->remove($workspaceDetails);
            }

            $this->workspaceRepository->remove($workspace);
            $this->addFlashMessage(
                $this->translateById('message.workspaceRemoved',
                    [
                        'workspaceName' => $workspace->getTitle(),
                        'unpublishedNodes' => count($unpublishedNodes),
                        'dependentWorkspaces' => count($dependentWorkspaces),
                    ]
                )
            );
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
        $workspaceDetails = $this->workspaceDetailsRepository->findOneByWorkspace($workspace);
        $owner = $workspace->getOwner();

        $creator = $creatorName = $lastChangedDate = $lastChangedBy = $lastChangedTimestamp = $isStale = null;
        $acl = [];

        if ($workspaceDetails) {
            if ($workspaceDetails->getCreator()) {
                $creatorUser = $this->userService->getUser($workspaceDetails->getCreator());
                $creatorName = $creatorUser ? $creatorUser->getLabel() : $creator;
            }
            $isStale = !$workspace->isPersonalWorkspace() && $workspaceDetails->getLastChangedDate() && $workspaceDetails->getLastChangedDate()->getTimestamp() < time() - $this->staleTime;

            if ($workspaceDetails->getLastChangedBy()) {
                $lastChangedBy = $this->userService->getUser($workspaceDetails->getLastChangedBy());
            }
            $lastChangedDate = $workspaceDetails->getLastChangedDate() ? $workspaceDetails->getLastChangedDate()->format('c') : null;
            $lastChangedTimestamp = $workspaceDetails->getLastChangedDate() ? $workspaceDetails->getLastChangedDate()->getTimestamp() : null;
            $acl = $workspaceDetails->getAcl() ?? [];
        }

        // TODO: Introduce a DTO for this
        return [
            'name' => $workspace->getName(),
            'title' => $workspace->getTitle(),
            'description' => $workspace->getDescription(),
            'owner' => $owner ? [
                'id' => $this->getUserId($owner),
                'label' => $owner->getLabel(),
            ] : null,
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
            'creator' => $creator ? [
                'id' => $creator,
                'label' => $creatorName,
            ] : null,
            'lastChangedDate' => $lastChangedDate,
            'lastChangedTimestamp' => $lastChangedTimestamp,
            'lastChangedBy' => $lastChangedBy ? [
                'id' => $this->getUserId($lastChangedBy),
                'label' => $lastChangedBy->getLabel(),
            ] : null,
            'acl' => array_map(fn(User $user) => [
                'id' => $this->getUserId($user),
                'label' => $user->getLabel(),
            ], $acl),
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

            if ($visibility === 'private' || !$this->userCanManageInternalWorkspaces()) {
                $owner = $this->userService->getCurrentUser();
            } else {
                $owner = null;
            }

            $workspace = new Workspace($workspaceName, $baseWorkspace, $owner);
            $workspace->setTitle($title);
            $workspace->setDescription($description);

            $this->workspaceRepository->add($workspace);

            // Create a new WorkspaceDetails object
            $workspaceDetails = new WorkspaceDetails($workspace,
                $this->securityContext->getAccount()->getAccountIdentifier());
            $this->workspaceDetailsRepository->add($workspaceDetails);

            // Persist the workspace and related data or the generated workspace info will be incomplete
            $this->persistenceManager->persistAll();

            $this->addFlashMessage(
                $this->translateById('message.workspaceCreated', ['workspaceName' => $workspace->getTitle()]),
            );
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
        $success = false;
        if ($workspace->getTitle() === '') {
            $workspace->setTitle($workspace->getName());
        }

        if (!$this->validateWorkspaceChain($workspace)) {
            $this->addFlashMessage($this->translateById('error.invalidWorkspaceChain',
                ['workspaceName' => $workspace->getTitle()]), '', Message::SEVERITY_ERROR);
        } else {
            $workspaceDetails = $this->workspaceDetailsRepository->findOneByWorkspace($workspace);

            if (!$workspaceDetails) {
                $workspaceDetails = new WorkspaceDetails($workspace);
                $this->workspaceDetailsRepository->add($workspaceDetails);
            }

            // Update access control list
            $providedAcl = $this->request->hasArgument('acl') ? $this->request->getArgument('acl') ?? [] : [];
            $acl = $workspace->getOwner() ? $providedAcl : [];
            $allowedUsers = array_map(fn($userName) => $this->userRepository->findByIdentifier($userName), $acl);

            // Rebase users if they were using the workspace but lost access by the update
            $allowedAccounts = array_map(static fn(User $user) => (string)$user->getAccounts()->first()->getAccountIdentifier(), $allowedUsers);
            $liveWorkspace = $this->workspaceRepository->findByIdentifier('live');
            foreach ($workspaceDetails->getAcl() as $prevAcl) {
                $aclAccount = $prevAcl->getAccounts()->first()->getAccountIdentifier();
                if (!in_array($aclAccount, $allowedAccounts, true)) {
                    /** @var Workspace $userWorkspace */
                    $userWorkspace = $this->workspaceRepository->findOneByName(UserUtility::getPersonalWorkspaceNameForUsername($aclAccount));
                    if ($userWorkspace->getBaseWorkspace() === $workspace) {
                        $userWorkspace->setBaseWorkspace($liveWorkspace);
                        $this->workspaceRepository->update($userWorkspace);
                    }
                }
            }

            $workspaceDetails->setAcl($allowedUsers);

            $this->workspaceRepository->update($workspace);
            $this->workspaceDetailsRepository->update($workspaceDetails);
            $this->persistenceManager->persistAll();

            $this->addFlashMessage(
                $this->translateById('message.workspaceUpdated', ['workspaceName' => $workspace->getTitle()]),
            );
            $success = true;
        }

        $this->view->assign('value', [
            'success' => $success,
            'messages' => $this->controllerContext->getFlashMessageContainer()->getMessagesAndFlush(),
            'workspace' => $this->getWorkspaceInfo($workspace),
            'baseWorkspaceOptions' => $this->prepareBaseWorkspaceOptions(),
        ]);
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
        $this->validateWorkspaceAccess($selectedWorkspace);

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

    public function publishWorkspaceAction(Workspace $workspace): void
    {
        $this->validateWorkspaceAccess($workspace);
        parent::publishWorkspaceAction($workspace);
    }

    public function discardWorkspaceAction(Workspace $workspace): void
    {
        $this->validateWorkspaceAccess($workspace);
        parent::discardWorkspaceAction($workspace);
    }

    public function showAction(Workspace $workspace): void
    {
        $this->validateWorkspaceAccess($workspace);
        parent::showAction($workspace);
    }

    protected function getUserId(User $user): string
    {
        return $this->persistenceManager->getIdentifierByObject($user);
    }

    protected function validateWorkspaceAccess(Workspace $workspace = null): void
    {
        if ($workspace && !$this->userCanAccessWorkspace($workspace)) {
            $this->translator->translateById(
                'error.workspaceInaccessible',
                ['workspaceName' => $workspace->getName()],
                null,
                null,
                'Main',
                'Shel.Neos.WorkspaceModule'
            );
            $this->redirect('index');
        }
    }

    protected function userCanAccessWorkspace(Workspace $workspace): bool
    {
        return $workspace->getName() !== 'live' && ($workspace->isInternalWorkspace() || $this->userService->currentUserCanReadWorkspace($workspace));
    }

    private function userCanManageInternalWorkspaces(): bool
    {
        return $this->privilegeManager->isPrivilegeTargetGranted('Neos.Neos:Backend.Module.Management.Workspaces.ManageInternalWorkspaces');
    }

    protected function translateById(string $id, array $arguments = []): string
    {
        return $this->translator->translateById($id, $arguments, null, null, 'Main', 'Shel.Neos.WorkspaceModule');
    }

    /**
     * Checks whether a workspace base workspace chain can be fully resolved without circular references
     */
    protected function validateWorkspaceChain(Workspace $workspace): bool
    {
        $baseWorkspaces = [$workspace->getName()];
        $currentWorkspace = $workspace;
        while ($currentWorkspace = $currentWorkspace->getBaseWorkspace()) {
            if (in_array($currentWorkspace->getName(), $baseWorkspaces, true)) {
                return false;
            }
            $baseWorkspaces[] = $currentWorkspace->getName();
        }
        return true;
    }

}
