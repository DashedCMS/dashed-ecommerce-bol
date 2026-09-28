<?php

namespace Dashed\DashedEcommerceBol\Classes;

/**
 * Wat de generator per taal voorstelt, met de meldingen van BolTitleRules die
 * na de herkansing nog openstaan.
 */
final class BolTitleProposal
{
    /**
     * @param  array<string, string>  $templates
     * @param  array<string, list<string>>  $warnings
     */
    public function __construct(
        public readonly array $templates,
        public readonly array $warnings,
    ) {
    }

    public function hasWarnings(): bool
    {
        foreach ($this->warnings as $warnings) {
            if ($warnings !== []) {
                return true;
            }
        }

        return false;
    }

    public function warningSummary(): string
    {
        $lines = [];
        foreach ($this->warnings as $locale => $warnings) {
            foreach ($warnings as $warning) {
                $lines[] = strtoupper($locale) . ': ' . $warning;
            }
        }

        return implode(' ', $lines);
    }
}
