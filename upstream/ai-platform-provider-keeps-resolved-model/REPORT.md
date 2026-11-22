# [Platform] A provider in a long-running worker sends the options of an earlier request's model

Draft, not posted. Versions: `symfony/ai-platform` v1.0.0-BETA1, `symfony/event-dispatcher` v8.1, PHP 8.5.12.

## What happens

`Provider` keeps the model that `supports()` resolved in `$resolvedModel`, so `invoke()` does not ask the catalog again. `invoke()` reuses it when `$resolvedModel->getName()` equals the requested name and clears it only then. For a name with options (`gpt?temperature=0.9`) the model's name is `gpt`, so the comparison fails, the catalog resolves the model again, and the cached model stays. `Provider` does not implement `ResetInterface`, so in a FrankenPHP worker or `messenger:consume` the platform service keeps it for the next request. A later `invoke('gpt')` that skips `supports()` (a `ModelRoutingEvent` listener that sets the provider, or a router that picks one without asking it) then gets the earlier request's model with its options. `reproduce.php` runs both requests through one `Platform` and prints:

```
options sent for request 1 ("gpt?temperature=0.9"): {"temperature":0.9}
options sent for request 2 ("gpt"): {"temperature":0.9}
Request 2 was sent with the temperature of request 1
```

## Expected

Request 2 is sent with the options of `gpt` alone (`[]`): a model resolved for one call never serves another.

## Effect in Intakt

None reachable: Intakt's platforms route with the default `CatalogBasedModelRouter`, which calls `supports()` right before `invoke()` on the same provider, and no listener sets the provider. Intakt's reset probe (`tests/Application/SymfonyAiResetTest.php`) lists `Provider::$resolvedModel` as the state a request leaves behind after the kernel's reset; that list changes when Intakt moves to a release with the fix below.

## Upstream status

Checked on 2026-11-06.

- **Pinned versions:** still fails. `php bin/console intakt:upstream:run` at `symfony/ai-platform` v1.0.0-BETA1: `still fails  ai-platform-provider-keeps-resolved-model`.
- **Development branch:** passes. `reproduce.php` in a scratch project with `symfony/ai-platform` at `dev-main` (58491454574c): `options sent for request 2 ("gpt"): []`, `REPRODUCTION PASSES`. `main` keeps the requested name beside the model (`$resolvedModelName`) and reuses the model only for that exact name, which clears it after every routed call.
- **Existing issue or pull request:** symfony/ai#2851 (`[Platform][Ollama] Send the options of a model name as options, not as part of the name`, merged 2026-10-27, after v1.0.0-BETA1 of 2026-10-26) brought that change, for another reason. No release holds it yet. `main` still keeps a model that `supports()` resolved when no `invoke()` follows; the next routed call replaces it, and only a call for the same name reuses it, so it carries no other request's options.
- **Searches** (`symfony/ai` issues and pull requests, open and closed): `resolvedModel`, `Provider ResetInterface`; the history of `src/platform/src/Provider.php` on `main`.
- **Decision:** `fixed upstream`. Nothing to file; the next `intakt:upstream:run` on a release after v1.0.0-BETA1 records the release that passes. Checked 2026-11-22: no `symfony/ai` release after v1.0.0-BETA1 yet (the newest tag is still v1.0.0-BETA1).
