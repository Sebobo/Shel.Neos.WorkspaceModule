<?php

/**
 * This file is part of the Shel.Neos.WorkspaceModule package.
 *
 * This package is Open Source Software. For the full copyright and license
 * information, please view the LICENSE file which was distributed with this
 * source code.
 */

declare(strict_types=1);

namespace Shel\Neos\WorkspaceModule\Mvc;

use Neos\Error\Messages\Message;
use Neos\Flow\Mvc\FlashMessage\FlashMessageContainer;
use Neos\Flow\Mvc\FlashMessage\FlashMessageStorageInterface;
use Psr\Http\Message\ResponseInterface as HttpResponseInterface;
use Psr\Http\Message\ServerRequestInterface as HttpRequestInterface;

/**
 * Puts the flash messages of an asynchronously loaded request into the response header.
 *
 * A request that the browser does not navigate to has no place to render the messages of the
 * action, so they are handed to the javascript that shows them as notifications.
 */
final class HttpHeaderFlashMessageStorage implements FlashMessageStorageInterface
{
    private FlashMessageContainer|null $flashMessageContainer = null;

    public function load(HttpRequestInterface $request): FlashMessageContainer
    {
        if ($this->flashMessageContainer === null) {
            $this->flashMessageContainer = new FlashMessageContainer();
        }

        return $this->flashMessageContainer;
    }

    public function persist(HttpResponseInterface $response): HttpResponseInterface
    {
        $messages = array_map(static fn(Message $message): array => [
            'title' => $message->getTitle(),
            'message' => $message->render(),
            'severity' => $message->getSeverity(),
            'code' => $message->getCode(),
        ], $this->flashMessageContainer?->getMessagesAndFlush() ?? []);
        if ($messages === []) {
            return $response;
        }

        return $response->withAddedHeader('X-Flow-FlashMessages', json_encode($messages, JSON_THROW_ON_ERROR));
    }
}
