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

use Neos\ContentRepository\Core\ContentRepository;
use Neos\ContentRepository\Core\Feature\WorkspaceCreation\Command\CreateWorkspace;
use Neos\ContentRepository\Core\Feature\WorkspaceCreation\Exception\WorkspaceAlreadyExists;
use Neos\ContentRepository\Core\Feature\WorkspaceModification\Command\ChangeBaseWorkspace;
use Neos\ContentRepository\Core\Feature\WorkspaceModification\Command\ChangeWorkspaceOwner;
use Neos\ContentRepository\Core\Feature\WorkspaceModification\Command\DeleteWorkspace;
use Neos\ContentRepository\Core\Feature\WorkspaceModification\Command\RenameWorkspace;
use Neos\ContentRepository\Core\Feature\WorkspacePublication\Command\DiscardIndividualNodesFromWorkspace;
use Neos\ContentRepository\Core\Feature\WorkspacePublication\Command\DiscardWorkspace;
use Neos\ContentRepository\Core\Feature\WorkspacePublication\Command\PublishIndividualNodesFromWorkspace;
use Neos\ContentRepository\Core\Feature\WorkspacePublication\Dto\NodeIdsToPublishOrDiscard;
use Neos\ContentRepository\Core\Feature\WorkspacePublication\Dto\NodeIdToPublishOrDiscard;
use Neos\ContentRepository\Core\Projection\Workspace\WorkspaceFinder;
use Neos\ContentRepository\Core\SharedModel\User\UserId;
use Neos\ContentRepository\Core\SharedModel\Workspace\ContentStreamId;
use Neos\ContentRepository\Core\SharedModel\Workspace\WorkspaceDescription;
use Neos\ContentRepository\Core\SharedModel\Workspace\WorkspaceName;
use Neos\ContentRepository\Core\SharedModel\Workspace\WorkspaceTitle;
use Neos\Flow\Annotations as Flow;
use Neos\ContentRepository\Core\Projection\Workspace\Workspace;
use Neos\Error\Messages\Message;
use Neos\Flow\I18n\Exception\IndexOutOfBoundsException;
use Neos\Flow\I18n\Exception\InvalidFormatPlaceholderException;
use Neos\Flow\Mvc\Exception\StopActionException;
use Neos\Flow\Mvc\View\JsonView;
use Neos\Flow\Persistence\Exception\IllegalObjectTypeException;
use Neos\Flow\Property\Exception as PropertyException;
use Neos\Flow\Property\TypeConverter\PersistentObjectConverter;
use Neos\Flow\Security\Authorization\PrivilegeManagerInterface;
use Neos\Flow\Security\Exception as SecurityException;
use Neos\Neos\Domain\Model\User;
use Neos\Neos\Domain\Repository\UserRepository;
use Neos\Neos\Domain\Service\WorkspaceNameBuilder;
use Neos\Neos\FrontendRouting\NodeAddressFactory;
use Neos\Neos\FrontendRouting\SiteDetection\SiteDetectionResult;
use Neos\Neos\PendingChangesProjection\ChangeFinder;
//use Shel\Neos\WorkspaceModule\Domain\Model\WorkspaceDetails;
//use Shel\Neos\WorkspaceModule\Domain\Repository\WorkspaceDetailsRepository;

class WorkspacesController extends \Neos\Neos\Controller\Module\Management\WorkspacesController
{
    protected $viewFormatToObjectNameMap = [
        'json' => JsonView::class,
    ];

//    #[Flow\Inject]
//    protected WorkspaceDetailsRepository $workspaceDetailsRepository;

    #[Flow\InjectConfiguration(path:'staleTime')]
    protected int $staleTime;

    #[Flow\Inject]
    protected PrivilegeManagerInterface $privilegeManager;

    #[Flow\Inject]
    protected UserRepository $userRepository;

