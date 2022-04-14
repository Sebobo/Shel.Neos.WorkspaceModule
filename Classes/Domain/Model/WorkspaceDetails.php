<?php
declare(strict_types=1);

namespace Shel\Neos\WorkspaceModule\Domain\Model;

use Neos\Flow\Annotations as Flow;

/**
 * @Flow\Entity
 */
class WorkspaceDetails
{
    /**
     * @var \DateTime | null
     */
    protected $lastChangedDate;

    /**
     * @var string | null
     */
    protected $lastChangedBy;

    /**
     * @var string
     */
    protected $workspaceName;

    public function __construct(string $workspaceName, \DateTime $lastChangedDate = null, string $lastChangedBy = null)
    {
        $this->workspaceName = $workspaceName;
        $this->lastChangedDate = $lastChangedDate;
        $this->lastChangedBy = $lastChangedBy;
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

}
