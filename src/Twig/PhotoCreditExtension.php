<?php

namespace App\Twig;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * Exposes the photo attributions recorded in config/preview/fleet.json, so
 * every Creative Commons photo can be credited where it is displayed and on
 * the /credits page. Returns null for images with no recorded credit (for
 * example a photo an administrator uploaded themselves).
 */
final class PhotoCreditExtension extends AbstractExtension
{
    /** @var array<string, mixed>|null */
    private ?array $manifest = null;

    public function __construct(
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir,
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('photo_credit', $this->photoCredit(...)),
            new TwigFunction('photo_credits', $this->photoCredits(...)),
        ];
    }

    /**
     * @return array{author: string, license: string, licenseUrl: string, sourceUrl: string}|null
     */
    public function photoCredit(?string $filename): ?array
    {
        if (null === $filename) {
            return null;
        }

        return $this->manifest()['photos'][$filename] ?? null;
    }

    /**
     * @return array<string, array{author: string, license: string, licenseUrl: string, sourceUrl: string, commonsFile: string}>
     */
    public function photoCredits(): array
    {
        return $this->manifest()['photos'] ?? [];
    }

    /**
     * @return array<string, mixed>
     */
    private function manifest(): array
    {
        if (null === $this->manifest) {
            $file = $this->projectDir.'/config/preview/fleet.json';
            $this->manifest = is_file($file)
                ? (json_decode((string) file_get_contents($file), true) ?: [])
                : [];
        }

        return $this->manifest;
    }
}