    public function indexAction(): void
    {
        $contentRepositoryId = SiteDetectionResult::fromRequest($this->request->getHttpRequest())->contentRepositoryId;
        $contentRepository = $this->contentRepositoryRegistry->get($contentRepositoryId);
        $currentAccount = $this->securityContext->getAccount();
        $userWorkspaceName = WorkspaceNameBuilder::fromAccountIdentifier($currentAccount->getAccountIdentifier());
        $userWorkspace = $contentRepository->getWorkspaceFinder()->findOneByName($userWorkspaceName);

        $workspaceData = [
            $userWorkspace->workspaceName->value => $this->getWorkspaceInfo($userWorkspace, $contentRepository),
        ];

        foreach ($contentRepository->getWorkspaceFinder()->findAll() as $workspace) {
            if ($this->userCanAccessWorkspace($workspace)) {
                $workspaceData[$workspace->workspaceName->value] = $this->getWorkspaceInfo($workspace, $contentRepository);
            }
        }

        $this->view->assignMultiple([
            'userWorkspace' => $userWorkspace->workspaceName->value,
            'baseWorkspaceOptions' => $this->prepareBaseWorkspaceOptions($contentRepository),
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
        $contentRepositoryId = SiteDetectionResult::fromRequest($this->request->getHttpRequest())->contentRepositoryId;
        $contentRepository = $this->contentRepositoryRegistry->get($contentRepositoryId);
        $currentAccount = $this->securityContext->getAccount();
        $userWorkspaceName = WorkspaceNameBuilder::fromAccountIdentifier($currentAccount->getAccountIdentifier());
        $userWorkspace = $contentRepository->getWorkspaceFinder()->findOneByName($userWorkspaceName);

        $workspaces = $contentRepository->getWorkspaceFinder()->findAll();

        $changesByWorkspace = [
            $userWorkspace->workspaceName->value => $this->computeChangesCount($userWorkspace, $contentRepository),
        ];
        foreach ($workspaces as $workspace) {
            if ($this->userCanAccessWorkspace($workspace)) {
                $changesByWorkspace[$workspace->workspaceName->value] = $this->computeChangesCount($workspace, $contentRepository);
            }
        }

        $this->view->assign('value', ['changesByWorkspace' => $changesByWorkspace]);
    }

    /**
     * Delete a workspace and all contained unpublished changes.
     * Descendent workspaces will be rebased on the live workspace.
     *
     * @throws IllegalObjectTypeException
     * @Flow\SkipCsrfProtection
     */
    public function deleteAction(WorkspaceName $workspaceName): void
    {
        $success = false;

        $contentRepositoryId = SiteDetectionResult::fromRequest($this->request->getHttpRequest())->contentRepositoryId;
        $contentRepository = $this->contentRepositoryRegistry->get($contentRepositoryId);
        $workspace = $contentRepository->getWorkspaceFinder()->findOneByName($workspaceName);

        /** @var Workspace[] $rebasedWorkspaces */
        $rebasedWorkspaces = [];

        if ($workspace === null) {
            $this->addFlashMessage(
                $this->getModuleLabel('workspaces.workspaceDoesNotExist'),
                '',
                Message::SEVERITY_ERROR
            );
        } elseif ($workspace->isPersonalWorkspace()) {
            $this->addFlashMessage(
                $this->translateById('message.workspaceIsPersonal', ['workspaceName' => $workspace->workspaceTitle->value]),
                '',
                Message::SEVERITY_ERROR
            );
        } else {
//            $liveWorkspace = $contentRepository->getWorkspaceFinder()->findOneByName(WorkspaceName::forLive());

            // Fetch and delete dependent workspaces for target workspace
            $dependentWorkspaces = $contentRepository->getWorkspaceFinder()->findByBaseWorkspace($workspace->workspaceName);

//            // TODO: Can we rebase a workspace with unpublished nodes? Will that be prone to rebase errors?
//            // TODO: Adjust to new API
//            foreach ($dependentWorkspaces as $dependentWorkspace) {
//                $dependentWorkspace->setBaseWorkspace($liveWorkspace);
//                //$this->workspaceRepository->update($dependentWorkspace);
//                $this->addFlashMessage(
//                    $this->translateById('message.workspaceRebased',
//                        [
//                            'dependentWorkspaceName' => $dependentWorkspace->getTitle(),
//                            'workspaceName' => $workspace->getTitle(),
//                        ]
//                    )
//                    , '', Message::SEVERITY_WARNING);
//                $rebasedWorkspaces[] = $dependentWorkspace;
//            }

            if (!empty($dependentWorkspaces)) {
                $dependentWorkspaceTitles = array_map(static fn($workspace) => $workspace->workspaceName->value, $dependentWorkspaces);

                $this->addFlashMessage(
                    $this->getModuleLabel(
                        'workspaces.workspaceCannotBeDeletedBecauseOfDependencies',
                        [$workspace->workspaceTitle?->value, implode(', ', $dependentWorkspaceTitles)]
                    ),
                    '',
                    Message::SEVERITY_ERROR
                );
            } else {
                /** @var ChangeFinder $changeFinder */
                $changeFinder = $contentRepository->projectionState(ChangeFinder::class);
                $unpublishedNodes = $changeFinder->countByContentStreamId($workspace->currentContentStreamId);

                if ($unpublishedNodes > 0) {
                    $contentRepository->handle(
                        DiscardWorkspace::create($workspaceName)
                    )->block();
                }

                $contentRepository->handle(
                    DeleteWorkspace::create($workspaceName)
                )->block();

//                $workspaceDetails = $this->workspaceDetailsRepository->findOneByWorkspace($workspace);
//                if ($workspaceDetails) {
//                    $this->workspaceDetailsRepository->remove($workspaceDetails);
//                }

                $this->addFlashMessage(
                    $this->translateById('message.workspaceRemoved',
                        [
                            'workspaceName' => $workspace->workspaceTitle?->value,
                            'unpublishedNodes' => $unpublishedNodes,
                            'dependentWorkspaces' => 0,
                        ]
                    )
                );
                $success = true;
            }
        }

        $this->view->assign('value', [
            'success' => $success,
            'messages' => $this->controllerContext->getFlashMessageContainer()->getMessagesAndFlush(),
            'rebasedWorkspaces' => array_map(static fn($workspace) => $workspace->workspaceName->value, $rebasedWorkspaces),
        ]);
    }

    protected function getWorkspaceInfo(Workspace $workspace, ContentRepository $contentRepository): array
    {
        $owner = $workspace->workspaceOwner ?
            $this->domainUserService->findByUserIdentifier(UserId::fromString($workspace->workspaceOwner)) :
            null;
        $creator = $creatorName = $lastChangedDate = $lastChangedBy = $lastChangedTimestamp = $isStale = null;
        $acl = [];

        if ($owner && $workspace->isPrivateWorkspace()) {
            $creator = $owner;
            $creatorName = $owner->getLabel();
        }

//        $workspaceDetails = $this->workspaceDetailsRepository->findOneByWorkspace($workspace);
//        if ($workspaceDetails) {
//            $creator = $workspaceDetails->getCreator();
//            if ($creator) {
//                $creatorUser = $this->userService->getUser($creator);
//                $creatorName = $creatorUser ? $creatorUser->getLabel() : $creator;
//            }
//            $isStale = !$workspace->isPersonalWorkspace() && $workspaceDetails->getLastChangedDate() && $workspaceDetails->getLastChangedDate()->getTimestamp() < time() - $this->staleTime;
//
//            if ($workspaceDetails->getLastChangedBy()) {
//                $lastChangedBy = $this->userService->getUser($workspaceDetails->getLastChangedBy());
//            }
//            $lastChangedDate = $workspaceDetails->getLastChangedDate() ? $workspaceDetails->getLastChangedDate()->format('c') : null;
//            $lastChangedTimestamp = $workspaceDetails->getLastChangedDate() ? $workspaceDetails->getLastChangedDate()->getTimestamp() : null;
//            $acl = $workspaceDetails->getAcl() ?? [];
//        }

        $baseWorkspace = null;
        if ($workspace->baseWorkspaceName !== null) {
            $baseWorkspace = $contentRepository->getWorkspaceFinder()->findOneByName($workspace->baseWorkspaceName);
        }

        // TODO: Introduce a DTO for this
        return [
            'name' => $workspace->workspaceName->value,
            'title' => $workspace->workspaceTitle?->value ?: '',
            'description' => $workspace->workspaceDescription,
            'owner' => $owner ? [
                'id' => $workspace->workspaceOwner,
                'label' => $owner->getLabel(),
            ] : null,
            'baseWorkspace' => $baseWorkspace ? [
                'name' => $baseWorkspace->workspaceName->value,
                'title' => $baseWorkspace->workspaceTitle?->value ?: '',
            ] : null,
            //'nodeCount' => $workspace->getNodeCount(),
            'nodeCount' => 0,
            'changesCounts' => null, // Will be retrieved async by the UI to speed up module loading time
            'isPersonal' => $workspace->isPersonalWorkspace(),
            'isInternal' => $workspace->isInternalWorkspace(),
            'isStale' => $isStale,
            'canPublish' => $this->domainUserService->currentUserCanPublishToWorkspace($workspace),
            'canManage' => $this->domainUserService->currentUserCanManageWorkspace($workspace),
            //'dependentWorkspacesCount' => count($this->workspaceRepository->findByBaseWorkspace($workspace)),
            'dependentWorkspacesCount' => 0,
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
     * @inheritDoc
     * Create action from Neos WorkspacesController but creates a new WorkspaceDetails object after workspace creation
     */
    public function createAction(
        WorkspaceTitle $title,
        WorkspaceName $baseWorkspace,
        string $visibility,
        WorkspaceDescription $description
    ): void
    {
        $success = true;
        $workspaceName = WorkspaceName::transliterateFromString('workspace-' . $title->value);

        $contentRepositoryId = SiteDetectionResult::fromRequest($this->request->getHttpRequest())
            ->contentRepositoryId;
        $contentRepository = $this->contentRepositoryRegistry->get($contentRepositoryId);

        $newWorkspace = null;
        $workspace = $contentRepository->getWorkspaceFinder()->findOneByName($workspaceName);
        if ($workspace instanceof Workspace) {
            $this->addFlashMessage($this->getModuleLabel('workspaces.workspaceWithThisTitleAlreadyExists'), '', Message::SEVERITY_WARNING);
            $success = false;
        } else {
            // If a workspace with the generated name already exists, try again with a new name
            while ($contentRepository->getWorkspaceFinder()->findOneByName($workspaceName) instanceof Workspace) {
                $workspaceName = WorkspaceName::fromString($workspaceName->value . '-' . substr(md5(random_bytes(10)), 0, 5));
            }

            $currentUserIdentifier = $this->domainUserService->getCurrentUserIdentifier();
            if (is_null($currentUserIdentifier)) {
                throw new \InvalidArgumentException('Cannot create workspace without a current user', 1652155039);
            }

            try {
                $contentRepository->handle(
                    CreateWorkspace::create(
                        $workspaceName,
                        $baseWorkspace,
                        $title,
                        $description,
                        ContentStreamId::create(),
                        ($visibility === 'private' || !$this->userCanManageInternalWorkspaces()) ? $currentUserIdentifier : null
                    )
                )->block();

                $newWorkspace = $contentRepository->getWorkspaceFinder()->findOneByName($workspaceName);
                $this->addFlashMessage(
                    $this->translateById('message.workspaceCreated', ['workspaceName' => $newWorkspace->workspaceTitle?->value]),
                );
            } catch (WorkspaceAlreadyExists $exception) {
                $this->addFlashMessage($this->getModuleLabel('workspaces.workspaceWithThisTitleAlreadyExists'), '', Message::SEVERITY_WARNING);
                $success = false;
            }
        }

        $this->view->assign('value', [
            'success' => $success,
            'messages' => $this->controllerContext->getFlashMessageContainer()->getMessagesAndFlush(),
            'workspace' => $newWorkspace ? $this->getWorkspaceInfo($newWorkspace, $contentRepository) : [],
            // Include a new list of base workspace options which might contain the new workspace depending on its visibility
            'baseWorkspaceOptions' => $this->prepareBaseWorkspaceOptions($contentRepository),
        ]);
    }

    /**
     * @inheritDoc
     */
    protected function prepareBaseWorkspaceOptions(
        ContentRepository $contentRepository,
        Workspace $excludedWorkspace = null,
    ): array
    {
        $options = parent::prepareBaseWorkspaceOptions($contentRepository, $excludedWorkspace);
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

//    /**
//     * @inheritDoc
//     */
//    protected function initializeUpdateAction(): void
//    {
//        $workspaceConfiguration = $this->arguments['workspace']->getPropertyMappingConfiguration();
//        $workspaceConfiguration->allowAllProperties();
//        $workspaceConfiguration->setTypeConverterOption(PersistentObjectConverter::class,
//            PersistentObjectConverter::CONFIGURATION_MODIFICATION_ALLOWED, true);
//    }

    /**
     * @inheritDoc
     */
    public function updateAction(
        WorkspaceName        $workspaceName,
        WorkspaceTitle       $title,
        WorkspaceDescription $description,
        ?string              $workspaceOwner
    ): void
    {
        $success = false;

        $contentRepositoryId = SiteDetectionResult::fromRequest($this->request->getHttpRequest())
            ->contentRepositoryId;
        $contentRepository = $this->contentRepositoryRegistry->get($contentRepositoryId);

        if ($title->value === '') {
            $title = WorkspaceTitle::fromString($workspaceName->value);
        }

        $workspace = $contentRepository->getWorkspaceFinder()->findOneByName($workspaceName);
        if ($workspace === null) {
            $this->addFlashMessage(
                $this->getModuleLabel('workspaces.workspaceDoesNotExist'),
                '',
                Message::SEVERITY_ERROR
            );
        } elseif (!$this->validateWorkspaceChain($workspace, $contentRepository->getWorkspaceFinder())) {
            $this->addFlashMessage(
                $this->translateById('error.invalidWorkspaceChain',
                    ['workspaceName' => $workspace->workspaceTitle]),
                '',
                Message::SEVERITY_ERROR
            );
        } else {

            if (!$workspace->workspaceTitle?->equals($title) || !$workspace->workspaceDescription->equals($description)) {
                $contentRepository->handle(
                    RenameWorkspace::create(
                        $workspaceName,
                        $title,
                        $description
                    )
                )->block();
            }

            // Change workspace owner if it changed
            if ($workspace->workspaceOwner !== $workspaceOwner) {
                $contentRepository->handle(
                    ChangeWorkspaceOwner::create(
                        $workspaceName,
                        $workspaceOwner ?: null,
                    )
                )->block();
            }

            // TODO: Changing the base

//            // TODO: Check & Reimplement
//            // Get or create workspace details
//            $workspaceDetails = $this->workspaceDetailsRepository->findOneByWorkspace($workspace);
//            if (!$workspaceDetails) {
//                $workspaceDetails = new WorkspaceDetails($workspace);
//                $this->workspaceDetailsRepository->add($workspaceDetails);
//            }
//
//            // Update access control list
//            $providedAcl = $this->request->hasArgument('acl') ? $this->request->getArgument('acl') ?? [] : [];
//            $acl = $workspace->workspaceOwner ? $providedAcl : [];
//            $allowedUsers = array_map(fn($userName) => $this->userRepository->findByIdentifier($userName), $acl);
//
//            // Rebase users if they were using the workspace but lost access by the update
//            $allowedAccounts = array_map(static fn(User $user) => (string)$user->getAccounts()->first()->getAccountIdentifier(), $allowedUsers);
//            $liveWorkspace = $contentRepository->getWorkspaceFinder()->findOneByName(WorkspaceName::forLive());
//            foreach ($workspaceDetails->getAcl() as $prevAcl) {
//                $aclAccount = $prevAcl->getAccounts()->first()->getAccountIdentifier();
//                if (!in_array($aclAccount, $allowedAccounts, true)) {
//                    $userWorkspace = $contentRepository->getWorkspaceFinder()->findOneByName(WorkspaceNameBuilder::fromAccountIdentifier($aclAccount));
//                    if ($userWorkspace && $userWorkspace->baseWorkspaceName->value === $workspace->workspaceName->value) {
//                        $contentRepository->handle(
//                            ChangeBaseWorkspace::create(
//                                $userWorkspace->workspaceName,
//                                $liveWorkspace->workspaceName,
//                            )
//                        );
//                    }
//                }
//            }
//
//            // Update workspace details
//            $workspaceDetails->setAcl($allowedUsers);
//            $this->workspaceDetailsRepository->update($workspaceDetails);
//            // TODO: Check if persist is still needed
//            //$this->persistenceManager->persistAll();

            $workspace = $contentRepository->getWorkspaceFinder()->findOneByName($workspaceName);
            $this->addFlashMessage($this->getModuleLabel('workspaces.workspaceHasBeenUpdated', [$title->value]));
            $success = true;
        }

        $this->view->assign('value', [
            'success' => $success,
            'messages' => $this->controllerContext->getFlashMessageContainer()->getMessagesAndFlush(),
            'workspace' => $workspace ? $this->getWorkspaceInfo($workspace, $contentRepository) : [],
            'baseWorkspaceOptions' => $this->prepareBaseWorkspaceOptions($contentRepository),
        ]);
    }

    /**
     * @inheritDoc
     *
     * @param array $nodes
     * @param string $action
     * @param string $selectedWorkspace
     * @throws IndexOutOfBoundsException|InvalidFormatPlaceholderException|StopActionException
     */
    public function publishOrDiscardNodesAction(array $nodes, string $action, string $selectedWorkspace): void
    {
        $selectedWorkspace = WorkspaceName::fromString($selectedWorkspace);
        $this->validateWorkspaceAccess($selectedWorkspace);

        $contentRepositoryId = SiteDetectionResult::fromRequest($this->request->getHttpRequest())
            ->contentRepositoryId;
        $contentRepository = $this->contentRepositoryRegistry->get($contentRepositoryId);
        $nodeAddressFactory = NodeAddressFactory::create($contentRepository);

        $nodesToPublishOrDiscard = [];
        foreach ($nodes as $node) {
            $nodeAddress = $nodeAddressFactory->createFromUriString($node);
            $nodesToPublishOrDiscard[] = new NodeIdToPublishOrDiscard(
                $nodeAddress->contentStreamId,
                $nodeAddress->nodeAggregateId,
                $nodeAddress->dimensionSpacePoint
            );
        }

        switch ($action) {
            case 'publish':
                $command = PublishIndividualNodesFromWorkspace::create(
                    $selectedWorkspace,
                    NodeIdsToPublishOrDiscard::create(...$nodesToPublishOrDiscard),
                );
                $contentRepository->handle($command)
                    ->block();
                $this->addFlashMessage($this->translator->translateById(
                    'workspaces.selectedChangesHaveBeenPublished',
                    [],
                    null,
                    null,
                    'Modules',
                    'Neos.Neos'
                ) ?: 'workspaces.selectedChangesHaveBeenPublished');
                break;
            case 'discard':
                $command = DiscardIndividualNodesFromWorkspace::create(
                    $selectedWorkspace,
                    NodeIdsToPublishOrDiscard::create(...$nodesToPublishOrDiscard),
                );
                $contentRepository->handle($command)
                    ->block();
                $this->addFlashMessage($this->translator->translateById(
                    'workspaces.selectedChangesHaveBeenDiscarded',
                    [],
                    null,
                    null,
                    'Modules',
                    'Neos.Neos'
                ) ?: 'workspaces.selectedChangesHaveBeenDiscarded');
                break;
            default:
                throw new \RuntimeException('Invalid action "' . htmlspecialchars($action) . '" given.', 1346167441);
        }

        $this->redirect('show', null, null, ['workspace' => $selectedWorkspace->value]);
    }

    public function publishWorkspaceAction(WorkspaceName $workspace): void
    {
        $this->validateWorkspaceAccess($workspace);
        parent::publishWorkspaceAction($workspace);
    }

    public function discardWorkspaceAction(WorkspaceName $workspace): void
    {
        $this->validateWorkspaceAccess($workspace);
        parent::discardWorkspaceAction($workspace);
    }

    public function showAction(WorkspaceName $workspace): void
    {
        $this->validateWorkspaceAccess($workspace);
        parent::showAction($workspace);
    }

    protected function getUserId(User $user): string
    {
        return $this->persistenceManager->getIdentifierByObject($user);
    }

    protected function validateWorkspaceAccess(WorkspaceName $workspaceName = null): void
    {
        $contentRepositoryId = SiteDetectionResult::fromRequest($this->request->getHttpRequest())->contentRepositoryId;
        $contentRepository = $this->contentRepositoryRegistry->get($contentRepositoryId);
        $workspace = $contentRepository->getWorkspaceFinder()->findOneByName($workspaceName);

        if ($workspaceName && !$this->userCanAccessWorkspace($workspace)) {
            $this->translator->translateById(
                'error.workspaceInaccessible',
                ['workspaceName' => $workspaceName->value],
                null,
                null,
                'Main',
                'Shel.Neos.WorkspaceModule'
            );
            $this->redirect('index');
        }
    }

    /**
     * Checks whether the current user can access the given workspace.
     * The check via the `userService` is modified via an aspect to allow access to the workspace if the
     * workspace is specifically allowed for the user.
     */
    protected function userCanAccessWorkspace(Workspace $workspace): bool
    {
        return !$workspace->workspaceName->isLive() && ($workspace->isInternalWorkspace() || $this->domainUserService->currentUserCanReadWorkspace($workspace));
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
    protected function validateWorkspaceChain(Workspace $workspace, WorkspaceFinder $workspaceFinder): bool
    {
        $baseWorkspaces = [$workspace->workspaceName->value];
        $currentWorkspace = $workspace;
        while ($currentWorkspace->baseWorkspaceName && $currentWorkspace = $workspaceFinder->findOneByName($currentWorkspace->baseWorkspaceName)) {
            if (in_array($currentWorkspace->workspaceName->value, $baseWorkspaces, true)) {
                return false;
            }
            $baseWorkspaces[] = $currentWorkspace->workspaceName->value;
        }
        return true;
    }

}
