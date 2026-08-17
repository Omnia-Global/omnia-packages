<?php

namespace OmniaGlobal\OmniaPackages\Tests\Verkada;

use Illuminate\Support\Facades\Http;
use OmniaGlobal\OmniaPackages\Tests\TestCase;
use OmniaGlobal\OmniaPackages\Verkada\AccessResult;
use OmniaGlobal\OmniaPackages\Verkada\HttpVerkadaGateway;

/**
 * The real client, against the payload shapes Verkada's reference documents.
 *
 * There was no test here at all, which is how a mapping that read `user_id` and
 * `door_id` off the top level of an event survived: only the fake had ever run,
 * and the fake returns whatever we decided it should. The fixtures below are
 * transcribed from apidocs.verkada.com, so a future edit is measured against
 * Verkada rather than against our own idea of Verkada.
 */
class HttpVerkadaGatewayTest extends TestCase
{
    private function gateway(): HttpVerkadaGateway
    {
        return new HttpVerkadaGateway(apiKey: 'test-key', baseUrl: 'https://api.verkada.example');
    }

    private function fake(array $responses): void
    {
        Http::fake(['*/token' => Http::response(['token' => 'session-token'])] + $responses);
    }

    // --- Discovery ----------------------------------------------------------

    /**
     * A door's `site` is an object. Taken as-is it reaches the browser as a
     * PHP array and renders "Ward A — [object Object]", which is what the
     * customer saw on the binding screen.
     */
    public function test_a_doors_nested_site_object_is_reduced_to_its_name(): void
    {
        $this->fake(['*/access/v1/doors*' => Http::response(['doors' => [
            [
                'door_id' => '0d2ec576-debc-4f1b-9501-6700659b7546',
                'name' => 'Recovery S8 Safe',
                'site' => ['name' => 'Riverside Hospital', 'site_id' => '42338454-dc42-4d25-80ce-35e42c2d0578'],
                'acu_name' => 'AC41 Ward A',
                'acu_id' => 'acu_1',
            ],
        ]])]);

        $this->assertSame([[
            'id' => '0d2ec576-debc-4f1b-9501-6700659b7546',
            'name' => 'Recovery S8 Safe',
            'site' => 'Riverside Hospital',
        ]], $this->gateway()->listDoors());
    }

    /** A camera's `site` is a plain string under the same key. Both must work. */
    public function test_a_cameras_flat_site_string_still_works(): void
    {
        $this->fake(['*/cameras/v1/devices*' => Http::response(['cameras' => [
            [
                'camera_id' => '99c16b13-6c52-45f6-9738-17f9918fa695',
                'name' => 'Ward A Corridor',
                'site' => 'Riverside Hospital',
                'location' => 'Level 2',
            ],
        ]])]);

        $this->assertSame([[
            'id' => '99c16b13-6c52-45f6-9738-17f9918fa695',
            'name' => 'Ward A Corridor',
            'site' => 'Riverside Hospital',
        ]], $this->gateway()->listCameras());
    }

    public function test_access_groups_are_listed(): void
    {
        $this->fake(['*/access/v1/access_groups' => Http::response(['access_groups' => [
            ['group_id' => '77c4f0e2-74f1-437d-9b12-ae7052a1', 'name' => 'Test Group'],
        ]])]);

        $this->assertSame([[
            'id' => '77c4f0e2-74f1-437d-9b12-ae7052a1',
            'name' => 'Test Group',
        ]], $this->gateway()->listAccessGroups());
    }

    /** Discovery degrades to an empty list so the binding screen survives. */
    public function test_a_failed_discovery_call_returns_nothing_rather_than_throwing(): void
    {
        $this->fake(['*/access/v1/doors*' => Http::response(status: 503)]);

        $this->assertSame([], $this->gateway()->listDoors());
    }

    // --- Group membership ---------------------------------------------------

    /**
     * Membership comes back as `user_ids`, a flat array of strings. Reading
     * `users[].user_id` finds nothing, and nothing is a legitimate answer for
     * an empty group — so every group looked empty and every entitled person
     * looked like access drift.
     */
    public function test_group_membership_is_read_from_user_ids(): void
    {
        $this->fake(['*/access/v1/access_groups/group*' => Http::response([
            'group_id' => 'grp_1',
            'name' => 'Ward A Nurses',
            'user_ids' => ['user_a', 'user_b'],
        ])]);

        $this->assertSame(['user_a', 'user_b'], $this->gateway()->listGroupUserIds('grp_1'));
    }

