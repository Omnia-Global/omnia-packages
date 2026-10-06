<?php

namespace OmniaGlobal\OmniaPackages\Tests\Verkada;

use Illuminate\Support\Carbon;
use OmniaGlobal\OmniaPackages\Tests\TestCase;
use OmniaGlobal\OmniaPackages\Verkada\AccessResult;
use OmniaGlobal\OmniaPackages\Verkada\AccessWebhookEvent;

/**
 * Verkada's access webhook in each of the vocabularies it actually sends.
 *
 * The alert fixture is the shape a live organisation sent Vault; the other two
 * follow apidocs.verkada.com and the events API. Every one must come out in
 * exactly the shape listAccessEvents() returns, so a host can upsert both.
 */
class AccessWebhookEventTest extends TestCase
{
    private const KEYS = ['event_id', 'time', 'verkada_user_id', 'verkada_user_name', 'door_id', 'door_name', 'result', 'event_type'];

    /** An event-based alert: no event id, the rule's id, and everything under data.details. */
    private function alert(array $overrides = []): array
    {
        return array_replace_recursive([
            'webhook_type' => 'access_control',
            'org_id' => 'org_1',
            'created_at' => 1755400005,
            'data' => [
                'alert_id' => 'rule_1',
                'alert_type' => 'door_opened',
                'created' => 1755400000,
                'details' => [
                    'door_info' => ['door_id' => 'door_1', 'name' => 'Front Door'],
                    'user_info' => ['user_id' => 'user_a', 'name' => 'A. Member'],
                ],
            ],
        ], $overrides);
    }

    public function test_an_event_based_alert_is_parsed(): void
    {
        $event = AccessWebhookEvent::parse($this->alert());

        $this->assertSame(self::KEYS, array_keys($event));
        $this->assertSame('user_a', $event['verkada_user_id']);
        $this->assertSame('A. Member', $event['verkada_user_name']);
        $this->assertSame('door_1', $event['door_id']);
        $this->assertSame('Front Door', $event['door_name']);
        $this->assertSame(AccessResult::GRANTED, $event['result']);
        $this->assertSame('door_opened', $event['event_type']);
        // The event's own moment, not the envelope's five seconds later.
        $this->assertSame('2025-08-17T03:06:40+00:00', $event['time']);
        $this->assertStringStartsWith('alert:', $event['event_id']);
    }

    /** The documented webhook: flat under data, the person in data.user_info. */
    public function test_the_documented_webhook_shape_is_parsed(): void
    {
        $event = AccessWebhookEvent::parse([
            'webhook_type' => 'access_control',
            'created_at' => '2025-08-17T03:06:45Z',
            'data' => [
                'event_id' => 'evt_9',
                'notification_type' => 'door_rejected',
                'created' => '2025-08-17T03:06:40Z',
                'door_id' => 'door_2',
                'door_info' => ['door_id' => 'door_2', 'name' => 'Side Entrance'],
                'user_info' => ['user_id' => 'user_b', 'name' => 'B. Member'],
                // A decoy: the person is in user_info, and this must lose.
                'user_id' => 'not_the_person',
            ],
        ]);

        $this->assertSame(self::KEYS, array_keys($event));
        $this->assertSame('evt_9', $event['event_id']);
        $this->assertSame('user_b', $event['verkada_user_id']);
        $this->assertSame('B. Member', $event['verkada_user_name']);
        $this->assertSame('door_2', $event['door_id']);
        $this->assertSame('Side Entrance', $event['door_name']);
        $this->assertSame(AccessResult::DENIED, $event['result']);
        $this->assertSame('door_rejected', $event['event_type']);
        $this->assertSame('2025-08-17T03:06:40+00:00', $event['time']);
    }

    /** The events-API vocabulary: camelCase inside data.event_info, and a boolean verdict. */
    public function test_the_event_info_shape_is_parsed(): void
    {
        $event = AccessWebhookEvent::parse([
            'data' => [
                'event_id' => 'evt_10',
                'event_type' => 'door_opened',
                'timestamp' => 1755400000,
                'event_info' => [
                    'userId' => 'user_c',
                    'userName' => 'C. Member',
                    'doorId' => 'door_3',
                    'doorName' => 'Gym Floor',
                    'accepted' => false,
                ],
            ],
        ]);

        $this->assertSame('evt_10', $event['event_id']);
        $this->assertSame('user_c', $event['verkada_user_id']);
        $this->assertSame('C. Member', $event['verkada_user_name']);
        $this->assertSame('door_3', $event['door_id']);
        $this->assertSame('Gym Floor', $event['door_name']);
        // Verkada's own `accepted` wins over a type that sounds like a success.
        $this->assertSame(AccessResult::DENIED, $event['result']);
        $this->assertSame('2025-08-17T03:06:40+00:00', $event['time']);
    }

