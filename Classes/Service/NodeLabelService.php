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

use Neos\ContentRepository\Domain\Model\NodeInterface;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\I18n\Translator;
use Neos\Neos\Service\IconNameMappingService;

/**
 * The labels and the icons of the nodes.
 * TODO: Check if we can get the labels from the nodes nodetype directly instead.
 */
#[Flow\Scope('singleton')]
final class NodeLabelService
{
    public function __construct(
        private readonly Translator $translator,
        private readonly IconNameMappingService $iconNameMappingService,
    ) {
    }

    /**
     * The icon of the node type, with the prefix of the font.
     *
     * The node types carry the icon in the older notation ("icon-dollar"), which the core
     * maps when it loads the node type, or in the modern one ("dollar"), which comes without
     * the prefix of the font - the neos.ui adds the default one, and so does this.
     */
    public function iconOf(NodeInterface $node): ?string
    {
        $icon = $node->getNodeType()->getConfiguration('ui.icon');
        if (!is_string($icon) || $icon === '') {
            return null;
        }
        if (str_contains($icon, ' ')) {
            return $this->iconNameMappingService->convert($icon);
        }

        return $this->iconNameMappingService->convert('icon-' . $icon);
    }

    /**
     * The label of the node type, the one a human sees in the editor.
     *
     * The name of a node type ("My.Vendor:Content.Something") is an
     * internal detail; the label is what the node type shows itself as ("Something"). A node type
     * that says "i18n" has no label of its own and is translated by its name instead.
     */
    public function nodeTypeLabel(NodeInterface $node): string
    {
        $nodeType = $node->getNodeType();
        $label = trim((string)$nodeType->getLabel());
        if ($label !== '' && $label !== 'i18n' && !str_contains($label, '${')) {
            $name = $nodeType->getName();
            $packageKey = str_contains($name, ':') ? explode(':', $name, 2)[0] : '';

            return $this->translateLabel($label, $packageKey);
        }

        $name = $nodeType->getName();
        // the name of a node type is "Package:Name", and the translations of the node types
        // live in the catalogue of that package
        [$packageKey, $shortName] = str_contains($name, ':') ? explode(':', $name, 2) : ['', $name];
        $translated = $this->translateById('ui.label', $packageKey, 'NodeTypes/' . $shortName);

        return $translated === 'ui.label' ? $name : $translated;
    }

    /**
     * The label of a property of a node.
     */
    public function propertyLabel(string $propertyName, NodeInterface $node): string
    {
        $properties = $node->getNodeType()->getProperties();
        if (!isset($properties[$propertyName]['ui']['label'])) {
            return $propertyName;
        }

        return $this->translateLabel((string)$properties[$propertyName]['ui']['label']);
    }

    /**
     * The label of the value of a property, if the property is a select box.
     *
     * A select box stores the value that was chosen ("red"), while the editor shows the
     * label of the choice ("Red"). The review shows the label, like the editor does.
     *
     * @return string|null the label of the value, or null if the property is no select box
     *                     or the value is not one of its choices
     */
    public function selectValueLabel(string $propertyName, NodeInterface $node, mixed $value): ?string
    {
        $choices = $this->selectChoices($propertyName, $node);
        if ($choices === []) {
            return null;
        }

        if (is_array($value)) {
            // A select box that holds more than one value.
            $labels = [];
            foreach ($value as $entry) {
                $label = $this->labelOfChoice($choices, $entry);
                if ($label !== null) {
                    $labels[] = $label;
                }
            }

            return $labels === [] ? null : implode(', ', $labels);
        }

        return $this->labelOfChoice($choices, $value);
    }

    /**
     * The choices of a select box property.
     *
     * The choices are part of the node type: the editor takes them from there, and so does
     * the review.
     *
     * @return array<string, array<string, mixed>>
     */
    private function selectChoices(string $propertyName, NodeInterface $node): array
    {
        $properties = $node->getNodeType()->getProperties();
        $uiConfiguration = $properties[$propertyName]['ui'] ?? null;
        if (!is_array($uiConfiguration)) {
            return [];
        }

        $values = $uiConfiguration['inspector']['editorOptions']['values']
            ?? $uiConfiguration['editorOptions']['values']
            ?? null;
        if (!is_array($values)) {
            return [];
        }

        $choices = [];
        foreach ($values as $value => $choice) {
            if (is_array($choice)) {
                $choices[(string)$value] = $choice;
            }
        }

        return $choices;
    }

    /**
     * The label of one choice of a select box.
     *
     * @param array<string, array<string, mixed>> $choices
     */
    private function labelOfChoice(array $choices, mixed $value): ?string
    {
        if (!is_string($value) && !is_int($value)) {
            return null;
        }

        $choice = $choices[(string)$value] ?? null;
        if (!is_array($choice)) {
            return null;
        }

        $label = $choice['label'] ?? null;
        if (!is_string($label) || trim($label) === '') {
            return null;
        }

        return $this->translateLabel($label);
    }

    /**
     * The labels of the properties are translation ids in the extended form
     * "Package:Source:Id", which the translator only resolves when it is told which part is
     * which.
     *
     * A label that is no translation id - "Red" for example - is returned as it is, and so is
     * a label whose id cannot be resolved.
     */
    public function translateLabel(string $label, string $defaultPackageKey = ''): string
    {
        $parts = explode(':', $label);
        if (count($parts) !== 3) {
            return $this->translateById($label, $defaultPackageKey);
        }

        $translated = (string)$this->translator->translateById(
            $parts[2],
            [],
            null,
            null,
            str_replace('.', '/', $parts[1]),
            $parts[0]
        );

        return $translated === '' ? $label : $translated;
    }

    /**
     * Translates an id, and returns it unchanged when there is no translation for it.
     */
    public function translateById(string $id, string $packageKey = '', string $sourceName = 'Main'): string
    {
        $translated = (string)$this->translator->translateById($id, [], null, null, $sourceName, $packageKey);

        return $translated === '' ? $id : $translated;
    }
}
