# Measured: the timed run and the review bench

The figures the [README](../README.md#measured) sums up, with how each was measured. The dashboard's **Trends** page reads its bench headline from the review bench table below (see [the Trends page](user/dashboard.md#the-trends-page)), so the table keeps its shape: the `Defects found` and `False alarms` columns, a `Panel` row, one `Date of the run:` line and one `Each change ran N times.` sentence.

## Timed run

Measured on 2026-11-17 on an older desktop with 4 cores (8 threads), 16 GB of memory, Ubuntu Linux and Docker 29: ten consecutive runs of the five-minute path, each from a fresh `git archive` of main, with a Hugging Face token in `.env.local` and the terminal path `docker compose exec intakt php bin/console intakt:demo`.

| Measure | Median | Range |
|---|---|---|
| Warm start, `docker compose up -d --wait` (image already built) | 13.5 s | 10 to 22 s |
| Demo review, `intakt:demo` to verdict | 42 s | 13 to 101 s |

Both reviewers answered in all ten runs, and all ten ended with the verdict `changes` on the sample change. A cold start, with the image removed and Docker's build cache kept, took a 42 s build and a 30 s start (72 s), then a 44 s review.

## Review bench

The review bench reviews eight small changes of the sample shop, five with one planted defect each and three without any, and counts what each reviewer caught, missed and raised in error. **It calls your paid models**, so it runs only when you ask, never in `bin/check` or CI. With a running install, this gave the results below:

```
docker compose exec intakt php bin/console intakt:bench --project=sample --repeat=3
```

| Reviewer | Defects found (measured) | False alarms (measured) | Tokens per change (measured) |
| --- | --- | --- | --- |
| Panel on Claude and GPT (`correctness` on Claude `opus`, `security` on GPT `gpt-6.2-sol`), 2026-11-21 | 15 of 15 | 3 | about 75,600 |
| Claude `opus` alone | 12 of 15 | 0 | about 13,250 |
| GPT `gpt-6.2-sol` alone | 15 of 15 | 3 | about 62,400 |
| Open-model panel (`correctness` and `security`, Hugging Face), 2026-11-16 | 11 of 15 | 2 | about 6,800 |
| `correctness` alone (open model) | 10 of 15 (1 harness failure left out, on a change without a defect) | 2 | about 2,460 |
| `security` alone (open model) | 8 of 15 | 0 | about 4,350 |

Each change ran 3 times. In the open-model run seven of the eight changes varied between the three runs (in the Claude and GPT run none did): a single run's result for one change is partly luck, so your own run will not match these figures exactly.

Date of the run: 2026-11-21. Claude and GPT run: subscription CLIs (`claude_code` model `opus`, `codex` model `gpt-6.2-sol` with effort high, judge on Claude `haiku`), 24 rounds, all three runs agreeing on every change; the panel's figures are the union of its two reviewers' findings, and all three false alarms came from the GPT reviewer. Open-model run: 2026-11-16. Models (the demo's Hugging Face mapping): `correctness` (tier strong) `moonshotai/Kimi-K3:deepinfra`, `security` (tier standard) `openai/gpt-oss-120b:novita`. What a run costs and how to read these figures: [the review bench](user/README.md#the-review-bench) in the user guide.
