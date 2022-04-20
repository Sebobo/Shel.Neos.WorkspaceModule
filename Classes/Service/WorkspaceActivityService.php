<?php
declare(strict_types=1);

namespace Shel\Neos\WorkspaceModule\Service;

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
use Neos\ContentRepository\Domain\Model\NodeInterface;
use Neos\ContentRepository\Domain\Model\Workspace;
use Neos\Flow\Persistence\PersistenceManagerInterface;
use Neos\Flow\Security\Context;
use Psr\Log\LoggerInterface;
use Shel\Neos\WorkspaceModule\Domain\Model\WorkspaceDetails;
use Shel\Neos\WorkspaceModule\Domain\Repository\WorkspaceDetailsRepository;

/**
 * @Flow\Scope("singleton")
 */
class WorkspaceActivityService
{
    /**
     * @Flow\Inject
     * @var WorkspaceDetailsRepository
     */
    protected $workspaceDetailsRepository;

    /**
     * @Flow\Inject
     * @var Context
     */
    protected $securityContext;

    /**
     * @var array<string, boolean>
     */
    protected $updatedWorkspaces = [];

    /**
     * @Flow\Inject
     * @var LoggerInterface
     */
    protected $systemLogger;

    /**
     * @Flow\Inject
     * @var PersistenceManagerInterface
     */
    protected $persistenceManager;

    public function nodePublished(NodeInterface $node, Workspace $targetWorkspace = null): void
    {
        if (!$targetWorkspace) {
            return;
        }
        $this->updatedWorkspaces[$targetWorkspace->getName()] = true;
    }

    public function nodeDiscarded(NodeInterface $node): void
    {
        $this->updatedWorkspaces[$node->getWorkspace()->getName()] = true;
    }

    public function shutdownObject(): void
    {
        $currentUser = $this->securityContext->getAccount()->getAccountIdentifier();

        foreach (array_keys($this->updatedWorkspaces) as $updatedWorkspace) {
            $workspaceDetails = $this->workspaceDetailsRepository->findOneByWorkspaceName($updatedWorkspace);

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
