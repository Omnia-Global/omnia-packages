<?php

namespace OmniaGlobal\OmniaPackages\Tests\Verkada;

use Illuminate\Http\Request;
use OmniaGlobal\OmniaPackages\Tests\TestCase;
use OmniaGlobal\OmniaPackages\Verkada\WebhookSignature;

/**
 * These tests are shaped by how the old ones failed.
 *
 * Every one of them passed while production refused every delivery Verkada
 * sent, because they signed with sign() and verified with verify() — a closed
 * loop that proves the two agree and nothing about whether either matches
 * Verkada. The fixture below is therefore built by hand from Verkada's own
 * published example, and sign() is checked against *it* rather than the other
 * way round.
 */
class WebhookSignatureTest extends TestCase
{
    private const SECRET = 'shared-secret';

    private const BODY = '{"webhook_id":"wh_1","data":{"event_id":"evt_1"}}';

    private const AT = 1755400000;

    /**
     * Verkada's scheme, transcribed from its documentation:
     *
     *   timestamp, signature = header.split('|')
     *   to_hash = body + b'|' + timestamp
     *   hmac_sha256(secret, to_hash).hexdigest() == signature
     */
    private function verkadaHeader(string $body = self::BODY, int $at = self::AT, string $secret = self::SECRET): string
    {
        return $at.'|'.hash_hmac('sha256', $body.'|'.$at, $secret);
    }

    public function test_it_accepts_the_header_verkada_actually_sends(): void
    {
        $signature = new WebhookSignature(self::SECRET);

        $this->assertTrue($signature->verify(self::BODY, $this->verkadaHeader(), now: self::AT));
    }

    /** The old scheme — a bare digest over the body — must not pass. */
    public function test_it_refuses_a_digest_over_the_body_alone(): void
    {
        $signature = new WebhookSignature(self::SECRET);

        $this->assertFalse($signature->verify(
            self::BODY,
            hash_hmac('sha256', self::BODY, self::SECRET),
            now: self::AT,
        ));
    }

    public function test_sign_produces_exactly_what_verkada_would_send(): void
    {
        $signature = new WebhookSignature(self::SECRET);

        $this->assertSame($this->verkadaHeader(), $signature->sign(self::BODY, self::AT));
    }

    public function test_a_wrong_secret_is_refused(): void
    {
        $signature = new WebhookSignature(self::SECRET);

        $this->assertFalse($signature->verify(
            self::BODY,
            $this->verkadaHeader(secret: 'somebody-elses-secret'),
            now: self::AT,
        ));
    }

    /**
     * The timestamp is inside the signed payload, so somebody replaying a
     * captured delivery cannot move it forward. Refusing on age is what makes
     * that worth anything.
     */
    public function test_a_stale_delivery_is_refused(): void
    {
        $signature = new WebhookSignature(self::SECRET, tolerance: 300);

        $this->assertTrue($signature->verify(self::BODY, $this->verkadaHeader(), now: self::AT + 299));
        $this->assertFalse($signature->verify(self::BODY, $this->verkadaHeader(), now: self::AT + 301));
    }

    /** A receiver whose clock runs behind must not accept unbounded ages. */
    public function test_a_delivery_from_too_far_in_the_future_is_refused(): void
    {
        $signature = new WebhookSignature(self::SECRET, tolerance: 300);

        $this->assertFalse($signature->verify(self::BODY, $this->verkadaHeader(), now: self::AT - 301));
    }

    public function test_a_malformed_header_is_refused(): void
    {
        $signature = new WebhookSignature(self::SECRET);

        foreach ([null, '', 'no-pipe-at-all', '|', 'notatimestamp|abc', self::AT.'|'] as $header) {
            $this->assertFalse(
                $signature->verify(self::BODY, $header, now: self::AT),
                'accepted ['.var_export($header, true).']',
            );
        }
    }

    /**
     * An unauthenticated endpoint that writes to a custody record is a worse
     * failure than a webhook that does not work yet.
     */
    public function test_everything_is_refused_when_no_secret_is_configured(): void
    {
        $signature = new WebhookSignature(null);

        $this->assertFalse($signature->isConfigured());
        $this->assertFalse($signature->verify(self::BODY, $this->verkadaHeader(), now: self::AT));
        // Even a digest computed against an empty secret must not pass.
        $this->assertFalse($signature->verify(
            self::BODY,
            $this->verkadaHeader(secret: ''),
            now: self::AT,
        ));
    }

    /**
     * The digest is over the body exactly as received. Decoding and
     * re-encoding normalises whitespace, and the digest then stops matching
     * for a reason nobody can see in a log — which is why verifyRequest()
     * reads getContent() and never the parsed array.
     */
    public function test_the_digest_is_over_the_raw_body_not_re_encoded_json(): void
    {
        $signature = new WebhookSignature(self::SECRET);
        $raw = '{"a": 1,  "b": 2}';
        $reencoded = json_encode(json_decode($raw, true));

        $this->assertNotSame($raw, $reencoded, 'The fixture must actually differ once normalised.');
        $this->assertTrue($signature->verify($raw, $signature->sign($raw, self::AT), now: self::AT));
        $this->assertFalse($signature->verify($reencoded, $signature->sign($raw, self::AT), now: self::AT));
    }

    public function test_it_reads_a_request_header(): void
    {
        $signature = new WebhookSignature(self::SECRET);

        $request = Request::create('/webhooks/verkada/access', 'POST', server: [
            'HTTP_VERKADA_SIGNATURE' => $signature->sign(self::BODY),
        ], content: self::BODY);

        $this->assertTrue($signature->verifyRequest($request));
    }

    public function test_it_builds_itself_from_config(): void
    {
        config([
            'omnia.verkada.webhook_secret' => self::SECRET,
            'omnia.verkada.webhook_tolerance' => 30,
        ]);

        $signature = WebhookSignature::fromConfig();

        $this->assertTrue($signature->isConfigured());
        // The configured tolerance is the one enforced.
        $this->assertFalse($signature->verify(self::BODY, $this->verkadaHeader(), now: self::AT + 31));
        $this->assertTrue($signature->verify(self::BODY, $this->verkadaHeader(), now: self::AT + 29));
    }
}
