<?php

namespace App\EventSubscriber;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Adds the security response headers the application relies on.
 *
 * The Content-Security-Policy below was derived from the resources the
 * templates actually load:
 *
 *   - cdnjs.cloudflare.com  jQuery, Bootstrap 4, Font Awesome, SB Admin 2
 *                           (10 stylesheets and 10 scripts)
 *   - fonts.googleapis.com  the Nunito stylesheet, whose font files are then
 *                           served from fonts.gstatic.com
 *   - ui-avatars.com        generated avatar images
 *
 * Two relaxations are deliberate, because removing them would mean rewriting
 * templates and changing behaviour:
 *
 *   - script-src keeps 'unsafe-inline' for four inline handlers that call
 *     confirm() before a delete (reservation, user and voiture delete forms
 *     plus voiture/show.html.twig). Inline event-handler attributes cannot be
 *     authorised by a nonce or a hash, only by 'unsafe-inline', so a stricter
 *     policy would silently drop the delete confirmations.
 *   - style-src keeps 'unsafe-inline' for the ~105 style="..." attributes and
 *     the <style> blocks the layout is built from. Nonces do not apply to
 *     style attributes either.
 *
 * 'unsafe-eval' is NOT granted: none of the bundled libraries need it.
 */
final class SecurityHeadersSubscriber implements EventSubscriberInterface
{
    /**
     * Paths owned by the Symfony development tooling. They are left untouched
     * so the profiler and the debug toolbar keep working as they do today.
     */
    private const EXCLUDED_PATH_PREFIXES = ['/_profiler', '/_wdt'];

    private const CSP_DIRECTIVES = [
        "default-src 'self'",
        "script-src 'self' https://cdnjs.cloudflare.com 'unsafe-inline'",
        "style-src 'self' https://cdnjs.cloudflare.com https://fonts.googleapis.com 'unsafe-inline'",
        "font-src 'self' https://fonts.gstatic.com https://cdnjs.cloudflare.com data:",
        "img-src 'self' https://ui-avatars.com data:",
        "connect-src 'self'",
        "form-action 'self'",
        "base-uri 'self'",
        "object-src 'none'",
        "frame-ancestors 'none'",
    ];

    public function __construct(
        #[Autowire('%kernel.environment%')]
        private readonly string $environment,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::RESPONSE => 'onKernelResponse'];
    }

    public function onKernelResponse(ResponseEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        foreach (self::EXCLUDED_PATH_PREFIXES as $prefix) {
            if (str_starts_with($request->getPathInfo(), $prefix)) {
                return;
            }
        }

        $headers = $event->getResponse()->headers;

        $headers->set('X-Content-Type-Options', 'nosniff');
        // frame-ancestors in the CSP covers modern browsers; this stays for old ones.
        $headers->set('X-Frame-Options', 'DENY');
        $headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $headers->set('Permissions-Policy', 'geolocation=(), microphone=(), camera=()');
        $headers->set('Content-Security-Policy', implode('; ', self::CSP_DIRECTIVES));

        // HSTS is only meaningful over HTTPS, and pinning it from a development
        // machine would make http://localhost unreachable for other projects.
        if ('prod' === $this->environment && $request->isSecure()) {
            $headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }
    }
}
