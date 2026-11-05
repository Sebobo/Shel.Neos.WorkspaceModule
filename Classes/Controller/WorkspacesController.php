<?php

declare(strict_types=1);

namespace Shel\Neos\WorkspaceModule\Controller;

/**
 * This file is part of the Shel.Neos.WorkspaceModule package.
 *
 * This package is Open Source Software. For the full copyright and license
 * information, please view the LICENSE file which was distributed with this
 * source code.
 */

use Neos\ContentRepository\Domain\Model\NodeInterface;
use Neos\ContentRepository\TypeConverter\NodeConverter;
use Neos\ContentRepository\Domain\Model\Workspace;
use Neos\ContentRepository\Domain\Repository\WorkspaceRepository;
use Neos\ContentRepository\Utility;
use Neos\Error\Messages\Message;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\Mvc\View\JsonView;
use Neos\Flow\Persistence\Exception\IllegalObjectTypeException;
use Neos\Flow\Property\Exception as PropertyException;
use Neos\Flow\Property\TypeConverter\PersistentObjectConverter;
use Neos\Flow\Security\Authorization\PrivilegeManagerInterface;
use Neos\Flow\Security\Account;
use Neos\Flow\Security\Exception as SecurityException;
use Neos\Neos\Domain\Model\User;
use Neos\Neos\Domain\Repository\UserRepository;
use Neos\Neos\Domain\Service\UserService;
use Neos\Neos\Utility\User as UserUtility;
use Shel\Neos\WorkspaceModule\Domain\Model\WorkspaceDetails;
use Shel\Neos\WorkspaceModule\Service\ContentDimensionService;
use Shel\Neos\WorkspaceModule\Service\DocumentDescriptionService;
use Shel\Neos\WorkspaceModule\Service\NodeTreeService;
use Shel\Neos\WorkspaceModule\Service\WorkspaceChangesService;
use Shel\Neos\WorkspaceModule\Service\WorkspacePublishingService;
use Shel\Neos\WorkspaceModule\Domain\Repository\WorkspaceDetailsRepository;
use Shel\Neos\WorkspaceModule\ViewModel\ChangeItems;

class WorkspacesController extends \Neos\Neos\Controller\Module\Management\WorkspacesController
{
    /**
     * The map of the view formats is a property of the action controller of the framework,
     * which has no type of its own - so this one has none either.
     *
     * @var array<string, class-string>
     */
    protected $viewFormatToObjectNameMap = [
        'json' => JsonView::class,
    ];

    #[Flow\Inject]
    protected WorkspaceDetailsRepository $workspaceDetailsRepository;

    #[Flow\InjectConfiguration(path: 'staleTime')]
    protected int $staleTime;

    #[Flow\Inject]
    protected PrivilegeManagerInterface $privilegeManager;

    #[Flow\Inject]
    protected UserRepository $userRepository;

    #[Flow\Inject]
    protected WorkspaceChangesService $workspaceChangesService;

    #[Flow\Inject]
    protected WorkspacePublishingService $workspacePublishingService;

    #[Flow\Inject]
    protected DocumentDescriptionService $documentDescriptionService;

    #[Flow\Inject]
    protected ContentDimensionService $contentDimensionService;

    #[Flow\Inject]
    protected NodeTreeService $nodeTreeService;

    public function indexAction(): void
    {
        $currentAccount = $this->securityContext->getAccount();

        /** @var Workspace $userWorkspace */
        $userWorkspace = $this->workspaceRepository->findOneByName(
            UserUtility::getPersonalWorkspaceNameForUsername($currentAccount->getAccountIdentifier())
        );

        $workspaceData = array_reduce(
            $this->workspaceRepository->findAll()->toArray(),
            function (array $carry, Workspace $workspace) {
                if ($this->userCanAccessWorkspace($workspace)) {
                    $carry[$workspace->getName()] = $this->getWorkspaceInfo($workspace);
                }
                return $carry;
            },
            [$userWorkspace->getName() => $this->getWorkspaceInfo($userWorkspace)]
        );

        $this->view->assignMultiple([
            'username' => $currentAccount->getAccountIdentifier(),
            'userWorkspace' => $userWorkspace,
            'baseWorkspaceOptions' => $this->prepareBaseWorkspaceOptions(),
            'userCanManageInternalWorkspaces' => $this->userCanManageInternalWorkspaces(),
            'userCanCreateWorkspaces' => $this->userCanCreateWorkspaces(),
            'userList' => $this->prepareOwnerOptions(),
            'workspaces' => $workspaceData,
            'validation' => $this->settings['validation'],
            'flashMessages' => $this->controllerContext->getFlashMessageContainer()->getMessagesAndFlush(),
        ]);
    }

