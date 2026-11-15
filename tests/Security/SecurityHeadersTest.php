<?php

namespace App\Tests\Security;

use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Requirement 6: security headers, including CSP and clickjacking
 * protection.
 *
 * These headers are implemented by App\EventSubscriber\SecurityHeadersSubscriber,
 * added on the separate fix/security-headers branch (commit eed0c11),
 * which is NOT part of this branch (test/security is cut from main, which
 * does not have that subscriber). Copying that class here would duplicate
 * unrelated work from another unmerged branch, which was explicitly ruled
 * out for this task.
 *
 * This test is written against the real response headers and is skipped
 * for now rather than deleted, so it runs for real as soon as
 * fix/security-headers is merged into main (or this branch is rebased onto
 * it) - at that point these assertions, not a new test, are what confirm
 * the headers reach real responses. Until then, no claim is made that this
 * requirement has been verified.
 */
class SecurityHeadersTest extends SecurityTestCase
{
    protected function setUp(): void
    {
        if (!class_exists(\App\EventSubscriber\SecurityHeadersSubscriber::class)) {
            self::markTestSkipped(
                'App\EventSubscriber\SecurityHeadersSubscriber is not present on this branch. '
                .'It is implemented on fix/security-headers (commit eed0c11) but not yet merged '
                .'into main. Merge or rebase to run these assertions for real.',
            );
        }

        parent::setUp();
    }

    public function testClickjackingHeadersArePresent(): void
    {
        $client = $this->bootWithFixtures();
        $client->request('GET', '/');

        self::assertSame('DENY', $client->getResponse()->headers->get('X-Frame-Options'));
        self::assertStringContainsString(
            "frame-ancestors 'none'",
            (string) $client->getResponse()->headers->get('Content-Security-Policy'),
        );
    }

    #[DataProvider('provideExpectedHeaders')]
    public function testHeaderIsPresent(string $header): void
    {
        $client = $this->bootWithFixtures();
        $client->request('GET', '/');

        self::assertNotNull($client->getResponse()->headers->get($header), $header.' must be present');
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideExpectedHeaders(): iterable
    {
        yield 'content type sniffing' => ['X-Content-Type-Options'];
        yield 'referrer policy' => ['Referrer-Policy'];
        yield 'content security policy' => ['Content-Security-Policy'];
        yield 'permissions policy' => ['Permissions-Policy'];
    }
}
