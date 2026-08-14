<?php

/*
|--------------------------------------------------------------------------
| Omnia shared package configuration
|--------------------------------------------------------------------------
| Merged into the host application automatically, so a product that is happy
| with the defaults sets environment variables and nothing else. Publish it
| with:
|
|   php artisan vendor:publish --tag=omnia-config
|
| The environment variable names are the ones Pulse, Campus and Vault already
| use, so adopting the package changes no .env file.
*/

return [

    'verkada' => [

        /*
        | The organisation API key, exchanged for a short-lived session token.
        |
        | Leaving it empty is a supported state, not a broken one: the service
        | provider binds LogVerkadaGateway instead, every call is written to
        | the log rather than sent, and the host application runs end to end
        | with no credentials and no cabinet on the desk.
        */
        'key' => env('VERKADA_API_KEY'),

        'base_url' => env('VERKADA_BASE_URL', 'https://api.verkada.com'),

        /*
        | Shared secret for the event webhooks. WebhookSignature refuses every
        | request when this is empty — an unauthenticated endpoint that writes
        | to a custody or attendance record is a worse failure than a webhook
        | that does not work yet.
        */
        'webhook_secret' => env('VERKADA_WEBHOOK_SECRET'),

        /*
        | The Helix event type to post custom video metadata against. Without
        | it createHelixEvent() returns null and nothing else changes.
        */
        'helix_event_type_uid' => env('VERKADA_HELIX_EVENT_TYPE_UID'),

        /*
        | Seconds to wait on any single call, and how many times to retry.
        | Deliberately short: these run inside jobs and behind webhooks, and a
        | vendor timeout must not become a queue backlog.
        */
        'timeout' => (int) env('VERKADA_TIMEOUT', 15),
        'retries' => (int) env('VERKADA_RETRIES', 2),
    ],

];
