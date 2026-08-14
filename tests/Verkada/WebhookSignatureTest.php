<?php

namespace OmniaGlobal\OmniaPackages\Tests\Verkada;

use Illuminate\Http\Request;
use OmniaGlobal\OmniaPackages\Tests\TestCase;
use OmniaGlobal\OmniaPackages\Verkada\WebhookSignature;

class WebhookSignatureTest extends TestCase
{
    private const SECRET = 'shared-secret';

    public function test_a_correctly_signed_body_is_accepted(): void
    {
        $signature = new WebhookSignature(self::SECRET);
        $body = '{"event_id":"evt_1"}';

        $this->assertTrue($signature->verify($body, $signature->sign($body)));
    }

    public function test_a_wrong_signature_is_refused(): void
    {
        $signature = new WebhookSignature(self::SECRET);

        $this->assertFalse($signature->verify('{"event_id":"evt_1"}', 'not-the-right-digest'));
    }

    public function test_a_missing_signature_is_refused(): void
    {
        $signature = new WebhookSignature(self::SECRET);

        $this->assertFalse($signature->verify('{}', null));
        $this->assertFalse($signature->verify('{}', ''));
    }

    /**
     * An unauthenticated endpoint that writes to a custody record is a worse
     * failure than a webhook that does not work yet.
     */
    public function test_everything_is_refused_when_no_secret_is_configured(): void
    {
        $signature = new WebhookSignature(null);

        $this->assertFalse($signature->isConfigured());
        $this->assertFalse($signature->verify('{}', 'anything'));
        // Even a digest computed against an empty secret must not pass.
        $this->assertFalse($signature->verify('{}', hash_hmac('sha256', '{}', '')));
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
        $this->assertTrue($signature->verify($raw, $signature->sign($raw)));
        $this->assertFalse($signature->verify($reencoded, $signature->sign($raw)));
    }

    public function test_it_reads_a_request_header(): void
    {
        $signature = new WebhookSignature(self::SECRET);
        $body = '{"event_id":"evt_2"}';

        $request = Request::create('/webhooks/verkada/access', 'POST', server: [
            'HTTP_VERKADA_SIGNATURE' => $signature->sign($body),
        ], content: $body);

        $this->assertTrue($signature->verifyRequest($request));
    }

    public function test_it_builds_itself_from_config(): void
    {
        config(['omnia.verkada.webhook_secret' => self::SECRET]);

        $this->assertTrue(WebhookSignature::fromConfig()->isConfigured());
    }
}
