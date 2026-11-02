<?php

/*
 * This file is part of the Shel.Neos.WorkspaceModule package.
 *
 * This package is Open Source Software. For the full copyright and license
 * information, please view the LICENSE file which was distributed with this
 * source code.
 */

declare(strict_types=1);

namespace Shel\Neos\WorkspaceModule\Domain\Model;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Neos\ContentRepository\Domain\Model\Workspace;
use Neos\Flow\Annotations as Flow;
use Neos\Neos\Domain\Model\User;

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
     * @ORM\OneToOne
     * @ORM\JoinColumn(onDelete="CASCADE")
     * @Flow\Identity
     * @var Workspace
     */
    protected $workspace;

    /**
     * @ORM\Column(nullable=true)
     * @var string | null
     */
    protected $creator;

    /**
     * The users the workspace is shared with.
     *
     * The property holds a collection of the doctrine. The flow reads the type of the
     * property at runtime, and a union with a generic is not understood there - so the type
     * of the collection is the one of the doctrine here.
     *
     * @ORM\ManyToMany
     * @var ArrayCollection<User>|null
     */
    protected $acl = null;

    /**
     * @param ArrayCollection<User>|null $acl
     */
    public function __construct(
        Workspace $workspace,
        ?string $creator = null,
        ?\DateTime $lastChangedDate = null,
        ?string $lastChangedBy = null,
        ?ArrayCollection $acl = null
    ) {
        $this->workspace = $workspace;
        $this->creator = $creator;
        $this->lastChangedDate = $lastChangedDate ?? new \DateTime();
        $this->lastChangedBy = $lastChangedBy ?? $creator;
        $this->acl = $acl ?? new ArrayCollection();
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

    public function getWorkspace(): Workspace
    {
        return $this->workspace;
    }

    public function getCreator(): ?string
    {
        return $this->creator;
    }

    public function setCreator(?string $creator): void
    {
        $this->creator = $creator;
    }

    /**
     * @param list<User> $acl
     */
    public function setAcl(array $acl): void
    {
        $this->acl = new ArrayCollection($acl);
    }

    /**
     * @return list<User>
     */
    public function getAcl(): array
    {
        return $this->acl === null ? [] : array_values($this->acl->toArray());
    }
}
