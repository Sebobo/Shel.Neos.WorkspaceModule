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

use Neos\Flow\Annotations as Flow;

/**
 * The description of the value of a property as text.
 *
 * The values of the nodes are of many kinds: text, numbers, dates, images, references to
 * other nodes and value objects - objects that hold a value of the domain, like the postal
 * address of a node. This service turns a value into the text the review shows, so that
 * two values can be compared and their difference rendered.
 */
#[Flow\Scope('singleton')]
final class ValueDescriptionService
{
    /**
     * The description of a value.
     */
    public function describe(mixed $value): string
    {
        if ($value === null) {
            return '';
        }
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        if (is_string($value)) {
            return $this->describeText($value);
        }
        if (is_int($value) || is_float($value)) {
            return (string)$value;
        }
        if ($value instanceof \DateTimeInterface) {
            return $value->format(\DateTimeInterface::ATOM);
        }
        if ($value instanceof \UnitEnum) {
            return $value instanceof \BackedEnum ? (string)$value->value : $value->name;
        }
        if (is_array($value) || $value instanceof \JsonSerializable) {
            return $this->describeStructure($value);
        }
        if ($value instanceof \Stringable) {
            return (string)$value;
        }

        // The value is of a kind that cannot be described - its type is the last thing that
        // is known about it.
        return get_debug_type($value);
    }

    /**
     * The description of a text: the markup is removed and the whitespace is normalised.
     */
    private function describeText(string $value): string
    {
        $contentSnippet = (string)preg_replace('/<br[^>]*>/', "\n", $value);
        $contentSnippet = (string)preg_replace('/<[^>]*>/', ' ', $contentSnippet);
        $contentSnippet = str_replace('&nbsp;', ' ', $contentSnippet);

        return trim((string)preg_replace('/ {2,}/', ' ', $contentSnippet));
    }

    /**
     * The description of a value that has a structure: an array or a value object.
     *
     * The value objects are stored as JSON in the content repository, so the JSON describes
     * them the way they are stored - and it tells the values of the fields of the object.
     */
    private function describeStructure(mixed $value): string
    {
        try {
            return json_encode(
                $value,
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
            );
        } catch (\JsonException) {
            // The value cannot be encoded - for example because it holds itself.
            return get_debug_type($value);
        }
    }
}
