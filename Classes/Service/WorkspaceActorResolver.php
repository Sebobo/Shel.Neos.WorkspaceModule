<?php

declare(strict_types=1);

namespace Shel\Neos\WorkspaceModule\Service;

/**
 * This file is part of the Shel.Neos.WorkspaceModule package.
 *
 * This package is Open Source Software. For the full copyright and license
 * information, please view the LICENSE file which was distributed with this
 * source code.
 */

use Neos\Flow\Annotations as Flow;
use Neos\Flow\Security\Account;
use Neos\Flow\Security\Context;
use Neos\Neos\Domain\Model\User;
use Neos\Neos\Domain\Service\UserService;
use Neos\Party\Domain\Service\PartyService;
use Shel\Neos\WorkspaceModule\Domain\WorkspaceActor;

/**
 * Resolves the identity of the actor who acts in the current request.
 */
#[Flow\Scope('singleton')]
class WorkspaceActorResolver
{
    #[Flow\Inject]
    protected Context $securityContext;

    #[Flow\Inject]
    protected UserService $userService;

    #[Flow\Inject]
    protected PartyService $partyService;

    /**
     * The name of the actor of this request, or NULL if the actor cannot be resolved to
     * a name.
     *
     * The name is looked up once per request: the actor does not change while the request
     * runs.
     */
    protected ?string $username = null;

    protected bool $resolved = false;

    /**
     * The name of the Neos user who acts in the current request, or NULL if there is no
     * name for the actor.
     */
    public function resolveUsername(): ?string
    {
        if ($this->resolved) {
            return $this->username;
        }

        $this->resolved = true;
        $this->username = $this->doResolveUsername();

        return $this->username;
    }

    /**
     * The account of the current request, or NULL if there is none - in the command line,
     * for example.
     */
    protected function getCurrentAccount(): ?Account
    {
        if (!$this->securityContext->canBeInitialized()) {
            return null;
        }

        foreach ($this->securityContext->getAuthenticationTokens() as $token) {
            if ($token->isAuthenticated() === true) {
                return $token->getAccount();
            }
        }

        return null;
    }

    protected function doResolveUsername(): ?string
    {
        $account = $this->getCurrentAccount();
        if ($account === null) {
            return null;
        }

        $accountIdentifier = $account->getAccountIdentifier();
        if ($accountIdentifier === '') {
            return null;
        }

        $username = $this->resolveUsernameOfUser($account);
        if ($username !== null) {
            return $username;
        }

        // The installation may know a name for a machine: a slot of the signal below
        // provides it.
        $actor = new WorkspaceActor();
        $this->workspaceActorRequested($actor, $accountIdentifier);

        if ($actor->getUsername() !== null) {
            return $actor->getUsername();
        }

        return null;
    }

    /**
     * The name of the Neos user behind the account, or NULL if the account does not
     * belong to a user of the backend.
     *
     * Only the accounts of the default provider are looked up: they are the accounts of
     * the users of the backend, and their identifier is the name of the user. The account
     * of another provider may be a secret - the token of an agent, for example.
     */
    protected function resolveUsernameOfUser(Account $account): ?string
    {
        $providerName = $account->getAuthenticationProviderName();
        if ($providerName !== $this->userService->getDefaultAuthenticationProviderName()) {
            return null;
        }

        $party = $this->partyService->getAssignedPartyOfAccount($account);
        if (!$party instanceof User) {
            return null;
        }

        $username = $this->userService->getUsername($party, $providerName);

        return $username === '' ? null : $username;
    }

    /**
     * The installation may know the name of a machine that acts without a user of the
     * backend - an agent that authenticates with a bearer token, for example.
     *
     * The identifier of the account is handed over so that a slot can look the machine up.
     * It is a secret for a token and must not be written down anywhere.
     */
    #[Flow\Signal]
    public function workspaceActorRequested(WorkspaceActor $actor, string $accountIdentifier): void
    {
    }
}
