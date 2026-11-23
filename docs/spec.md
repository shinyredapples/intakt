# Spec: the review loop (first release)

Status: draft for the owner's approval. Deadline: 2026-11-22 18:00 CET, the Symfony AI & MCP Hackathon.

## What it does

Intakt reviews a pull request with a panel of AI reviewers and tells the builder, human or agent, exactly what to fix.

1. **Take a pull request.** From GitHub, or from a local git repository where a branch against its base stands in for a pull request (the demo needs no forge account).
2. **Run a configurable panel.** Any number of reviewers, each a role: a provider and a model tier through Symfony AI's Platform, a prompt, read-only tools on the exact head, and a budget. A quorum policy (all, majority, or one strong reviewer plus one other) decides the outcome. Reviewers run in parallel.
3. **Return structured verdicts.** Every reviewer must return the verdict of the design as a typed object through structured output ([Reviews](design/05-architecture/07-reviews.md)): `approve`, `changes` or `blocked`, with findings (`id`, `severity`, `file`, `line`, `symbol`, `text`, `fixable_by`, `repeat`, `requirement`, `evidence`), `resolved` and `follow_ups`. Reviewers are asked for `approve` or `changes`; a reviewer's `blocked` that names a finding is recorded as `changes`, an answer that names a finding id reserved for Intakt's failed reviews is a failed review, and `blocked` is recorded only when Intakt itself holds a review back (a reviewer without tools, a stopped reviewer, a cut stream, a refused approval). An output that does not map to the verdict object, or fails its validation, is a failed review: it never approves and uses no review cycle.
4. **Keep finding ids across rounds.** A later round sees the open findings, marks fixed ones `resolved` and a returning one `repeat`. From round two on, a new blocking finding must be a regression or cite a requirement.
5. **Judge.** One cheap call marks each finding act, defer or reject against the task's acceptance criteria and non-goals. Only a P3 finding can be deferred or rejected: a P1 or P2 stays on the act list whatever its mark, with the judge's reason kept beside it as a note. A finding marked as a gate gap never reaches the act list: marked act or defer it is a follow-up, marked reject it is dropped. The builder sees only what to act on.
6. **Serve agents over MCP.** An MCP server lets an agent start a review, follow it and read verdicts and the findings to act on. Reviewers themselves reach their repository tools through an MCP client.
7. **Show it live.** A small dashboard shows each round: reviewers, their progress as it streams, verdicts, findings with ids, the judge's call, tokens and cost.
8. **Run in five minutes.** `docker compose up` starts Intakt, its SQLite database and a sample repository with a pull request waiting for review. The juror adds one or more provider API keys and starts a review. A live demo review needs one provider key or one Hugging Face token, and its calls are billed to its owner; without a key, `/demo` plays the recorded real review the image ships, with no model call. A juror without an Anthropic or OpenAI key sets `HF_TOKEN`, a fine-grained Hugging Face token with only "Make calls to Inference Providers", which reaches Hugging Face's router through the existing `openai_compatible` provider: alone it puts `correctness` on `moonshotai/Kimi-K3.1-Pro:deepinfra` and `security` on `deepseek-ai/DeepSeek-V4-Pro-0813:deepinfra`; beside an Anthropic key, `correctness` stays on Anthropic and `security` runs on `moonshotai/Kimi-K3.1-Pro:deepinfra`. Each seeded model names its router provider, because the router's default provider may refuse tools together with structured output, and the base URL is `https://router.huggingface.co` without `/v1`, because the bridge appends it.

Reviewers from different AI labs means models trained by different companies (an AI lab is the company that trains the model; a provider is the API Intakt calls). Two AI labs through one Hugging Face token satisfy it; two separate providers (for example Anthropic and Hugging Face) are the stronger setup, because one token is one outage, one rate limit and one bill.

## For whom

- **Hackathon jurors:** clone, add an API key, `docker compose up`, review the sample pull request, read the verdicts and the judge's call, connect an MCP client. All within five minutes.
- **Developers using AI agents:** a review gate their agent can call over MCP, with findings it can act on, that does not move the goalposts between rounds.
- **Intakt itself:** the review loop is the first stage of the full loop in the design; nothing in it is throwaway.

## Symfony AI, used fully

| Component | Use |
|---|---|
| Platform | every model call; several providers at once; structured output for verdicts and judge calls; streaming for live progress; token usage for cost; rate-limit and error handling |
| Agent | one agent per reviewer role and for the judge, with read-only repository tools, tool effect hints and a message store per round |
| Store | project memory: earlier findings and project docs retrieved into each reviewer's briefing |
| AI Bundle | providers configured in PHP (`config/packages/ai.php`); agents, tiers and the panel configured in YAML |
| MCP Bundle and PHP MCP SDK | the MCP server for agents, and the client through which reviewers use their repository tools |
| Mate | development diagnostics on the running app |

Likely library bugs found on the way become reproductions in `upstream/`, not workarounds hidden in the code.

## Out of scope for this release

Building changes, merging, the budget planner beyond recording cost, subscriptions and vendor CLIs, the maintainer agent, job containers per review, notifiers, Gitea, the setup wizard. The plugin contracts stay small and grow with their first real user.

## Acceptance at the deadline

1. On a clean machine with Docker, `git clone`, adding one API key to `.env.local` and `docker compose up` gives a running dashboard with the sample pull request in under five minutes, measured and recorded on [the bench page](bench.md#timed-run) (the measured runs start from a fresh `git archive` of main; the step-for-step fresh-clone run is D3 of #2164).
2. A review of the sample pull request with a two-reviewer panel on models of two different AI labs produces schema-valid verdicts, a judge result and a cost line, visible live on the dashboard.
3. A second round on a fixed head keeps the finding ids, marks fixed findings resolved and refuses unanchored new blocking findings.
4. An MCP client (for example an agent CLI) connected to Intakt's MCP server can start that review and read the findings to act on.
5. The same review works on a real GitHub pull request with a token in `.env.local`.
6. The panel, the tiers and the providers are changed in config alone, with no code change, and the change shows in the next review.
7. `bin/check` and CI are green on main; every user-visible feature has its `docs/user/` and `docs/architecture/` page.
8. The repository holds no secret, and stays private until the owner approves publishing.
