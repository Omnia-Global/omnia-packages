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
 * ⚠ Endpoint paths below follow the public API documentation and have **not**
 * been exercised against a live Verkada organisation. Confirming them is the
 * phase-0 spike. Until that happens, only LogVerkadaGateway has actually run —
 * see the README.
 */
interface VerkadaGateway
{
    // --- Access users and groups -------------------------------------------

    /** Create (or find by email) an access user. Returns the Verkada user ID. */
    public function ensureAccessUser(string $name, string $email, ?string $phone = null): string;

    public function addUserToGroup(string $verkadaUserId, string $groupId): void;

    public function removeUserFromGroup(string $verkadaUserId, string $groupId): void;

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
     * @return array<array{event_id: string|null, time: string, verkada_user_id: string|null, door_id: string|null, result: string|null}>
     */
    public function listAccessEvents(DateTimeInterface $since, int $limit = 500): array;

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