    public function test_group_membership_also_reads_the_older_object_shape(): void
    {
        $this->fake(['*/access/v1/access_groups/group*' => Http::response([
            'group_id' => 'grp_1',
            'users' => [['user_id' => 'user_a'], ['user_id' => 'user_b']],
        ])]);

        $this->assertSame(['user_a', 'user_b'], $this->gateway()->listGroupUserIds('grp_1'));
    }

    // --- Access events ------------------------------------------------------

    /**
     * The identities live inside `event_info`, in camelCase, under an envelope
     * that is snake_case. Reading them off the top level yields a null door on
     * every event, and an event with no door is skipped as "not our cabinet" —
     * so the sync reported success and recorded nothing.
     */
    public function test_an_event_is_flattened_out_of_the_event_info_envelope(): void
    {
        $this->fake(['*/events/v1/access*' => Http::response(['events' => [
            [
                'event_id' => 'evt_1',
                'timestamp' => 1755400000,
                'device_id' => 'acu_1',
                'device_type' => 'access_control',
                'event_type' => 'door_opened',
                'event_info' => [
                    'userId' => 'user_a',
                    'userName' => 'A. Nurse',
                    'doorId' => 'door_1',
                    'doorInfo' => ['name' => 'Recovery S8 Safe', 'accessControllerId' => 'acu_1'],
                    'accepted' => true,
                ],
            ],
        ]])]);

        $events = $this->gateway()->listAccessEvents(now()->subHour());

        $this->assertCount(1, $events);
        $this->assertSame('evt_1', $events[0]['event_id']);
        $this->assertSame('user_a', $events[0]['verkada_user_id']);
        $this->assertSame('A. Nurse', $events[0]['verkada_user_name']);
        $this->assertSame('door_1', $events[0]['door_id']);
        $this->assertSame('Recovery S8 Safe', $events[0]['door_name']);
        $this->assertSame(AccessResult::GRANTED, $events[0]['result']);
        $this->assertSame('door_opened', $events[0]['event_type']);
        // Unix seconds in, ISO-8601 out: Carbon::parse() of a bare integer is
        // not the moment anybody means.
        $this->assertSame('2025-08-17T03:06:40+00:00', $events[0]['time']);
    }

    public function test_a_refusal_is_normalised_to_denied(): void
    {
        $this->fake(['*/events/v1/access*' => Http::response(['events' => [
            [
                'event_id' => 'evt_2',
                'timestamp' => 1755400000,
                'event_type' => 'door_rejected',
                'event_info' => ['userId' => 'user_b', 'doorId' => 'door_1', 'accepted' => false],
            ],
        ]])]);

        $events = $this->gateway()->listAccessEvents(now()->subHour());

        $this->assertSame(AccessResult::DENIED, $events[0]['result']);
        $this->assertSame('door_rejected', $events[0]['event_type']);
    }

    /** An org still on the older flat shape must keep working. */
    public function test_the_older_flat_event_shape_is_still_read(): void
    {
        $this->fake(['*/events/v1/access*' => Http::response(['events' => [
            [
                'event_id' => 'evt_3',
                'timestamp' => 1755400000,
                'user_id' => 'user_c',
                'door_id' => 'door_2',
                'door_name' => 'Side Entrance',
                'event_type' => 'door_opened',
            ],
        ]])]);

        $events = $this->gateway()->listAccessEvents(now()->subHour());

        $this->assertSame('user_c', $events[0]['verkada_user_id']);
        $this->assertSame('door_2', $events[0]['door_id']);
        $this->assertSame('Side Entrance', $events[0]['door_name']);
    }

    /** page_size above Verkada's documented maximum is a 400, not a big page. */
    public function test_the_page_size_is_clamped_to_verkadas_maximum(): void
    {
        $this->fake(['*/events/v1/access*' => Http::response(['events' => []])]);

        $this->gateway()->listAccessEvents(now()->subHour(), limit: 500);

        Http::assertSent(fn ($request) => ! str_contains($request->url(), '/token')
            ? str_contains($request->url(), 'page_size=200')
            : true);
    }

    // --- Auth ---------------------------------------------------------------

    public function test_the_org_key_is_exchanged_for_a_session_token(): void
    {
        $this->fake(['*/access/v1/doors*' => Http::response(['doors' => []])]);

        $this->gateway()->listDoors();

        Http::assertSent(fn ($request) => str_contains($request->url(), '/token')
            && $request->hasHeader('x-api-key', 'test-key'));

        Http::assertSent(fn ($request) => str_contains($request->url(), '/access/v1/doors')
            && $request->hasHeader('x-verkada-auth', 'session-token'));
    }
}
