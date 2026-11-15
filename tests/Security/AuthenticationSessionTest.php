<?php

namespace App\Tests\Security;

use Doctrine\ORM\EntityManagerInterface;

/**
 * Requirement 5: authentication and session security.
 *
 * Covers: wrong credentials are rejected, correct credentials authenticate,
 * login_throttling (max_attempts: 5 in config/packages/security.yaml) locks
 * out further attempts - including a correct password - once exceeded, and
 * the stored password is never the plaintext value.
 */
class AuthenticationSessionTest extends SecurityTestCase
{
    private function submitLogin(\Symfony\Bundle\FrameworkBundle\KernelBrowser $client, string $username, string $password): void
    {
        $token = static::getContainer()->get('security.csrf.token_manager')
            ->getToken('authenticate')->getValue();

        // config/packages/csrf.yaml puts 'authenticate' in stateless_token_ids,
        // so Symfony's SameOriginCsrfTokenManager does not validate a secret
        // value - it checks the request's Origin/Referer header against the
        // request's own host instead. The test client sends neither header
        // by default, which would make every submission fail CSRF
        // validation regardless of the credentials, turning both the
        // "wrong password" and "correct password" assertions true for the
        // wrong reason. HTTP_ORIGIN is set here to the client's own default
        // host so CSRF validation genuinely passes or fails independently
        // of the credentials being tested.
        $client->request('POST', '/login', [
            'username' => $username,
            'password' => $password,
            '_csrf_token' => $token,
        ], [], ['HTTP_ORIGIN' => 'http://localhost']);
    }

    private function isAuthenticated(\Symfony\Bundle\FrameworkBundle\KernelBrowser $client): bool
    {
        $client->request('GET', '/profile');

        return 200 === $client->getResponse()->getStatusCode();
    }

    public function testWrongPasswordDoesNotAuthenticate(): void
    {
        $client = $this->bootWithFixtures();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->makeUser($em, 'victim', 'CorrectPass123');

        $this->submitLogin($client, 'victim', 'WrongPassword');

        self::assertResponseRedirects('/login');
        self::assertFalse($this->isAuthenticated($client));
    }

    public function testUnknownUsernameDoesNotAuthenticate(): void
    {
        $client = $this->bootWithFixtures();

        $this->submitLogin($client, 'nobody-here', 'WhateverPass123');

        self::assertResponseRedirects('/login');
        self::assertFalse($this->isAuthenticated($client));
    }

    public function testCorrectCredentialsAuthenticate(): void
    {
        $client = $this->bootWithFixtures();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->makeUser($em, 'victim', 'CorrectPass123');

        $this->submitLogin($client, 'victim', 'CorrectPass123');

        self::assertResponseRedirects();
        self::assertTrue($this->isAuthenticated($client));
    }

    /**
     * config/packages/security.yaml sets login_throttling: max_attempts: 5.
     * After 5 failed attempts, the 6th must be blocked even with the
     * correct password.
     */
    public function testLoginThrottlingBlocksAfterRepeatedFailures(): void
    {
        $client = $this->bootWithFixtures();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->makeUser($em, 'victim', 'CorrectPass123');

        for ($i = 0; $i < 5; ++$i) {
            $this->submitLogin($client, 'victim', 'WrongPassword'.$i);
        }

        // The 6th attempt uses the CORRECT password; throttling must still
        // block it if the limit was reached.
        $this->submitLogin($client, 'victim', 'CorrectPass123');

        self::assertFalse(
            $this->isAuthenticated($client),
            'the correct password must still be rejected once the attempt limit is reached',
        );
    }

    public function testStoredPasswordIsNeverThePlaintextValue(): void
    {
        $this->bootWithFixtures();
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $user = $this->makeUser($em, 'victim', 'CorrectPass123');

        self::assertNotSame('CorrectPass123', $user->getPassword());
        self::assertGreaterThan(20, \strlen((string) $user->getPassword()), 'a bcrypt/argon hash is expected, not a short value');
    }
}
