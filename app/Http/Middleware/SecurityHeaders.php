<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The headers a browser needs to be told, since Laravel sets none of them.
 *
 * Deliberately not a Content-Security-Policy. Riff embeds third-party
 * players in iframes, loads map tiles from a provider, and serves a Vite
 * bundle; a CSP that covers all three is real work and a wrong one breaks
 * the map silently. That is a deliberate task, not a line in an audit.
 */
class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        // Riff is never framed: it has no embeddable surface, and the
        // artist pages carry follow and report buttons worth clickjacking.
        $response->headers->set('X-Frame-Options', 'SAMEORIGIN');

        // Uploaded artist photos are served from the media disk; a
        // sniffed content type is how an image becomes a script.
        $response->headers->set('X-Content-Type-Options', 'nosniff');

        // A show or artist URL names a real place and a real band. Send
        // the origin to other sites, never the path.
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');

        // Nothing here asks for a camera, a microphone, or a location:
        // the map uses an explicit in-page prompt, not a silent read.
        $response->headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=(self)');

        return $response;
    }
}
