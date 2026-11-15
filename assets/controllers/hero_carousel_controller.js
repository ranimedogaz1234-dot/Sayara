import { Controller } from '@hotwired/stimulus';

/**
 * Hero vehicle carousel. Slides are server-rendered (real vehicles passed
 * from HomeController::index()); this controller only toggles which one
 * is visible. All slides stay in the DOM and visible-by-default via CSS,
 * so a JS failure never hides the featured vehicles entirely.
 */
export default class extends Controller {
    static targets = ['slide', 'dots'];

    connect() {
        this.index = 0;
        this.timer = null;

        const prefersReduced = window.matchMedia
            && window.matchMedia('(prefers-reduced-motion: reduce)').matches;

        if (!prefersReduced && this.slideTargets.length > 1) {
            this.timer = window.setInterval(() => this.next(), 6000);
        }
    }

    disconnect() {
        if (this.timer) {
            window.clearInterval(this.timer);
        }
    }

    next() {
        this.goTo({ params: { index: (this.index + 1) % this.slideTargets.length } });
    }

    prev() {
        const count = this.slideTargets.length;
        this.goTo({ params: { index: (this.index - 1 + count) % count } });
    }

    goTo({ params: { index } }) {
        if (typeof index !== 'number' || Number.isNaN(index)) {
            return;
        }

        this.slideTargets.forEach((slide, i) => slide.classList.toggle('cr-active', i === index));

        if (this.hasDotsTarget) {
            this.dotsTarget.querySelectorAll('.cr-dot').forEach((dot, i) => {
                dot.classList.toggle('cr-active', i === index);
            });
        }

        this.index = index;
    }
}
