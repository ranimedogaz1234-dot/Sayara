<?php

namespace App\Twig;

use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;

/**
 * Formats an amount as US dollars, the single currency the site displays:
 * {{ voiture.prixParJour|usd }} -> "$4,800", {{ total|usd(2) }} -> "$9,600.00".
 */
final class PriceExtension extends AbstractExtension
{
    public function getFilters(): array
    {
        return [
            new TwigFilter('usd', $this->usd(...)),
        ];
    }

    public function usd(float|int|string|null $amount, int $decimals = 0): string
    {
        return '$'.number_format((float) $amount, $decimals, '.', ',');
    }
}
