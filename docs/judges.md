# For judges: Intakt in a few minutes

Your coding agent says it is done. Intakt tells you whether to believe it: a panel of reviewers on models from different makers reviews the change, and a judge marks every finding against the task, so every P1 and P2 finding whose quote checks out reaches the act list.

## a. No key, about 2 minutes

1. `git clone https://github.com/shinyredapples/intakt.git && cd intakt`
2. `docker compose up -d --wait`: it returns once the container is healthy.
3. Open http://localhost:7781. You see **Set up Intakt** with two choices: "Watch a recorded real review now" and "Paste one provider key or Hugging Face token and run the demo review".
4. Open http://localhost:7781/demo and press **Play the demo run**. You see "The demo run is starting: the caption at the bottom of each page names the stage it is in." The caption counts "Stage 1 of 7" up to "Stage 5 of 7: The round is judged: the act list names the findings to fix now."
5. Press **Open the review** in the caption. The review of `sample-change` in `demo-replay` carries the **Replay** mark. Round 1 reads "Changes requested": `correctness` asked for changes with 4 findings, `security` approved. Under "Findings and the judge's call", F1 (P2, `src/discount.js:3`), F2 (P1, `src/report.js:4`) and F4 (P2) read **Fix now**; F3 reads "Dropped before the judge: none of its 2 quoted code fragments is in the change".

## b. One Hugging Face token or provider key, about 5 minutes

1. On http://localhost:7781, paste the key or token in step 1 ("A key or token for Anthropic, OpenAI or Hugging Face") and press **Keep the key**. Calls are billed to the key's owner.
2. `docker compose restart intakt`, so the sample project gets its reviewers. Wait until `docker compose ps` shows the container as healthy.
3. Reload http://localhost:7781: step 2 now reads "1 project is configured." and lists the `sample` project. Press **Start the first review** in step 3. Its live review page opens: each reviewer's row fills in as it answers, then the round shows its outcome (`approve`, `changes` or `blocked`) and "Findings and the judge's call" lists the act list, each entry marked **Fix now**.

From the terminal, `docker compose exec intakt php bin/console intakt:demo` runs the same review and prints the verdict, each reviewer and the act list.

## c. With your agent over MCP

This path runs a live review, so first do steps 1 and 2 of path b (keep a key, restart, wait until healthy): without them the `sample` project has no reviewers.

1. Get the token: `docker compose exec intakt php bin/console intakt:sample:show /app/var/data/sample-repository` prints `Connect an MCP client: claude mcp add ... --header "Authorization: Bearer <token>"`. Step 4 of the first-run page ("Your own agent") shows the address, `http://localhost:7781/mcp/owner`.
2. Run the printed `claude mcp add` line in the clone, then start `claude` there.
3. Install the skill: copy `skills/intakt-review` to `~/.claude/skills/intakt-review` ([details](../skills/intakt-review/README.md)).
4. Say "intakt review the demo". The agent starts the review with `start_review`, fixes only what the act list names inside the container, and reports the verdict, the rounds used and why it stopped.

## Where to look in the code

| Promise | Enforced by | Test |
|---|---|---|
| Finding ids are kept across rounds | `src/Core/Review/Domain/FindingLedger.php` | `tests/Core/Review/Domain/FindingLedgerTest.php`: `testFindingIdsStayStableOverThreeRounds`; `tests/Core/Review/UseCase/RunReviewRound/FindingsAcrossRoundsTest.php`: `testIdsStayStableOverThreeRoundsAndANewFindingGetsTheNextId` |
| P1 and P2 findings whose quote checks out reach the act list | `src/Core/Review/Contract/JudgedFindings.php`, `src/Core/Review/Contract/Judgement.php` | `tests/Core/Review/UseCase/JudgeRound/JudgeRoundTest.php`: `testAJudgeThatRejectsAP1AndDefersAP2LeavesBothOnTheActList`, `testAnInvalidJudgeAnswerLeavesEveryBlockingFindingOnTheActListAndMakesNoFollowUp` |
| Figure kinds (measured, counted, estimated) are never added together | `src/Core/Platform/Contract/Figures.php` (throws `MixedFigureKinds`) | `tests/Core/Platform/Contract/FiguresTest.php`: `testATotalOverMeasuredAndEstimatedFiguresIsRefused` |
| A finding that quotes code absent from the change is dropped before the judge | `src/Core/Review/Domain/QuotesInChange.php`, called by `src/Core/Review/UseCase/JudgeRound/JudgeRoundHandler.php` | `tests/Core/Review/Domain/QuotesInChangeTest.php`: `testAFindingQuotingANameAbsentFromTheChangeIsDropped`, `testAFindingWhoseQuoteIsInTheChangeIsKept` |

## Symfony AI and MCP pieces used

- **Platform**: every model call, through `src/Plugin/SymfonyAiVendor/`.
- **Agent**: the reviewers and their tool loop (`src/Core/Review/Infrastructure/ReviewerRuns.php`).
- **Store**: project memory (`src/Core/Memory/Infrastructure/StoreProjectMemory.php`).
- **AI Bundle**: configures the provider platforms (`config/packages/ai.php`).
- **MCP Bundle**: two servers. The owner server at `/mcp/owner` has five tools (`list_projects`, `review_status`, `review_verdicts`, `review_act_list`, `start_review`), one resource template (`intakt://rounds/{roundId}/findings`) and progress notifications while `start_review` waits. The review server at `/mcp/review` gives reviewers `read_file`, `list_directory` and `search_files`. Neither has prompts.

Every Symfony and Symfony AI feature in use, with its status: [Highlights](https://shinyredapples.github.io/intakt/).

## More

- [The review bench](../README.md#review-bench): how well the panel finds planted defects, measured.
- [Upstream reproductions](architecture/upstream-reproductions.md): library bugs found while building Intakt.
- [Connecting an agent through MCP](user/mcp.md).
