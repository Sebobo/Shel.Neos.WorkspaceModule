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

use Neos\ContentRepository\Domain\Model\Workspace;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\Persistence\QueryResultInterface;
use Neos\Flow\Persistence\Repository;
use Shel\Neos\WorkspaceModule\Domain\Model\WorkspaceDetails;

/**
 * @method WorkspaceDetails findOneByWorkspace(Workspace $workspace)
 * @method QueryResultInterface findAll()
 *
 * @Flow\Scope("singleton")
 */
class WorkspaceDetailsRepository extends Repository
{
}
