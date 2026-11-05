<?php
declare(strict_types=1);

namespace Shel\Neos\WorkspaceModule\Service;

/**
 * This file is part of the Shel.Neos.WorkspaceModule package.
 *
 * This package is Open Source Software. For the full copyright and license
 * information, please view the LICENSE file which was distributed with this
 * source code.
 */

use Neos\ContentRepository\Domain\Repository\WorkspaceRepository;
use Neos\Flow\Annotations as Flow;
use Neos\ContentRepository\Domain\Model\Node;
use Neos\ContentRepository\Domain\Model\NodeInterface;
use Neos\ContentRepository\Domain\Model\Workspace;
use Neos\ContentRepository\Domain\Service\PublishingService;
use Neos\Flow\Persistence\PersistenceManagerInterface;
use Neos\Flow\Security\Context;
use Neos\Neos\Domain\Model\User;
use Neos\Neos\Domain\Service\UserService;
use Neos\Neos\EventLog\Domain\Model\NodeEvent;
use Neos\Neos\EventLog\Domain\Repository\EventRepository;
use Psr\Log\LoggerInterface;
use Shel\Neos\WorkspaceModule\Domain\Model\WorkspaceDetails;
use Shel\Neos\WorkspaceModule\Domain\Repository\WorkspaceDetailsRepository;
use Shel\Neos\WorkspaceModule\ViewModel\ActivityItem;

#[Flow\Scope('singleton')]
class WorkspaceActivityService
{
    #[Flow\Inject]
    protected WorkspaceDetailsRepository $workspaceDetailsRepository;

    #[Flow\Inject]
    protected Context $securityContext;

    /**
     * The workspaces that were touched, by their name.
     *
     * @var array<string, Workspace>
     */
    protected array $updatedWorkspaces = [];

    #[Flow\Inject]
    protected LoggerInterface $systemLogger;

    #[Flow\Inject]
    protected PersistenceManagerInterface $persistenceManager;

    #[Flow\Inject]
    protected WorkspaceRepository $workspaceRepository;

    #[Flow\Inject]
    protected UserService $userService;

    #[Flow\Inject]
    protected PublishingService $publishingService;

    #[Flow\Inject]
    protected EventRepository $eventRepository;

    #[Flow\Inject]
    protected RelativeDateService $relativeDateService;

    public function nodePublished(NodeInterface $node, ?Workspace $targetWorkspace = null): void
    {
        if (!$targetWorkspace) {
            return;
        }
        $this->updatedWorkspaces[$targetWorkspace->getName()] = $targetWorkspace;
    }

    public function nodeDiscarded(NodeInterface $node): void
    {
        $this->updatedWorkspaces[$node->getWorkspace()->getName()] = $node->getWorkspace();
    }

    /**
     * The last change of a workspace: the newest of the unpublished nodes, and the one who
     * made it, from the newest event of the workspace in the log of content events.
     *
     * The author is unknown when the log of events of the installation is switched off.
     */
    public function lastChangeOf(Workspace $workspace): ?ActivityItem
    {
        $dateTime = null;
        foreach ($this->publishingService->getUnpublishedNodes($workspace) as $node) {
            $lastModificationDateTime = $node instanceof Node
                ? $node->getLastModificationDateTime()
                : null;
            if ($lastModificationDateTime !== null
                && ($dateTime === null || $lastModificationDateTime > $dateTime)
            ) {
                $dateTime = $lastModificationDateTime;
            }
        }
        if ($dateTime === null) {
            return null;
        }

        $query = $this->eventRepository->createQuery();
        /** @var NodeEvent|null $event */
        $event = $query
            ->matching(
                $query->logicalAnd([
                    $query->equals('workspaceName', $workspace->getName()),
                    $query->equals('eventType', 'Node.Updated'),
                    $query->equals('parentEvent', null),
                ])
            )
            ->setOrderings(['uid' => $query::ORDER_DESCENDING])
            ->setLimit(1)
            ->execute()
            ->getFirst();

        $author = null;
        if ($event !== null) {
            $author = $this->userService->getUser($event->getAccountIdentifier());
        }

        return new ActivityItem(
            dateTime: $dateTime,
            relativeLabel: $this->relativeDateService->label($dateTime),
            author: $author?->getLabel(),
        );
    }

    public function shutdownObject(): void
    {
        $currentUser = $this->securityContext->getAccount()->getAccountIdentifier();

        foreach ($this->updatedWorkspaces as $updatedWorkspace) {
            $workspaceDetails = $this->workspaceDetailsRepository->findOneByWorkspace($updatedWorkspace);

            if ($workspaceDetails) {
                $workspaceDetails->setLastChangedDate(new \DateTime());
                $workspaceDetails->setLastChangedBy($currentUser);
                $this->workspaceDetailsRepository->update($workspaceDetails);
            } else {
                $workspaceDetails = new WorkspaceDetails($updatedWorkspace, null, new \DateTime(), $currentUser);
                $this->workspaceDetailsRepository->add($workspaceDetails);
            }
        }

        $this->persistenceManager->persistAll();
    }
}
