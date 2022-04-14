<?php
declare(strict_types=1);

namespace Shel\Neos\WorkspaceModule\Domain\Repository;

use Neos\Flow\Annotations as Flow;
use Neos\Flow\Persistence\QueryResultInterface;
use Neos\Flow\Persistence\Repository;
use Shel\Neos\WorkspaceModule\Domain\Model\WorkspaceDetails;

/**
 * @method WorkspaceDetails findOneByIdentifier(string $identifier)
 * @method WorkspaceDetails findOneByWorkspaceName(string $workspaceName)
 * @method QueryResultInterface findAll()
 *
 * @Flow\Scope("singleton")
 */
class WorkspaceDetailsRepository extends Repository
{
}
