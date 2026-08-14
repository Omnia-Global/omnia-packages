<?php

namespace OmniaGlobal\OmniaPackages\Verkada;

use DateTimeInterface;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * The development and test double. Binds automatically whenever no Verkada API
 * key is configured, so an entire application — the sync jobs, the nightly
 * reconcile, whatever the product builds on top — runs on a laptop with no
 * hardware on the desk.
 *
 * Two deliberate choices worth knowing about:
 *
 *  - listGroupUserIds() returns an empty array, so the nightly reconciler finds
 *    "entitled but missing" and never "present but not entitled". Without a
 *    real org there is no drift to find, and inventing some would train
 *    developers to ignore the reconciler's output.
 *
 *  - footageLink() and thumbnailUrl() return placeholder URLs rather than null.
 *    Returning null would make every evidence panel in development look like a
 *    camera outage, and outages are a thing we want to be able to *see* when
 *    testing, not the permanent background state.
 */
class LogVerkadaGateway implements VerkadaGateway
{
    public function ensureAccessUser(string $name, string $email, ?string $phone = null): string
    {
        $id = 'fake-user-'.Str::substr(md5($email), 0, 12);
        Log::info('[verkada:fake] ensureAccessUser', compact('name', 'email', 'phone') + ['returns' => $id]);

        return $id;
    }

    public function addUserToGroup(string $verkadaUserId, string $groupId): void
    {
        Log::info('[verkada:fake] addUserToGroup', compact('verkadaUserId', 'groupId'));
    }

    public function removeUserFromGroup(string $verkadaUserId, string $groupId): void
    {
        Log::info('[verkada:fake] removeUserFromGroup', compact('verkadaUserId', 'groupId'));
    }

    public function deactivateUser(string $verkadaUserId): void
    {
        Log::info('[verkada:fake] deactivateUser', compact('verkadaUserId'));
    }

    public function listGroupUserIds(string $groupId): array
    {
        Log::info('[verkada:fake] listGroupUserIds', compact('groupId'));

        return [];
    }

    public function unlockDoor(string $doorId, ?string $asVerkadaUserId = null): void
    {
        Log::info('[verkada:fake] unlockDoor', compact('doorId', 'asVerkadaUserId'));
    }

    /*
     | Discovery returns demo hardware, unlike listAccessEvents() which
     | deliberately returns nothing.
     |
     | The difference is what an empty list *means* on each screen. An empty
     | event list is honest — nothing has happened. An empty door list makes
     | the binding screen look broken and gives a developer nothing to click,
     | so the fake supplies a small, obviously-fake estate instead. Every id is
     | prefixed `demo_` so it can never be mistaken for a real one.
     */

    public function listDoors(): array
    {
        return [
            ['id' => 'demo_door_recovery', 'name' => 'Recovery Ward — S8 safe', 'site' => 'Demo Site'],
            ['id' => 'demo_door_theatre', 'name' => 'Theatre — drug cupboard', 'site' => 'Demo Site'],
            ['id' => 'demo_door_pharmacy', 'name' => 'Pharmacy — bulk store', 'site' => 'Demo Site'],
        ];
    }

    public function listCameras(): array
    {
        return [
            ['id' => 'demo_cam_recovery', 'name' => 'Recovery Ward — cabinet', 'site' => 'Demo Site'],
            ['id' => 'demo_cam_theatre', 'name' => 'Theatre — anteroom', 'site' => 'Demo Site'],
            ['id' => 'demo_cam_corridor', 'name' => 'Corridor — outside pharmacy', 'site' => 'Demo Site'],
        ];
    }

    public function listAccessGroups(): array
    {
        return [
            ['id' => 'demo_grp_s8', 'name' => 'S8 keyholders'],
            ['id' => 'demo_grp_pharmacy', 'name' => 'Pharmacy staff'],
            ['id' => 'demo_grp_afterhours', 'name' => 'After-hours access'],
        ];
    }

    public function testConnection(): array
    {
        return [
            'ok' => false,
            'message' => 'No Verkada API key is configured, so this application is running against the logging gateway. '
                .'Doors and cameras shown here are demo data and no real door will open.',
        ];
    }

    public function listAccessEvents(DateTimeInterface $since, int $limit = 500): array
    {
        Log::info('[verkada:fake] listAccessEvents', ['since' => $since->format('c'), 'limit' => $limit]);

        // No invented events. An empty event list is honest — nothing has
        // happened — and inventing some would train developers to ignore the
        // real ones. Products that need door events in development create them
        // through their own UI, which produces a row that can then be
        // correlated, counted and reconciled against.
        return [];
    }

    public function footageLink(string $cameraId, DateTimeInterface $at): ?string
    {
        return 'https://command.verkada.com/fake/footage/'.$cameraId.'?ts='.$at->getTimestamp();
    }

    public function thumbnailUrl(string $cameraId, DateTimeInterface $at): ?string
    {
        return 'https://command.verkada.com/fake/thumb/'.$cameraId.'?ts='.$at->getTimestamp();
    }

    public function createHelixEvent(string $cameraId, DateTimeInterface $at, array $attributes): ?string
    {
        $id = 'fake-helix-'.Str::random(10);
        Log::info('[verkada:fake] createHelixEvent', [
            'camera_id' => $cameraId,
            'at' => $at->format('c'),
            'attributes' => $attributes,
            'returns' => $id,
        ]);

        return $id;
    }

    public function enrolPersonOfInterest(string $name, string $photoPath): string
    {
        $id = 'fake-poi-'.Str::substr(md5($name), 0, 12);
        Log::info('[verkada:fake] enrolPersonOfInterest', compact('name', 'photoPath') + ['returns' => $id]);

        return $id;
    }

    public function removePersonOfInterest(string $profileId): void
    {
        Log::info('[verkada:fake] removePersonOfInterest', compact('profileId'));
    }

    public function listPersonOfInterestIds(): array
    {
        return [];
    }
}
