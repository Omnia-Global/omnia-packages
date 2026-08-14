<?php

namespace OmniaGlobal\OmniaPackages\Verkada;

use DateTimeInterface;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Verkada API client. https://apidocs.verkada.com
 *
 * Auth: the long-lived org API key is exchanged for a short-lived token
 * (POST /token, x-api-key header) which is then sent as x-verkada-auth. The
 * token lasts 30 minutes; we cache it for 25 and let it be re-minted rather
 * than tracking expiry precisely.
 *
 * Endpoint paths below follow the public documentation. The phase-0 sandbox
 * spike confirms them against a real org before this class is trusted in
 * production — anything the spike corrects is corrected here and nowhere else,
 * which is the reason the interface exists.
 */
class HttpVerkadaGateway implements VerkadaGateway
{
    public function __construct(
        private readonly string $apiKey,
        private readonly string $baseUrl = 'https://api.verkada.com',
        private readonly ?string $helixEventTypeUid = null,
        private readonly int $timeout = 15,
        private readonly int $retries = 2,
    ) {}

    // --- Access users and groups -------------------------------------------

    public function ensureAccessUser(string $name, string $email, ?string $phone = null): string
    {
        $existing = $this->request()->get('/access/v1/access_users/user', ['email' => $email]);

        if ($existing->successful() && ($id = $existing->json('user_id'))) {
            return $id;
        }

        return $this->request()
            ->post('/access/v1/access_users/user', array_filter([
                'full_name' => $name,
                'email' => $email,
                'phone' => $phone,
            ]))
            ->throw()
            ->json('user_id');
    }

    public function addUserToGroup(string $verkadaUserId, string $groupId): void
    {
        $this->request()
            ->put('/access/v1/access_groups/group/user', [
                'group_id' => $groupId,
                'user_id' => $verkadaUserId,
            ])
            ->throw();
    }

    public function removeUserFromGroup(string $verkadaUserId, string $groupId): void
    {
        $response = $this->request()
            ->delete('/access/v1/access_groups/group/user', [
                'group_id' => $groupId,
                'user_id' => $verkadaUserId,
            ]);

        // Already absent is the desired end state, not a failure.
        if ($response->status() !== 404) {
            $response->throw();
        }
    }

    public function deactivateUser(string $verkadaUserId): void
    {
        $this->request()
            ->put('/access/v1/access_users/user/deactivate', ['user_id' => $verkadaUserId])
            ->throw();
    }

    public function listGroupUserIds(string $groupId): array
    {
        $response = $this->request()
            ->get('/access/v1/access_groups/group', ['group_id' => $groupId])
            ->throw();

        return collect($response->json('users', []))
            ->pluck('user_id')
            ->filter()
            ->values()
            ->all();
    }

    // --- Discovery ----------------------------------------------------------

    public function listDoors(): array
    {
        return $this->discover('/access/v1/doors', 'doors', fn (array $d) => [
            'id' => $d['door_id'] ?? $d['id'] ?? null,
            'name' => $d['name'] ?? $d['door_name'] ?? 'Unnamed door',
            'site' => $d['site_name'] ?? $d['site'] ?? null,
        ]);
    }

    public function listCameras(): array
    {
        return $this->discover('/cameras/v1/devices', 'cameras', fn (array $c) => [
            'id' => $c['camera_id'] ?? $c['id'] ?? null,
            'name' => $c['name'] ?? 'Unnamed camera',
            'site' => $c['site'] ?? $c['location'] ?? null,
        ]);
    }

    public function listAccessGroups(): array
    {
        return $this->discover('/access/v1/access_groups', 'access_groups', fn (array $g) => [
            'id' => $g['group_id'] ?? $g['id'] ?? null,
            'name' => $g['name'] ?? 'Unnamed group',
        ]);
    }

    public function testConnection(): array
    {
        try {
            $response = Http::baseUrl($this->baseUrl)
                ->withHeaders(['x-api-key' => $this->apiKey])
                ->timeout($this->timeout)
                ->post('/token');

            if ($response->successful()) {
                return ['ok' => true, 'message' => 'Connected to Verkada Command.'];
            }

            return [
                'ok' => false,
                'message' => "Verkada refused the API key (HTTP {$response->status()}). Check the key and that it has access to this organisation.",
            ];
        } catch (\Throwable $e) {
            return [
                'ok' => false,
                'message' => 'Could not reach Verkada: '.$e->getMessage(),
            ];
        }
    }

