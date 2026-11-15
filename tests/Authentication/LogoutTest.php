<?php

namespace App\Tests\Authentication;

use App\Tests\Security\SecurityTestCase;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Logout and session invalidation. Nothing in the already-merged
 * tests/Security/* suite exercises /logout at all - AuthenticationSessionTest
 * covers login, login_throttling and password hashing only.
 *
 * security.yaml sets logout.enable_csrf: true, so logout_path() (used in
 * templates/base.html.twig) appends a _csrf_token query parameter
 * automatically - these tests read that real, rendered link rather than
 * hand-constructing one.
 */
class LogoutTest extends SecurityTestCase
{
    private function isAuthenticated(\Symfony\Bundle\FrameworkBundle\KernelBrowser $client): bool
    {
        $client->request('GET', '/profile');

        return 200 === $client->getResponse()->getStatusCode();
    }

    public function testLogoutEndsTheSession(): void
    {
        $client = $this->bootWithFixtures();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $user = $this->makeUser($em, 'renter', 'RenterPass123');

        $client->loginUser($user);
        self::assertTrue($this->isAuthenticated($client), 'precondition: the user must start out logged in');

        $crawler = $client->request('GET', '/');
        $logoutHref = $crawler->filter('a[href^="/logout"]')->attr('href');
        self::assertNotNull($logoutHref, 'expected a rendered logout link with a real CSRF token');

        $client->request('GET', $logoutHref);

        self::assertFalse(
            $this->isAuthenticated($client),
            'a request to a protected route must be rejected once the user has logged out',
        );
    }

    public function testLogoutWithoutAValidCsrfTokenIsRejected(): void
    {
        $client = $this->bootWithFixtures();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $user = $this->makeUser($em, 'renter', 'RenterPass123');

        $client->loginUser($user);
        self::assertTrue($this->isAuthenticated($client));

        $client->request('GET', '/logout?_csrf_token=forged-not-a-real-token');

        self::assertTrue(
            $this->isAuthenticated($client),
            'a forged CSRF token must not be able to log the user out',
        );
    }

    public function testAnonymousRequestToLogoutDoesNotError(): void
    {
        $client = $this->bootWithFixtures();

        // /logout is intercepted by the firewall's logout listener before
        // SecurityController::logout()'s own body (a \LogicException) ever
        // runs - even for a request with no session to end.
        $client->request('GET', '/logout');

        self::assertLessThan(500, $client->getResponse()->getStatusCode());
    }
}
