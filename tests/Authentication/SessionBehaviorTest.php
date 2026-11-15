<?php

namespace App\Tests\Authentication;

use App\Tests\Security\SecurityTestCase;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Session behaviour not covered by the already-merged
 * tests/Security/AuthenticationSessionTest (which posts directly to /login
 * and never visits a protected page first, so it cannot observe Symfony's
 * target-path redirect) or anything else in that suite: being sent back to
 * the page that originally required login, and the "remember me" checkbox
 * security.yaml's remember_me key configures.
 */
class SessionBehaviorTest extends SecurityTestCase
{
    private function submitLogin(
        \Symfony\Bundle\FrameworkBundle\KernelBrowser $client,
        string $username,
        string $password,
        bool $rememberMe = false,
    ): void {
        $token = static::getContainer()->get('security.csrf.token_manager')
            ->getToken('authenticate')->getValue();

        $data = [
            'username' => $username,
            'password' => $password,
            '_csrf_token' => $token,
        ];
        if ($rememberMe) {
            $data['_remember_me'] = 'on';
        }

        // See AuthenticationSessionTest for why HTTP_ORIGIN is required:
        // 'authenticate' is a stateless CSRF token id, validated by
        // Origin/Referer rather than a secret.
        $client->request('POST', '/login', $data, [], ['HTTP_ORIGIN' => 'http://localhost']);
    }

    public function testLoginRedirectsBackToTheOriginallyRequestedPage(): void
    {
        $client = $this->bootWithFixtures();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->makeUser($em, 'renter', 'RenterPass123');

        // Visiting a protected page while anonymous records it as the
        // target path in the session.
        $client->request('GET', '/profile/edit');
        self::assertResponseRedirects('/login');

        $this->submitLogin($client, 'renter', 'RenterPass123');

        self::assertResponseRedirects(
            '/profile/edit',
            null,
            'after login the user should land back on the page they originally asked for, not just the homepage',
        );
    }

    public function testLoginWithoutAPriorTargetGoesToTheDefault(): void
    {
        $client = $this->bootWithFixtures();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->makeUser($em, 'renter', 'RenterPass123');

        // No protected page was visited first - SecurityController::login()
        // computes the destination itself in this case (ROLE_USER -> '/').
        $this->submitLogin($client, 'renter', 'RenterPass123');

        self::assertResponseRedirects('/');
    }

    public function testRememberMeCookieIsSetWhenChecked(): void
    {
        $client = $this->bootWithFixtures();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->makeUser($em, 'renter', 'RenterPass123');

        $this->submitLogin($client, 'renter', 'RenterPass123', rememberMe: true);

        $names = array_map(static fn ($c) => $c->getName(), $client->getCookieJar()->all());
        self::assertContains('REMEMBERME', $names, 'security.yaml configures remember_me; checking the box must set its cookie');
    }

    public function testRememberMeCookieIsNotSetWhenUnchecked(): void
    {
        $client = $this->bootWithFixtures();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->makeUser($em, 'renter', 'RenterPass123');

        $this->submitLogin($client, 'renter', 'RenterPass123', rememberMe: false);

        $names = array_map(static fn ($c) => $c->getName(), $client->getCookieJar()->all());
        self::assertNotContains('REMEMBERME', $names, 'always_remember_me is false, so an unchecked box must not persist the session');
    }
}
