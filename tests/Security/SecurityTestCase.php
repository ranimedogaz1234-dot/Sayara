<?php

namespace App\Tests\Security;

use App\Entity\Reservation;
use App\Entity\User;
use App\Entity\Voiture;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Shared fixtures for the security regression suite.
 *
 * Database isolation: phpunit.dist.xml forces DATABASE_URL to
 * sqlite:///var/test_security.db for every test process (force="true",
 * verified to win over the value Dotenv loads from .env). Before relying on
 * that, the resolved Doctrine connection parameters were inspected directly
 * - without ever opening a connection - via a throwaway canary test, which
 * printed var/test_security.db and never var/data.db. setUpFixtures() below
 * re-asserts that path on every run as a second, live safety check: if a
 * future change to config ever points the test suite back at the real
 * database, these tests fail loudly before touching a single row of it,
 * rather than deleting real data again.
 */
abstract class SecurityTestCase extends WebTestCase
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
        $path = str_replace('\\', '/', $params['path'] ?? $params['url'] ?? '');

        // Originally checked for the literal "test_security.db" this branch's
        // own phpunit.dist.xml forces. Since fix/profile-password merged,
        // config/packages/doctrine.yaml's own when@test "url" (a direct
        // config value, not read from DATABASE_URL) takes precedence over
        // that env var override and resolves to var/test.db instead - a
        // different filename, equally isolated. The check is now path-based
        // rather than tied to one specific filename, so it keeps working
        // regardless of which of the two (equally safe) mechanisms wins.
        self::assertMatchesRegularExpression(
            '#/var/test[^/]*\.db$#',
            $path,
            'Refusing to run: the test suite is not connected to an isolated '
            .'var/test*.db file (resolved to "'.$path.'"). This must never '
            .'resolve to var/data.db.',
        );
        self::assertStringNotContainsString(
            '/data.db',
            $path,
            'Refusing to run: resolved to the real development database ("'.$path.'").',
        );
    }

    /**
     * Reads the real CSRF token straight out of the rendered delete form on
     * the reservation index page - the same value a real browser would
     * submit. Minting one through security.csrf.token_manager directly was
     * tried first and does not work here: by the time client->request()
     * returns, HttpKernel has already popped that request off the request
     * stack, so the session-backed token storage has no current request to
     * attach to and throws SessionNotFoundException. Scraping the token
     * from a page rendered for the caller's own session sidesteps that
     * entirely, and is only valid for a reservation the caller can see on
     * their own index - which is exactly what a real attacker would not
     * have for someone else's reservation (see
     * IdorPreventionTest::testUserCannotDeleteAnotherUsersReservation).
     */
    protected function scrapeDeleteToken(KernelBrowser $client, int $reservationId): string
    {
        $crawler = $client->request('GET', '/reservation');
        $input = $crawler->filter('form[action="/reservation/'.$reservationId.'"] input[name="_token"]');

        self::assertGreaterThan(
            0,
            $input->count(),
            'expected a delete form for reservation '.$reservationId.' on the index page',
        );

        return (string) $input->attr('value');
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

    protected function makeVoiture(EntityManagerInterface $em, bool $disponible = true): Voiture
    {
        $voiture = new Voiture();
        $voiture->setMarque('Test');
        $voiture->setModele('Model');
        $voiture->setPrixParJour(50.0);
        $voiture->setDisponible($disponible);

        $em->persist($voiture);
        $em->flush();

        return $voiture;
    }

    protected function makeReservation(EntityManagerInterface $em, User $client, Voiture $voiture): Reservation
    {
        $reservation = new Reservation();
        $reservation->setClient($client);
        $reservation->setVoiture($voiture);
        $reservation->setDateDebut(new \DateTime('+1 day'));
        $reservation->setDateFin(new \DateTime('+3 days'));
        $reservation->setPrixTotal(150.0);

        $em->persist($reservation);
        $em->flush();

        return $reservation;
    }
}
