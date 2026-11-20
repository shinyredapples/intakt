# The 90-second video

The script and shot list of Intakt's hackathon video. Every shot is the real product, captured from a running install; the music is an original track, and every cut lands on a beat ("in Takt" means "in time"). The voice-over is captioned at the bottom of the frame.

| Time | Shot | Voice-over |
|---|---|---|
| 0:00 to 0:13 | The octopus conductor lifts its baton; one lonely reviewer lets bugs slip past and changes its mind. | Your coding agent says it is done. Should you believe it? One reviewer misses things, and changes its mind between rounds. |
| 0:13 to 0:21 | A terminal, sped up: `git clone`, `docker compose up`. | Intakt starts in five minutes: clone it, docker compose up. |
| 0:22 to 0:36 | The live review page: two reviewers on models from two AI labs, findings filling in on the beat. | A panel of reviewers on models from different AI labs reviews the change, live on the dashboard. Every finding carries an id, a priority and a quote from the code. |
| 0:37 to 0:51 | The review bench result, then the judge marking each finding. | On our review bench, the panel found 11 of 15 planted defects. A judge checks every finding. |
| 0:51 to 0:53 | One bar of silence. | |
| 0:53 to 0:59 | A finding the judge rejected returns to the act list: its quote checks out against the code. | A serious finding that quotes real code stays, whatever the judge says. |
| 0:59 to 1:04 | Round 2: the same finding ids return, each stamped fixed. | Round two keeps the same ids. No moving goalposts. |
| 1:04 to 1:14 | Claude Code drives the loop over MCP: start a review, read the act list, fix. | Your agent drives the loop over MCP: start a review, read the act list, fix, repeat until the verdict is clean. |
| 1:14 to 1:23 | Symfony AI and MCP Bundle tiles; the upstream reports. | Built on Symfony AI and the MCP Bundle. Every bug we hit is reported upstream, with a fix where we could. |
| 1:24 to 1:30 | Logo and repository URL; the octopus bows. | Clone it. Watch a real review without a key. Intakt. |

The figure in the voice-over comes from the README's [review bench](../README.md#review-bench) (measured, 2026-11-16).

Notes on the precise behavior behind some lines of the voice-over:

- **0:22 to 0:36 and 0:37 to 0:51:** a finding's code quote is optional. A finding has no quote field; the quotes are the inline backtick quotes of its text. Before the judge, the quote check drops a finding only when it quotes code and none of its quotes is in the change; a finding without a quote goes to the judge. The judge then checks the findings that survive the quote check, not every finding, and a round whose every finding is dropped ends without a judge call (see [Quote check](architecture/review-flow.md#quote-check)).
- **1:04 to 1:14:** "repeat until the verdict is clean" is the goal, not a guarantee. The [review skill](user/mcp.md#the-review-skill) also stops when only P3 findings remain, when every open P1 or P2 finding is one it no longer fixes (a finding still open after two rounds of fixes is reported instead), or after 3 rounds (5 when an open finding is about security or data loss). It can therefore stop with findings still open, and running out of rounds is not an approval.
- **1:14 to 1:23:** not every report is filed. Of the 23 reports under [`upstream/`](../upstream/README.md), the status sections record 11 as filed: the pull requests symfony/ai#2874, symfony/ai#2875 (for two reports), symfony/ai#2888 (merged), symfony/ai#2890, symfony/ai#2963 and doctrine/DoctrineBundle#2335 (merged), the issues symfony/ai#2944, symfony/ai#2945 and symfony/ai#2962, and a comment on symfony/ai#2617. Six record `fixed upstream` and two `already reported`, so they have nothing to file; three are `not filed` by the owner's decision; one (`dbal-failed-commit-leaves-transaction-open`) needs a recheck before filing. None is `ready to file` today.
