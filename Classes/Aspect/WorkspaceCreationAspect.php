<?php

declare(strict_types=1);

namespace Shel\Neos\WorkspaceModule\Aspect;

/**
 * This file is part of the Shel.Neos.WorkspaceModule package.
 *
 * This package is Open Source Software. For the full copyright and license
 * information, please view the LICENSE file which was distributed with this
 * source code.
 */

use Neos\ContentRepository\Domain\Model\Workspace;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\Aop\JoinPointInterface;
use Shel\Neos\WorkspaceModule\Service\WorkspaceDetailsService;

/**
 * Records the creator of a workspace, however the workspace was created.
 *
 * The advice is on the repository and not on the controller of the backend module, so that
 * a workspace that is created on the command line or by an agent gets its details as well.
 */
#[Flow\Aspect]
class WorkspaceCreationAspect
{
    #[Flow\Inject]
    protected WorkspaceDetailsService $workspaceDetailsService;

    /**
     * Creates the details of a new workspace and records who created it.
     */
    #[Flow\Around("method(Neos\ContentRepository\Domain\Repository\WorkspaceRepository->add())")]
    public function createDetailsOfNewWorkspace(JoinPointInterface $joinPoint): void
    {
        $workspace = $joinPoint->getMethodArgument('object');

        $joinPoint->getAdviceChain()->proceed($joinPoint);

        if (!$workspace instanceof Workspace || !$this->needsDetails($workspace)) {
            return;
        }

        $this->workspaceDetailsService->applyCreator($workspace);
    }

    /**
     * Whether the workspace gets details.
     *
     * The live workspace is the root of the tree and belongs to nobody, and the personal
     * workspace of a user is created by the core for that user - neither of them is
     * something that was created here.
     */
    protected function needsDetails(Workspace $workspace): bool
    {
        if ($workspace->isPersonalWorkspace()) {
            return false;
        }

        return $workspace->getBaseWorkspace() !== null;
    }
}