    /**
     * Shared shape for the discovery calls.
     *
     * Returns [] on any failure rather than throwing. The binding screen falls
     * back to manual ID entry when the list is empty, so a Verkada outage
     * degrades the screen instead of breaking it — and an administrator who
     * already has the ID on a commissioning sheet is never blocked.
     *
     * @param  callable(array): array  $map
     */
    private function discover(string $path, string $key, callable $map): array
    {
        try {
            $response = $this->request()->get($path);

            if (! $response->successful()) {
                Log::warning('Verkada discovery failed', ['path' => $path, 'status' => $response->status()]);

                return [];
            }

            return collect($response->json($key, $response->json('data', [])))
                ->map($map)
                ->filter(fn (array $row) => filled($row['id']))
                ->values()
                ->all();
        } catch (\Throwable $e) {
            Log::warning('Verkada discovery threw', ['path' => $path, 'error' => $e->getMessage()]);

            return [];
        }
    }

    // --- Doors --------------------------------------------------------------

    public function unlockDoor(string $doorId, ?string $asVerkadaUserId = null): void
    {
        // Unlocking *as a user* attributes the act to them in Command's own
        // audit; unlocking as admin attributes it to the integration, which is
        // useless in an investigation. Prefer the former whenever we know who.
        $path = $asVerkadaUserId !== null
            ? '/access/v1/door/user_unlock'
            : '/access/v1/door/admin_unlock';

        $this->request()
            ->post($path, array_filter([
                'door_id' => $doorId,
                'user_id' => $asVerkadaUserId,
            ]))
            ->throw();
    }

    // --- Access events ------------------------------------------------------

    public function listAccessEvents(DateTimeInterface $since, int $limit = 500): array
    {
        $response = $this->request()
            ->get('/events/v1/access', [
                'start_time' => $since->getTimestamp(),
                'page_size' => $limit,
            ])
            ->throw();

        return collect($response->json('events', []))
            ->map(fn (array $event) => [
                'event_id' => $event['event_id'] ?? null,
                'time' => $event['timestamp'] ?? $event['created_at'] ?? null,
                'verkada_user_id' => $event['user_id'] ?? null,
                'door_id' => $event['door_id'] ?? null,
                'result' => $event['result'] ?? $event['event_type'] ?? null,
            ])
            ->filter(fn (array $event) => $event['time'] !== null)
            ->values()
            ->all();
    }

    // --- Footage ------------------------------------------------------------

    public function footageLink(string $cameraId, DateTimeInterface $at): ?string
    {
        $response = $this->request()->get('/cameras/v1/footage/link', [
            'camera_id' => $cameraId,
            'timestamp' => $at->getTimestamp(),
        ]);

        // A missing clip is a normal outcome — the camera may have been
        // offline — and must not fail the transaction that asked for it.
        return $response->successful() ? $response->json('url') : null;
    }

    public function thumbnailUrl(string $cameraId, DateTimeInterface $at): ?string
    {
        $response = $this->request()->get('/cameras/v1/footage/thumbnails/link', [
            'camera_id' => $cameraId,
            'timestamp' => $at->getTimestamp(),
        ]);

        return $response->successful() ? $response->json('url') : null;
    }

    // --- Helix --------------------------------------------------------------

    public function createHelixEvent(string $cameraId, DateTimeInterface $at, array $attributes): ?string
    {
        if ($this->helixEventTypeUid === null) {
            return null;
        }

        $response = $this->request()->post('/cameras/v1/video_tagging/event', [
            'camera_id' => $cameraId,
            'time_ms' => $at->getTimestamp() * 1000,
            'event_type_uid' => $this->helixEventTypeUid,
            'attributes' => $attributes,
        ]);

        return $response->successful() ? $response->json('event_id') : null;
    }

    // --- Person of Interest -------------------------------------------------

    public function enrolPersonOfInterest(string $name, string $photoPath): string
    {
        return $this->request()
            ->attach('file', file_get_contents($photoPath), basename($photoPath))
            ->post('/cameras/v1/people/person_of_interest', ['label' => $name])
            ->throw()
            ->json('person_id');
    }

    public function removePersonOfInterest(string $profileId): void
    {
        $response = $this->request()
            ->delete('/cameras/v1/people/person_of_interest', ['person_id' => $profileId]);

        if ($response->status() !== 404) {
            $response->throw();
        }
    }

    public function listPersonOfInterestIds(): array
    {
        $response = $this->request()
            ->get('/cameras/v1/people/person_of_interest')
            ->throw();

        return collect($response->json('persons_of_interest', []))
            ->pluck('person_id')
            ->filter()
            ->values()
            ->all();
    }

    // --- Plumbing -----------------------------------------------------------

    private function request(): PendingRequest
    {
        return Http::baseUrl($this->baseUrl)
            ->withHeaders(['x-verkada-auth' => $this->token()])
            ->acceptJson()
            ->timeout($this->timeout)
            ->retry($this->retries, 200, throw: false);
    }

    private function token(): string
    {
        return Cache::remember('omnia.verkada.token', now()->addMinutes(25), function (): string {
            return Http::baseUrl($this->baseUrl)
                ->withHeaders(['x-api-key' => $this->apiKey])
                ->post('/token')
                ->throw()
                ->json('token');
        });
    }
}
