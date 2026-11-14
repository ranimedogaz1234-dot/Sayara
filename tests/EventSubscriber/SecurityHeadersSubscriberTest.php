<?php

namespace App\Tests\EventSubscriber;

use App\EventSubscriber\SecurityHeadersSubscriber;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

/**
 * Unit coverage for the security response headers.
 *
 * Deliberately a plain TestCase: it never boots the kernel and never opens a
 * database connection.
 */
class SecurityHeadersSubscriberTest extends TestCase
{
    private function respond(
        string $environment = 'prod',
        string $path = '/',
        bool $https = false,
        int $requestType = HttpKernelInterface::MAIN_REQUEST,
    ): Response {
        $request = Request::create(($https ? 'https' : 'http').'://carrent.test'.$path);
        $response = new Response();

        $subscriber = new SecurityHeadersSubscriber($environment);
        $subscriber->onKernelResponse(new ResponseEvent(
            $this->createStub(HttpKernelInterface::class),
            $request,
            $requestType,
            $response,
        ));

        return $response;
    }

    public function testBaselineHeadersArePresent(): void
    {
        $headers = $this->respond()->headers;

        self::assertSame('nosniff', $headers->get('X-Content-Type-Options'));
        self::assertSame('DENY', $headers->get('X-Frame-Options'));
        self::assertSame('strict-origin-when-cross-origin', $headers->get('Referrer-Policy'));
        self::assertNotNull($headers->get('Permissions-Policy'));
        self::assertNotNull($headers->get('Content-Security-Policy'));
    }

    public function testClickjackingIsBlockedByBothMechanisms(): void
    {
        $headers = $this->respond()->headers;

        self::assertSame('DENY', $headers->get('X-Frame-Options'));
        self::assertStringContainsString("frame-ancestors 'none'", $headers->get('Content-Security-Policy'));
    }

    /**
     * Every host the templates actually load from has to be allowed, otherwise
     * the layout silently loses its CSS, fonts, scripts or avatars.
     *
     */
    #[DataProvider('provideRequiredCspSources')]
    public function testCspAllowsTheResourcesTheTemplatesUse(string $directive, string $source): void
    {
        $csp = $this->respond()->headers->get('Content-Security-Policy');

        $matched = null;
        foreach (explode('; ', $csp) as $candidate) {
            if (str_starts_with($candidate, $directive.' ')) {
                $matched = $candidate;
                break;
            }
        }

        self::assertNotNull($matched, sprintf('the CSP has no "%s" directive', $directive));
        self::assertStringContainsString($source, $matched);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function provideRequiredCspSources(): iterable
    {
        yield 'bootstrap/jquery/fontawesome scripts' => ['script-src', 'https://cdnjs.cloudflare.com'];
        yield 'bootstrap/sb-admin stylesheets' => ['style-src', 'https://cdnjs.cloudflare.com'];
        yield 'google fonts stylesheet' => ['style-src', 'https://fonts.googleapis.com'];
        yield 'google font files' => ['font-src', 'https://fonts.gstatic.com'];
        yield 'font awesome font files' => ['font-src', 'https://cdnjs.cloudflare.com'];
        yield 'generated avatars' => ['img-src', 'https://ui-avatars.com'];
        yield 'uploaded vehicle images' => ['img-src', "'self'"];
    }

    /**
     * The inline confirm() handlers on the delete forms cannot be covered by a
     * nonce, so this exception is intentional and asserted, not accidental.
     */
    public function testInlineExceptionsAreLimitedToScriptAndStyle(): void
    {
        $csp = $this->respond()->headers->get('Content-Security-Policy');
        $directives = [];
        foreach (explode('; ', $csp) as $directive) {
            [$name] = explode(' ', $directive, 2);
            $directives[$name] = $directive;
        }

        self::assertStringContainsString("'unsafe-inline'", $directives['script-src']);
        self::assertStringContainsString("'unsafe-inline'", $directives['style-src']);

        // Nothing else may be relaxed.
        self::assertStringNotContainsString("'unsafe-eval'", $csp, 'no bundled library needs eval');
        foreach (['default-src', 'connect-src', 'form-action', 'base-uri', 'object-src', 'img-src', 'font-src'] as $name) {
            self::assertStringNotContainsString(
                "'unsafe-inline'",
                $directives[$name],
                sprintf('%s must not allow inline content', $name),
            );
        }
    }

    public function testHstsOnlyOnSecureProductionRequests(): void
    {
        self::assertNotNull(
            $this->respond('prod', '/', true)->headers->get('Strict-Transport-Security'),
            'HSTS belongs on HTTPS production responses',
        );

        self::assertNull(
            $this->respond('prod', '/', false)->headers->get('Strict-Transport-Security'),
            'HSTS must not be sent over plain HTTP',
        );
        self::assertNull(
            $this->respond('dev', '/', true)->headers->get('Strict-Transport-Security'),
            'HSTS must not be pinned from a development machine',
        );
    }

    public function testDevelopmentToolingIsLeftAlone(): void
    {
        foreach (['/_profiler/abc123', '/_wdt/abc123'] as $path) {
            self::assertNull(
                $this->respond('dev', $path)->headers->get('Content-Security-Policy'),
                sprintf('%s should keep working untouched', $path),
            );
        }
    }

    public function testSubRequestsAreIgnored(): void
    {
        self::assertNull(
            $this->respond('prod', '/', false, HttpKernelInterface::SUB_REQUEST)
                ->headers->get('Content-Security-Policy'),
        );
    }
}
