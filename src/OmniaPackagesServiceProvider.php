<?php

namespace OmniaGlobal\OmniaPackages;

use Illuminate\Support\ServiceProvider;
use OmniaGlobal\OmniaPackages\Verkada\HttpVerkadaGateway;
use OmniaGlobal\OmniaPackages\Verkada\LogVerkadaGateway;
use OmniaGlobal\OmniaPackages\Verkada\VerkadaGateway;
use OmniaGlobal\OmniaPackages\Verkada\WebhookSignature;

/**
 * The one provider, as in visns-packages. Every module the package grows binds
 * from here.
 */
class OmniaPackagesServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/omnia.php', 'omnia');

        $this->registerVerkada();
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/omnia.php' => config_path('omnia.php'),
            ], 'omnia-config');
        }
    }

    /**
     * The presence of an API key decides which gateway binds.
     *
     * A developer with a sandbox key gets the real client; a laptop with none
     * gets the logging fake and the whole application still runs. Nothing else
     * in a host application ever asks whether Verkada is configured — that
     * question is answered exactly once, here.
     *
     * Bound with `scoped` rather than `singleton` so a queue worker gets a
     * fresh instance per job and cannot carry a stale session token across
     * jobs that may be hours apart.
     */
    private function registerVerkada(): void
    {
        $this->app->scoped(VerkadaGateway::class, function (): VerkadaGateway {
            $key = config('omnia.verkada.key');

            if (blank($key)) {
                return new LogVerkadaGateway;
            }

            return new HttpVerkadaGateway(
                apiKey: $key,
                baseUrl: config('omnia.verkada.base_url') ?? 'https://api.verkada.com',
                helixEventTypeUid: config('omnia.verkada.helix_event_type_uid'),
                timeout: (int) config('omnia.verkada.timeout', 15),
                retries: (int) config('omnia.verkada.retries', 2),
            );
        });

        $this->app->scoped(WebhookSignature::class, fn () => WebhookSignature::fromConfig());
    }
}
