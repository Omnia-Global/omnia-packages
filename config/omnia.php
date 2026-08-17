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
        | The organisation id. Needed only to stream footage in a browser —
        | every other call infers the organisation from the API key. It is an
        | identifier rather than a secret, and it appears in every webhook
        | delivery Verkada sends.
        */
        'org_id' => env('VERKADA_ORG_ID'),

        /*
        | Shared secret for the event webhooks. WebhookSignature refuses every
        | request when this is empty — an unauthenticated endpoint that writes
        | to a custody or attendance record is a worse failure than a webhook
        | that does not work yet.
        */
        'webhook_secret' => env('VERKADA_WEBHOOK_SECRET'),

        /*
        | How old a signed delivery may be before it is refused, in seconds.
        |
        | Verkada signs the timestamp along with the body, so this is a real
        | replay bound rather than a formality. Wider than Verkada's own
        | 60-second example on purpose: a replay is idempotent on the event ID,
        | while a host whose clock has drifted a couple of minutes silently
        | records nothing at all.
        */
        'webhook_tolerance' => (int) env('VERKADA_WEBHOOK_TOLERANCE', 300),

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
