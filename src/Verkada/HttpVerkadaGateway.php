<?php

namespace OmniaGlobal\OmniaPackages\Verkada;

use Carbon\CarbonImmutable;
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
    /** Verkada's cap on a single historical stream request. */
    private const MAX_STREAM_SECONDS = 3600;

    public function __construct(
        private readonly string $apiKey,
        private readonly string $baseUrl = 'https://api.verkada.com',
        private readonly ?string $helixEventTypeUid = null,
        private readonly int $timeout = 15,
        private readonly int $retries = 2,
        /** Required for streaming only; every other call infers it from the key. */
        private readonly ?string $orgId = null,
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

    public function sendPassInvite(string $verkadaUserId): void
    {
        $this->request()
            ->post('/access/v1/access_users/user/pass/invite', ['user_id' => $verkadaUserId])
            ->throw();
    }

    public function deactivateUser(string $verkadaUserId): void
    {
        $this->request()
            ->put('/access/v1/access_users/user/deactivate', ['user_id' => $verkadaUserId])
            ->throw();
    }

    /**
     * Verkada returns the membership as `user_ids`: a flat array of strings,
     * not a list of user objects. Reading `users[].user_id` — which this did —
     * finds nothing, and "nothing" is a legitimate answer for an empty group,
     * so every group looked empty and every entitled person looked like access
     * drift. A reconcile that reports the whole roster as missing is not a
     * loud failure; it is a quiet one that gets ignored.
     */
    public function listGroupUserIds(string $groupId): array
    {
        $response = $this->request()
            ->get('/access/v1/access_groups/group', ['group_id' => $groupId])
            ->throw();

        return collect($response->json('user_ids', []))
            // Older payloads (and Command's own exports) carry objects instead.
            ->merge(collect($response->json('users', []))->pluck('user_id'))
            ->filter(fn ($id) => is_string($id) && $id !== '')
            ->unique()
            ->values()
            ->all();
    }

    // --- Discovery ----------------------------------------------------------

    public function listDoors(): array
    {
        return $this->discover('/access/v1/doors', 'doors', fn (array $d) => [
            'id' => $d['door_id'] ?? $d['id'] ?? null,
            'name' => $d['name'] ?? $d['door_name'] ?? 'Unnamed door',
            'site' => $this->siteName($d),
        ]);
    }

    public function listCameras(): array
    {
        return $this->discover('/cameras/v1/devices', 'cameras', fn (array $c) => [
            'id' => $c['camera_id'] ?? $c['id'] ?? null,
            'name' => $c['name'] ?? 'Unnamed camera',
            'site' => $this->siteName($c) ?? (is_string($c['location'] ?? null) ? $c['location'] : null),
        ]);
    }

    /**
     * A site is a `{name, site_id}` object on a door and a plain string on a
     * camera, and both arrive under the key `site`.
     *
     * Taking the value as-is put a PHP array into a field the products render
     * as text, so every door in the binding list read "Ward A — [object
     * Object]" — the kind of defect that makes an administrator distrust the
     * whole screen, including the door ID beside it, which was correct.
     */
    private function siteName(array $row): ?string
    {
        $site = $row['site'] ?? null;

        return match (true) {
            is_string($site) && $site !== '' => $site,
            is_array($site) => $site['name'] ?? null,
            default => $row['site_name'] ?? null,
        };
    }

    public function listAccessGroups(): array
    {
        return $this->discover('/access/v1/access_groups', 'access_groups', fn (array $g) => [
            'id' => $g['group_id'] ?? $g['id'] ?? null,
            'name' => $g['name'] ?? 'Unnamed group',
        ]);
    }

    public function listAccessUsers(): array
    {
        return $this->discover('/access/v1/access_users', 'access_members', fn (array $u) => [
            'id' => $u['user_id'] ?? $u['id'] ?? null,
            'name' => $u['full_name'] ?? $u['name'] ?? 'Unnamed person',
            'email' => $u['email'] ?? null,
            'employee_id' => $u['employee_id'] ?? null,
            'is_visitor' => (bool) ($u['is_visitor'] ?? false),
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
                // Verkada's name for the badge holder, carried alongside the id.
                // A host that cannot match the credential to its own records can
                // still say who Verkada thinks it is, instead of showing a UUID.
                'verkada_user_name' => $event['user_name']
                    ?? ($info['userName'] ?? null)
                    ?? ($info['userInfo']['name'] ?? null),
                'door_id' => $doorId,
                'user_id' => $asVerkadaUserId,
            ]))
            ->throw();
    }

    // --- Access events ------------------------------------------------------

    /**
     * Verkada's documented page_size ceiling. Asking for more is a 400, and a
     * 400 here is not a slow sync — it is no openings at all, from the path
     * that exists to catch what the webhook dropped.
     */
    private const MAX_PAGE_SIZE = 200;

    public function listAccessEvents(DateTimeInterface $since, int $limit = 500): array
    {
        $response = $this->request()
            ->get('/events/v1/access', [
                'start_time' => $since->getTimestamp(),
                'page_size' => min($limit, self::MAX_PAGE_SIZE),
            ])
            ->throw();

        return collect($response->json('events', []))
            ->map(fn (array $event) => $this->accessEvent($event))
            ->filter(fn (array $event) => $event['time'] !== null)
            ->values()
            ->all();
    }

    public function recentAccessEvents(string $verkadaUserId, int $limit = 20): array
    {
        $response = $this->request()
            ->get('/events/v1/access', [
                'user_id' => $verkadaUserId,
                'page_size' => min($limit, self::MAX_PAGE_SIZE),
            ]);

        if (! $response->successful()) {
            // A person with no history, or a filter Verkada does not accept.
            // Neither should take down the screen that asked.
            Log::warning('Verkada recentAccessEvents failed', ['status' => $response->status()]);

            return [];
        }

        return collect($response->json('events', []))
            ->map(fn (array $event) => collect($this->accessEvent($event))
                ->only(['time', 'door_name', 'result'])
                ->all())
            ->filter(fn (array $event) => $event['time'] !== null)
            ->values()
            ->all();
    }

    /**
     * One access event, flattened.
     *
     * Verkada puts the identities inside an `event_info` object and names them
     * in camelCase — `userId`, `doorId`, `doorInfo.name` — while the envelope
     * around it is snake_case. Reading `user_id` and `door_id` off the top
     * level, as this did, finds neither: every polled event came back with a
     * null door, was skipped as "a door we do not manage", and the products
     * recorded nothing while reporting a healthy sync.
     *
     * The flat keys are still read first so an org on the older shape keeps
     * working — the two have to coexist rather than be swapped.
     *
     * @param  array<string, mixed>  $event
     * @return array{event_id: string|null, time: string|null, verkada_user_id: string|null, verkada_user_name: string|null, door_id: string|null, door_name: string|null, result: string, event_type: string|null}
     */
    private function accessEvent(array $event): array
    {
        $info = $event['event_info'] ?? [];
        $type = $event['event_type'] ?? $event['notification_type'] ?? null;

        $doorId = $event['door_id']
            ?? ($info['doorId'] ?? null)
            ?? ($info['door_id'] ?? null)
            ?? ($info['doorInfo']['door_id'] ?? null);

        return [
            'event_id' => $event['event_id'] ?? null,
            // Normalised to ISO-8601 here rather than at each call site: the
            // API sends Unix seconds, and Carbon::parse() of a bare integer is
            // not the timestamp anybody expects.
            'time' => $this->timestamp($event['timestamp'] ?? $event['created_at'] ?? $event['created'] ?? null),
            'verkada_user_id' => $event['user_id']
                ?? ($info['userId'] ?? null)
                ?? ($info['user_id'] ?? null)
                ?? ($info['userInfo']['user_id'] ?? null),
            // Verkada's name for the badge holder, carried alongside the id.
            // A host that cannot match the credential to its own records can
            // still say who Verkada thinks it is, instead of showing a UUID.
            'verkada_user_name' => $event['user_name']
                ?? ($info['userName'] ?? null)
                ?? ($info['userInfo']['name'] ?? null),
            'door_id' => $doorId,
            // Always displayable: falls back to the id when Verkada supplies
            // no name, so a caller never has to null-coalesce.
            'door_name' => $event['door_name']
                ?? ($info['doorInfo']['name'] ?? null)
                ?? ($info['doorName'] ?? null)
                ?? $doorId,
            'result' => AccessResult::normalise($type, $info['accepted'] ?? null),
            // The raw type is kept beside the verdict: door_held_open and
            // door_forced are both "granted" and both worth reading.
            'event_type' => $type,
        ];
    }

    private function timestamp(mixed $raw): ?string
    {
        if ($raw === null || $raw === '') {
            return null;
        }

        return is_numeric($raw)
            ? CarbonImmutable::createFromTimestamp((int) $raw)->toIso8601String()
            : (string) $raw;
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

    /**
     * A short-lived JWT for the streaming endpoint. Verkada issues these for
     * 30 minutes; they are never stored, because a stored one outlives the
     * reason it was minted.
     */
    public function streamingToken(): ?string
    {
        $response = $this->request()->get('/cameras/v1/footage/token');

        if (! $response->successful()) {
            Log::warning('Verkada streaming token refused', ['status' => $response->status()]);

            return null;
        }

        return $response->json('jwt') ?? $response->json('token');
    }

    public function footageStreamUrl(string $cameraId, DateTimeInterface $from, DateTimeInterface $to): ?string
    {
        if (blank($this->orgId)) {
            Log::warning('Verkada streaming needs an organisation id; set VERKADA_ORG_ID.');

            return null;
        }

        $seconds = $to->getTimestamp() - $from->getTimestamp();

        if ($seconds <= 0 || $seconds > self::MAX_STREAM_SECONDS) {
            Log::warning('Verkada streaming window out of range', ['seconds' => $seconds]);

            return null;
        }

        $jwt = $this->streamingToken();

        if (blank($jwt)) {
            return null;
        }

        // The stream lives under /stream on the same regional host as the API,
        // and `stream.m3u8` is the key that asks for an HLS playlist rather
        // than a single segment.
        return rtrim($this->baseUrl, '/').'/stream/cameras/v1/footage/stream/stream.m3u8?'.http_build_query([
            'org_id' => $this->orgId,
            'camera_id' => $cameraId,
            'start_time' => $from->getTimestamp(),
            'end_time' => $to->getTimestamp(),
            'jwt' => $jwt,
            'resolution' => 'high_res',
        ]);
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
