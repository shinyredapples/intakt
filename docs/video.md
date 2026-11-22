# The 90-second video

The script and shot list of Intakt's hackathon video. Every shot is the real product, captured from a running install; the music is an original track, and every cut lands on a beat ("in Takt" means "in time"). The voice-over is captioned at the bottom of the frame.

| Time | Shot | Voice-over |
|---|---|---|
| 0:00 to 0:05.5 | Cold open: a coding agent's "Done", then Intakt's verdict card "Changes needed" on the same change. | Your coding agent says it's done. Should you believe it? |
| 0:05.5 to 0:07.6 | The octopus conductor lifts its baton; the logo. | This is Intakt. |
| 0:07.6 to 0:13.56 | A terminal, sped up: `git clone`, `docker compose up -d --wait`; then the first-run page and **Play the demo run** on `/demo`. | Clone it, start it, and watch a real recorded review. |
| 0:13.56 to 0:23.55 | The demo's live review page: `correctness` on Claude (`opus`) and `security` on GPT (`gpt-6.2-sol`), findings F1 to F8 arriving on the beat. | Two reviewers, Claude and GPT, read the change. Two AI labs catch what one misses. |
| 0:23.55 to 0:31.55 | A scripted terminal shot of the quote check: F2's quotes (`customerName`, `execSync`) are found in the change, and a finding whose only quote is not in the change is dropped. The shipped recording drops no finding, so this shot is not taken from it. | Every finding must point to the exact code it is about. If it can't, it never reaches your list. |
| 0:31.55 to 0:37.55 | The judge merges the duplicates (F5 and F7 into F2, F6 into F1); the verdict card "Changes needed: 5 things to fix". | The judge merges what both reviewers found into one list to fix. |
| 0:37.55 to 0:51.5 | A real Claude Code session drives the loop over MCP: the dashboard full frame, a small card with the agent's current step (start a review, read what to fix, fix, start the next pass). | Or hand it to your agent, over MCP. Claude Code starts a review, reads what to fix, fixes it, and runs the next pass until it is clean. |
| 0:51.5 to 0:53.6 | The stop: that session's second pass running, the baton held high. | |
| 0:53.6 to 1:01.55 | That session's second pass lands: the same finding ids as the first pass, shown closed or still open as they really are. | Pass two checks the same findings again. No moving goalposts. |
| 1:01.55 to 1:09.55 | The review bench table (in the README when the video was made, now on the [bench page](bench.md#review-bench)). | Five planted defects, each change tested three times. Claude plus GPT caught all five, every run. |
| 1:09.55 to 1:19.55 | The Symfony AI packages and the MCP Bundle in `composer.json`; the upstream reports. | Built on Symfony AI and the MCP Bundle. Every bug we hit building Intakt for the Symfony hackathon was reported upstream, with fixes where we could. |
| 1:19.55 to 1:30 | Logo and repository URL; the octopus bows. | Try Intakt. Clone it, start it, and watch a real review. |

The keyless demo ends after its first round ([the demo walkthrough](guide/demo-walkthrough.md): the shipped review has one round); the second pass is not part of it and comes only from the real Claude Code session, which needs a model key.

The figures in the voice-over at 1:01.55 come from the [review bench](bench.md#review-bench): Claude `opus` alone found 12 of 15 (5 planted defects, 3 runs each), the panel on Claude and GPT 15 of 15 (measured, 2026-11-21).

Notes on the precise behavior behind some lines of the voice-over:

- **0:13.56 to 0:37.55:** "every finding must point to the exact code it is about" is the short form: the quote check holds a finding to the code it quotes, but a finding's code quote is optional. A finding has no quote field; the quotes are the inline backtick quotes of its text. Before the judge, the quote check drops a finding only when it quotes code and none of its quotes is in the change; a finding without a quote goes to the judge. The judge then checks the findings that survive the quote check, not every finding, and a round whose every finding is dropped ends without a judge call (see [Quote check](architecture/review-flow.md#quote-check)).
- **0:37.55 to 0:51.5:** "runs the next pass until it is clean" is the goal, not a guarantee. The [review skill](user/mcp.md#the-review-skill) also stops when only P3 findings remain, when every open P1 or P2 finding is one it no longer fixes (a finding still open after two rounds of fixes is reported instead), or after 3 rounds (5 when an open finding is about security or data loss). It can therefore stop with findings still open, and running out of rounds is not an approval.
- **1:09.55 to 1:19.55:** "every bug we hit ... was reported upstream" is the short form: every report is written up under `upstream/`, but not every report is filed. Of the 30 reports under [`upstream/`](../upstream/README.md), the status sections record 20 as filed (as pull requests, issues or a comment). One of them, the Rector argument list report, was filed as a pull request and declined upstream (rectorphp/rector-src#9012). One records `fixed upstream` (by others), two `already reported` and four pass with nothing to file; three are `not filed` by the owner's decision. None is `ready to file`, and none needs a recheck before filing. The [report index](../upstream/README.md) and each report's status section name the individual references.
