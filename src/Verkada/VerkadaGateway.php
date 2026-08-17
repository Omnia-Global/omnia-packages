<?php

namespace OmniaGlobal\OmniaPackages\Verkada;

use DateTimeInterface;

/**
 * Everything the Omnia products ask of Verkada, in one place.
 *
 * Two implementations: HttpVerkadaGateway talks to Command, LogVerkadaGateway
 * writes to the log and returns plausible data. The fake binds automatically
 * whenever no API key is configured, which is how a whole application runs on
 * a laptop with no hardware on the desk.
 *
 * The interface is the *union* of what all three products need, not the
 * intersection. Pulse and Campus issue mobile passes; Vault reads footage and
 * writes Helix events. A product that never calls a method pays nothing for
 * its existence, and the fakes make the unused half free — so there is no
 * reason to split this into narrower contracts and every reason not to, since
 * a split would mean three places to change when Verkada changes.
 *
 * ⚠ Paths and payload shapes below were checked field-by-field against
 * apidocs.verkada.com, and the discovery calls have now run against a live
 * organisation. The event paths have not. What that audit found is the reason
 * for the caution: four mappings were wrong in ways that failed silently — a
 * webhook signature that refused every real delivery, identities read from the
 * top level of an event instead of its `event_info` envelope, group membership
 * read from the wrong key, and a door's nested `site` object rendered into a
 * screen as text. None of them threw. All of them passed their tests.
 *
 * The lesson worth keeping: a fake you wrote agrees with a client you wrote,
 * and proves nothing about the vendor. Fixtures belong to Verkada's reference.
 */
interface VerkadaGateway
{
    // --- Access users and groups -------------------------------------------

    /** Create (or find by email) an access user. Returns the Verkada user ID. */
    public function ensureAccessUser(string $name, string $email, ?string $phone = null): string;

    public function addUserToGroup(string $verkadaUserId, string $groupId): void;

    public function removeUserFromGroup(string $verkadaUserId, string $groupId): void;

    /**
     * Email the person a Verkada Pass (mobile credential) invite.
     *
     * Used by Pulse and Campus, where staff and members carry a pass on a
     * phone. Vault's people carry cards, so it never calls this — which costs
     * it nothing.
     */
    public function sendPassInvite(string $verkadaUserId): void;

    public function deactivateUser(string $verkadaUserId): void;

    /** @return array<string> Verkada user IDs currently in the group, for reconciliation. */
    public function listGroupUserIds(string $groupId): array;

    // --- Discovery: what exists in Command ----------------------------------
    // Used by the admin binding screen so a cabinet is linked by picking a real
    // door from a list, rather than by pasting an ID somebody read off a
    // spreadsheet. Every one of these returns [] on failure rather than
    // throwing: a Verkada outage must degrade the screen to manual entry, not
    // break it.

    /** @return array<array{id: string, name: string, site: string|null}> */
    public function listDoors(): array;

    /** @return array<array{id: string, name: string, site: string|null}> */
    public function listCameras(): array;

    /** @return array<array{id: string, name: string}> */
    public function listAccessGroups(): array;

    /**
     * Everybody Command holds an access credential for.
     *
     * The point is matching, not administration: a host keeps its own record
     * of a person and needs to know which badge is theirs, and asking somebody
     * to copy a UUID off one screen and paste it into another is how a ward's
     * morphine safe ends up attributing openings to the wrong nurse.
     *
     * `employee_id` is included because it is usually the only identifier the
     * two systems genuinely share — emails differ between a corporate directory
     * and a clinical one far more often than payroll numbers do.
     *
     * @return array<array{id: string, name: string, email: string|null, employee_id: string|null, is_visitor: bool}>
     */
    public function listAccessUsers(): array;

    /**
     * Prove the credentials work, for the "Test connection" button.
     *
     * @return array{ok: bool, message: string}
     */
    public function testConnection(): array;

    // --- Doors --------------------------------------------------------------

    /**
     * Unlock a door as a specific user, so the act is attributed to them in
     * Command's own audit rather than to the integration.
     *
     * Used only for the narrow cases that need it — a remote release by a
     * pharmacist, an escorted audit. Standing access is
     * group membership, so the door keeps working when the application is down.
     */
    public function unlockDoor(string $doorId, ?string $asVerkadaUserId = null): void;

    // --- Access events ------------------------------------------------------

    /**
     * Door events since a timestamp, for the local mirror and webhook backfill.
     *
     * `door_id` and `door_name` are both returned because they are genuinely
     * different things and both are wanted: the id is what a product matches
     * against its own record of which door belongs to which cabinet, room or
     * site, and the name is what it shows a human. `door_name` falls back to
     * the id when Verkada does not supply one, so it is always displayable.
     *
     * `time` is always ISO-8601, never the Unix seconds Verkada sends, and
     * `result` is always `granted` or `denied` — see AccessResult. Verkada's
     * own word for the event (`door_opened`, `door_held_open`, `door_forced`)
     * is kept beside it as `event_type`, because the verdict is what a product
     * queries on and the raw type is what an investigator reads.
     *
     * `$limit` is clamped to Verkada's documented maximum page size of 200.
     *
     * `verkada_user_name` is Verkada's own label for the badge holder. It is
     * never a substitute for a host's own record of a person — it carries no
     * entitlement, registration or role — but it lets a product name somebody
     * it has not yet been told about, rather than displaying a UUID.
     *
     * @return array<array{event_id: string|null, time: string, verkada_user_id: string|null, verkada_user_name: string|null, door_id: string|null, door_name: string|null, result: string, event_type: string|null}>
     */
    public function listAccessEvents(DateTimeInterface $since, int $limit = 500): array;

    /**
     * Recent door events for one person, newest first.
     *
     * @return array<array{time: string, door_name: string|null, result: string}>
     */
    public function recentAccessEvents(string $verkadaUserId, int $limit = 20): array;

    // --- Footage ------------------------------------------------------------

    /** A deep link into Command at this camera and moment. */
    public function footageLink(string $cameraId, DateTimeInterface $at): ?string;

    /** A still from this camera at this moment, for attaching to a record. */
    public function thumbnailUrl(string $cameraId, DateTimeInterface $at): ?string;

    // --- Helix --------------------------------------------------------------

    /**
     * Push a custody transaction into Command as searchable video metadata, so
     * security can search their own footage by item or by person without ever
     * opening this application. Returns the Helix event ID.
     *
     * @param  array<string, scalar|null>  $attributes
     */
    public function createHelixEvent(string $cameraId, DateTimeInterface $at, array $attributes): ?string;

    // --- Person of Interest (optional recognition layer) --------------------

    /**
     * Enrol a member of staff as a Person of Interest. Returns the profile ID.
     *
     * Only ever called when config('vault.recognition.enabled') is true and a
     * current consent record exists.
     */
    public function enrolPersonOfInterest(string $name, string $photoPath): string;

    /** Remove an enrolment. Called on exit, and on consent withdrawal. */
    public function removePersonOfInterest(string $profileId): void;

    /** @return array<string> Profile IDs currently enrolled, for reconciliation. */
    public function listPersonOfInterestIds(): array;
}
