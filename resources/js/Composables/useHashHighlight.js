import { router } from '@inertiajs/vue3';
import { onMounted, onBeforeUnmount } from 'vue';

/**
 * Scroll to whatever the URL's hash names and flash it.
 *
 * Notifications link to the *thing* ("someone replied to you") rather than
 * the page it lives on, because a long wall makes "we took you to the
 * right page, now go looking" useless. The anchor ids are set by the wall
 * components (`post-123`, `comment-456`).
 *
 * Runs on mount and again on every Inertia navigation: clicking a second
 * notification for the same page does not remount anything, so without the
 * listener the second click would appear to do nothing.
 *
 * Retries briefly, because the target may still be rendering. If it never
 * turns up -- the post is on an older page of the wall -- this quietly
 * does nothing rather than jumping somewhere wrong.
 */
export function useHashHighlight({ attempts = 10, interval = 60, flashFor = 2000 } = {}) {
    let timers = [];

    const clearTimers = () => {
        timers.forEach((t) => clearTimeout(t));
        timers = [];
    };

    const flash = (element) => {
        element.scrollIntoView({ behavior: 'smooth', block: 'center' });
        element.classList.add('riff-flash');
        timers.push(setTimeout(() => element.classList.remove('riff-flash'), flashFor));
    };

    const find = (remaining) => {
        const hash = window.location.hash;

        if (!hash || hash.length < 2) {
            return;
        }

        // Not a selector built from the hash: an id is user-reachable
        // input, and querySelector would happily run whatever it parses.
        const element = document.getElementById(decodeURIComponent(hash.slice(1)));

        if (element) {
            flash(element);

            return;
        }

        if (remaining > 0) {
            timers.push(setTimeout(() => find(remaining - 1), interval));
        }
    };

    const start = () => {
        clearTimers();
        find(attempts);
    };

    let stopListening;

    onMounted(() => {
        start();
        stopListening = router.on('navigate', start);
    });

    onBeforeUnmount(() => {
        clearTimers();
        stopListening?.();
    });
}
