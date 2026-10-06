<?php

namespace OmniaGlobal\OmniaPackages\Verkada;

use Carbon\CarbonImmutable;

/**
 * Verkada's access webhook, reduced to one door event.
 *
 * Verkada speaks about the same door event in several vocabularies, and which
 * one arrives depends on how the webhook was created in Command rather than on
 * anything a product chooses:
 *
 *   alert (event-based)  data.details.door_info.door_id, data.details.user_info.*
 *   documented webhook   data.door_id / data.door_info.*, data.user_info.*
 *   events API           data.event_info.doorId, data.event_info.userId
 *
 * So every one of them is read, rather than betting on the shape a given
 * customer's Command happens to send. The alert form is first because it is
 * what a live organisation actually sent Vault.
 *
 * The return shape is deliberately identical to one element of
 * VerkadaGateway::listAccessEvents(), so a host feeds the webhook and the
 * polling backfill into the same upsert and the two paths cannot disagree —
 * which, before AccessResult, they did.
 *
 * Lifted from Vault's webhook controller, where every rule below was learned
 * the hard way. The pitfalls are recorded next to the code that avoids them.
 */
final class AccessWebhookEvent
{
    /**
     * @param  array<mixed>  $payload  The decoded webhook JSON.
     * @return array{event_id: string|null, time: string, verkada_user_id: string|null, verkada_user_name: string|null, door_id: string|null, door_name: string|null, result: string, event_type: string|null}
     */
    public static function parse(array $payload): array
    {
        $doorId = self::first($payload, [
            'data.details.door_info.door_id',
            'data.door_id',
            'data.door_info.door_id',
            'data.event_info.doorId',
            'door_id',
        ]);

        /*
         | The person is in `data.user_info.user_id`, not `data.user_id`.
         |
         | Read from the wrong place it is simply null, the event records with
         | nobody attached, and the log fills with openings that say a door was
         | used and cannot say by whom.
         */
        $userId = self::first($payload, [
            'data.details.user_info.user_id',
            'data.user_info.user_id',
            'data.event_info.userId',
            'data.user_id',
            'user_id',
        ]);

        $type = self::eventType($payload);

        return [
            'event_id' => self::eventId($payload, $doorId, $userId),
            'time' => self::time($payload),
            'verkada_user_id' => $userId,
            'verkada_user_name' => self::first($payload, [
                'data.details.user_info.name',
                'data.user_info.name',
                'data.event_info.userName',
            ]),
            'door_id' => $doorId,
            // Always displayable: falls back to the id, as listAccessEvents does.
            'door_name' => self::first($payload, [
                'data.details.door_info.name',
                'data.door_info.name',
                'data.door_name',
                'data.event_info.doorName',
                'data.event_info.doorInfo.name',
            ]) ?? $doorId,
            'result' => AccessResult::normalise($type, self::accepted($payload)),
            'event_type' => $type,
        ];
    }

    /**
     * The dotted paths of every field in a payload, without any of the values.
     *
     * Vendor payloads are the one input nobody controls and nobody can test
     * against reality until they meet it. This makes an unfamiliar shape
     * readable from a log line — `data.event_info.doorId` rather than a
     * transcript of somebody's name, email and phone number.
     *
     * @param  array<mixed>  $payload
     * @return array<string>
     */
    public static function shape(array $payload): array
    {
        return self::paths($payload, '');
    }

    /**
     * @param  array<mixed>  $payload
     * @return array<string>
     */
    private static function paths(array $payload, string $prefix): array
    {
        $paths = [];

        foreach ($payload as $key => $value) {
            $path = $prefix === '' ? (string) $key : "{$prefix}.{$key}";

            // An empty array is a leaf; anything else with children is walked.
            $paths = array_merge($paths, is_array($value) && $value !== []
                ? self::paths($value, $path)
                : [$path]);
        }

        return $paths;
    }

