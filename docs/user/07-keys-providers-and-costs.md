# 7. Keys, providers and costs

*Chapter 7 of 9 in [the Intakt user guide](README.md).*

## Whose models, and whose bill

Your first live review ran on one key. Now you want the real setup: two labs on every change, and a cost figure you can show your manager. This chapter covers where keys go, how a project picks its models, and how Intakt counts the money.

## The providers Intakt knows

| Provider | What it is | What it needs |
|---|---|---|
| `anthropic` | Claude through the API | `ANTHROPIC_API_KEY` |
| `openai` | GPT through the API | `OPENAI_API_KEY` |
| `openai_compatible` | any endpoint that speaks the OpenAI API, Hugging Face among them | `OPENAI_COMPATIBLE_BASE_URL` and `OPENAI_COMPATIBLE_API_KEY` |
| `claude_code` | a reviewer or the judge on a Claude subscription | `CLAUDE_CODE_OAUTH_TOKEN` |
| `codex` | a reviewer on a Codex subscription | a login file, see the reference |

A provider without its key is switched off, and Intakt still starts. Step 3 of the setup wizard, **Add an AI account**, lists each one, for example: Provider "openai" is disabled: set OPENAI_API_KEY to enable it.

![Step 3 of the setup wizard, "Add an AI account", on an install with no key: a field to paste a key, then four lines saying the anthropic, openai, openai_compatible and claude_code providers are disabled and which variable enables each, and the Back, Skip and Keep the key buttons](../assets/guide/add-a-key.webp)

## Where a key goes

Keys never go into a committed file. Put them in one of these places:

1. **The setup wizard,** for your first key: paste it on step 3, **Add an AI account**. It is checked with the provider before it is kept.
2. **`.env.local`,** for everything else: copy `.env.dist` to `.env.local` and fill in the variables above.
3. **Symfony secrets,** for example `php bin/console secrets:set ANTHROPIC_API_KEY`.

A key in `.env.local` or in the secrets always wins over one kept from the setup wizard. After you add or change a key, restart Intakt so it reads it.

## Two labs, one Hugging Face token

A Hugging Face token (`HF_TOKEN`) is the cheapest way to get two labs. In the container, Intakt hands it to `openai_compatible` with the Hugging Face router, unless you set that provider's own variables. The demo then runs `correctness` on Kimi K3.1 Pro and `security` on DeepSeek V4 Pro.

Two separate providers are the stronger setup, though. One token is one outage, one rate limit and one bill. With `HF_TOKEN` and an Anthropic key, correctness stays on Anthropic and security moves to Kimi.

## Tiers: how a project picks its models

A project never names a model per reviewer. It maps three tiers to a provider and a model each:

- **strong,** usually your most capable model,
- **standard,** a mid-range model,
- **light,** a small model; the owl runs on it.

Each reviewer names a tier. So one changed line moves every reviewer on that tier to a newer model. Use the newest model of each maker for reviews; the example configuration names the ones the project recommends today. The tiers live in the project file ([chapter 9](09-running-it-for-a-team.md#your-project-and-its-panel) says which file that is).

## When a provider says "slow down"

When a provider limits a call, the account that made it cools down until the reset time the provider sent, at most one day. The reviewer moves on at once to the next account of the same provider, if you have one. A tier can also list substitutes from other providers, used only when its own provider cannot answer.

## Reading the cost

Every review page shows the tokens and the cost of each round. The cost is an estimate: Intakt multiplies the tokens by the prices you set, in US dollars per million tokens.

1. Open the project file ([chapter 9](09-running-it-for-a-team.md#your-project-and-its-panel)).
2. Under `budget.prices`, add each model with its `input` and `output` price.
3. Optionally set `budget.price_date`, the day you read the prices, in quotes. If you read them on different days, use the earliest.
4. Run `php bin/console cache:clear`.

The tokens table splits the prompt's cache tokens. **Cache read** and **Cache write** are the ones the provider named as such; **Cached** is the rest it did not tell apart, so a round can show a cache read and a cached figure side by side. Optional `cache_read` and `cache_write` prices apply to the first two, and the cached figure costs the `input` price.

The page then shows a figure, an **estimated** label and the price date. A model without a price makes the cost read "unknown, no price for" that model, never a partial sum. This holds for a call that has not recorded its end yet (still running, or cut off) too: if its model has no price, the round's cost is unknown; if the model has a price, such a call adds nothing. The demo brings its own list prices, dated 2026-11-13, the earliest day any of them was read.

## Limits for a long build

A build that keeps making progress is worth its tokens, so spend alone will not end one. Four limits will decide when a build without progress stops. Intakt does not watch a running build yet, so today they only have to be valid.

1. Open `config/services.yaml`.
2. Under `parameters:`, set any of `intakt.build.allowance_tokens`, `intakt.build.idle_seconds`, `intakt.build.ceiling_tokens` and `intakt.build.task_total_tokens`: whole numbers of at least 1, the ceiling not below the allowance.
3. Run `php bin/console cache:clear`.

A stopped build will keep its commits. The [complete reference](../reference/user-manual.md#running-a-build) has the defaults.

## Running on a subscription

A reviewer, or the owl, can run on a Claude subscription through the `claude` command-line tool. Create the token with `claude setup-token` and store it as `CLAUDE_CODE_OAUTH_TOKEN`. A Codex subscription works for reviewers too.

Which plans may be used this way is the vendor's decision, not Intakt's. Read the vendor's terms for your case, and use an API key when in doubt.

The [complete reference](../reference/user-manual.md#configuring-providers-and-api-keys) has every variable, several accounts per provider, substitutes and subscription limits.

Next: [When something goes wrong](08-when-something-goes-wrong.md)
