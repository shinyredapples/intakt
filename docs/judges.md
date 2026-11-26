# For judges: Intakt in a few minutes

The tested submission is release [v0.4.1](https://github.com/shinyredapples/intakt/releases/tag/v0.4.1), tested on path a (the keyless demo) and path b (a run with a key); step 1 below clones it. Main is welcome too, with what we built after the deadline, but it is not the tested submission.

Your coding agent says it is done. Intakt tells you whether to believe it: a panel of reviewers on models from different AI labs reviews the change, every finding must quote the code it is about, and a judge makes one list to fix.

Watch the 90-second demo: [https://youtu.be/-8v0nflAQBk](https://youtu.be/-8v0nflAQBk). Its script and shot list: [docs/video.md](video.md)

## Why this needed Symfony AI and MCP

- **Platform**: one interface over the Anthropic, OpenAI and OpenAI-compatible providers, so one panel mixes models from different AI labs.
- **Structured output**: the schema of a verdict or judge result goes out with the request where the provider supports structured output, and Intakt maps and validates every reviewer verdict and judge result after the call, also the JSON a CLI reviewer returns. **Agent**: the reviewers' tool loop. **Store**: project memory.
- **Messenger**: each reviewer's run and the judging of a round go to a worker, so a round runs in the background.
- **MCP Bundle**: your own agent starts reviews and reads the act list and findings as tools, then fixes the code in its own workspace.

## a. No key, about 2 minutes

1. `git clone --branch v0.4.1 https://github.com/shinyredapples/intakt.git && cd intakt`
2. `docker compose up -d --wait`: it returns once the container is healthy.
3. Open http://localhost:7781. You see the first-run page, titled **See a real review**, with "1 minute · no key" and **Play the demo run** as its one main button. Below it are three steps (Read, Check, To fix) and **Use your own project** with three cards: "1. Add a key here", "2. Add a project" and "3. First review".
4. Press **Play the demo run** (http://localhost:7781/demo has the same button). The demo page opens. You see "The demo run is starting: the caption at the bottom of each page names the stage it is in." The caption shows one progress dot per stage (five in all) and a short line; the last one reads "Checked: To fix lists what still needs changing." As soon as the first stage shows, the page takes you to the review, and eight seconds after the last stage the caption closes by itself.
5. The review of `sample-change` in `demo-replay` carries the **Replay** mark. The owl's verdict card reads **Changes needed**, with the number to fix, and below it the findings stand in three groups (Urgent, Should fix, Nice to have), one row each with its first sentence, file and line. The reviewers ran on models from two AI labs, Claude (`opus`) and GPT (`gpt-6.2-sol`). Press **Full record**: round 1 reads "Changes requested": `correctness` (`opus`) and `security` (`gpt-6.2-sol`) each asked for changes with 4 findings. Under "Findings and the judge's call", F1 (P1, `src/discount.js:3`, also reported as F6), F2 (P1, `src/report.js:4`, also reported as F5, F7), F3 (P2), F4 (P2) and F8 (P3) read **Fix now**.

## b. One Hugging Face token or provider key, about 5 minutes

First clone and start Intakt as in steps 1 and 2 of path a.

1. Open http://localhost:7781/setup, the first-run page (once path a has played, http://localhost:7781 opens the board instead in a desktop browser, and a phone opens the newest review, since the board is then at rest; `/?board=1` is the board on any device). Paste the key or token in card 1 ("A key or token for Anthropic, OpenAI or Hugging Face") and press **Keep the key**. Intakt asks the provider once; the page then reads "The ... key ending in ... is kept. Restart Intakt to use it." Calls are billed to the key's owner.
2. Restart as the page says: `docker compose restart intakt`, so the sample project gets its reviewers. Wait until `docker compose ps` shows the container as healthy.
3. Reload http://localhost:7781/setup: card 2 now reads "1 project is configured." and lists the `sample` project. Then start the live review:
   - **If you skipped path a:** press **Start the first review** in card 3. Its live review page opens: each reviewer's row fills in as it answers, then the owl's verdict card gives the outcome and the groups below it list what to fix.
   - **If you played path a:** card 3 shows **Open the review** for the recorded run and no start button. Run `docker compose exec intakt php bin/console intakt:demo` instead. It runs the same live review and prints the verdict, each reviewer, the act list and an "Open the review:" line with the address of its review page.

## c. With your agent over MCP

This path runs a live review, so first do steps 1 and 2 of path b (keep a key, restart, wait until healthy): without them the `sample` project has no reviewers.

1. Get the token: `docker compose exec intakt php bin/console intakt:sample:show /app/var/data/sample-repository` prints `Connect an MCP client: claude mcp add ... --header "Authorization: Bearer <token>"`, with the address `http://localhost:7781/mcp/owner` in it (the first-run page shows no address or command).
2. The clone carries a `.mcp.json` for that address, so there is nothing to add: run `export INTAKT_MCP_TOKEN=<the token from step 1>`, then start `claude` in the clone and approve the `intakt` server it offers. `claude mcp list` shows `intakt` as connected. (Or run the printed `claude mcp add` line instead.)
3. Install the skill: copy `skills/intakt-review` to `~/.claude/skills/intakt-review` ([details](../skills/intakt-review/README.md)).
4. Say "intakt review the demo". The agent starts the review with `start_review`, fixes only what the act list names inside the container, and reports the verdict, the rounds used and why it stopped.

## Where to look in the code

| Promise | Enforced by | Test |
|---|---|---|
| Finding ids are kept across rounds | `src/Core/Review/Domain/FindingLedger.php` | `tests/Core/Review/Domain/FindingLedgerTest.php`: `testFindingIdsStayStableOverThreeRounds`; `tests/Core/Review/UseCase/RunReviewRound/FindingsAcrossRoundsTest.php`: `testIdsStayStableOverThreeRoundsAndANewFindingGetsTheNextId` |
| P1 and P2 findings whose quote checks out reach the act list, gate gaps too | `src/Core/Review/Contract/JudgedFindings.php`, `src/Core/Review/Contract/Judgement.php` | `tests/Core/Review/UseCase/JudgeRound/JudgeRoundTest.php`: `testAJudgeThatRejectsAP1AndDefersAP2LeavesBothOnTheActList`; `tests/Core/Review/Contract/JudgedFindingsTest.php`: `testAfterAnInvalidJudgeAnswerEveryBlockingFindingIsOnTheActListAndNoFollowUpIsMade` |
| Figure kinds (measured, counted, estimated) are never added together | `src/Core/Platform/Contract/Figures.php` (throws `MixedFigureKinds`) | `tests/Core/Platform/Contract/FiguresTest.php`: `testATotalOverMeasuredAndEstimatedFiguresIsRefused` |
| A finding is dropped before the judge when none of its checked quotes appears in the change | `src/Core/Review/Domain/QuotesInChange.php`, called by `src/Core/Review/UseCase/JudgeRound/JudgeRoundHandler.php` | `tests/Core/Review/Domain/QuotesInChangeTest.php`: `testAFindingQuotingANameAbsentFromTheChangeIsDropped`, `testAFindingWhoseQuoteIsInTheChangeIsKept` |
| A dropped finding keeps, for each quote, the changed lines that come closest to it (lines sharing at least half of the quote's name parts, most shared first, at most three lines of 160 characters each, for every quote, a repeated quote once; each line is read from the checked-out file, and credentials are hidden in the whole file before a line is cut; the `[redacted]` mark put in a credential's place is not counted as a name; a file that cannot be read from the checkout gives no line, nor does a symbolic link or a file under one, nor a file that turned into a link or another file while it was read, nor a file in which a hidden credential runs over several lines, nor a file whose path holds one; none when no line comes close) | `src/Core/Review/Domain/NearestChangedLines.php` and `src/Core/Review/Infrastructure/AddedLinesInCheckout.php`, called by `src/Core/Review/UseCase/JudgeRound/JudgeRoundHandler.php` | `tests/Core/Review/Domain/NearestChangedLinesTest.php`: `testAQuoteSharingNamePartsWithAChangedLineRecordsThatLineWithItsFileAndNumber`, `testAQuoteWhoseNamePartsAppearOnNoChangedLineRecordsNoLine`, `testAtMostThreeLinesAreKeptPerQuote`, `testAQuoteTheFindingRepeatsIsRecordedOnce`, `testARedactionMarkerDoesNotCountAsANameInTheChange`; `tests/Core/Review/Infrastructure/AddedLinesInCheckoutTest.php`: `testAPrivateKeyBodyLineWhoseBeginLineIsOutsideTheHunkGivesNoLine`, `testAHeldCredentialHoldingACrlfAddedOnTwoLinesLeavesNoPart`, `testAFileMissingFromTheCheckoutGivesNoLineWhileAnotherFileStillDoes`, `testAnAddedSymlinkGivesNoLineOfTheUnchangedFileItPointsTo`, `testALineIsRedactedWholeBeforeTheRuleCutsItSoACredentialAcrossTheCutLeavesNoPart` |

## Symfony AI and MCP pieces used

- **Platform**: every model call, through `src/Plugin/SymfonyAiVendor/`.
- **Agent**: the reviewers and their tool loop (`src/Core/Review/Infrastructure/ReviewerRuns.php`).
- **Store**: project memory (`src/Core/Memory/Infrastructure/StoreProjectMemory.php`).
- **AI Bundle**: configures the provider platforms (`config/packages/ai.php`).
- **MCP Bundle**: two servers. The owner server at `/mcp/owner` has five tools (`list_projects`, `review_status`, `review_verdicts`, `review_act_list`, `start_review`), one resource template (`intakt://rounds/{roundId}/findings`) and progress notifications while `start_review` waits. The review server at `/mcp/review` gives reviewers `read_file`, `list_directory` and `search_files`, which also sends progress. Both `start_review` and `search_files` send progress only when the client supplies a progress token. Neither server has prompts.

Every Symfony and Symfony AI feature in use, with its status: [Highlights](https://shinyredapples.github.io/intakt/highlights/).

## More

- What was planned, achieved and what comes next: [the hackathon page](hackathon.md).
- [The review bench](bench.md#review-bench): how well the panel finds planted defects, measured.
- [Upstream reproductions](architecture/upstream-reproductions.md): library bugs found while building Intakt.
- [Connecting an agent through MCP](reference/mcp.md).
