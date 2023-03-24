<?php

declare(strict_types=1);

namespace Shel\Neos\WorkspaceModule\Domain\Repository;

/**
 * This file is part of the Shel.Neos.WorkspaceModule package.
 *
 * (c) 2022 Sebastian Helzle
 *
 * This package is Open Source Software. For the full copyright and license
 * information, please view the LICENSE file which was distributed with this
 * source code.
 */

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query\ResultSetMapping;
use Neos\ContentRepository\Domain\Model\Workspace;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\Persistence\QueryResultInterface;
use Neos\Flow\Persistence\Repository;
use Neos\Neos\Domain\Model\User;
use Shel\Neos\WorkspaceModule\Domain\Model\WorkspaceDetails;

/**
 * @method WorkspaceDetails findOneByWorkspace(Workspace $workspace)
 * @method QueryResultInterface<WorkspaceDetails> findAll()
 *
 * @Flow\Scope("singleton")
 */
class WorkspaceDetailsRepository extends Repository
{
    /**
     * @Flow\Inject
     * @var EntityManagerInterface
     */
    protected $entityManager;

    /**
     * @return string[] The names of all workspaces shared with the given user
     */
    public function findAllowedWorkspaceNamesForUser(User $user): array
    {
        // Prepare raw query
        $rsm = new ResultSetMapping();
        $rsm->addScalarResult('workspace', 'workspace');

        // Find all workspaces shared with the given user with one query
        $queryString = '
            SELECT d.workspace FROM shel_neos_workspacemodule_domain_model_workspacedetails d
                JOIN shel_neos_workspacemodule_domain_model_workspace_1536f_acl_join a
                             ON d.persistence_object_identifier = a.workspacemodule_workspacedetails
            WHERE a.neos_user = ?
        ';
        $query = $this->entityManager->createNativeQuery($queryString, $rsm);
        $query->setParameter(1, $user);
        return $query->getSingleColumnResult();
    }
}
