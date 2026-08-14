<?php

namespace OmniaGlobal\OmniaPackages\Verkada;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Verifies a Verkada event webhook.
 *
 * Verkada signs the raw request body with a shared secret using HMAC-SHA256
 * and sends the digest in the `verkada-signature` header. The endpoints that
 * receive these sit outside auth and outside CSRF — the caller is a vendor,
 * and this signature is the only thing that proves it.
 *
 * **It refuses everything when no secret is configured.** That is deliberate
 * and worth not "fixing": an unauthenticated endpoint that writes to a custody
 * record, an attendance roll or a door history is a far worse failure than a
 * webhook that does not work yet. A product with no secret set should see its
 * webhooks rejected loudly, not accepted quietly.
 */
class WebhookSignature
{
    public const HEADER = 'verkada-signature';

    public function __construct(
        private readonly ?string $secret,
    ) {}

    public static function fromConfig(): self
    {
        return new self(config('omnia.verkada.webhook_secret'));
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
     */
    public function verify(string $rawBody, ?string $signature): bool
    {
        if (blank($this->secret)) {
            Log::warning('Verkada webhook rejected: no signing secret configured.');

            return false;
        }

        if (blank($signature)) {
            return false;
        }

        return hash_equals(hash_hmac('sha256', $rawBody, $this->secret), $signature);
    }

    /** The digest a caller would have to send for this body. Used in tests. */
    public function sign(string $rawBody): string
    {
        return hash_hmac('sha256', $rawBody, (string) $this->secret);
    }

    public function isConfigured(): bool
    {
        return filled($this->secret);
    }
}
