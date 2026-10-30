<?php

/*
 * This file is part of the Shel.Neos.WorkspaceModule package.
 *
 * (c) 2022 Sebastian Helzle
 *
 * This package is part of the Neos CMS.
 *
 * This package is Open Source Software. For the full copyright and license
 * information, please view the LICENSE file which was distributed with this
 * source code.
 */

declare(strict_types=1);

namespace Shel\Neos\WorkspaceModule\Mvc;

use Neos\Flow\Mvc\ActionRequest;
use Neos\Flow\Security\RequestPatternInterface;

/**
 * Matches the requests that the review view loads asynchronously.
 *
 * The review loads the list of documents and the changes of a document with htmx, which sends
 * the requests with the format "htmx". The pattern is used to store the flash messages of such
 * a request in the response header instead of in the session, because the browser does not
 * navigate to the response and would not render them.
 */
final class HtmxRequestPattern implements RequestPatternInterface
{
    public function matchRequest(ActionRequest $request): bool
    {
        return $request->getFormat() === 'htmx';
    }
}
