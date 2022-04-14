<?php
declare(strict_types=1);

namespace Shel\Neos\WorkspaceModule;

use Neos\ContentRepository\Domain\Model\Workspace;
use Neos\Flow\Core\Bootstrap;
use Neos\Flow\Package\Package as BasePackage;
use Shel\Neos\WorkspaceModule\Service\WorkspaceActivityService;

class Package extends BasePackage
{
    public function boot(Bootstrap $bootstrap): void
    {
        $dispatcher = $bootstrap->getSignalSlotDispatcher();

        $dispatcher->connect(
            Workspace::class,
            'afterNodePublishing',
            WorkspaceActivityService::class,
            'afterNodePublishing'
        );
    }
}
