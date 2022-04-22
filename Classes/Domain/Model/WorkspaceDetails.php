<?php
declare(strict_types=1);

namespace Shel\Neos\WorkspaceModule\Domain\Model;

/**
 * This file is part of the Shel.Neos.WorkspaceModule package.
 *
 * (c) 2022 Sebastian Helzle
 *
 * This package is Open Source Software. For the full copyright and license
 * information, please view the LICENSE file which was distributed with this
 * source code.
 */

use Doctrine\ORM\Mapping as ORM;
use Neos\Flow\Annotations as Flow;

/**
 * @Flow\Entity
 */
class WorkspaceDetails
{
    /**
     * @ORM\Column(nullable=true)
     * @var \DateTime | null
     */
    protected $lastChangedDate;

    /**
     * @ORM\Column(nullable=true)
     * @var string | null
     */
    protected $lastChangedBy;

    /**
     * @var string
     */
    protected $workspaceName;

    /**
     * @ORM\Column(nullable=true)
     * @var string | null
     */
    protected $creator;

    public function __construct(string $workspaceName, string $creator = null, \DateTime $lastChangedDate = null, string $lastChangedBy = null)
    {
        $this->workspaceName = $workspaceName;
        $this->creator = $creator;
        $this->lastChangedDate = $lastChangedDate ?? new \DateTime();
        $this->lastChangedBy = $lastChangedBy ?? $creator;
    }

    public function getLastChangedDate(): ?\DateTime
    {
        return $this->lastChangedDate;
    }

    public function setLastChangedDate(?\DateTime $lastChangedDate): void
    {
        $this->lastChangedDate = $lastChangedDate;
    }

    public function getLastChangedBy(): ?string
    {
        return $this->lastChangedBy;
    }

    public function setLastChangedBy(?string $lastChangedBy): void
    {
        $this->lastChangedBy = $lastChangedBy;
    }

    public function getWorkspaceName(): string
    {
        return $this->workspaceName;
    }

    public function getCreator(): ?string
    {
        return $this->creator;
    }

    public function setCreator(?string $creator): void
    {
        $this->creator = $creator;
    }

}