    /**
     * Verkada's word for what happened — `alert_type` on an alert,
     * `notification_type` on a raw event, `event_type` elsewhere.
     *
     * @param  array<mixed>  $payload
     */
    private static function eventType(array $payload): ?string
    {
        return self::first($payload, [
            'data.alert_type',
            'data.notification_type',
            'data.event_type',
            'event_type',
        ]);
    }

    /**
     * Verkada's own verdict, when it sends one. Only a real boolean counts —
     * a string "false" is truthy, and reading it as granted would hide a
     * refusal.
     *
     * @param  array<mixed>  $payload
     */
    private static function accepted(array $payload): ?bool
    {
        foreach (['data.accepted', 'data.details.accepted', 'data.event_info.accepted'] as $path) {
            $value = data_get($payload, $path);

            if (is_bool($value)) {
                return $value;
            }
        }

        return null;
    }

    /**
     * A key that identifies this *occurrence*, for idempotency.
     *
     * The alert payload carries no event id — only `alert_id`, which names the
     * alert **rule** in Command, not the occurrence. Using it directly would be
     * quietly catastrophic: an upsert keyed on it collapses every event under
     * that rule, for the life of the instance, into the first one ever
     * recorded, while the webhook keeps returning 202.
     *
     * So when there is no true event id, one is derived from what does identify
     * the occurrence: the rule, the moment, the door and the person. Stable
     * across a redelivery of the same event — which is the point — and distinct
     * between two events, including two by the same person at the same door a
     * second apart.
     *
     * The derivation is byte-for-byte Vault's, including hashing the *raw*
     * timestamp rather than the normalised one, so ids Vault has already
     * stored still match after it moves onto this class.
     *
     * Null means "record it": a duplicate is visible and correctable; a
     * silently dropped event is not.
     *
     * @param  array<mixed>  $payload
     */
    private static function eventId(array $payload, ?string $doorId, ?string $userId): ?string
    {
        if ($id = self::first($payload, ['data.event_id', 'event_id'])) {
            return $id;
        }

        $alertId = data_get($payload, 'data.alert_id');

        if (blank($alertId)) {
            return null;
        }

        return 'alert:'.sha1(implode('|', [
            $alertId,
            (string) (data_get($payload, 'data.created') ?? data_get($payload, 'created_at')),
            (string) $doorId,
            (string) $userId,
        ]));
    }

    /**
     * When it happened, as ISO-8601, preferring the event's own time over the
     * envelope's.
     *
     * `data.created` is when the door was used; `created_at` is when Verkada
     * built the delivery. They are usually a second apart and occasionally much
     * more — a retry after an outage carries a fresh envelope around an old
     * event — and an event timestamped by the retry lands in the wrong window.
     *
     * Verkada sends Unix seconds for some events and ISO-8601 for others. With
     * no usable time at all the moment of receipt stands in, which is what
     * Vault did: an event at roughly the right time beats no event.
     *
     * @param  array<mixed>  $payload
     */
    private static function time(array $payload): string
    {
        $raw = data_get($payload, 'data.created')
            ?? data_get($payload, 'data.details.created')
            ?? data_get($payload, 'data.timestamp')
            ?? data_get($payload, 'created_at')
            ?? data_get($payload, 'timestamp');

        try {
            $at = match (true) {
                is_numeric($raw) => CarbonImmutable::createFromTimestamp((int) $raw),
                is_string($raw) && $raw !== '' => CarbonImmutable::parse($raw),
                default => CarbonImmutable::now(),
            };
        } catch (\Throwable) {
            $at = CarbonImmutable::now();
        }

        return $at->toIso8601String();
    }

    /**
     * The first path that holds a non-empty scalar, as a string.
     *
     * @param  array<mixed>  $payload
     * @param  array<string>  $paths
     */
    private static function first(array $payload, array $paths): ?string
    {
        foreach ($paths as $path) {
            $value = data_get($payload, $path);

            if (is_scalar($value) && ! is_bool($value) && (string) $value !== '') {
                return (string) $value;
            }
        }

        return null;
    }
}
