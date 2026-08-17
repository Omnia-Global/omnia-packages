<?php

namespace OmniaGlobal\OmniaPackages\Verkada;

/**
 * Verkada's event vocabulary, reduced to the only distinction the products act
 * on: was the door opened, or was somebody refused.
 *
 * Verkada says `door_opened`, `door_granted`, `door_rejected`, and sends an
 * `accepted` boolean on the polled events. Every product stores `granted` or
 * `denied` and queries on it — Vault raises an access-denied discrepancy from
 * it — so the raw string has to be reduced somewhere. Doing it here means the
 * webhook path and the polling path cannot disagree, which they did: the same
 * opening arrived as `door_opened` from one and `granted` from the other, and
 * the "denied" filter matched neither.
 *
 * The raw type is never thrown away — callers keep it alongside — because
 * `door_held_open` and `door_forced` are worth reading in an investigation even
 * though neither changes this verdict.
 */
final class AccessResult
{
    public const GRANTED = 'granted';

    public const DENIED = 'denied';

    /**
     * Verkada's own `accepted` flag wins when present; otherwise the event type
     * is matched on the fragments Verkada uses for a refusal.
     *
     * An unrecognised type resolves to `granted`, deliberately. The asymmetry
     * is that a false `denied` manufactures an incident against a named person
     * out of an event nobody has classified, while a false `granted` leaves an
     * opening in the log looking ordinary — which is what an unknown event
     * mostly is. New refusal vocabulary belongs in the list below, not in a
     * changed default.
     */
    public static function normalise(?string $eventType, ?bool $accepted = null): string
    {
        if ($accepted !== null) {
            return $accepted ? self::GRANTED : self::DENIED;
        }

        $type = strtolower((string) $eventType);

        foreach (['denied', 'deny', 'reject', 'declin', 'refus', 'unauthor', 'invalid', 'fail', 'lockdown'] as $refusal) {
            if (str_contains($type, $refusal)) {
                return self::DENIED;
            }
        }

        return self::GRANTED;
    }
}
