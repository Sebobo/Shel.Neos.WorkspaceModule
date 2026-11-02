<?php

/*
 * This file is part of the Shel.Neos.WorkspaceModule package.
 *
 * This package is Open Source Software. For the full copyright and license
 * information, please view the LICENSE file which was distributed with this
 * source code.
 */

declare(strict_types=1);

namespace Shel\Neos\WorkspaceModule\Tests\Functional;

use Neos\ContentRepository\Domain\Model\NodeInterface;
use PHPUnit\Framework\Attributes\Test;

class ContentTest extends WorkspaceTestCase
{
    #[Test]
    public function theLiveWorkspaceHasTheSiteWithTwoDocuments(): void
    {
        $home = $this->nodeInLiveWorkspace($this->sitePath('/home'));
        self::assertInstanceOf(NodeInterface::class, $home);
        self::assertSame('Home', $home->getProperty('title'));

        $aboutUs = $this->nodeInLiveWorkspace($this->sitePath('/about-us'));
        self::assertInstanceOf(NodeInterface::class, $aboutUs);
        self::assertSame('About us', $aboutUs->getProperty('title'));
    }

    #[Test]
    public function theTestWorkspaceHasThePublishedContent(): void
    {
        $home = $this->nodeInTestWorkspace($this->sitePath('/home'));
        self::assertInstanceOf(NodeInterface::class, $home);
        self::assertSame('Home', $home->getProperty('title'));
    }
}
