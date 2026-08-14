<?php

namespace OmniaGlobal\OmniaPackages\Tests\Verkada;

use OmniaGlobal\OmniaPackages\Tests\TestCase;
use OmniaGlobal\OmniaPackages\Verkada\LogVerkadaGateway;

/**
 * The fake is not uniformly "return nothing" — the choice per method is
 * deliberate, because an empty result means different things on different
 * screens. These tests pin those choices so a well-meaning tidy-up does not
 * flatten them.
 */
class LogVerkadaGatewayTest extends TestCase
{
    private LogVerkadaGateway $gateway;

    protected function setUp(): void
    {
        parent::setUp();
        $this->gateway = new LogVerkadaGateway;
        LogVerkadaGateway::resolveRecentEventsUsing(null);
    }

    protected function tearDown(): void
    {
        // Static state: leaking it would make one test's resolver another
        // test's mystery.
        LogVerkadaGateway::resolveRecentEventsUsing(null);
        parent::tearDown();
    }

    public function test_it_returns_a_stable_fake_user_id_for_the_same_email(): void
    {
        $first = $this->gateway->ensureAccessUser('A Person', 'person@example.com');
        $second = $this->gateway->ensureAccessUser('A Person', 'person@example.com');

        $this->assertSame($first, $second, 'A reconcile must not see the same person as two.');
        $this->assertStringStartsWith('fake-user-', $first);
    }

    /**
     * An empty event list is honest — nothing has happened. Inventing events
     * would train developers to ignore the real ones.
     */
    public function test_it_invents_no_access_events(): void
    {
        $this->assertSame([], $this->gateway->listAccessEvents(now()->subHour()));
    }

    /**
     * So the nightly reconcile finds "entitled but missing" and never "present
     * but not entitled". Without a real organisation there is no drift to find.
     */
    public function test_group_membership_is_empty_so_the_reconciler_finds_no_drift(): void
    {
        $this->assertSame([], $this->gateway->listGroupUserIds('grp_anything'));
    }

    /**
     * Discovery is the exception: an empty door list makes a binding screen
     * look broken and gives nobody anything to click.
     */
    public function test_discovery_returns_demo_hardware_clearly_marked_as_such(): void
    {
        foreach ([$this->gateway->listDoors(), $this->gateway->listCameras()] as $devices) {
            $this->assertNotEmpty($devices);

            foreach ($devices as $device) {
                $this->assertStringStartsWith('demo_', $device['id'],
                    'A fake id must never be mistakable for a real one.');
                $this->assertArrayHasKey('name', $device);
            }
        }

        $this->assertNotEmpty($this->gateway->listAccessGroups());
    }

    /**
     * Returning null would make every evidence panel look like a camera
     * outage, and outages are something we want to be able to *see* in
     * development rather than have as the permanent background state.
     */
    public function test_footage_links_are_placeholders_rather_than_null(): void
    {
        $at = now();

        $this->assertNotNull($this->gateway->footageLink('cam_1', $at));
        $this->assertNotNull($this->gateway->thumbnailUrl('cam_1', $at));
    }

    public function test_it_says_plainly_that_it_is_not_connected(): void
    {
        $result = $this->gateway->testConnection();

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('no real door will open', $result['message']);
    }

    /**
     * A product that mirrors door events locally wants the fake to serve that
     * rather than invented rows, or a member drill-down shows different
     * history from the dashboard beside it. The package cannot read a host's
     * model, so the host registers a resolver.
     */
    public function test_a_host_can_serve_its_own_mirrored_history(): void
    {
        LogVerkadaGateway::resolveRecentEventsUsing(fn (string $id, int $limit) => [
            ['time' => '2026-01-01T09:00:00+00:00', 'door_name' => 'Ward Door', 'result' => 'door_opened'],
        ]);

        $events = $this->gateway->recentAccessEvents('vk_1');

        $this->assertCount(1, $events);
        $this->assertSame('Ward Door', $events[0]['door_name']);
    }

    public function test_an_empty_mirror_falls_back_to_demo_rows(): void
    {
        // A brand-new instance has mirrored nothing yet, and an empty panel
        // reads as broken rather than as new.
        LogVerkadaGateway::resolveRecentEventsUsing(fn () => []);

        $this->assertNotEmpty($this->gateway->recentAccessEvents('vk_1'));
    }

    public function test_without_a_resolver_it_returns_demo_rows(): void
    {
        $events = $this->gateway->recentAccessEvents('vk_1');

        $this->assertNotEmpty($events);
        foreach ($events as $event) {
            $this->assertArrayHasKey('door_name', $event);
            $this->assertArrayHasKey('result', $event);
        }
    }

    public function test_helix_returns_an_id_so_callers_can_store_one(): void
    {
        $this->assertStringStartsWith(
            'fake-helix-',
            (string) $this->gateway->createHelixEvent('cam_1', now(), ['item' => 'x']),
        );
    }
}
