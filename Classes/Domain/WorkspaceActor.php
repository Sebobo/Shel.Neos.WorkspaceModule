<?php

/*
 * This file is part of the Shel.Neos.WorkspaceModule package.
 *
 * This package is Open Source Software. For the full copyright and license
 * information, please view the LICENSE file which was distributed with this
 * source code.
 */

declare(strict_types=1);

namespace Shel\Neos\WorkspaceModule\Domain;

use Neos\Flow\Annotations as Flow;

/**
 * The identity of the actor who acts in the installation: a user of the backend, or a
 * machine - an agent, for example.
 */
#[Flow\Proxy(false)]
final class WorkspaceActor
{
    protected ?string $username = null;

    public function getUsername(): ?string
    {
        return $this->username;
    }

    public function setUsername(?string $username): void
    {
        $this->username = $username;
    }
}
