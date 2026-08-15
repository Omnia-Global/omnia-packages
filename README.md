# Omnia Packages

Shared Laravel code for the Omnia Global products — **Pulse**, **Campus** and
**Vault**.

[![tests](https://img.shields.io/badge/tests-22%20passing-02BD6F)](#developing)
[![licence](https://img.shields.io/badge/licence-MIT-02BD6F)](LICENSE)
[![php](https://img.shields.io/badge/php-8.3%20%7C%208.4-777BB4)](composer.json)

The three products are one family: Laravel 13 + Inertia v3 + React 19 + Verkada,
one instance per customer. This package holds what all three need and none of
them should own.

It starts with the Verkada client, which existed as **three copies** — Pulse's
and Campus's byte-identical, Vault's a superset. A change to Verkada's API meant
the same fix applied by hand three times, and the endpoint paths in all three
are still unverified against a live organisation. One package, one fix.

---

## Contents

- [Installing](#installing) · [Why public](#why-this-repository-is-public)
- [Verkada](#verkada) — [the gateway](#the-gateway) · [webhooks](#webhook-signatures) · [the fake](#what-the-fake-does-and-why-it-differs-per-method) · [`door_id` vs `door_name`](#door_id-and-door_name)
- [Configuration](#configuration) · [Overriding the binding](#overriding-the-binding)
- [Who uses what](#who-uses-what) · [Developing](#developing)
- [Known limitations](#known-limitations) · [Roadmap](#roadmap) · [Versioning](#versioning)

---

## Installing

```bash
composer config repositories.omnia-packages vcs https://github.com/Omnia-Global/omnia-packages.git
composer require omniaglobal/omnia-packages
```

That is the whole setup. The service provider is auto-discovered and the config
is merged, so a product happy with the defaults sets environment variables and
nothing else.

To override any default:

```bash
php artisan vendor:publish --tag=omnia-config
```

### Why this repository is public

Because a private Composer package forces **every build server to carry a
GitHub credential just to install a Verkada API client**.

That is not hypothetical. Composer fetches a private package as a zipball from
the GitHub HTTPS API, which needs a `github-oauth` token in `auth.json` — and a
Forge deploy that clones the product repo perfectly well over SSH will still
fail with a bare `404` on the package, because Composer never uses SSH for a
dist download. The workarounds all exist (`no-api` plus a source install, a
per-repo deploy key, a token on every server) and every one of them is a thing
to configure, rotate and forget.

Public removes the class of problem instead of routing around it. The sibling
[`visns-packages`](https://github.com/Omnia-Global/visns-packages) has been
public all along, so this is also the house pattern.

**There is nothing here worth keeping shut**: a Verkada API client, a webhook
signature check, and a logging fake. No credentials, no customer data, no
infrastructure detail. The products that consume it — Pulse, Campus and Vault —
stay private, and that is where the business logic lives.

---

## Verkada

### The gateway

One interface, two implementations, bound by whether an API key exists.

```php
use OmniaGlobal\OmniaPackages\Verkada\VerkadaGateway;

class SyncCabinetAccess implements ShouldQueue
{
    public function handle(VerkadaGateway $verkada): void
    {
        $verkada->addUserToGroup($verkadaUserId, $groupId);
    }
}
```

**Nothing in a host application ever asks whether Verkada is configured.** That
question is answered once, in the service provider: with a key you get
`HttpVerkadaGateway`, without one you get `LogVerkadaGateway`, and the
application runs either way — the sync jobs, the nightly reconcile, the whole
product, on a laptop with no hardware on the desk.

| Area | Methods |
|---|---|
| **Access users & groups** | `ensureAccessUser` · `addUserToGroup` · `removeUserFromGroup` · `sendPassInvite` · `deactivateUser` · `listGroupUserIds` |
| **Discovery** | `listDoors` · `listCameras` · `listAccessGroups` · `testConnection` |
| **Doors** | `unlockDoor` |
| **Events** | `listAccessEvents` · `recentAccessEvents` |
| **Footage** | `footageLink` · `thumbnailUrl` |
| **Helix** | `createHelixEvent` |
| **Person of Interest** | `enrolPersonOfInterest` · `removePersonOfInterest` · `listPersonOfInterestIds` |

Nineteen methods, and the interface is the **union** of what the three products
need rather than the intersection. Pulse and Campus issue mobile passes; Vault
reads footage and writes Helix events. A product that never calls a method pays
nothing for its existence — the fakes make the unused half free — so splitting
this into narrower contracts would buy nothing and cost three places to change
when Verkada changes.

`unlockDoor()` deserves a note. It exists, and it should stay a narrow tool: a
remote release, an escorted audit. Standing access is *group membership*, synced
and reconciled, so a door keeps working when the application is down, mid-deploy
or on the wrong side of a severed link. A product that unlocks doors by API call
has put its own uptime between a person and a door.

### Webhook signatures

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

HMAC-SHA256 over the **raw** body against the `verkada-signature` header. The
raw body matters: decoding and re-encoding normalises whitespace, and the digest
then stops matching for a reason nobody can see in a log. `verifyRequest()`
reads `getContent()` and never the parsed array.

**It refuses everything when no secret is configured**, deliberately. An
unauthenticated endpoint that writes to a custody record, an attendance roll or
a door history is a worse failure than a webhook that does not work yet.

### What the fake does, and why it differs per method

`LogVerkadaGateway` is not uniformly "return nothing". Each choice is
deliberate, because an empty result means different things on different screens
— and `LogVerkadaGatewayTest` pins every one of them so a well-meaning tidy-up
cannot flatten them.

| Method | Returns | Why |
|---|---|---|
| `listAccessEvents` | `[]` | An empty event list is **honest** — nothing happened. Inventing events trains developers to ignore the real ones |
| `listGroupUserIds` | `[]` | So a nightly reconcile finds "entitled but missing" and never "present but not entitled". Without a real org there is no drift to find, and inventing some would teach people to ignore the reconciler's output |
| `listDoors` · `listCameras` · `listAccessGroups` | three demo rows each | An empty door list makes a *binding screen* look broken and gives nobody anything to click. Every id is prefixed `demo_` so it can never be mistaken for a real one |
| `footageLink` · `thumbnailUrl` | placeholder URLs | `null` would make every evidence panel look like a camera outage, and outages are something you want to be able to *see* in testing rather than have as the permanent background state |
| `ensureAccessUser` | a stable id derived from the email | The same person twice must not look like two people to a reconciler |
| `recentAccessEvents` | the host's mirror if one is registered, else demo rows | [See below](#serving-your-own-mirrored-history) |
| `testConnection` | `ok: false` and an explanation | It says plainly that no key is set and no real door will open |

#### Serving your own mirrored history

A product that mirrors door events locally almost certainly wants the fake to
serve *that* rather than invented rows — without a Command organisation the
mirror is the only door data that exists, and a drill-down disagreeing with the
dashboard beside it is worse than one showing nothing at all.

A package cannot read a host's model, so the host registers a resolver:

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

Pulse and Campus both register this. Vault does not — it reads its openings
directly.

### `door_id` and `door_name`

`listAccessEvents()` returns both, because they are genuinely different things
and both are wanted:

- **`door_id`** — what a product matches against its own record of which door
  belongs to which cabinet, room or site. Vault reads this.
- **`door_name`** — what it shows a human. Pulse and Campus read this, and store
  it in their `access_events.door` column.

`door_name` falls back to the id when Verkada supplies no name, so it is always
displayable and a caller never has to null-coalesce. Collapsing them into one
field is what the products did before, and it meant the identifier and the label
were the same string — fine until something needed to match on it.

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

These are the variable names all three products already used, so adopting the
package changed no `.env` file anywhere.

**Authentication.** The long-lived org key is exchanged for a short-lived token
(`POST /token` with `x-api-key`, then `x-verkada-auth` on everything else). The
token lasts 30 minutes; the client caches it for 25 under `omnia.verkada.token`
and lets it be re-minted rather than tracking expiry precisely. The cache key is
namespaced so two products sharing a cache store cannot collide.

### Overriding the binding

The package resolves its key from config. A product that stores credentials
somewhere richer can bind the gateway itself and the package will step aside:

```php
// Pulse and Campus both do this — the credential is one an administrator typed
// into the Integrations screen, encrypted at rest, with env as the fallback.
$this->app->scoped(VerkadaGateway::class, function () {
    $key = IntegrationSetting::resolve('verkada', 'api_key', 'omnia.verkada.key');

    return $key ? new HttpVerkadaGateway($key) : new LogVerkadaGateway;
});
```

That seam is temporary and named on purpose: `IntegrationSetting` is the next
module to move here, at which point both products drop their override.

---

## Who uses what

| | Pulse | Campus | Vault |
|---|---|---|---|
| Access users, groups, reconcile | ✔ | ✔ | ✔ |
| `sendPassInvite` — mobile credentials | ✔ | ✔ | — cards, not phones |
| `recentAccessEvents` — a person's history | ✔ | declared, unused | — |
| `listAccessEvents` reads | `door_name` | `door_name` | `door_id` |
| Discovery (`listDoors` …) | — | — | ✔ cabinet binding screen |
| Footage, Helix, Person of Interest | — | — | ✔ |
| `WebhookSignature` | — | — own Guest verifier | ✔ |
| Overrides the container binding | ✔ | ✔ | — uses the package's |

Campus's Guest webhook keeps its own signature check because it also accepts
`x-verkada-signature`, which this package does not, and changing that would
silently break a webhook already configured in Command. Unifying it is a
deliberate future change, not an oversight.

---

## Developing

```bash
composer install
./vendor/bin/phpunit      # 22 tests, 52 assertions
./vendor/bin/pint
```

Tests run on `orchestra/testbench` — no host application required, no database,
no credentials.

The suite is small and every test is there for a reason. The ones worth knowing:

| File | What it defends |
|---|---|
| `GatewayBindingTest` | The package's whole promise — ask for `VerkadaGateway`, never ask whether Verkada is configured. Including that an **empty-string** key counts as no key, since a half-filled `.env` is the common case |
| `WebhookSignatureTest` | That an unsigned, wrongly-signed or unconfigured webhook is refused — and that the digest is over the raw body, not re-encoded JSON |
| `LogVerkadaGatewayTest` | Every per-method choice in the table above, so the fake's deliberate asymmetry survives a tidy-up |

### Working on the package and a product together

Point the product at your working copy instead of the published repository:

```jsonc
// the product's composer.json
"repositories": [
  { "type": "path", "url": "../omnia-packages", "options": { "symlink": true } }
],
"require": { "omniaglobal/omnia-packages": "@dev" }
```

Swap back to the `vcs` entry and a tagged version before committing — a path
repository does not resolve on a build server.

---

## Known limitations

Kept honest rather than glossed over.

| | |
|---|---|
| **Every HTTP endpoint path is unverified against a live Verkada organisation.** They follow the public API documentation and have never run against a real org. Only `LogVerkadaGateway` has actually executed. This is the single largest risk here — and the reason it is worth having in one place, because the phase-0 spike now fixes it once for all three products rather than three times |
| `enrolPersonOfInterest()` expects a filesystem path to a photo. Nothing uploads one yet |
| No rate-limit handling beyond the retry. Verkada's documented limits have not been exercised |
| `recentAccessEvents()` on the HTTP client returns `[]` rather than throwing when Verkada refuses the filter, so a screen asking for a person's history degrades to empty rather than erroring. Whether that is right depends on the screen |

---

## Roadmap

Modules to move here next, ordered by how much duplication each removes. Each is
independently useful; stopping after any one still leaves the family better off.

| | Module | Pulse | Campus | Vault |
|---|---|---|---|---|
| 1 | **Integrations** — `IntegrationSetting`, encrypted credentials with env fallback, and the admin screen | has it | has it | **missing** |
| 2 | **Message templates** — model, markdown render, test send | has it | has it | **missing** |
| 3 | **Branding** — white-label name, logo, colour | has it | has it | **missing** |
| 4 | **Audit** — the append-only entry model | — | has it | has it |
| 5 | **Address lookup** — gateway plus Google, Nominatim, fallback | has it | has it | n/a |

Module 1 is the natural next step: it removes both products' container override
*and* gives Vault an encrypted credential store it currently lacks.

A React sibling, `omnia-components`, is planned for the app shell, the auth
screens and the admin pages that pair with these modules. It should be
**private**, matching `visns-components` — and it should hold the composed
pieces only, because the shadcn primitives are meant to be copied per repo.

### Deliberately not here, and not planned

A shared `User` model, role enum or auth stack. The three products disagree
about what a person *is* — Pulse has members, Campus has guardians and students,
Vault has endorsements and entitlements — and that is the abstraction which
looks cheapest on day one and cannot be removed on day four hundred. Middleware
and Fortify *actions* are fair game; the model they act on is not.

---

## Versioning

Semantic versioning, currently `0.x`. The major stays at zero until the endpoint
paths have been confirmed against a live Verkada organisation — that spike is
what earns a `1.0`, not the passage of time.

| Version | What changed |
|---|---|
| `0.2.0` | `sendPassInvite`, `recentAccessEvents`, `door_name` alongside `door_id`, and the resolver hook — all surfaced by adopting the package in Pulse and Campus |
| `0.1.0` | First release: the gateway, both implementations, `WebhookSignature` |

Adding a method to `VerkadaGateway` is a **breaking change for any host that
implements the interface by hand**. Products should extend `LogVerkadaGateway`
in their test doubles rather than implementing the interface — Pulse's fakes did
the latter and broke the moment the interface grew, which is exactly the
argument for the former.

---

## Licence

MIT — see [LICENSE](LICENSE). The products that consume it stay private.
