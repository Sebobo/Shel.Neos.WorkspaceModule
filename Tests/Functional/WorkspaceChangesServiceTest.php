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
use Neos\ContentRepository\Domain\Service\ContentDimensionPresetSourceInterface;
use Neos\ContentRepository\Domain\Service\Context;
use PHPUnit\Framework\Attributes\Test;
use Shel\Neos\WorkspaceModule\Service\WorkspaceChangesService;

/**
 * The tests of the grouping of the unpublished changes by the document they belong to.
 */
class WorkspaceChangesServiceTest extends WorkspaceTestCase
{
    private WorkspaceChangesService $workspaceChangesService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->workspaceChangesService = $this->getObject(WorkspaceChangesService::class);
    }

    #[Test]
    public function aWorkspaceWithoutChangesHasNoDocuments(): void
    {
        $this->workspaceChangesService->clearCache();
        $documents = $this->workspaceChangesService->getDocumentsWithChanges($this->testWorkspace());

        self::assertSame([], $documents);
    }

    #[Test]
    public function theDocumentWithAChangeIsInTheList(): void
    {
        $home = $this->nodeInTestWorkspace($this->sitePath('/home'));
        self::assertInstanceOf(NodeInterface::class, $home);
        $home->setProperty('title', 'A new title');
        $this->persistenceManager->persistAll();
        $this->workspaceChangesService->clearCache();

        $documents = $this->workspaceChangesService->getDocumentsWithChanges($this->testWorkspace());

        self::assertCount(1, $documents);
        $site = array_values($documents)[0];
        self::assertSame($this->siteNodeName, $site['siteNodeName']);
        self::assertCount(1, $site['documents']);
        self::assertSame('A new title', $site['documents'][0]->label);
    }

    #[Test]
    public function onlyTheDocumentsWithChangesAreInTheList(): void
    {
        $home = $this->nodeInTestWorkspace($this->sitePath('/home'));
        self::assertInstanceOf(NodeInterface::class, $home);
        $home->setProperty('title', 'A new title');
        $this->persistenceManager->persistAll();
        $this->workspaceChangesService->clearCache();

        $documents = $this->workspaceChangesService->getDocumentsWithChanges($this->testWorkspace());

        self::assertCount(1, $documents);
        $site = array_values($documents)[0];
        self::assertCount(1, $site['documents']);
        self::assertSame('A new title', $site['documents'][0]->label);
    }

    #[Test]
    public function aDocumentThatWasCreatedInTheWorkspaceIsInTheList(): void
    {
        $site = $this->nodeInTestWorkspace('/sites/' . $this->siteNodeName);
        self::assertInstanceOf(NodeInterface::class, $site);
        $site->createNode('new-page', $this->nodeTypeManager->getNodeType('Acme.Demo:Page'));
        $this->persistenceManager->persistAll();
        $this->workspaceChangesService->clearCache();

        $documents = $this->workspaceChangesService->getDocumentsWithChanges($this->testWorkspace());

        self::assertCount(1, $documents);
        $siteData = array_values($documents)[0];
        self::assertCount(1, $siteData['documents']);
        self::assertSame('created', $siteData['documents'][0]->kind);
        self::assertTrue($siteData['documents'][0]->isNew);
    }

    #[Test]
    public function aDocumentThatWasDeletedInTheWorkspaceIsInTheList(): void
    {
        $aboutUs = $this->nodeInTestWorkspace($this->sitePath('/about-us'));
        self::assertInstanceOf(NodeInterface::class, $aboutUs);
        $aboutUs->remove();
        $this->persistenceManager->persistAll();
        $this->workspaceChangesService->clearCache();

        $documents = $this->workspaceChangesService->getDocumentsWithChanges($this->testWorkspace());

        self::assertCount(1, $documents);
        $site = array_values($documents)[0];
        self::assertCount(1, $site['documents']);
        self::assertSame('deleted', $site['documents'][0]->kind);
        self::assertTrue($site['documents'][0]->isDeleted);
    }

    #[Test]
    public function theChangesOfADocumentAreInTheOrderOfTheContentTree(): void
    {
        $home = $this->nodeInTestWorkspace($this->sitePath('/home'));
        self::assertInstanceOf(NodeInterface::class, $home);
        $home->setProperty('title', 'A new title');
        $text = $home->getNode('main')->getNode('text');
        self::assertInstanceOf(NodeInterface::class, $text);
        $text->setProperty('text', 'A new text');
        $this->persistenceManager->persistAll();
        $this->workspaceChangesService->clearCache();

        $changes = $this->workspaceChangesService->getChangesForDocument($this->testWorkspace(), $home);

        self::assertCount(2, $changes);
        $changes = $changes->items;
        // The document comes first, the text below it comes after it.
        self::assertSame('A new title', $changes[0]->label);
        self::assertSame('A new text', $changes[1]->label);
    }

    #[Test]
    public function aChangeThatNeedsItsParentSaysSo(): void
    {
        $site = $this->nodeInTestWorkspace('/sites/' . $this->siteNodeName);
        self::assertInstanceOf(NodeInterface::class, $site);
        $newDocument = $site->createNode('new-page', $this->nodeTypeManager->getNodeType('Acme.Demo:Page'));
        // The page has a collection "main" that is created with it.
        $main = $newDocument->getNode('main');
        self::assertInstanceOf(NodeInterface::class, $main);
        $main->createNode('text', $this->nodeTypeManager->getNodeType('Acme.Demo:Text'));
        $this->persistenceManager->persistAll();
        $this->workspaceChangesService->clearCache();

        $changes = $this->workspaceChangesService->getChangesForDocument($this->testWorkspace(), $newDocument);

        $changesWithRequiredParents = [];
        foreach ($changes->items as $change) {
            if ($change->requiredParents !== []) {
                $changesWithRequiredParents[] = $change;
            }
        }

        self::assertNotSame([], $changesWithRequiredParents, 'The text needs the collection it is in.');
    }

    #[Test]
    public function theDocumentsAreNestedLikeTheyAreInTheContentTree(): void
    {
        // The three documents below the home page are changed, the home page itself is not.
        foreach (['team' => 'The new team', 'services' => 'The new services', 'contact' => 'The new contact'] as $nodeName => $title) {
            $document = $this->nodeInTestWorkspace($this->sitePath('/home/' . $nodeName));
            self::assertInstanceOf(NodeInterface::class, $document);
            $document->setProperty('title', $title);
        }
        $this->persistenceManager->persistAll();
        $this->workspaceChangesService->clearCache();

        $documents = $this->workspaceChangesService->getDocumentsWithChanges($this->testWorkspace());
        $site = array_values($documents)[0];
        $documentsOfSite = $site['documents'];

        self::assertCount(3, $documentsOfSite);
        foreach ($documentsOfSite as $document) {
            // The documents sit below the home page, which is not in the list of the documents
            // with changes - so the home page is named above them.
            self::assertStringStartsWith('The new ', $document->label);
            self::assertSame(0, $document->level);
            self::assertFalse($document->parentIsInList);
            self::assertSame(['Home'], $document->breadcrumb);
        }
    }

    #[Test]
    public function theDocumentsOfAVariantAreGroupedBelowTheDocumentOfThatVariant(): void
    {
        $variantContext = $this->contextOfASecondVariant();
        if ($variantContext === null) {
            self::markTestSkipped('The installation has no dimension with a second value.');
        }

        // The home page and the page below it are changed in the variant of the installation
        // and in a second one.
        $home = $this->nodeInTestWorkspace($this->sitePath('/home'));
        self::assertInstanceOf(NodeInterface::class, $home);
        $team = $this->nodeInTestWorkspace($this->sitePath('/home/team'));
        self::assertInstanceOf(NodeInterface::class, $team);
        $home->setProperty('title', 'A new title');
        $team->setProperty('title', 'A new team');

        $liveHome = $this->nodeInLiveWorkspace($this->sitePath('/home'));
        self::assertInstanceOf(NodeInterface::class, $liveHome);
        $liveTeam = $this->nodeInLiveWorkspace($this->sitePath('/home/team'));
        self::assertInstanceOf(NodeInterface::class, $liveTeam);
        $variantHome = $liveHome->createVariantForContext($variantContext);
        $variantTeam = $liveTeam->createVariantForContext($variantContext);
        $variantHome->setProperty('title', 'Another new title');
        $variantTeam->setProperty('title', 'Another new team');

        $this->persistenceManager->persistAll();
        $this->workspaceChangesService->clearCache();

        $documents = $this->workspaceChangesService->getDocumentsWithChanges($this->testWorkspace());
        $site = array_values($documents)[0];
        $documentsOfSite = $site['documents'];

        // The home page and the document below it, in the variant of the installation and in
        // the other one.
        self::assertCount(4, $documentsOfSite);

        // A document follows the document it sits below - and the document it sits below is
        // the one of its own variant.
        foreach ($documentsOfSite as $index => $document) {
            if ($document->parent === null) {
                continue;
            }
            $positionOfParent = array_search(
                $document->parent,
                array_column($documentsOfSite, 'contextPath'),
                true
            );
            self::assertIsInt($positionOfParent, 'the document the one sits below is in the list');
            self::assertSame(
                $positionOfParent + 1,
                $index,
                'the document below a document follows the document of its own variant'
            );
            self::assertSame(1, $document->level);
        }
    }

    #[Test]
    public function theNumberOfTheChangesIsCountedByTheirKind(): void
    {
        $home = $this->nodeInTestWorkspace($this->sitePath('/home'));
        self::assertInstanceOf(NodeInterface::class, $home);
        $home->setProperty('title', 'A new title');

        $aboutUs = $this->nodeInTestWorkspace($this->sitePath('/about-us'));
        self::assertInstanceOf(NodeInterface::class, $aboutUs);
        $aboutUs->remove();

        $site = $this->nodeInTestWorkspace('/sites/' . $this->siteNodeName);
        self::assertInstanceOf(NodeInterface::class, $site);
        $site->createNode('new-page', $this->nodeTypeManager->getNodeType('Acme.Demo:Page'));

        $this->persistenceManager->persistAll();
        $this->workspaceChangesService->clearCache();

        $pendingChanges = $this->workspaceChangesService->getChangesCount($this->testWorkspace());

        self::assertSame(1, $pendingChanges->new);
        self::assertSame(1, $pendingChanges->changed);
        self::assertSame(1, $pendingChanges->removed);
        self::assertSame(3, $pendingChanges->total);
    }

    /**
     * The context of a variant of the content - the variant of the test workspace that is
     * not the one the content is read with by default.
     *
     * Null when the installation has no dimension with a second value.
     */
    private function contextOfASecondVariant(): ?Context
    {
        $presetSource = $this->getObject(ContentDimensionPresetSourceInterface::class);
        foreach ($presetSource->getAllPresets() as $dimensionName => $dimensionConfiguration) {
            $values = [];
            foreach ((array)($dimensionConfiguration['presets'] ?? []) as $presetConfiguration) {
                foreach ((array)($presetConfiguration['values'] ?? []) as $value) {
                    $values[(string)$value] = true;
                }
            }
            $values = array_keys($values);
            if (count($values) < 2) {
                continue;
            }

            // The variant the content is in by default is the one of the home page.
            $home = $this->nodeInTestWorkspace($this->sitePath('/home'));
            self::assertInstanceOf(NodeInterface::class, $home);
            $defaultValues = $home->getDimensions()[(string)$dimensionName] ?? [];
            $secondValue = null;
            foreach ($values as $value) {
                if (!in_array($value, (array)$defaultValues, true)) {
                    $secondValue = $value;
                    break;
                }
            }
            if ($secondValue === null) {
                continue;
            }

            return $this->contextFactory->create([
                'workspaceName' => 'test',
                'dimensions' => [(string)$dimensionName => [$secondValue]],
            ]);
        }

        return null;
    }
}
