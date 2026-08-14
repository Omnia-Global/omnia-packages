<?php

namespace OmniaGlobal\OmniaPackages\Tests\Verkada;

use OmniaGlobal\OmniaPackages\Tests\TestCase;
use OmniaGlobal\OmniaPackages\Verkada\HttpVerkadaGateway;
use OmniaGlobal\OmniaPackages\Verkada\LogVerkadaGateway;
use OmniaGlobal\OmniaPackages\Verkada\VerkadaGateway;

/**
 * The binding is the package's whole promise: a host application asks for
 * VerkadaGateway and never asks whether Verkada is configured.
 */
class GatewayBindingTest extends TestCase
{
    public function test_the_logging_fake_binds_when_no_key_is_configured(): void
    {
        config(['omnia.verkada.key' => null]);

        $this->assertInstanceOf(LogVerkadaGateway::class, app(VerkadaGateway::class));
    }

    public function test_an_empty_string_key_is_treated_as_no_key(): void
    {
        // A half-filled .env is the common case, and it must not produce a
        // client that authenticates with an empty string.
        config(['omnia.verkada.key' => '']);

        $this->assertInstanceOf(LogVerkadaGateway::class, app(VerkadaGateway::class));
    }

    public function test_the_http_client_binds_when_a_key_is_configured(): void
    {
        config(['omnia.verkada.key' => 'sk_test_123']);

        $this->assertInstanceOf(HttpVerkadaGateway::class, app(VerkadaGateway::class));
    }

    public function test_a_host_application_can_override_the_binding(): void
    {
        config(['omnia.verkada.key' => 'sk_test_123']);

        $this->app->bind(VerkadaGateway::class, fn () => new LogVerkadaGateway);

        $this->assertInstanceOf(LogVerkadaGateway::class, app(VerkadaGateway::class));
    }

    public function test_the_config_is_merged_so_a_product_need_not_publish_it(): void
    {
        $this->assertSame('https://api.verkada.com', config('omnia.verkada.base_url'));
        $this->assertSame(15, config('omnia.verkada.timeout'));
    }
}
