# For judges

A short path through Intakt: what it gives you, how to try it with or without a key, what was measured, and where to look next.

## What Intakt gives you

Your coding agent says it is done; Intakt tells you whether to believe it. A panel of reviewers on models from different makers reviews the change, and a judge marks every finding against the task. Your own agent runs this loop over MCP until the verdict is clean ([MCP details](user/mcp.md)).

## Try it

```
git clone https://github.com/shinyredapples/intakt.git && cd intakt
docker compose up -d --wait
```

- No key: open http://localhost:7781/demo and press **Play the demo run**. It plays the shipped recording of a real review of the sample change onto the dashboard without calling any model ([details](user/README.md#playing-the-demo-run-in-the-dashboard)).
- With one provider key or a Hugging Face token: open http://localhost:7781 and save it on the first-run page, run `docker compose restart intakt` so the sample project gets its reviewers, reload the setup page, then press **Start the first review**, or run `docker compose exec intakt php bin/console intakt:demo` for the verdict in the terminal ([reading a verdict](user/README.md#reading-a-verdict)).

## What was measured

- [Review bench](../README.md#review-bench), run on 2026-11-16: the panel found 11 of 15 planted defects with 2 false alarms, about 6,800 tokens per change (measured). How the bench works: [the review bench in plain words](guide/review-bench.md).
- [Timed run](../README.md#timed-run), 2026-11-17, ten runs: warm start median 13.5 s, demo review median 42 s (measured).

## Found and fixed upstream

Running the Symfony AI beta and Symfony 8.2 beta turned up bugs that Intakt reproduced, and fixed or reported upstream: [the table in the README](../README.md#found-and-fixed-upstream), and every reproduction with its report in [`upstream/`](../upstream/README.md).

## Where to look next

- [The review flow](architecture/review-flow.md): the loop inside, drawn.
- [Symfony in Intakt](guide/symfony-in-intakt.md): the Symfony and Symfony AI parts it uses.
- [Status](../README.md#status): what works today and what is planned.
- [Showcase](showcase/README.md) and [Journey](journey.md): this repository built by an AI loop, in figures.
