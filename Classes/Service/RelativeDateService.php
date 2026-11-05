<?php

/*
 * This file is part of the Shel.Neos.WorkspaceModule package.
 *
 * This package is Open Source Software. For the full copyright and license
 * information, please view the LICENSE file which was distributed with this
 * source code.
 */

declare(strict_types=1);

namespace Shel\Neos\WorkspaceModule\Service;

use DateTimeInterface;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\I18n\Translator;

/**
 * Dates of the last days as they meet on the street: "just now", "5 minutes ago" - a
 * change of a few minutes ago is worth more to a reviewer than its position in the day.
 *
 * Older dates are not rendered, the caller keeps the formatted date for them.
 */
#[Flow\Scope('singleton')]
final class RelativeDateService
{
    /**
     * Dates older than this many seconds are not spoken relative.
     */
    private const RECENT_SECONDS = 7 * 86400;

    public function __construct(
        private readonly Translator $translator,
    ) {
    }

    /**
     * The relative label of a date of the last week, or null when it is older.
     */
    public function label(?DateTimeInterface $dateTime): ?string
    {
        if ($dateTime === null) {
            return null;
        }
        $age = \max(0, \time() - (int)$dateTime->format('U'));
        if ($age > self::RECENT_SECONDS) {
            return null;
        }

        return match (true) {
            $age < 60 => $this->translate('relative.justNow'),
            $age < 3600 => $this->translate(
                $age < 120 ? 'relative.minute' : 'relative.minutes',
                [(int)\floor($age / 60)]
            ),
            $age < 86400 => $this->translate(
                $age < 7200 ? 'relative.hour' : 'relative.hours',
                [(int)\floor($age / 3600)]
            ),
            $age < 172800 => $this->translate('relative.yesterday'),
            $age < 604800 => $this->translate('relative.days', [(int)\floor($age / 86400)]),
            default => null,
        };
    }

    /**
     * @param list<string|int> $arguments
     */
    private function translate(string $labelId, array $arguments = []): ?string
    {
        $label = $this->translator->translateById(
            $labelId,
            $arguments,
            null,
            null,
            'Main',
            'Shel.Neos.WorkspaceModule'
        );

        return is_string($label) && $label !== '' ? $label : null;
    }
}
