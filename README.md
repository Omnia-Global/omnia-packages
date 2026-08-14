# Omnia Packages

Shared Laravel code for the Omnia Global products — **Pulse**, **Campus** and
**Vault**.

The three are one family: Laravel 13 + Inertia v3 + React 19 + Verkada, one
instance per customer. This package holds what all three need and none of them
should own — starting with the Verkada client, which until now existed as three
byte-identical copies.

> Sibling package: [`omnia-components`](https://github.com/Omnia-Global/omnia-components)
> for the React side. Different family from `visns-packages`, which serves the
> Visns CRMs on a different stack and a different release cadence.

---

## Installing

```bash
composer config repositories.omnia-packages vcs git@github.com:Omnia-Global/omnia-packages.git
composer require omniaglobal/omnia-packages
```

The service provider is auto-discovered and the config is merged, so a product
happy with the defaults sets environment variables and nothing else.

While iterating on the package itself, point at your working copy instead:

```jsonc
// composer.json
"repositories": [
  { "type": "path", "url": "../omnia-packages", "options": { "symlink": true } }
],
"require": { "omniaglobal/omnia-packages": "@dev" }
```

To override any default:

```bash
php artisan vendor:publish --tag=omnia-config
```

---

## Modules

### Verkada

One interface, two implementations, bound by whether an API key exists.

```php
use OmniaGlobal\OmniaPackages\Verkada\VerkadaGateway;

public function __construct(private readonly VerkadaGateway $verkada) {}
```

**Nothing in a host application ever asks whether Verkada is configured.** That
question is answered once, in the service provider: with a key you get
`HttpVerkadaGateway`, without one you get `LogVerkadaGateway`, and the
application runs either way.

| Area | Methods |
|---|---|
| Access users & groups | `ensureAccessUser`, `addUserToGroup`, `removeUserFromGroup`, `sendPassInvite`, `deactivateUser`, `listGroupUserIds` |
| Discovery | `listDoors`, `listCameras`, `listAccessGroups`, `testConnection` |
| Doors | `unlockDoor` |
| Events | `listAccessEvents`, `recentAccessEvents` |
| Footage | `footageLink`, `thumbnailUrl` |
| Helix | `createHelixEvent` |
| Person of Interest | `enrolPersonOfInterest`, `removePersonOfInterest`, `listPersonOfInterestIds` |

The interface is the **union** of what the three products need, not the
intersection. Pulse and Campus issue mobile passes; Vault reads footage and
writes Helix events. A product that never calls a method pays nothing for its
existence, and splitting this into narrower contracts would only mean three
places to change when Verkada changes.

#### Webhook signatures

```php
use OmniaGlobal\OmniaPackages\Verkada\WebhookSignature;

public function access(Request $request, WebhookSignature $signature)
{
    if (! $signature->verifyRequest($request)) {
        return response()->json(['message' => 'Invalid signature.'], 401);
    }
    // …
}
```

HMAC-SHA256 over the **raw** body against the `verkada-signature` header.

**It refuses everything when no secret is configured**, deliberately. An
unauthenticated endpoint that writes to a custody record, an attendance roll or
a door history is a worse failure than a webhook that does not work yet.

#### What the fake does, and why it differs per method

`LogVerkadaGateway` is not uniformly "return nothing". Each choice is
deliberate, because an empty result means different things on different
screens, and `LogVerkadaGatewayTest` pins them so a tidy-up cannot flatten
them:

| Method | Returns | Why |
|---|---|---|
| `listAccessEvents` | `[]` | An empty event list is honest — nothing happened. Inventing events trains developers to ignore the real ones |
| `listGroupUserIds` | `[]` | So a nightly reconcile finds "entitled but missing" and never "present but not entitled". Without a real org there is no drift to find |
| `listDoors` / `listCameras` / `listAccessGroups` | three demo rows | An empty door list makes a binding screen look broken. Every id is prefixed `demo_` so it can never be mistaken for a real one |
| `footageLink` / `thumbnailUrl` | placeholder URLs | `null` would make every evidence panel look like a camera outage, and outages are something you want to be able to *see* in testing |
| `ensureAccessUser` | a stable id derived from the email | The same person twice must not look like two people to a reconciler |
| `recentAccessEvents` | the host's mirror if one is registered, else demo rows | See below |
| `testConnection` | `ok: false` and an explanation | It says plainly that no key is set and no real door will open |

#### Serving your own mirrored history from the fake

A product that mirrors door events locally almost certainly wants the fake to
serve *that* rather than invented rows — without a Command organisation the
mirror is the only door data that exists, and a drill-down showing different
history from the dashboard beside it is worse than one showing none.

The package cannot read a host's model, so the host registers a resolver:

```php
// AppServiceProvider::boot()
LogVerkadaGateway::resolveRecentEventsUsing(
    fn (string $verkadaUserId, int $limit) => AccessEvent::query()
        ->where('verkada_user_id', $verkadaUserId)
        ->latest('occurred_at')
        ->limit($limit)
        ->get()
        ->map(fn (AccessEvent $e) => [
            'time' => $e->occurred_at->toIso8601String(),
            'door_name' => $e->door,
            'result' => $e->result,
        ])
        ->all(),
);
```

An empty result falls back to the demo rows, because a brand-new instance has
mirrored nothing yet and an empty panel reads as broken rather than as new.

#### `door_id` and `door_name`

`listAccessEvents()` returns both, because they are different things and both
are wanted: the **id** is what a product matches against its own record of
which door belongs to which cabinet, room or site, and the **name** is what it
shows a human. `door_name` falls back to the id when Verkada supplies no name,
so it is always displayable.

---

## Configuration

| Variable | Config key | Without it |
|---|---|---|
| `VERKADA_API_KEY` | `omnia.verkada.key` | `LogVerkadaGateway` binds; no door is ever touched |
| `VERKADA_BASE_URL` | `omnia.verkada.base_url` | `https://api.verkada.com` |
| `VERKADA_WEBHOOK_SECRET` | `omnia.verkada.webhook_secret` | **Every webhook is refused** |
| `VERKADA_HELIX_EVENT_TYPE_UID` | `omnia.verkada.helix_event_type_uid` | Helix push is skipped; nothing else changes |
| `VERKADA_TIMEOUT` | `omnia.verkada.timeout` | 15 seconds |
| `VERKADA_RETRIES` | `omnia.verkada.retries` | 2 |

These are the variable names all three products already use, so adopting the
package changes no `.env` file.

---

## Developing

```bash
composer install
./vendor/bin/phpunit      # 22 tests, 52 assertions
./vendor/bin/pint
```

Tests run on `orchestra/testbench` — no host application required.

---

## Known limitations

Kept honest rather than glossed over.

| | |
|---|---|
| **Every HTTP endpoint path is unverified against a live Verkada organisation.** They follow the public API documentation. Confirming them is the phase-0 spike; until then only `LogVerkadaGateway` has actually run. This is the single largest risk in the package | |
| `enrolPersonOfInterest()` expects a filesystem path to a photo. Nothing uploads one yet | |
| There is no rate-limit handling beyond the retry. Verkada's documented limits have not been exercised | |

---

## Roadmap

Modules to move here next, in order of how much duplication each removes:

1. **Integrations** — `IntegrationSetting`, encrypted credentials with env
   fallback, and the admin screen. Pulse and Campus have it; Vault does not.
2. **Message templates** — the model, the markdown render and the test send.
   Identical in Pulse and Campus; absent from Vault.
3. **Branding** — white-label name, logo and colour.
4. **Audit** — the append-only entry model Campus and Vault both grew.
5. **Address lookup** — the gateway plus Google, Nominatim and a fallback.

Deliberately **not** here, and not planned: a shared `User` model, role enum or
auth stack. The three products disagree about what a person is — Pulse has
members, Campus has guardians and students, Vault has endorsements and
entitlements — and that is the abstraction which looks cheapest on day one and
cannot be removed on day four hundred. Middleware and Fortify *actions* are
fair game; the model they act on is not.

---

## Licence

Proprietary — Omnia Global.
