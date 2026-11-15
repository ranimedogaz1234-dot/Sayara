<?php

namespace App\Tests\Reservation;

use App\Entity\Reservation;
use App\Entity\User;
use App\Entity\Voiture;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Shared fixtures for the reservation regression suite.
 *
 * Database isolation: this branch (test/reservations) was cut from main,
 * which does not have test/security's or fix/profile-password's test-DB
 * fixes - both unmerged. Checking the resolved Doctrine connection
 * parameters directly (no connection opened) before writing any test
 * confirmed APP_ENV=test on this plain-main branch still resolves to
 * var/data.db, the real development database, because dbname_suffix in
 * config/packages/doctrine.yaml (when@test) has no effect on a SQLite file
 * path. Rather than rely on another branch's unmerged fix,
 * phpunit.dist.xml here forces its own DATABASE_URL to
 * sqlite:///var/test_reservations.db (force="true", wins over Dotenv's
 * .env value) - verified through the real test runner with a throwaway
 * canary test before trusting it, removed afterward. bootWithFixtures()
 * below re-asserts that path on every run as a live safety net: if this
 * ever resolves to var/data.db again, every test in this suite fails
 * loudly before touching a single row of it.
 */
abstract class ReservationTestCase extends WebTestCase
{
    protected function bootWithFixtures(): KernelBrowser
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $this->assertIsolatedDatabase($em);

        $em->createQuery('DELETE FROM '.Reservation::class)->execute();
        $em->createQuery('DELETE FROM '.User::class)->execute();
        $em->createQuery('DELETE FROM '.Voiture::class)->execute();

        return $client;
    }

    private function assertIsolatedDatabase(EntityManagerInterface $em): void
    {
        $params = $em->getConnection()->getParams();
        $path = $params['path'] ?? $params['url'] ?? '';

        self::assertStringContainsString(
            'test_reservations.db',
            $path,
            'Refusing to run: the test suite is not connected to the isolated '
            .'database (resolved to "'.$path.'"). This must never resolve to '
            .'var/data.db.',
        );
    }

    protected function makeUser(EntityManagerInterface $em, string $username, string $password, string $role = 'ROLE_USER'): User
    {
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);

        $user = new User();
        $user->setUsername($username);
        $user->setNom('Test '.$username);
        $user->setEmail($username.'@example.test');
        $user->setRole($role);
        $user->setPassword($hasher->hashPassword($user, $password));

        $em->persist($user);
        $em->flush();

        return $user;
    }

    protected function makeVoiture(EntityManagerInterface $em, bool $disponible = true, float $prixParJour = 50.0): Voiture
    {
        $voiture = new Voiture();
        $voiture->setMarque('Test');
        $voiture->setModele('Model');
        $voiture->setPrixParJour($prixParJour);
        $voiture->setDisponible($disponible);

        $em->persist($voiture);
        $em->flush();

        return $voiture;
    }

    /**
     * Bypasses the form entirely - used for fixtures only. Mirrors what
     * ReservationController::new() does, including marking the car
     * unavailable, so tests that need an *existing* reservation start from
     * a state the real controller could have produced.
     */
    protected function makeReservation(
        EntityManagerInterface $em,
        User $client,
        Voiture $voiture,
        ?\DateTime $dateDebut = null,
        ?\DateTime $dateFin = null,
        string $statut = 'pending',
    ): Reservation {
        $dateDebut ??= new \DateTime('+1 day');
        $dateFin ??= new \DateTime('+3 days');

        $days = $dateFin->diff($dateDebut)->days + 1;

        $reservation = new Reservation();
        $reservation->setClient($client);
        $reservation->setVoiture($voiture);
        $reservation->setDateDebut($dateDebut);
        $reservation->setDateFin($dateFin);
        $reservation->setPrixTotal($days * $voiture->getPrixParJour());
        $reservation->setStatut($statut);

        $voiture->setDisponible(false);

        $em->persist($reservation);
        $em->flush();

        return $reservation;
    }
}