    public function test_an_explicit_accepted_flag_is_honoured(): void
    {
        $event = AccessWebhookEvent::parse($this->alert(['data' => ['alert_type' => 'door_rejected', 'accepted' => true]]));

        $this->assertSame(AccessResult::GRANTED, $event['result']);
    }

    /** Always displayable, as listAccessEvents promises. */
    public function test_the_door_name_falls_back_to_the_id(): void
    {
        $payload = $this->alert();
        unset($payload['data']['details']['door_info']['name']);

        $this->assertSame('door_1', AccessWebhookEvent::parse($payload)['door_name']);
    }

    /**
     * `alert_id` names the rule, not the occurrence. Keyed on it directly,
     * every event under one rule would collapse into the first ever recorded.
     */
    public function test_a_redelivered_alert_keeps_its_synthetic_id(): void
    {
        // Same occurrence, fresh envelope: a retry after an outage.
        $first = AccessWebhookEvent::parse($this->alert());
        $retry = AccessWebhookEvent::parse($this->alert(['created_at' => 1755409999]));

        $this->assertSame($first['event_id'], $retry['event_id']);
    }

    public function test_a_different_person_under_the_same_rule_gets_a_different_id(): void
    {
        $member = AccessWebhookEvent::parse($this->alert());
        $other = AccessWebhookEvent::parse($this->alert(['data' => ['details' => ['user_info' => ['user_id' => 'user_z']]]]));
        $later = AccessWebhookEvent::parse($this->alert(['data' => ['created' => 1755400001]]));

        $this->assertNotSame($member['event_id'], $other['event_id']);
        $this->assertNotSame($member['event_id'], $later['event_id']);
    }

    /**
     * Pinned to Vault's derivation, so ids Vault already stored still match
     * after it moves onto this class.
     */
    public function test_the_synthetic_id_matches_vaults_derivation(): void
    {
        $this->assertSame(
            'alert:'.sha1('rule_1|1755400000|door_1|user_a'),
            AccessWebhookEvent::parse($this->alert())['event_id'],
        );
    }

    /** Nothing to key on means record it — a duplicate is correctable, a drop is not. */
    public function test_no_event_id_and_no_alert_id_yields_null(): void
    {
        $payload = $this->alert();
        unset($payload['data']['alert_id']);

        $this->assertNull(AccessWebhookEvent::parse($payload)['event_id']);
    }

    public function test_epoch_seconds_and_iso_times_agree(): void
    {
        $epoch = AccessWebhookEvent::parse(['data' => ['created' => 1755400000]]);
        $iso = AccessWebhookEvent::parse(['data' => ['created' => '2025-08-17T03:06:40+00:00']]);
        $numericString = AccessWebhookEvent::parse(['data' => ['created' => '1755400000']]);

        $this->assertSame('2025-08-17T03:06:40+00:00', $epoch['time']);
        $this->assertSame($epoch['time'], $iso['time']);
        $this->assertSame($epoch['time'], $numericString['time']);
    }

    public function test_the_envelope_time_is_used_when_the_event_has_none(): void
    {
        $this->assertSame(
            '2025-08-17T03:06:45+00:00',
            AccessWebhookEvent::parse(['created_at' => 1755400005, 'data' => []])['time'],
        );
    }

    public function test_no_usable_time_falls_back_to_receipt(): void
    {
        Carbon::setTestNow('2026-10-06 09:00:00');

        try {
            $this->assertSame('2026-10-06T09:00:00+00:00', AccessWebhookEvent::parse(['data' => []])['time']);
            $this->assertSame('2026-10-06T09:00:00+00:00', AccessWebhookEvent::parse(['data' => ['created' => 'not a date']])['time']);
        } finally {
            Carbon::setTestNow();
        }
    }

    /** Paths, never values: a log line must not carry a member's name. */
    public function test_shape_lists_paths_without_values(): void
    {
        $shape = AccessWebhookEvent::shape($this->alert());

        $this->assertContains('data.details.user_info.name', $shape);
        $this->assertContains('data.details.door_info.door_id', $shape);
        $this->assertContains('created_at', $shape);
        $this->assertNotContains('A. Member', $shape);
        $this->assertStringNotContainsString('A. Member', implode(' ', $shape));
    }
}
