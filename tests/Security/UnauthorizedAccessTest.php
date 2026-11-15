<?php

namespace App\Tests\Security;

use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Requirement 1: unauthorized (anonymous) access to protected routes.
 *
 * Every route here requires authentication. An anonymous request must be
 * redirected to the login page, never served the protected content.
 */
class UnauthorizedAccessTest extends SecurityTestCase
{
    #[DataProvider('provideProtectedRoutes')]
    public function testAnonymousRequestIsRedirectedToLogin(string $path): void
    {
        $client = $this->bootWithFixtures();

        $client->request('GET', $path);

        self::assertResponseRedirects('/login', null, sprintf('%s must not be reachable anonymously', $path));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideProtectedRoutes(): iterable
    {
        yield 'admin dashboard' => ['/dashboard'];
        yield 'user list (admin)' => ['/user'];
        yield 'vehicle list (admin)' => ['/voiture'];
        yield 'reservation list' => ['/reservation'];
        yield 'new reservation' => ['/reservation/new'];
        yield 'profile' => ['/profile'];
        yield 'profile edit' => ['/profile/edit'];
    }

    public function testPublicRoutesStayReachable(): void
    {
        $client = $this->bootWithFixtures();

        foreach (['/', '/voitures', '/login', '/signup'] as $path) {
            $client->request('GET', $path);
            self::assertResponseIsSuccessful(sprintf('%s should stay public', $path));
        }
    }
}
