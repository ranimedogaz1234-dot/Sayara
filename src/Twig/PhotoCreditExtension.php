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
            new TwigFunction('hero_credit', $this->heroCredit(...)),
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

        $photo = $this->manifest()['photos'][$filename] ?? null;

        return is_array($photo) && isset($photo['license']) ? $photo : null;
    }

    /**
     * @return array<string, array{author: string, license: string, licenseUrl: string, sourceUrl: string, commonsFile: string}>
     */
    public function photoCredits(): array
    {
        // Photos supplied by the site owner carry no third-party licence and
        // are not listed on the credits page.
        return array_filter($this->manifest()['photos'] ?? [], static fn ($p): bool => is_array($p) && isset($p['license']));
    }

    /**
     * Attribution for the homepage hero image (a composite derived from a
     * Commons photograph), or null when none is recorded.
     *
     * @return array{image: string, caption: string, author: string, license: string, licenseUrl: string, sourceUrl: string, commonsFile: string, modification: string}|null
     */
    public function heroCredit(): ?array
    {
        $hero = $this->manifest()['hero'] ?? null;

        return is_array($hero) ? $hero : null;
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
