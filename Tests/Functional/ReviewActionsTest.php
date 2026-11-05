<?php

namespace Shel\Neos\WorkspaceModule\Tests\Functional;

use GuzzleHttp\Psr7\Uri;
use Neos\ContentRepository\Domain\Model\NodeInterface;
use Neos\ContentRepository\Domain\Repository\NodeDataRepository;
use Neos\Flow\I18n\Translator;
use Neos\Flow\Security\Account;
use Neos\Flow\Security\AccountRepository;
use Neos\Neos\Domain\Model\Domain;
use Neos\Neos\Domain\Repository\DomainRepository;
use PHPUnit\Framework\Attributes\Test;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestFactoryInterface;
use Shel\Neos\WorkspaceModule\Service\WorkspaceChangesService;

/**
 * The tests of the actions of the review.
 *
 * They talk to the module over http, the way the browser does: a request goes through the
 * routing, the mapping of the arguments, the policy of the method and the rendering of the
 * answer. What the customer sees is the effect of the action on the content - that is what
 * these tests check.
 *
 * A request clears the state of the persistence manager when it is done - the state of a
 * request is the state of a request. The changes of the tests are therefore made after the
 * review is opened, so that the content repository holds them in the state of the request
 * that acts on them.
 */
class ReviewActionsTest extends WorkspaceTestCase
{
    protected $testableSecurityEnabled = true;

    private WorkspaceChangesService $workspaceChangesService;
    private NodeDataRepository $nodeDataRepository;

    /**
     * The identifier of the session of the browser.
     */
    private ?string $session = null;

    /**
     * The token that every request of the browser carries.
     */
    private ?string $csrfToken = null;

