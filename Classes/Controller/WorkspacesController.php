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
use Neos\ContentRepository\Domain\Model\Workspace;
use Neos\ContentRepository\Domain\Repository\WorkspaceRepository;
use Neos\ContentRepository\TypeConverter\NodeConverter;
use Neos\ContentRepository\Utility;
use Neos\Error\Messages\Message;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\Mvc\View\JsonView;
use Neos\Flow\Persistence\Exception\IllegalObjectTypeException;
use Neos\Flow\Property\Exception as PropertyException;
use Neos\Flow\Property\TypeConverter\PersistentObjectConverter;
use Neos\Flow\Security\Authorization\PrivilegeManagerInterface;
use Neos\Flow\Security\Exception as SecurityException;
use Neos\Neos\Domain\Model\User;
use Neos\Neos\Domain\Repository\UserRepository;
use Neos\Neos\Domain\Service\UserService;
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

    /**
     * @Flow\Inject
     * @var WorkspaceRepository
     */
    protected $workspaceRepository;

    /**
     * @Flow\Inject
     * @var UserService
     */
    protected $userService;

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
     * @Flow\SkipCsrfProtection
     */
    public function deleteAction(Workspace $workspace): void
    {
        $success = false;
        /** @var Workspace[] $rebasedWorkspaces */
        $rebasedWorkspaces = [];

        if ($workspace->isPersonalWorkspace() && $workspace->getOwner() !== null) {
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
                    $this->translateById(
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
                $this->translateById(
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
     * @Flow\SkipCsrfProtection
     */
    public function pruneAction(Workspace $workspace): void
    {
        $success = false;
        $unpublishedNodes = $this->publishingService->getUnpublishedNodes($workspace);

        if (count($unpublishedNodes) > 0 && $this->userService->currentUserCanPublishToWorkspace($workspace)) {
            try {
                $this->publishingService->discardNodes($unpublishedNodes);

                $this->addFlashMessage(
                    $this->translateById(
                        'message.workspacePruned',
                        ['workspaceName' => $workspace->getTitle(), 'nodeCount' => count($unpublishedNodes)]
                    ),
                );

                // Persist the workspace and related data or the generated workspace info will be incomplete
                $this->persistenceManager->persistAll();
                $success = true;
            } catch (\Exception $e) {
                $this->addFlashMessage(
                    $this->translateById(
                        'error.pruneFailed',
                        ['workspaceName' => $workspace->getTitle()]
                    ),
                    '',
                    Message::SEVERITY_WARNING
                );
            }
        } else {
            $this->addFlashMessage(
                $this->translateById(
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

    protected function getWorkspaceInfo(Workspace $workspace): array
    {
        $workspaceDetails = $this->workspaceDetailsRepository->findOneByWorkspace($workspace);

        /** @var User $owner */
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
            $acl = $workspaceDetails->getAcl() ?? [];
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
            $this->addFlashMessage(
                $this->translator->translateById(
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
     */
    protected function prepareBaseWorkspaceOptions(Workspace $excludedWorkspace = null): array
    {
        $options = parent::prepareBaseWorkspaceOptions($excludedWorkspace);
        foreach (array_keys($options) as $workspaceName) {
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
                $this->translateById(
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
            $providedAcl = $this->request->hasArgument('acl') ? $this->request->getArgument('acl') ?? [] : [];
            $acl = $workspace->getOwner() ? $providedAcl : [];
            $allowedUsers = array_map(fn($userName) => $this->userRepository->findByIdentifier($userName), $acl);

            // Rebase users if they were using the workspace but lost access by the update
            $allowedAccounts = array_map(
                static fn(User $user) => (string)$user->getAccounts()->first()->getAccountIdentifier(),
                $allowedUsers
            );
            $liveWorkspace = $this->workspaceRepository->findByIdentifier('live');
            foreach ($workspaceDetails->getAcl() as $prevAcl) {
                $aclAccount = $prevAcl->getAccounts()->first()->getAccountIdentifier();
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
                    $this->translator->translateById(
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
                $this->publishingService->discardNodes($nodes);
                $this->addFlashMessage(
                    $this->translator->translateById(
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

    public function showAction(Workspace $workspace): void
    {
        $this->validateWorkspaceAccess($workspace);
        parent::showAction($workspace);
    }

    /**
     * Hands the review view the information it needs beyond the core's change list: whether the
     * node state itself changed, so the legend can say "hidden" only when the node was actually
     * hidden and not merely edited while being hidden.
     *
     * @return array<string,mixed>
     */
    protected function computeSiteChanges(Workspace $selectedWorkspace)
    {
        $siteChanges = parent::computeSiteChanges($selectedWorkspace);
        foreach ($siteChanges as $siteKey => $site) {
            foreach ($site['documents'] as $documentDimension => $documents) {
                foreach ($documents as $documentKey => $document) {
                    foreach ($document['changes'] as $changeKey => $change) {
                        $siteChanges[$siteKey]['documents'][$documentDimension][$documentKey]['changes'][$changeKey]['nodeStateChanged']
                            = $this->nodeStateDifferences($change['node']);
                    }
                }
            }
        }

        return $siteChanges;
    }

    /**
     * The core diff only renders string properties: a boolean and a number both render to an
     * empty string, so a change of such a property produced no diff at all and the review listed
     * the node without being able to say what had changed.
     *
     * @param mixed $propertyValue
     * @return string
     */
    protected function renderSlimmedDownContent($propertyValue)
    {
        if (is_int($propertyValue) || is_float($propertyValue)) {
            return (string)$propertyValue;
        }

        return parent::renderSlimmedDownContent($propertyValue);
    }

    /**
     * Extends the core's diff by what it cannot represent: boolean properties and the node state
     * itself (hidden, hidden in index and the two visibility dates).
     *
     * The core method is kept as it is and only added to, so that the text, image, asset and
     * datetime diffs stay the core's. The node state is not part of the property store at all -
     * getProperties() returns declared properties only, while the state lives in columns of its
     * own - which is why hiding a node produced an empty diff in the review.
     *
     * @return array<string,mixed>
     */
    protected function renderContentChanges(NodeInterface $changedNode)
    {
        $contentChanges = parent::renderContentChanges($changedNode);

        return $contentChanges
            + $this->renderBooleanPropertyChanges($changedNode, $contentChanges)
            + $this->renderNodeStateChanges($changedNode);
    }

    /**
     * Boolean properties the core skipped because both values render to an empty string.
     *
     * @param array<string,mixed> $alreadyRendered the changes the core has already produced
     * @return array<string,array<string,mixed>>
     */
    protected function renderBooleanPropertyChanges(NodeInterface $changedNode, array $alreadyRendered): array
    {
        if ($changedNode->isRemoved()) {
            return [];
        }

        /** @var NodeInterface|null $originalNode */
        $originalNode = $this->getOriginalNode($changedNode);
        $propertyDefaults = $changedNode->getNodeType()->getDefaultValuesForProperties();

        $booleanChanges = [];
        foreach ($changedNode->getProperties() as $propertyName => $changedValue) {
            if (isset($alreadyRendered[$propertyName])) {
                continue;
            }
            $originalValue = $originalNode === null ? null : $originalNode->getProperty($propertyName);
            if (!is_bool($changedValue) && !is_bool($originalValue)) {
                continue;
            }
            // The same conditions the core applies before it renders a property at all
            if ($originalNode === null && empty($changedValue)) {
                continue;
            }
            if (isset($propertyDefaults[$propertyName]) && $changedValue === $propertyDefaults[$propertyName]) {
                continue;
            }
            if ($changedValue === $originalValue) {
                continue;
            }
            $booleanChanges[$propertyName] = [
                'type' => 'boolean',
                'propertyLabel' => $this->getPropertyLabel($propertyName, $changedNode),
                'original' => $originalValue,
                'changed' => $changedValue,
            ];
        }

        return $booleanChanges;
    }

    /**
     * @return array<string,array<string,mixed>>
     */
    protected function renderNodeStateChanges(NodeInterface $changedNode): array
    {
        $stateChanges = [];
        foreach ($this->nodeStateDifferences($changedNode) as $stateName => $difference) {
            $isDate = $difference['changed'] instanceof \DateTimeInterface
                || $difference['original'] instanceof \DateTimeInterface;
            $stateChanges[$stateName] = [
                'type' => $isDate ? 'datetime' : 'boolean',
                'propertyLabel' => 'Shel.Neos.WorkspaceModule:Main:review.nodeState.' . $stateName,
                'original' => $difference['original'],
                'changed' => $difference['changed'],
            ];
        }

        return $stateChanges;
    }

    /**
     * The node state that differs from the published version, keyed by state name.
     *
     * @return array<string,array{original: mixed, changed: mixed}>
     */
    protected function nodeStateDifferences(NodeInterface $changedNode): array
    {
        if ($changedNode->isRemoved()) {
            return [];
        }

        /** @var NodeInterface|null $originalNode */
        $originalNode = $this->getOriginalNode($changedNode);
        if ($originalNode === null) {
            return [];
        }

        $differences = [];
        foreach (['hidden', 'hiddenInIndex'] as $stateName) {
            $changedValue = $this->readBooleanState($changedNode, $stateName);
            $originalValue = $this->readBooleanState($originalNode, $stateName);
            if ($changedValue === $originalValue) {
                continue;
            }
            $differences[$stateName] = ['original' => $originalValue, 'changed' => $changedValue];
        }
        foreach (['hiddenBeforeDateTime', 'hiddenAfterDateTime'] as $stateName) {
            $changedValue = $this->readDateState($changedNode, $stateName);
            $originalValue = $this->readDateState($originalNode, $stateName);
            if ($changedValue?->getTimestamp() === $originalValue?->getTimestamp()) {
                continue;
            }
            $differences[$stateName] = ['original' => $originalValue, 'changed' => $changedValue];
        }

        return $differences;
    }

    private function readBooleanState(NodeInterface $node, string $stateName): bool
    {
        return match ($stateName) {
            'hidden' => $node->isHidden(),
            'hiddenInIndex' => $node->isHiddenInIndex(),
            default => throw new \InvalidArgumentException('Not a boolean node state: ' . $stateName),
        };
    }

    private function readDateState(NodeInterface $node, string $stateName): ?\DateTimeInterface
    {
        return match ($stateName) {
            'hiddenBeforeDateTime' => $node->getHiddenBeforeDateTime(),
            'hiddenAfterDateTime' => $node->getHiddenAfterDateTime(),
            default => throw new \InvalidArgumentException('Not a date node state: ' . $stateName),
        };
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
