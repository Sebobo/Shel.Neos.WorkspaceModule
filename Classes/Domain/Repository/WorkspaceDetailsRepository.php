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
