<?php
declare(strict_types=1);

namespace Shel\Neos\WorkspaceModule\Service;

use Neos\Flow\Annotations as Flow;
use Neos\ContentRepository\Domain\Model\NodeInterface;
use Neos\ContentRepository\Domain\Model\Workspace;
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
     * @var LoggerInterface
     */
    protected $logger;

    public function afterNodePublishing(NodeInterface $node, Workspace $targetWorkspace): void
    {
        $this->logger->debug('afterNodePublishing', ['node' => $node, 'targetWorkspace' => $targetWorkspace]);
        $this->updatedWorkspaces[$targetWorkspace->getName()] = true;
    }

    public function shutdownObject(): void
    {
        $this->logger->debug('shutdown workspace activity', [$this->updatedWorkspaces]);

        //$currentUser = $this->securityContext->getAccount()->getAccountIdentifier();
        //
        //foreach ($this->updatedWorkspaces as $updatedWorkspace) {
        //    $workspaceDetails = $this->workspaceDetailsRepository->findOneByWorkspaceName($updatedWorkspace);
        //
        //    if ($workspaceDetails) {
        //        $workspaceDetails->setLastChangedDate(new \DateTime());
        //        $this->workspaceDetailsRepository->update($workspaceDetails);
        //    } else {
        //        $workspaceDetails = new WorkspaceDetails($updatedWorkspace, new \DateTime(), $currentUser);
        //        $this->workspaceDetailsRepository->add($workspaceDetails);
        //    }
        //}
    }
}
