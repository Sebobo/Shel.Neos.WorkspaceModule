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
use Neos\ContentRepository\Domain\Service\ContentDimensionPresetSourceInterface;
use Neos\Flow\Annotations as Flow;
use Neos\Neos\Service\IconNameMappingService;

/**
 * The content dimensions of the installation and the choice of the presets of the review.
 */
#[Flow\Scope('singleton')]
final class ContentDimensionService
{
    public function __construct(
        private readonly ContentDimensionPresetSourceInterface $contentDimensionPresetSource,
        private readonly IconNameMappingService $iconNameMappingService,
        private readonly NodeLabelService $nodeLabelService,
    ) {
    }

    /**
     * The content dimensions of the installation and the presets that can be chosen for them.
     *
     * An installation without content dimensions has no configuration at all, and the review
     * shows no switcher then.
     *
     * @param array<string, string> $selection the selected preset per dimension
     * @return array<string, array<string,mixed>>
     */
    public function getDimensionConfiguration(array $selection = []): array
    {
        $dimensions = [];
        foreach ($this->contentDimensionPresetSource->getAllPresets() as $dimensionName => $dimensionConfiguration) {
            $dimensionName = (string)$dimensionName;
            $selectedPreset = $selection[$dimensionName] ?? '';
            $presets = [
                [
                    'id' => '',
                    'label' => $this->nodeLabelService->translateLabel('Shel.Neos.WorkspaceModule:Main:review.dimension.all'),
                    'values' => [],
                    'selected' => $selectedPreset === '',
                    // The selection that the choice of this preset leads to.
                    'selection' => $this->selectionWith($selection, $dimensionName, ''),
                ],
            ];
            foreach ($dimensionConfiguration['presets'] ?? [] as $presetIdentifier => $presetConfiguration) {
                $presetIdentifier = (string)$presetIdentifier;
                $presets[] = [
                    'id' => $presetIdentifier,
                    'label' => $this->nodeLabelService->translateLabel((string)($presetConfiguration['label'] ?? $presetIdentifier)),
                    'values' => array_values((array)($presetConfiguration['values'] ?? [])),
                    'selected' => $selectedPreset === $presetIdentifier,
                    'selection' => $this->selectionWith($selection, $dimensionName, $presetIdentifier),
                ];
            }
            $dimensions[$dimensionName] = [
                'name' => $dimensionName,
                'label' => $this->nodeLabelService->translateLabel((string)($dimensionConfiguration['label'] ?? $dimensionName)),
                'icon' => $this->iconNameMappingService->convert((string)($dimensionConfiguration['icon'] ?? '')),
                'selectedPreset' => $selectedPreset,
                // The label of the preset that is shown, the one of all variants when nothing
                // is selected.
                'selectedLabel' => $this->labelOfSelectedPreset($presets, $selectedPreset),
                'presets' => $presets,
            ];
        }

        return $dimensions;
    }

    /**
     * The selection that results from choosing a preset of a dimension.
     *
     * A dimension without a preset is not part of the selection: it shows every variant.
     *
     * @param array<string, string> $selection the selected preset per dimension
     * @return string the "dimension:preset" pairs, separated by a comma
     */
    private function selectionWith(array $selection, string $dimensionName, string $presetIdentifier): string
    {
        $selection[$dimensionName] = $presetIdentifier;
        $parts = [];
        foreach ($selection as $name => $identifier) {
            if ((string)$identifier === '') {
                continue;
            }
            $parts[] = $name . ':' . $identifier;
        }

        return implode(',', $parts);
    }

    /**
     * The label of the preset that is selected, read from the list of the presets.
     *
     * @param list<array<string,mixed>> $presets
     */
    private function labelOfSelectedPreset(array $presets, string $selectedPreset): string
    {
        foreach ($presets as $preset) {
            if ((string)$preset['id'] === $selectedPreset) {
                return (string)$preset['label'];
            }
        }

        return (string)($presets[0]['label'] ?? '');
    }

    /**
     * The presets that are selected, read from the request.
     *
     * The selection is a list of "dimension:preset" pairs, so that it survives in the url of
     * the review and can be shared.
     *
     * @return array<string, string> the identifier of the preset per dimension
     */
    public function parseDimensionSelection(string $selection): array
    {
        $selected = [];
        foreach (explode(',', $selection) as $part) {
            if (!str_contains($part, ':')) {
                continue;
            }
            [$dimensionName, $presetIdentifier] = explode(':', $part, 2);
            $selected[$dimensionName] = $presetIdentifier;
        }

        return $selected;
    }

    /**
     * The dimension values of the selected presets, as the content context expects them.
     *
     * @param array<string, string> $selection
     * @return array<string, list<string>>
     */
    public function getDimensionValues(array $selection): array
    {
        $configuration = $this->contentDimensionPresetSource->getAllPresets();
        $values = [];
        foreach ($selection as $dimensionName => $presetIdentifier) {
            foreach ((array)($configuration[$dimensionName]['presets'][$presetIdentifier]['values'] ?? []) as $value) {
                $values[$dimensionName][] = (string)$value;
            }
        }

        return $values;
    }

    /**
     * The dimensions of a node as the labels the core shows them with.
     *
     * A node carries a list of values per dimension, and every one of them belongs to a preset
     * of its own.
     *
     * @return list<string>
     */
    public function describeDimensions(NodeInterface $node): array
    {
        $dimensions = [];
        foreach ($node->getDimensions() as $dimensionName => $dimensionValues) {
            foreach ((array)$dimensionValues as $dimensionValue) {
                $dimensions[] = $this->dimensionLabel((string)$dimensionName, (string)$dimensionValue);
            }
        }

        return $dimensions;
    }

    /**
     * The label of the preset a dimension value belongs to, the way the core shows them.
     */
    private function dimensionLabel(string $dimensionName, string $dimensionValue): string
    {
        // The core returns null when no preset fits the value, although its annotation says
        // that it returns an array.
        /** @var array<string, mixed>|null $preset */
        $preset = $this->contentDimensionPresetSource->findPresetByDimensionValues($dimensionName, [$dimensionValue]);
        if ($preset === null) {
            return $dimensionValue;
        }

        return $this->nodeLabelService->translateLabel((string)($preset['label'] ?? $dimensionValue));
    }
}
