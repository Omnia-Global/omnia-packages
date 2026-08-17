<?php

namespace OmniaGlobal\OmniaPackages\Verkada;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Verifies a Verkada event webhook. https://apidocs.verkada.com/reference/securing-webhooks
 *
 * Verkada sends `Verkada-Signature: <timestamp>|<signature>`, where the
 * signature is an HMAC-SHA256 hex digest, keyed with the shared secret, over
 * the **raw request body, a pipe, and that same timestamp**:
 *
 *     hash_hmac('sha256', $rawBody.'|'.$timestamp, $secret)
 *
 * Both halves matter. Signing the body alone — which is what this class used
 * to do — produces a digest that can never equal the header Verkada actually
 * sends, so **every live delivery was refused with a 401** while the tests went
 * green, because they signed and verified with the same wrong scheme. Anything
 * changed here has to be checked against Verkada's published example rather
 * than against our own sign().
 *
 * The timestamp is inside the signed payload, so it cannot be edited without
 * invalidating the digest. That is what makes the freshness check worth
 * anything: it bounds how long a captured delivery can be replayed.
 *
 * **It refuses everything when no secret is configured.** That is deliberate
 * and worth not "fixing": an unauthenticated endpoint that writes to a custody
 * record, an attendance roll or a door history is a far worse failure than a
 * webhook that does not work yet.
 */
class WebhookSignature
{
    public const HEADER = 'verkada-signature';

    /**
     * @param  int  $tolerance  Seconds a delivery may be old before it is
     *                          refused. Verkada's own example uses 60. The
     *                          default here is wider because the two failures
     *                          do not cost the same: a replayed delivery is
     *                          idempotent on the event ID, while a server whose
     *                          clock has drifted two minutes silently stops
     *                          recording who opened a controlled cabinet.
     *                          Tighten it with VERKADA_WEBHOOK_TOLERANCE where
     *                          the clock is known to be disciplined.
     */
    public function __construct(
        private readonly ?string $secret,
        private readonly int $tolerance = 300,
    ) {}

    public static function fromConfig(): self
    {
        return new self(
            config('omnia.verkada.webhook_secret'),
            (int) config('omnia.verkada.webhook_tolerance', 300),
        );
    }

    public function verifyRequest(Request $request): bool
    {
        return $this->verify($request->getContent(), $request->header(self::HEADER));
    }

    /**
     * @param  string  $rawBody  The body exactly as received — not the decoded
     *                           array, and not re-encoded JSON. Re-encoding
     *                           reorders keys and changes whitespace, and the
     *                           digest stops matching for reasons nobody can
     *                           see in a log.
     * @param  string|null  $header  The whole Verkada-Signature header value,
     *                               timestamp and digest together.
     * @param  int|null  $now  Injectable clock, for tests.
     */
    public function verify(string $rawBody, ?string $header, ?int $now = null): bool
    {
        if (blank($this->secret)) {
            Log::warning('Verkada webhook rejected: no signing secret configured.');

            return false;
        }

        if (blank($header)) {
            return false;
        }

        [$timestamp, $signature] = array_pad(explode('|', $header, 2), 2, null);

        /*
         | Every rejection below says why.
         |
         | A refused webhook is invisible from inside Command — Verkada retries,
         | gives up, and the product simply has no openings — so this log line
         | is the only place the failure surfaces. Reasons are logged; digests
         | are not, because the reason is the part an operator can act on.
         */
        if (! ctype_digit((string) $timestamp) || blank($signature)) {
            Log::warning('Verkada webhook rejected: malformed signature header.', [
                'expected' => '<unix timestamp>|<hex digest>',
            ]);

            return false;
        }

        $age = ($now ?? time()) - (int) $timestamp;

        // Absolute, so a receiver whose clock runs behind Verkada's refuses a
        // delivery from the "future" rather than quietly accepting any age.
        if (abs($age) > $this->tolerance) {
            Log::warning('Verkada webhook rejected: timestamp outside tolerance.', [
                'age_seconds' => $age,
                'tolerance_seconds' => $this->tolerance,
            ]);

            return false;
        }

        if (! hash_equals($this->digest($rawBody, $timestamp), $signature)) {
            Log::warning('Verkada webhook rejected: signature did not match.');

            return false;
        }

        return true;
    }

    /**
     * The complete header value a caller would have to send for this body.
     *
     * Timestamp and digest together, in Verkada's format, so a test that uses
     * this exercises the same parsing a live delivery does.
     */
    public function sign(string $rawBody, ?int $timestamp = null): string
    {
        $timestamp ??= time();

        return $timestamp.'|'.$this->digest($rawBody, (string) $timestamp);
    }

    public function isConfigured(): bool
    {
        return filled($this->secret);
    }

    private function digest(string $rawBody, string $timestamp): string
    {
        return hash_hmac('sha256', $rawBody.'|'.$timestamp, (string) $this->secret);
    }
}
