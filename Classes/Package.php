<?php
declare(strict_types=1);

namespace Shel\Neos\WorkspaceModule;

/**
 * This file is part of the Shel.Neos.WorkspaceModule package.
 *
 * (c) 2022 Sebastian Helzle
 *
 * This package is Open Source Software. For the full copyright and license
 * information, please view the LICENSE file which was distributed with this
 * source code.
 */

use Neos\Flow\Core\Bootstrap;
use Neos\Flow\Package\Package as BasePackage;
//use Neos\Neos\Service\PublishingService;
use Shel\Neos\WorkspaceModule\Service\WorkspaceActivityService;

class Package extends BasePackage
{
    public function boot(Bootstrap $bootstrap): void
    {
        // TODO: Cleanup. This doesn't work anymore. I guess the workspace projection should be extended if more details should be known about the workspace.
//        $dispatcher = $bootstrap->getSignalSlotDispatcher();

//        $dispatcher->connect(
//            PublishingService::class,
//            'nodePublished',
//            WorkspaceActivityService::class,
//            'nodePublished'
//        );
//
//        $dispatcher->connect(
//            PublishingService::class,
//            'nodeDiscarded',
//            WorkspaceActivityService::class,
//            'nodeDiscarded'
//        );
    }
}
