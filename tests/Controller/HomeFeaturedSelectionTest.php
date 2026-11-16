<?php

namespace App\Tests\Controller;

use App\Entity\Voiture;
use App\Tests\Security\SecurityTestCase;
use Doctrine\ORM\EntityManagerInterface;

/**
 * The homepage selection shows the cars pinned in app.featured_selection
 * first, in that order, then fills up to nine cards with the most expensive
 * other available cars. Every card links to a working details page.
 *
 * Runs against the isolated test database (asserted by SecurityTestCase).
 */
class HomeFeaturedSelectionTest extends SecurityTestCase
{
    private function car(EntityManagerInterface $em, string $marque, string $modele, float $price, bool $available = true): Voiture
    {
        $voiture = (new Voiture())
            ->setMarque($marque)
            ->setModele($modele)
            ->setPrixParJour($price)
            ->setDisponible($available);
        $em->persist($voiture);

        return $voiture;
    }

    public function testPinnedCarsLeadTheSelectionAndEveryCardLinkWorks(): void
    {
        $client = $this->bootWithFixtures();
        $em = static::getContainer()->get(EntityManagerInterface::class);

        // Pinned cars are cheaper than the others on purpose: price order
        // alone would leave them out of the selection.
        $this->car($em, 'Rolls-Royce', 'Cullinan — Black & Gold', 2200);
        $this->car($em, 'Lamborghini', 'Urus — Grey', 1400);
        $this->car($em, 'Rolls-Royce', 'Cullinan — Emerald Green', 1800);
        $this->car($em, 'Lamborghini', 'Urus — Black', 1500);
        foreach (range(1, 8) as $i) {
            $this->car($em, 'Brand'.$i, 'Model '.$i, 5000 + $i * 100);
        }
        $this->car($em, 'Hidden', 'Unavailable', 99000, false);
        $em->flush();

        $crawler = $client->request('GET', '/');
        self::assertResponseIsSuccessful();

        $names = $crawler->filter('.lx-card__name a')->each(static fn ($a): string => trim($a->text()));
        self::assertCount(9, $names);
        self::assertSame(
            ['Cullinan — Emerald Green', 'Urus — Grey', 'Urus — Black', 'Cullinan — Black & Gold'],
            array_slice($names, 0, 4),
        );
        // Then the priciest others, highest first; never an unavailable car.
        self::assertSame(['Model 8', 'Model 7', 'Model 6', 'Model 5', 'Model 4'], array_slice($names, 4));
        self::assertNotContains('Unavailable', $names);

        foreach ($crawler->filter('.lx-card__name a')->links() as $link) {
            $client->click($link);
            self::assertResponseIsSuccessful();
        }
    }
}