    /**
     * The number of the accounts of the test - every account needs its own name.
     */
    private int $accounts = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->workspaceChangesService = $this->getObject(WorkspaceChangesService::class);
        $this->nodeDataRepository = $this->getObject(NodeDataRepository::class);
        // The answers of the module are fragments - a redirect would hide them.
        $this->browser->setFollowRedirects(false);
    }

    /**
     * The content of the tests is created with a user, because the content repository asks
     * the privileges of the user before it writes.
     */
    protected function setUpContent(): void
    {
        $this->authenticateAs(['Neos.Neos:Editor']);
        $this->createContentInLiveWorkspace();
    }

    #[Test]
    public function theReviewShowsTheDocumentsAndTheirChanges(): void
    {
        $this->changeTheTitleOfTheHomePage('A new title');
        $this->openReview();

        // The list of the documents holds the document with the change.
        $documents = $this->documentsOfTheReview();
        self::assertContains($this->contextPathOfTheHomePage(), $documents);

        // The changes of the document hold the change.
        $changes = $this->changesOfTheDocument($this->contextPathOfTheHomePage());
        self::assertContains($this->contextPathOfTheHomePage(), $changes);
    }

    #[Test]
    public function theReviewHasAButtonBackToTheWorkspaces(): void
    {
        $this->openReview();

        $body = (string)$this->request('show')->getBody();

        // The button stands at the top of the review and goes back to the overview.
        self::assertStringContainsString('workspace-review-back', $body);
        self::assertStringContainsString('moduleArguments%5B%40action%5D=index', $body);
    }

    #[Test]
    public function theReviewHasAButtonToResetTheSelection(): void
    {
        $this->openReview();

        $body = (string)$this->request('show')->getBody();

        // The button stands at the left of the buttons that publish and discard.
        self::assertStringContainsString('resetSelectionButton', $body);
        self::assertMatchesRegularExpression('/<button[^>]*workspace-review-reset[^>]*>/', $body);
        // Without a selection there is nothing to reset.
        self::assertMatchesRegularExpression('/<button[^>]*workspace-review-reset[^>]*disabled="disabled"/', $body);
    }

    #[Test]
    public function theButtonThatDiscardsComesBeforeTheButtonThatPublishes(): void
    {
        $this->openReview();

        $body = (string)$this->request('show')->getBody();

        $discard = strpos($body, 'discardComboButton');
        $publish = strpos($body, 'publishComboButton');
        self::assertIsInt($discard);
        self::assertIsInt($publish);
        self::assertLessThan($publish, $discard);
    }

    #[Test]
    public function theButtonsOfTheFooterSayWhatTheyDo(): void
    {
        $this->openReview();

        $body = (string)$this->request('show')->getBody();

        // The title of a button says what happens when it is pressed - the label says it in
        // short. The publish button says where the changes go.
        self::assertStringContainsString(
            'title="' . $this->titleOfTheButton('review.publishDocumentTitle', ['live']) . '"',
            $body
        );
        self::assertStringContainsString(
            'title="' . $this->titleOfTheButton('review.discardDocumentTitle') . '"',
            $body
        );
        // The menu of the buttons holds the other options, and they say what they do, too.
        self::assertStringContainsString(
            'title="' . $this->titleOfTheButton('review.publishAllTitle', ['test']) . '"',
            $body
        );
        self::assertStringContainsString(
            'title="' . $this->titleOfTheButton('review.discardAllTitle', ['test']) . '"',
            $body
        );
    }

    #[Test]
    public function theButtonOfAChangeSaysWhereTheChangeGoes(): void
    {
        $this->openReview();
        $home = $this->changeTheTitleOfTheHomePage('A new title');

        $response = $this->request('documentChanges', ['document' => $home->getContextPath()]);
        self::assertSame(200, $response->getStatusCode(), $this->answerOf($response));

        self::assertStringContainsString(
            'title="' . $this->titleOfTheButton('review.publishChange', ['live']) . '"',
            (string)$response->getBody()
        );
    }

    #[Test]
    public function theTitleOfTheButtonNamesTheWorkspaceTheChangesGoTo(): void
    {
        $this->openReview();
        // The workspace has a name that a human does not know it by.
        $live = $this->liveWorkspace();
        $live->setTitle('Published');
        $this->persistenceManager->persistAll();

        $body = (string)$this->request('show')->getBody();

        // The title of the workspace is the name in the title of the button - not its
        // technical name.
        self::assertStringContainsString(
            'title="' . $this->titleOfTheButton('review.publishDocumentTitle', ['Published']) . '"',
            $body
        );
        self::assertStringNotContainsString(
            'title="' . $this->titleOfTheButton('review.publishDocumentTitle', ['live']) . '"',
            $body
        );
    }

    #[Test]
    public function aSingleChangeIsPublishedToTheLiveWorkspace(): void
    {
        $this->openReview();
        $home = $this->changeTheTitleOfTheHomePage('A new title');

        $response = $this->post('publishChange', ['node' => $home->getContextPath()]);

        self::assertSame(200, $response->getStatusCode(), $this->answerOf($response));
        $this->refreshTheContent();

        $published = $this->nodeInLiveWorkspace($this->sitePath('/home'));
        self::assertInstanceOf(NodeInterface::class, $published);
        self::assertSame('A new title', $published->getProperty('title'));

        // The change is gone - the document has no change any more.
        self::assertSame([], $this->documentsWithChanges());
    }

    #[Test]
    public function aSingleChangeIsDiscarded(): void
    {
        $this->openReview();
        $home = $this->changeTheTitleOfTheHomePage('A new title');

        $response = $this->post('discardChange', ['node' => $home->getContextPath()]);

        self::assertSame(200, $response->getStatusCode(), $this->answerOf($response));
        $this->refreshTheContent();

        // The document is the published one again.
        $discarded = $this->nodeInTestWorkspace($this->sitePath('/home'));
        self::assertInstanceOf(NodeInterface::class, $discarded);
        self::assertSame('Home', $discarded->getProperty('title'));

        $published = $this->nodeInLiveWorkspace($this->sitePath('/home'));
        self::assertInstanceOf(NodeInterface::class, $published);
        self::assertSame('Home', $published->getProperty('title'));
    }

    #[Test]
    public function allTheChangesOfADocumentArePublished(): void
    {
        $this->openReview();
        $home = $this->changeTheTitleOfTheHomePage('A new title');
        $this->changeTheTextOfTheHomePage('A new text');

        $response = $this->post('publishDocument', ['document' => $home->getContextPath()]);

        self::assertSame(200, $response->getStatusCode(), $this->answerOf($response));
        $this->refreshTheContent();

        // The title and the text of the document are published - not only one of them.
        $published = $this->nodeInLiveWorkspace($this->sitePath('/home'));
        self::assertInstanceOf(NodeInterface::class, $published);
        self::assertSame('A new title', $published->getProperty('title'));
        $publishedText = $published->getNode('main')->getNode('text');
        self::assertInstanceOf(NodeInterface::class, $publishedText);
        self::assertSame('A new text', $publishedText->getProperty('text'));

        self::assertSame([], $this->documentsWithChanges());
    }

    #[Test]
    public function allTheChangesOfADocumentAreDiscarded(): void
    {
        $this->openReview();
        $home = $this->changeTheTitleOfTheHomePage('A new title');
        $this->changeTheTextOfTheHomePage('A new text');

        $response = $this->post('discardDocument', ['document' => $home->getContextPath()]);

        self::assertSame(200, $response->getStatusCode(), $this->answerOf($response));
        $this->refreshTheContent();

        // The title and the text are the published ones again.
        $discarded = $this->nodeInTestWorkspace($this->sitePath('/home'));
        self::assertInstanceOf(NodeInterface::class, $discarded);
        self::assertSame('Home', $discarded->getProperty('title'));
        $discardedText = $discarded->getNode('main')->getNode('text');
        self::assertInstanceOf(NodeInterface::class, $discardedText);
        self::assertSame('The text of the home page.', $discardedText->getProperty('text'));
    }

    #[Test]
    public function theSelectedChangesArePublished(): void
    {
        $this->openReview();
        $home = $this->changeTheTitleOfTheHomePage('A new title');
        $this->changeTheTitleOfTheAboutUsPage('Another new title');

        $response = $this->post('publishSelected', ['nodes' => [$home->getContextPath()]]);

        self::assertSame(200, $response->getStatusCode(), $this->answerOf($response));
        $this->refreshTheContent();

        // Only the selected change is published - the other one is still a change.
        $published = $this->nodeInLiveWorkspace($this->sitePath('/home'));
        self::assertInstanceOf(NodeInterface::class, $published);
        self::assertSame('A new title', $published->getProperty('title'));
        $notPublished = $this->nodeInLiveWorkspace($this->sitePath('/about-us'));
        self::assertInstanceOf(NodeInterface::class, $notPublished);
        self::assertSame('About us', $notPublished->getProperty('title'));

        $documentsWithChanges = $this->documentsWithChanges();
        self::assertCount(1, $documentsWithChanges);
        self::assertSame('Another new title', $documentsWithChanges[0]->label);
    }

    #[Test]
    public function theSelectedChangesAreDiscarded(): void
    {
        $this->openReview();
        $home = $this->changeTheTitleOfTheHomePage('A new title');
        $this->changeTheTitleOfTheAboutUsPage('Another new title');

        $response = $this->post('discardSelected', ['nodes' => [$home->getContextPath()]]);

        self::assertSame(200, $response->getStatusCode(), $this->answerOf($response));
        $this->refreshTheContent();

        // Only the selected change is discarded - the other one is still a change.
        $discarded = $this->nodeInTestWorkspace($this->sitePath('/home'));
        self::assertInstanceOf(NodeInterface::class, $discarded);
        self::assertSame('Home', $discarded->getProperty('title'));
        $notDiscarded = $this->nodeInTestWorkspace($this->sitePath('/about-us'));
        self::assertInstanceOf(NodeInterface::class, $notDiscarded);
        self::assertSame('Another new title', $notDiscarded->getProperty('title'));
    }

    #[Test]
    public function theSelectedDocumentsArePublished(): void
    {
        $this->openReview();
        $this->changeTheTitleOfTheHomePage('A new title');
        $aboutUs = $this->changeTheTitleOfTheAboutUsPage('Another new title');

        $response = $this->post('publishSelected', ['documents' => [$aboutUs->getContextPath()]]);

        self::assertSame(200, $response->getStatusCode(), $this->answerOf($response));
        $this->refreshTheContent();

        // The document of the selection is published with all of its changes, the other
        // document is not touched.
        $published = $this->nodeInLiveWorkspace($this->sitePath('/about-us'));
        self::assertInstanceOf(NodeInterface::class, $published);
        self::assertSame('Another new title', $published->getProperty('title'));
        $notPublished = $this->nodeInLiveWorkspace($this->sitePath('/home'));
        self::assertInstanceOf(NodeInterface::class, $notPublished);
        self::assertSame('Home', $notPublished->getProperty('title'));
    }

    #[Test]
    public function allTheChangesOfTheWorkspaceArePublished(): void
    {
        $this->openReview();
        $this->changeTheTitleOfTheHomePage('A new title');
        $this->changeTheTitleOfTheAboutUsPage('Another new title');

        $response = $this->post('publishAll');

        self::assertSame(200, $response->getStatusCode(), $this->answerOf($response));
        $this->refreshTheContent();

        $published = $this->nodeInLiveWorkspace($this->sitePath('/home'));
        self::assertInstanceOf(NodeInterface::class, $published);
        self::assertSame('A new title', $published->getProperty('title'));
        $publishedAboutUs = $this->nodeInLiveWorkspace($this->sitePath('/about-us'));
        self::assertInstanceOf(NodeInterface::class, $publishedAboutUs);
        self::assertSame('Another new title', $publishedAboutUs->getProperty('title'));

        self::assertSame([], $this->documentsWithChanges());
    }

    #[Test]
    public function allTheChangesOfTheWorkspaceAreDiscarded(): void
    {
        $this->openReview();
        $this->changeTheTitleOfTheHomePage('A new title');
        $this->changeTheTitleOfTheAboutUsPage('Another new title');

        $response = $this->post('discardAll');

        self::assertSame(200, $response->getStatusCode(), $this->answerOf($response));
        $this->refreshTheContent();

        $discarded = $this->nodeInTestWorkspace($this->sitePath('/home'));
        self::assertInstanceOf(NodeInterface::class, $discarded);
        self::assertSame('Home', $discarded->getProperty('title'));
        $discardedAboutUs = $this->nodeInTestWorkspace($this->sitePath('/about-us'));
        self::assertInstanceOf(NodeInterface::class, $discardedAboutUs);
        self::assertSame('About us', $discardedAboutUs->getProperty('title'));

        self::assertSame([], $this->documentsWithChanges());
    }

    #[Test]
    public function aRemovedDocumentIsPublished(): void
    {
        $this->openReview();
        $aboutUs = $this->removeTheAboutUsPage();

        $response = $this->post('publishDocument', ['document' => $aboutUs->getContextPath()]);

        self::assertSame(200, $response->getStatusCode(), $this->answerOf($response));
        $this->refreshTheContent();

        // The document is not on the website any more.
        self::assertNull($this->nodeInLiveWorkspace($this->sitePath('/about-us')));
    }

    #[Test]
    public function aRemovedDocumentIsDiscarded(): void
    {
        $this->openReview();
        $aboutUs = $this->removeTheAboutUsPage();

        $response = $this->post('discardChange', ['node' => $aboutUs->getContextPath()]);

        self::assertSame(200, $response->getStatusCode(), $this->answerOf($response));
        $this->refreshTheContent();

        // The document is back - the change that removed it is gone.
        $restored = $this->nodeInTestWorkspace($this->sitePath('/about-us'));
        self::assertInstanceOf(NodeInterface::class, $restored);
        self::assertSame('About us', $restored->getProperty('title'));
    }

    #[Test]
    public function aDocumentThatWasCreatedInTheWorkspaceIsDiscardedWithItsContent(): void
    {
        $this->openReview();
        $newDocument = $this->createANewPage();

        $response = $this->post('discardDocument', ['document' => $newDocument->getContextPath()]);

        self::assertSame(200, $response->getStatusCode(), $this->answerOf($response));
        $this->refreshTheContent();

        // Neither the document nor its content is left behind - a child whose parent is
        // gone is an orphan that the content repository cannot do anything with.
        self::assertNull($this->nodeInTestWorkspace($this->sitePath('/new-page')));
        self::assertNull($this->nodeInTestWorkspace($this->sitePath('/new-page/main')));
        self::assertNull($this->nodeInTestWorkspace($this->sitePath('/new-page/main/text')));
    }

    #[Test]
    public function aUserWithoutThePrivilegeToPublishCannotPublish(): void
    {
        // An editor without the privilege to publish to the live workspace.
        $this->authenticateAs(['Neos.Neos:RestrictedEditor']);
        $this->openReview();
        $home = $this->changeTheTitleOfTheHomePage('A new title');

        $response = $this->post('publishChange', ['node' => $home->getContextPath()]);

        self::assertSame(403, $response->getStatusCode(), $this->answerOf($response));
        $this->refreshTheContent();

        // Nothing was published - the change is still a change.
        $published = $this->nodeInLiveWorkspace($this->sitePath('/home'));
        self::assertInstanceOf(NodeInterface::class, $published);
        self::assertSame('Home', $published->getProperty('title'));
        self::assertCount(1, $this->documentsWithChanges());
    }

    /**
     * The title of a button, as it stands in the markup of the review.
     *
     * @param array<int, string> $parameters
     */
    private function titleOfTheButton(string $translationId, array $parameters = []): string
    {
        $translator = $this->getObject(Translator::class);
        $title = (string)$translator->translateById(
            $translationId,
            $parameters,
            null,
            null,
            'Main',
            'Shel.Neos.WorkspaceModule'
        );

        return htmlspecialchars($title, ENT_QUOTES);
    }

    /**
     * The home page with a new title.
     */
    private function changeTheTitleOfTheHomePage(string $title): NodeInterface
    {
        $home = $this->nodeInTestWorkspace($this->sitePath('/home'));
        self::assertInstanceOf(NodeInterface::class, $home);
        $home->setProperty('title', $title);
        $this->persistenceManager->persistAll();

        return $home;
    }

    /**
     * The about us page with a new title.
     */
    private function changeTheTitleOfTheAboutUsPage(string $title): NodeInterface
    {
        $aboutUs = $this->nodeInTestWorkspace($this->sitePath('/about-us'));
        self::assertInstanceOf(NodeInterface::class, $aboutUs);
        $aboutUs->setProperty('title', $title);
        $this->persistenceManager->persistAll();

        return $aboutUs;
    }

    /**
     * The text of the home page with a new text.
     */
    private function changeTheTextOfTheHomePage(string $text): NodeInterface
    {
        $home = $this->nodeInTestWorkspace($this->sitePath('/home'));
        self::assertInstanceOf(NodeInterface::class, $home);
        $textNode = $home->getNode('main')->getNode('text');
        self::assertInstanceOf(NodeInterface::class, $textNode);
        $textNode->setProperty('text', $text);
        $this->persistenceManager->persistAll();

        return $textNode;
    }

    /**
     * The about us page, removed in the workspace.
     */
    private function removeTheAboutUsPage(): NodeInterface
    {
        $aboutUs = $this->nodeInTestWorkspace($this->sitePath('/about-us'));
        self::assertInstanceOf(NodeInterface::class, $aboutUs);
        $aboutUs->remove();
        $this->persistenceManager->persistAll();

        return $aboutUs;
    }

    /**
     * A new page with a text, created in the workspace.
     */
    private function createANewPage(): NodeInterface
    {
        $site = $this->nodeInTestWorkspace('/sites/' . $this->siteNodeName);
        self::assertInstanceOf(NodeInterface::class, $site);
        $newDocument = $site->createNode('new-page', $this->nodeTypeManager->getNodeType('Acme.Demo:Page'));
        $newDocument->setProperty('title', 'New page');
        $this->createTextIn($newDocument, 'The text of the new page.');
        $this->persistenceManager->persistAll();

        return $newDocument;
    }

    /**
     * The path of the home page, as the review and the actions use it.
     */
    private function contextPathOfTheHomePage(): string
    {
        $home = $this->nodeInTestWorkspace($this->sitePath('/home'));
        self::assertInstanceOf(NodeInterface::class, $home);

        return $home->getContextPath();
    }

    /**
     * A user with the given roles.
     *
     * The account is saved, so that the session of the request can load it again.
     *
     * @param list<string> $roleNames
     */
    private function authenticateAs(array $roleNames): void
    {
        $account = new Account();
        $account->setAccountIdentifier('reviewer-' . ++$this->accounts);
        $account->setAuthenticationProviderName('TestingProvider');
        $account->setRoles(array_map(
            fn (string $roleName) => $this->policyService->getRole($roleName),
            $roleNames
        ));
        $this->getObject(AccountRepository::class)->add($account);
        $this->persistenceManager->persistAll();
        $this->authenticateAccount($account);
    }

    /**
     * The review of the workspace, as the reviewer opens it.
     *
     * The answer carries the token that every request of the browser needs and the cookie
     * of the session - the browser keeps both.
     */
    private function openReview(): void
    {
        $response = $this->request('show');
        self::assertSame(200, $response->getStatusCode(), $this->answerOf($response));

        $body = (string)$response->getBody();
        self::assertSame(
            1,
            preg_match('/X-Flow-Csrftoken&quot;:&quot;([a-f0-9]+)&quot;/', $body, $matches),
            'the review carries the token for the requests of the browser'
        );
        $this->csrfToken = $matches[1] ?? '';

        $setCookie = $response->getHeaderLine('Set-Cookie');
        self::assertSame(1, preg_match('/^[^=]+=([^;]+)/', $setCookie, $cookie), 'the review starts a session');
        $this->session = $cookie[1] ?? '';
    }

    /**
     * The documents of the review, with the paths the review uses for them.
     *
     * @return list<string> the paths of the documents, in the order of the list
     */
    private function documentsOfTheReview(): array
    {
        $response = $this->request('documentTree');
        self::assertSame(200, $response->getStatusCode(), $this->answerOf($response));

        preg_match_all('/data-document="([^"]*)"/', (string)$response->getBody(), $matches);

        return $matches[1];
    }

    /**
     * The changes of a document, with the paths the review uses for them.
     *
     * @return list<string> the paths of the changes, in the order of the list
     */
    private function changesOfTheDocument(string $document): array
    {
        $response = $this->request('documentChanges', ['document' => $document]);
        self::assertSame(200, $response->getStatusCode(), $this->answerOf($response));

        preg_match_all('/data-nodepath="([^"]*)"/', (string)$response->getBody(), $matches);

        return $matches[1];
    }

    /**
     * A request of the browser, with the session and the token of the review.
     *
     * @param array<string, mixed> $arguments
     */
    private function post(string $action, array $arguments = []): ResponseInterface
    {
        return $this->request($action, $arguments, 'POST');
    }

    /**
     * A request of the browser.
     *
     * @param array<string, mixed> $arguments
     */
    private function request(string $action, array $arguments = [], string $method = 'GET'): ResponseInterface
    {
        $moduleArguments = array_merge([
            '@package' => 'Shel.Neos.WorkspaceModule',
            '@controller' => 'Workspaces',
            '@action' => $action,
            '@format' => 'htmx',
            'workspace' => 'test',
        ], $arguments);

        $uri = new Uri('http://localhost/neos/management/workspaces?' . http_build_query([
            'moduleArguments' => $moduleArguments,
        ]));

        $request = $this->getObject(ServerRequestFactoryInterface::class)->createServerRequest($method, $uri);
        if ($method === 'POST') {
            // The arguments of the request travel in the body of the request.
            $request = $request->withParsedBody(['moduleArguments' => $moduleArguments]);
        }
        if ($this->session !== null) {
            $request = $request->withCookieParams(['Flow_Testing_Session' => $this->session]);
        }
        if ($this->csrfToken !== null) {
            $request = $request->withHeader('X-Flow-Csrftoken', $this->csrfToken);
        }

        return $this->browser->sendRequest($request);
    }

    /**
     * A request out on the route of the module, like the handover of the session - not to
     * the backend module, with it arguments.
     */
    private function directRequest(string $path): ResponseInterface
    {
        $request = $this->getObject(ServerRequestFactoryInterface::class)
            ->createServerRequest('GET', new Uri('http://localhost' . $path));
        if ($this->session !== null) {
            $request = $request->withCookieParams(['Flow_Testing_Session' => $this->session]);
        }

        return $this->browser->sendRequest($request);
    }

    /**
     * The handover of the session of the review to the domain of a site: the preview of a
     * document of another domain starts with it.
     */
    #[Test]
    public function theSessionOfTheReviewIsHandedOverWithAToken(): void
    {
        $this->authenticateAs(['Neos.Neos:Editor']);

        // The site of the tests is moved to another domain, so that the preview of its
        // documents is loaded from a host that the session of the review is not valid on.
        $domain = $this->domainRepository->findOneByHostname($this->siteHostName);
        self::assertInstanceOf(Domain::class, $domain);
        $domain->setHostname('other-site.test');
        $this->domainRepository->update($domain);
        $this->persistenceManager->persistAll();

        $this->openReview();
        $home = $this->changeTheTitleOfTheHomePage('A new title');
        $this->refreshTheContent();

        $response = $this->request('documentChanges', ['document' => $home->getContextPath()]);
        self::assertSame(200, $response->getStatusCode(), $this->answerOf($response));

        self::assertSame(
            1,
            preg_match('/data-preview-handover-url="([^"]+)"/', (string)$response->getBody(), $matches),
            'the changes carry the handover of the session for the domain of another site: ' . mb_substr(
                (string)$response->getBody(),
                0,
                1200
            )
        );
        $handoverPath = str_replace('http://other-site.test', '', $matches[1] ?? '');

        $response = $this->directRequest((string)$handoverPath);
        self::assertSame(200, $response->getStatusCode(), $this->answerOf($response));
        // the handover carries the cookie of the session of the review to that host
        self::assertSame(
            1,
            (int)preg_match('/^Flow_Testing_Session/', $response->getHeaderLine('Set-Cookie')),
            'the handover carries the cookie of the session of the review'
        );

        // the token of a session that was expired cannot hand it over any more
        $response = $this->directRequest('/neos/workspace-review/preview-session?token=no-token');
        self::assertNotSame(200, $response->getStatusCode(), 'the handover of an unknown token answered');
    }

    /**
     * The documents of the workspace that have changes.
     *
     * @return list<\Shel\Neos\WorkspaceModule\ViewModel\DocumentItem>
     */
    private function documentsWithChanges(): array
    {
        $this->workspaceChangesService->clearCache();

        $documents = [];
        foreach ($this->workspaceChangesService->getDocumentsWithChanges($this->testWorkspace()) as $site) {
            foreach ($site['documents'] as $document) {
                $documents[] = $document;
            }
        }

        return $documents;
    }

    /**
     * The answer of the module, as text - it is the reason of a failed request.
     */
    private function answerOf(ResponseInterface $response): string
    {
        $body = strip_tags((string)$response->getBody());

        return 'The module answered: ' . trim(preg_replace('/\s+/', ' ', $body) ?? '');
    }

    /**
     * The content repository has to forget the nodes it holds, so that the nodes are read
     * again from the database.
     */
    private function refreshTheContent(): void
    {
        $this->nodeDataRepository->flushNodeRegistry();
        $this->contextFactory->reset();
    }
}
