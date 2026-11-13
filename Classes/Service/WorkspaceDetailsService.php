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

use Neos\ContentRepository\Domain\Model\Workspace;
use Neos\Flow\Annotations as Flow;
use Shel\Neos\WorkspaceModule\Domain\Model\WorkspaceDetails;
use Shel\Neos\WorkspaceModule\Domain\Repository\WorkspaceDetailsRepository;

/**
 * Maintains the details of the workspaces: who created a workspace, and who changed it
 * last.
 */
#[Flow\Scope('singleton')]
class WorkspaceDetailsService
{
    #[Flow\Inject]
    protected WorkspaceDetailsRepository $workspaceDetailsRepository;

    #[Flow\Inject]
    protected WorkspaceActorResolver $workspaceActorResolver;

    /**
     * The details of the workspaces of this request, by the name of the workspace.
     *
     * The details are created but not written to the database before the end of the
     * request, so a lookup in the database would not find them.
     *
     * @var array<string, WorkspaceDetails>
     */
    protected array $detailsByWorkspaceName = [];

    /**
     * The details of the workspace, created if they do not exist yet.
     */
    public function getOrCreate(Workspace $workspace): WorkspaceDetails
    {
        $workspaceName = $workspace->getName();
        $details = $this->detailsByWorkspaceName[$workspaceName] ?? null;
        if ($details instanceof WorkspaceDetails) {
            return $details;
        }

        $details = $this->workspaceDetailsRepository->findOneByWorkspace($workspace);
        if (!$details instanceof WorkspaceDetails) {
            $details = new WorkspaceDetails($workspace);
            $this->workspaceDetailsRepository->add($details);
        }

        $this->detailsByWorkspaceName[$workspaceName] = $details;

        return $details;
    }

    /**
     * Records who created the workspace.
     *
     * The creator is only recorded once: the workspace keeps the creator it had, even if
     * somebody else works with it later.
     */
    public function applyCreator(Workspace $workspace): void
    {
        $details = $this->getOrCreate($workspace);
        if ($details->getCreator() !== null) {
            return;
        }

        $username = $this->workspaceActorResolver->resolveUsername();
        if ($username === null) {
            return;
        }

        $details->setCreator($username);
        $details->setLastChangedBy($username);
        $this->workspaceDetailsRepository->update($details);
    }

    /**
     * Records who changed the workspace last.
     *
     * The name of the actor is the name of the Neos user who acts. If the actor cannot be
     * resolved to a name, the creator of the workspace is kept: it is the name of a user
     * as well, and it is the closest that is known.
     */
    public function applyLastChange(Workspace $workspace): void
    {
        $details = $this->getOrCreate($workspace);

        $username = $this->workspaceActorResolver->resolveUsername() ?? $details->getCreator();
        if ($username === null) {
            return;
        }

        $details->setLastChangedDate(new \DateTime());
        $details->setLastChangedBy($username);
        $this->workspaceDetailsRepository->update($details);
    }
}
