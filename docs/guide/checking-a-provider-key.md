# Checking a provider key

Intakt needs a key from an AI company (a provider) before its reviewers can work. On a new install you can paste one on the **See a real review** page (`/setup`, which the board's **Set up Intakt** button opens) instead of editing a file, and Intakt checks it before it keeps it.

## Where the field is

The first step of the setup page, the AI providers, has a field "A key or token for" followed by the providers Intakt knows, and a **Keep the key** button. The field is there only while no key is set: once a key is in `.env.local`, in the secrets vault, or kept from this page, the step is Done and the field goes away. The field hides what you type.

## What the check does

When you press **Keep the key**, Intakt first works out from the key's shape which provider it belongs to. Then it asks that provider one tiny question ("Answer with the single word: ok") with the key, using the cheapest model your settings already use on that provider (the `light` tier of the first project that has one there). On a fresh install, with no project yet, it uses the cheapest model a new project would get. The question is always the same, so nothing of yours is sent with it. Only a key the provider accepts is kept; any other key never reaches the disk.

## What the page tells you

The page comes back with one line:

| The line says | What it means | What to do |
|---|---|---|
| The key ending in its last characters is kept | the provider accepted it | restart Intakt, so it starts using the key |
| The key has the form of no listed provider | it does not look like a key of any provider Intakt knows; the line lists them | copy the whole key again, from the provider's page |
| The provider refused the key | the provider said no to it | check that it is the right key, still valid and not revoked |
| The provider could not be asked to check the key | the check got no usable answer (no connection, or the provider was busy) | try again later |
| The provider accepted the key, but it could not be written to the data folder | the key was good, but Intakt could not save it, so it is not kept | check that the data folder can be written to, then press **Keep the key** again |
| The kept provider key cannot be read (followed by "Fix or remove the kept key, or set the key in .env.local.") | a key kept from this page earlier is in the data folder, but Intakt cannot read it | fix or remove the kept key, or set the key in `.env.local` |
| A provider key is already configured, so no key was kept | a key was set meanwhile, or an account of any provider is already enabled, so the page keeps nothing | to change the key, edit `.env.local` |

The key itself is never shown again and never written to a log: the page names only its provider and at most its last four characters. A key in `.env.local` or in the secrets vault always wins over one kept from this page. The [user guide](../reference/user-manual.md#running-the-demo-on-a-hugging-face-token) has the technical details, such as where the kept key is stored.