    public function getChangesAction(): void
    {
        $currentAccount = $this->securityContext->getAccount();

        /** @var Workspace $userWorkspace */
        $userWorkspace = $this->workspaceRepository->findOneByName(
            UserUtility::getPersonalWorkspaceNameForUsername($currentAccount->getAccountIdentifier())
        );

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
     */
    #[Flow\SkipCsrfProtection]
    public function deleteAction(Workspace $workspace): void
    {
        $success = false;
        /** @var list<Workspace> $rebasedWorkspaces */
        $rebasedWorkspaces = [];

        if ($workspace->isPersonalWorkspace() && $workspace->getOwner() !== null) {
            $this->addFlashMessage(
                (string)$this->translateById('message.workspaceIsPersonal', ['workspaceName' => $workspace->getTitle()]),
                '',
                Message::SEVERITY_ERROR
            );
        } else {
            $liveWorkspace = $this->workspaceRepository->findByIdentifier('live');

            // Fetch and delete dependent workspaces for target workspace
            /** @var list<Workspace> $dependentWorkspaces */
            $dependentWorkspaces = $this->workspaceRepository->findByBaseWorkspace($workspace);
            foreach ($dependentWorkspaces as $dependentWorkspace) {
                $dependentWorkspace->setBaseWorkspace($liveWorkspace);
                $this->workspaceRepository->update($dependentWorkspace);
                $this->addFlashMessage(
                    (string)$this->translateById(
                        'message.workspaceRebased',
                        [
                            'dependentWorkspaceName' => $dependentWorkspace->getTitle(),
                            'workspaceName' => $workspace->getTitle(),
                        ]
                    )
                    ,
                    '',
                    Message::SEVERITY_WARNING
                );
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
                (string)$this->translateById(
                    'message.workspaceRemoved',
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

    /**
     * Prune a workspace and all contained unpublished changes.
     *
     * @throws IllegalObjectTypeException
     */
    #[Flow\SkipCsrfProtection]
    public function pruneAction(Workspace $workspace): void
    {
        $success = false;
        $unpublishedNodes = $this->publishingService->getUnpublishedNodes($workspace);

        if (count($unpublishedNodes) > 0 && $this->userService->currentUserCanPublishToWorkspace($workspace)) {
            try {
                $this->publishingService->discardNodes($unpublishedNodes);

                $this->addFlashMessage(
                    (string)$this->translateById(
                        'message.workspacePruned',
                        ['workspaceName' => $workspace->getTitle(), 'contentCount' => count($unpublishedNodes)]
                    ),
                );

                // Persist the workspace and related data or the generated workspace info will be incomplete
                $this->persistenceManager->persistAll();
                $success = true;
            } catch (\Exception $e) {
                $this->addFlashMessage(
                    (string)$this->translateById(
                        'error.pruneFailed',
                        ['workspaceName' => $workspace->getTitle()]
                    ),
                    '',
                    Message::SEVERITY_WARNING
                );
            }
        } else {
            $this->addFlashMessage(
                (string)$this->translateById(
                    'message.workspaceEmpty',
                    ['workspaceName' => $workspace->getTitle()]
                ),
                '',
                Message::SEVERITY_WARNING
            );
        }

        $this->view->assign('value', [
            'success' => $success,
            'messages' => $this->controllerContext->getFlashMessageContainer()->getMessagesAndFlush(),
            'workspace' => $this->getWorkspaceInfo($workspace),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    protected function getWorkspaceInfo(Workspace $workspace): array
    {
        $workspaceDetails = $this->workspaceDetailsRepository->findOneByWorkspace($workspace);

        /** @var User|null $owner */
        $owner = $workspace->getOwner();
        $primaryOwnerAccount = $owner?->getAccounts()[0] ?? null;

        $creator = $creatorName = $lastChangedDate = $lastChangedBy = $lastChangedTimestamp = $isStale = null;
        $acl = [];

        if ($workspaceDetails) {
            $creator = $workspaceDetails->getCreator();
            if ($creator) {
                $creatorUser = $this->userService->getUser($creator);
                $creatorName = $creatorUser ? $creatorUser->getLabel() : $creator;
            }
            $isStale = !$workspace->isPersonalWorkspace() && $workspaceDetails->getLastChangedDate(
                ) && $workspaceDetails->getLastChangedDate()->getTimestamp() < time() - $this->staleTime;

            if ($workspaceDetails->getLastChangedBy()) {
                $lastChangedBy = $this->userService->getUser($workspaceDetails->getLastChangedBy());
            }
            $lastChangedDate = $workspaceDetails->getLastChangedDate() ? $workspaceDetails->getLastChangedDate(
            )->format('c') : null;
            $lastChangedTimestamp = $workspaceDetails->getLastChangedDate() ? $workspaceDetails->getLastChangedDate(
            )->getTimestamp() : null;
            $acl = $workspaceDetails->getAcl();
        }

        // TODO: Introduce a DTO for this
        return [
            'name' => $workspace->getName(),
            'title' => $workspace->getTitle(),
            'description' => $workspace->getDescription(),
            'owner' => $owner ? [
                'id' => $this->getUserId($owner),
                'name' => $primaryOwnerAccount?->getAccountIdentifier(),
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
            'canManage' => $this->canManageWorkspace($workspace),
            'dependentWorkspacesCount' => count($this->workspaceRepository->findByBaseWorkspace($workspace)),
            'creator' => $creator ? [
                'id' => $creator,
                'name' => $creator,
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
     * @param string $title Human friendly title of the workspace, for example "Christmas Campaign"
     * @param Workspace $baseWorkspace Workspace the new workspace should be based on
     * @param string $visibility Visibility of the new workspace, must be either "internal" or "shared"
     * @param string $description A description explaining the purpose of the new workspace
     */
    #[Flow\Validate(argumentName: 'title', type: '\Neos\Flow\Validation\Validator\NotEmptyValidator')]
    public function createAction($title, Workspace $baseWorkspace, $visibility, $description = ''): void
    {
        $success = true;

        $workspace = $this->workspaceRepository->findOneByTitle($title);
        if ($workspace instanceof Workspace) {
            $this->addFlashMessage(
                (string)$this->translator->translateById(
                    'workspaces.workspaceWithThisTitleAlreadyExists',
                    [],
                    null,
                    null,
                    'Modules',
                    'Neos.Neos'
                ),
                '',
                Message::SEVERITY_WARNING
            );
            $success = false;
        } else {
            $workspaceName = $this->renderWorkspaceName($title);
            // If a workspace with the generated name already exists, try again with a new name
            while ($this->workspaceRepository->findOneByName($workspaceName) instanceof Workspace) {
                $workspaceName = $this->renderWorkspaceName($title);
            }

            if ($visibility === 'private' || !$this->userCanCreateWorkspaces()) {
                $owner = $this->userService->getCurrentUser();
            } else {
                $owner = null;
            }

            $workspace = new Workspace($workspaceName, $baseWorkspace, $owner);
            $workspace->setTitle($title);
            $workspace->setDescription($description);

            $this->workspaceRepository->add($workspace);

            // Create a new WorkspaceDetails object
            $workspaceDetails = new WorkspaceDetails(
                $workspace,
                $this->securityContext->getAccount()->getAccountIdentifier()
            );
            $this->workspaceDetailsRepository->add($workspaceDetails);

            // Persist the workspace and related data or the generated workspace info will be incomplete
            $this->persistenceManager->persistAll();

            $this->addFlashMessage(
                (string)$this->translateById('message.workspaceCreated', ['workspaceName' => $workspace->getTitle()]),
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
     * Returns a valid internal name for a new workspace based on the given title and the current timestamp
     */
    protected function renderWorkspaceName(string $workspaceTitle): string
    {
        $timestamp = base_convert(microtime(false), 10, 36);
        $randomHash = substr($timestamp, -5, 5);
        return Utility::renderValidNodeName($workspaceTitle) . '-' . $randomHash;
    }

    /**
     * @inheritDoc
     * @return array<string, string>
     */
    protected function prepareBaseWorkspaceOptions(?Workspace $excludedWorkspace = null): array
    {
        $options = parent::prepareBaseWorkspaceOptions($excludedWorkspace);
        foreach (array_keys($options) as $workspaceName) {
            /** @var Workspace|null $workspace */
            $workspace = $this->workspaceRepository->findByIdentifier($workspaceName);
            if (!$workspace || !$this->userService->currentUserCanReadWorkspace($workspace)) {
                unset($options[$workspaceName]);
            }
        }
        asort($options, SORT_FLAG_CASE | SORT_NATURAL);
        return $options;
    }

    /**
     * @inheritDoc
     * @return array<string, string>
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
    protected function initializeAction(): void
    {
        parent::initializeAction();
        // The services hold the changes of the workspace and the nodes of the base
        // workspace in a cache, so that they are not asked for them again and again. The
        // cache belongs to the request: the changes of a request that is over are not the
        // changes of the next one.
        $this->workspaceChangesService->clearCache();
        $this->nodeTreeService->clearCache();
    }

    /**
     * @inheritDoc
     */
    protected function initializeUpdateAction(): void
    {
        parent::initializeUpdateAction();
        $workspaceConfiguration = $this->arguments['workspace']->getPropertyMappingConfiguration();
        $workspaceConfiguration->allowAllProperties();
        $workspaceConfiguration->setTypeConverterOption(
            PersistentObjectConverter::class,
            PersistentObjectConverter::CONFIGURATION_MODIFICATION_ALLOWED,
            true
        );
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
            $this->addFlashMessage(
                (string)$this->translateById(
                    'error.invalidWorkspaceChain',
                    ['workspaceName' => $workspace->getTitle()]
                ),
                '',
                Message::SEVERITY_ERROR
            );
        } else {
            $workspaceDetails = $this->workspaceDetailsRepository->findOneByWorkspace($workspace);

            if (!$workspaceDetails) {
                $workspaceDetails = new WorkspaceDetails($workspace);
                $this->workspaceDetailsRepository->add($workspaceDetails);
            }

            // Update access control list
            /** @var list<string> $providedAcl */
            $providedAcl = $this->request->hasArgument('acl') ? (array)$this->request->getArgument('acl') : [];
            $acl = $workspace->getOwner() ? $providedAcl : [];
            $allowedUsers = [];
            foreach ($acl as $userName) {
                $allowedUser = $this->userRepository->findByIdentifier($userName);
                if ($allowedUser instanceof User) {
                    $allowedUsers[] = $allowedUser;
                }
            }

            // Rebase users if they were using the workspace but lost access by the update
            $allowedAccounts = [];
            foreach ($allowedUsers as $allowedUser) {
                $accountIdentifier = $this->firstAccountIdentifierOf($allowedUser);
                if ($accountIdentifier !== null) {
                    $allowedAccounts[] = $accountIdentifier;
                }
            }
            $liveWorkspace = $this->workspaceRepository->findByIdentifier('live');
            foreach ($workspaceDetails->getAcl() as $prevAcl) {
                $aclAccount = $this->firstAccountIdentifierOf($prevAcl);
                if ($aclAccount === null) {
                    continue;
                }
                if (!in_array($aclAccount, $allowedAccounts, true)) {
                    /** @var Workspace $userWorkspace */
                    $userWorkspace = $this->workspaceRepository->findOneByName(
                        UserUtility::getPersonalWorkspaceNameForUsername($aclAccount)
                    );
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
                (string)$this->translateById('message.workspaceUpdated', ['workspaceName' => $workspace->getTitle()]),
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
     * @param list<NodeInterface> $nodes
     * @param string $action
     * @param Workspace|null $selectedWorkspace
     * @throws \Exception|PropertyException|SecurityException
     */
    public function publishOrDiscardNodesAction(array $nodes, $action, ?Workspace $selectedWorkspace = null): void
    {
        $this->validateWorkspaceAccess($selectedWorkspace);

        $propertyMappingConfiguration = $this->propertyMapper->buildPropertyMappingConfiguration();
        $propertyMappingConfiguration->setTypeConverterOption(
            NodeConverter::class,
            NodeConverter::REMOVED_CONTENT_SHOWN,
            true
        );
        foreach ($nodes as $key => $node) {
            $nodes[$key] = $this->propertyMapper->convert($node, NodeInterface::class, $propertyMappingConfiguration);
        }

        switch ($action) {
            case 'publish':
                foreach ($nodes as $node) {
                    $this->publishingService->publishNode($node);
                }
                $this->addFlashMessage(
                    (string)$this->translator->translateById(
                        'workspaces.selectedChangesHaveBeenPublished',
                        [],
                        null,
                        null,
                        'Modules',
                        'Neos.Neos'
                    )
                );
                break;
            case 'discard':
                foreach ($nodes as $node) {
                    $this->workspacePublishingService->discardNodeWithItsChildren($node);
                }
                $this->addFlashMessage(
                    (string)$this->translator->translateById(
                        'workspaces.selectedChangesHaveBeenDiscarded',
                        [],
                        null,
                        null,
                        'Modules',
                        'Neos.Neos'
                    )
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

    /**
     * The review of a workspace: the documents with changes on the left, the changes of the
     * selected document on the right.
     *
     * Both parts are loaded asynchronously, so this action only renders the frame they are
     * loaded into.
     */
    public function showAction(Workspace $workspace, string $dimensions = ''): void
    {
        $this->validateWorkspaceAccess($workspace);
        $dimensionValues = $this->assignDimensions($dimensions);
        $this->view->assignMultiple([
            'selectedWorkspace' => $workspace,
            'selectedWorkspaceName' => $workspace->getName(),
            'selectedWorkspaceLabel' => $workspace->getTitle() ?: $workspace->getName(),
            'baseWorkspaceLabel' => $this->baseWorkspaceLabel($workspace),
            'canPublishToBaseWorkspace' => $workspace->getBaseWorkspace() !== null
                && $this->userService->currentUserCanPublishToWorkspace($workspace->getBaseWorkspace()),
            'flashMessages' => $this->controllerContext->getFlashMessageContainer()->getMessagesAndFlush(),
        ]);
    }

    /**
     * The label of the workspace the changes are published to - the base of the workspace
     * that is reviewed.
     */
    private function baseWorkspaceLabel(Workspace $workspace): string
    {
        $baseWorkspace = $workspace->getBaseWorkspace();

        return $baseWorkspace === null ? '' : ($baseWorkspace->getTitle() ?: $baseWorkspace->getName());
    }

    /**
     * The documents of the workspace that have changes, loaded asynchronously.
     *
     * The tree is loaded on its own when the review opens and travels with every answer that
     * changes something afterwards. When it is reloaded by hand, the document that is open
     * stays open - only the first load opens the first document.
     */
    public function documentTreeAction(Workspace $workspace, bool $reload = false, string $selectedDocument = '', string $dimensions = ''): void
    {
        $this->validateWorkspaceAccess($workspace);
        $dimensionValues = $this->assignDimensions($dimensions);
        $this->view->assignMultiple([
            'selectedWorkspace' => $workspace,
            'selectedWorkspaceName' => $workspace->getName(),
            'sites' => $this->workspaceChangesService->getDocumentsWithChanges($workspace, $dimensionValues),
            'autoLoad' => !$reload,
            // The document that is open stays marked, even when the tree is loaded again.
            'selectedDocument' => $selectedDocument,
        ]);
    }

    /**
     * The changes of a single document, loaded asynchronously.
     */
    public function documentChangesAction(Workspace $workspace, string $document, string $dimensions = ''): void
    {
        $this->validateWorkspaceAccess($workspace);
        $dimensionValues = $this->assignDimensions($dimensions);
        $documentNode = $this->workspaceChangesService->findDocumentInChanges($workspace, $document, $dimensionValues);
        $changesOfDocument = $documentNode === null
            ? []
            : $this->workspaceChangesService->getChangesOfDocument($workspace, $documentNode, $dimensionValues);
        $changes = $documentNode === null
            ? ChangeItems::fromArray([])
            : $this->workspaceChangesService->getChangesForDocument($workspace, $documentNode, $dimensionValues);
        $documentItem = $documentNode === null
            ? null
            : $this->documentDescriptionService->describeDocument($documentNode, $changesOfDocument);
        $this->view->assignMultiple([
            'selectedWorkspace' => $workspace,
            'selectedWorkspaceName' => $workspace->getName(),
            // The list of documents travels with the answer, so that the tree can mark the
            // document that is open.
            'sites' => $this->workspaceChangesService->getDocumentsWithChanges($workspace, $dimensionValues),
            'selectedDocument' => $document,
            'changes' => $changes,
            'document' => $documentItem,
            'baseWorkspaceLabel' => $this->baseWorkspaceLabel($workspace),
            'canPublishToBaseWorkspace' => $this->canPublishToBaseWorkspace($workspace),
        ]);
    }

    /**
     * Publishes one change.
     */
    public function publishChangeAction(string $node, Workspace $workspace, string $dimensions = ''): void
    {
        $this->validateWorkspaceAccess($workspace);
        $dimensionValues = $this->assignDimensions($dimensions);
        $nodeInstance = $this->resolveNode($node);
        $this->workspacePublishingService->publishNodesWithTheirAncestors([$nodeInstance]);
        $this->addFlashMessage((string)$this->translator->translateById('review.changePublished', [], null, null, 'Main', 'Shel.Neos.WorkspaceModule'));
        $this->assignReviewResponse($workspace, $this->workspaceChangesService->findClosestDocument($nodeInstance), $dimensionValues);
    }

    /**
     * Discards a single change.
     */
    public function discardChangeAction(string $node, Workspace $workspace, string $dimensions = ''): void
    {
        $this->validateWorkspaceAccess($workspace);
        $dimensionValues = $this->assignDimensions($dimensions);
        $nodeInstance = $this->resolveNode($node);
        $this->workspacePublishingService->discardNodeWithItsChildren($nodeInstance);
        $this->addFlashMessage((string)$this->translator->translateById('review.changeDiscarded', [], null, null, 'Main', 'Shel.Neos.WorkspaceModule'));
        $this->assignReviewResponse($workspace, $this->workspaceChangesService->findClosestDocument($nodeInstance), $dimensionValues);
    }

    /**
     * Publishes all the changes of a document.
     */
    public function publishDocumentAction(Workspace $workspace, string $document = '', string $dimensions = ''): void
    {
        $this->validateWorkspaceAccess($workspace);
        $dimensionValues = $this->assignDimensions($dimensions);
        $documentNode = $this->workspaceChangesService->findDocumentInChanges($workspace, $document, $dimensionValues);
        $nodes = $documentNode === null
            ? []
            : $this->workspaceChangesService->getChangesOfDocument($workspace, $documentNode, $dimensionValues);
        $this->workspacePublishingService->publishNodesWithTheirAncestors($nodes);
        $this->addFlashMessage((string)$this->translator->translateById('review.documentPublished', [], null, null, 'Main', 'Shel.Neos.WorkspaceModule'));
        $this->assignReviewResponse($workspace, $documentNode, $dimensionValues);
    }

    /**
     * Discards all the changes of a document.
     */
    public function discardDocumentAction(Workspace $workspace, string $document = '', string $dimensions = ''): void
    {
        $this->validateWorkspaceAccess($workspace);
        $dimensionValues = $this->assignDimensions($dimensions);
        $documentNode = $this->workspaceChangesService->findDocumentInChanges($workspace, $document, $dimensionValues);
        $this->discardChanges($documentNode === null
            ? []
            : $this->workspaceChangesService->getChangesOfDocument($workspace, $documentNode, $dimensionValues));
        $this->addFlashMessage((string)$this->translator->translateById('review.documentDiscarded', [], null, null, 'Main', 'Shel.Neos.WorkspaceModule'));
        $this->assignReviewResponse($workspace, $documentNode, $dimensionValues);
    }

    /**
     * Publishes the selected changes.
     *
     * The selection can hold single changes and whole documents: a document stands for all the
     * changes it has, so that the changes of several documents can be published at once.
     *
     * Without a selection the changes of the document that is open are published - the button
     * of the review acts on the document then.
     */
    public function publishSelectedAction(Workspace $workspace, string $dimensions = ''): void
    {
        $this->validateWorkspaceAccess($workspace);
        $dimensionValues = $this->assignDimensions($dimensions);
        $selection = $this->selectionFromRequest();
        $selectedNodes = [];
        foreach ($selection['nodes'] as $node) {
            $selectedNodes[] = $this->resolveNode($node);
        }
        foreach ($selection['documents'] as $document) {
            foreach ($this->changesOfDocument($workspace, $document, $dimensionValues) as $change) {
                $selectedNodes[] = $change;
            }
        }
        if ($selectedNodes === []) {
            // nothing is selected - the changes of the document that is open are published
            $selectedNodes = $this->changesOfDocument($workspace, $selection['document'], $dimensionValues);
        }
        $this->workspacePublishingService->publishNodesWithTheirAncestors($selectedNodes);
        $this->addFlashMessage((string)$this->translator->translateById('review.changesPublished', [], null, null, 'Main', 'Shel.Neos.WorkspaceModule'));
        $this->assignReviewResponse($workspace, $this->documentThatStaysOpen($workspace, $selection['document']), $dimensionValues);
    }

    /**
     * Discards the selected changes.
     */
    public function discardSelectedAction(Workspace $workspace, string $dimensions = ''): void
    {
        $this->validateWorkspaceAccess($workspace);
        $dimensionValues = $this->assignDimensions($dimensions);
        $selection = $this->selectionFromRequest();
        $changes = [];
        foreach ($selection['nodes'] as $node) {
            $changes[] = $this->resolveNode($node);
        }
        foreach ($selection['documents'] as $document) {
            foreach ($this->changesOfDocument($workspace, $document, $dimensionValues) as $change) {
                $changes[] = $change;
            }
        }
        if ($changes === []) {
            // nothing is selected - the changes of the document that is open are discarded
            $changes = $this->changesOfDocument($workspace, $selection['document'], $dimensionValues);
        }
        $this->discardChanges($changes);
        $this->addFlashMessage((string)$this->translator->translateById('review.changesDiscarded', [], null, null, 'Main', 'Shel.Neos.WorkspaceModule'));
        $this->assignReviewResponse($workspace, $this->documentThatStaysOpen($workspace, $selection['document']), $dimensionValues);
    }

    /**
     * The selection of the review: the changes and the documents that are selected, and the
     * document that is open.
     *
     * The selection travels in the hidden fields of the review, in the namespace of the module
     * arguments - the arguments of a module action are read from there.
     *
     * @return array{nodes: list<string>, documents: list<string>, document: string}
     */
    private function selectionFromRequest(): array
    {
        $arguments = $this->request->getArguments();
        $strings = static function (mixed $value): array {
            if (!is_array($value)) {
                return [];
            }

            return array_values(array_filter($value, 'is_string'));
        };

        return [
            'nodes' => $strings($arguments['nodes'] ?? []),
            'documents' => $strings($arguments['documents'] ?? []),
            'document' => (string)($arguments['document'] ?? ''),
        ];
    }

    /**
     * The changes of a document, addressed by its context path.
     *
     * @param array<string, list<string>> $dimensionValues the variants to show, empty for all
     * @return list<NodeInterface>
     */
    private function changesOfDocument(Workspace $workspace, string $documentContextPath, array $dimensionValues): array
    {
        if ($documentContextPath === '') {
            return [];
        }
        $documentNode = $this->resolveNode($documentContextPath);

        return $this->workspaceChangesService->getChangesOfDocument($workspace, $documentNode, $dimensionValues);
    }

    /**
     * Discards a list of changes, without leaving orphans behind.
     *
     * The deepest change comes first: a node that was created in the workspace is removed
     * together with its children, and a change that is gone already must not be discarded
     * again.
     *
     * @param list<NodeInterface> $changes
     */
    private function discardChanges(array $changes): void
    {
        $this->workspacePublishingService->discardNodesWithTheirChildren($changes);
    }

    /**
     * The document that stays open after an action.
     *
     * A selection can span several documents. The document that was open stays open when it
     * still has changes, so that the review does not jump away under the reviewer.
     */
    private function documentThatStaysOpen(Workspace $workspace, string $documentContextPath): ?NodeInterface
    {
        if ($documentContextPath === '') {
            return null;
        }

        try {
            return $this->resolveNode($documentContextPath);
        } catch (\Exception) {
            // The document is gone - it was published or discarded with the selection.
            return null;
        }
    }

    /**
     * Publishes all changes of the workspace.
     */
    public function publishAllAction(Workspace $workspace, string $dimensions = ''): void
    {
        $this->validateWorkspaceAccess($workspace);
        $dimensionValues = $this->assignDimensions($dimensions);
        $targetWorkspace = $workspace->getBaseWorkspace();
        if ($targetWorkspace === null) {
            throw new \RuntimeException('The workspace ' . $workspace->getName() . ' has no base workspace to publish to.');
        }
        $this->publishingService->publishNodes(
            $this->publishingService->getUnpublishedNodes($workspace),
            $targetWorkspace
        );
        $this->addFlashMessage((string)$this->translator->translateById('review.allChangesPublished', [], null, null, 'Main', 'Shel.Neos.WorkspaceModule'));
        $this->assignReviewResponse($workspace, null, $dimensionValues);
    }

    /**
     * Discards all changes of the workspace.
     */
    public function discardAllAction(Workspace $workspace, string $dimensions = ''): void
    {
        $this->validateWorkspaceAccess($workspace);
        $dimensionValues = $this->assignDimensions($dimensions);
        $this->publishingService->discardNodes($this->publishingService->getUnpublishedNodes($workspace));
        $this->addFlashMessage((string)$this->translator->translateById('review.allChangesDiscarded', [], null, null, 'Main', 'Shel.Neos.WorkspaceModule'));
        $this->assignReviewResponse($workspace, null, $dimensionValues);
    }

    /**
     * The answer of an action that changed something: the list of documents and the changes of
     * the document that was open, both refreshed.
     *
     * @param array<string, list<string>> $dimensionValues the variants that are shown
     */
    private function assignReviewResponse(Workspace $workspace, NodeInterface|null $document = null, array $dimensionValues = []): void
    {
        $this->workspaceChangesService->clearCache();
        // A published or discarded node is only removed from the database when the request
        // ends. The answer shows the workspace as it is after the change, so the pending
        // changes have to be written before the changes are calculated again.
        $this->persistenceManager->persistAll();
        // The answer holds the changes of the document and the list of documents, so that the
        // tree is refreshed together with the changes: a document whose last change was
        // published or discarded leaves the list.
        $changesOfDocument = $document === null
            ? []
            : $this->workspaceChangesService->getChangesOfDocument($workspace, $document, $dimensionValues);
        $changes = $document === null
            ? ChangeItems::fromArray([])
            : $this->workspaceChangesService->getChangesForDocument($workspace, $document, $dimensionValues);
        if ($document !== null && count($changes) === 0) {
            // The document has no change any more - it is not in the list of the documents
            // with changes, so it cannot be the open one.
            $document = null;
        }
        $documentItem = $document === null
            ? null
            : $this->documentDescriptionService->describeDocument($document, $changesOfDocument);
        $this->view->assignMultiple([
            'selectedWorkspace' => $workspace,
            'selectedWorkspaceName' => $workspace->getName(),
            'sites' => $this->workspaceChangesService->getDocumentsWithChanges($workspace, $dimensionValues),
            'selectedDocument' => $document?->getContextPath() ?? '',
            'changes' => $changes,
            'document' => $documentItem,
            'baseWorkspaceLabel' => $this->baseWorkspaceLabel($workspace),
            'canPublishToBaseWorkspace' => $this->canPublishToBaseWorkspace($workspace),
        ]);
    }

    /**
     * The content dimensions of the review: the presets that can be chosen and the values the
     * changes are filtered with.
     *
     * The selection is a list of "dimension:preset" pairs in the url of the review, so that it
     * can be shared and survives a reload.
     *
     * @return array<string, list<string>> the dimension values of the selected presets
     */
    private function assignDimensions(string $selection): array
    {
        $selected = $this->contentDimensionService->parseDimensionSelection($selection);
        $this->view->assignMultiple([
            'contentDimensions' => $this->contentDimensionService->getDimensionConfiguration($selected),
            'selectedDimensions' => $selection,
        ]);

        return $this->contentDimensionService->getDimensionValues($selected);
    }

    /**
     * Resolves the node of a change from its context path.
     *
     * The node can be a removed one - a deleted page is a change like any other - and the
     * property mapper only resolves those when it is told to show removed content, the same
     * way the core does it before it publishes or discards nodes.
     */
    private function resolveNode(string $contextPath): NodeInterface
    {
        $propertyMappingConfiguration = $this->propertyMapper->buildPropertyMappingConfiguration();
        $propertyMappingConfiguration->setTypeConverterOption(
            NodeConverter::class,
            NodeConverter::REMOVED_CONTENT_SHOWN,
            true
        );
        $node = $this->propertyMapper->convert($contextPath, NodeInterface::class, $propertyMappingConfiguration);
        if (!$node instanceof NodeInterface) {
            throw new \RuntimeException('Could not resolve the node "' . $contextPath . '".');
        }

        return $node;
    }

    /**
     * Whether the current user may publish the changes of the workspace into its base workspace.
     */
    private function canPublishToBaseWorkspace(Workspace $workspace): bool
    {
        $baseWorkspace = $workspace->getBaseWorkspace();

        return $baseWorkspace !== null && $this->userService->currentUserCanPublishToWorkspace($baseWorkspace);
    }

    protected function getUserId(User $user): string
    {
        return $this->persistenceManager->getIdentifierByObject($user);
    }

    protected function validateWorkspaceAccess(?Workspace $workspace = null): void
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

    /**
     * Checks whether the current user can access the given workspace.
     * The check via the `userService` is modified via an aspect to allow access to the workspace if the
     * workspace is specifically allowed for the user.
     */
    protected function userCanAccessWorkspace(Workspace $workspace): bool
    {
        return $workspace->getName() !== 'live' && ($workspace->isInternalWorkspace(
                ) || $this->userService->currentUserCanReadWorkspace($workspace));
    }

    private function userCanManageInternalWorkspaces(): bool
    {
        return $this->privilegeManager->isPrivilegeTargetGranted(
            'Neos.Neos:Backend.Module.Management.Workspaces.ManageInternalWorkspaces'
        );
    }

    private function userCanCreateWorkspaces(): bool
    {
        return $this->privilegeManager->isPrivilegeTargetGranted(
            'Neos.Neos:Backend.CreateWorkspaces'
        );
    }

    /**
     * The identifier of the first account of a user.
     *
     * The accounts of a user are a collection or an array - depending on how the user was
     * loaded - so both have to be handled.
     */
    private function firstAccountIdentifierOf(User $user): ?string
    {
        $accounts = $user->getAccounts();
        $account = is_array($accounts) ? ($accounts[0] ?? null) : $accounts->first();
        if (!$account instanceof Account) {
            return null;
        }

        return $account->getAccountIdentifier();
    }

    /**
     * The translation of a label of the core.
     *
     * @param array<string, string|int> $arguments
     */
    protected function translateById(string $id, array $arguments = []): string
    {
        return $this->translator->translateById(
            $id,
            $arguments,
            null,
            null,
            'Main',
            'Shel.Neos.WorkspaceModule'
        ) ?? $id;
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

    protected function canManageWorkspace(Workspace $workspace): bool
    {
        // Workaround to make personal workspaces with missing owners manageable
        if ($workspace->isPersonalWorkspace() && !$workspace->getOwner()) {
            return $this->privilegeManager->isPrivilegeTargetGranted('Neos.Neos:Backend.Module.Management.Workspaces.ManageAllPrivateWorkspaces');
        }
        return $this->userService->currentUserCanManageWorkspace($workspace);
    }

}
