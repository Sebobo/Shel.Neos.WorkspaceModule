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
     * The labels of the properties are translation ids in the extended form
     * "Package:Source:Id", which the translator only resolves when it is told which part is
     * which.
     */
    public function translateLabel(string $label, string $defaultPackageKey = ''): string
    {
        $parts = explode(':', $label);
        if (count($parts) !== 3) {
            return $this->translateById($label, $defaultPackageKey);
        }

        return (string)$this->translator->translateById(
            $parts[2],
            [],
            null,
            null,
            str_replace('.', '/', $parts[1]),
            $parts[0]
        );
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
