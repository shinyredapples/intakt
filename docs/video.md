# The 90-second video

The script and shot list of Intakt's hackathon video. Every shot is the real product, captured from a running install; the music is an original track, and every cut lands on a beat ("in Takt" means "in time"). The voice-over is captioned at the bottom of the frame.

| Time | Shot | Voice-over |
|---|---|---|
| 0:00 to 0:13 | The octopus conductor lifts its baton; one lonely reviewer lets bugs slip past and changes its mind. | Your coding agent says it is done. Should you believe it? One reviewer misses things, and changes its mind between rounds. |
| 0:13 to 0:21 | A terminal, sped up: `git clone`, `docker compose up`. | Intakt starts in five minutes: clone it, docker compose up. |
| 0:22 to 0:36 | The live review page: two reviewers on models from two AI labs, findings filling in on the beat. | A panel of reviewers on models from different AI labs reviews the change, live on the dashboard. Every finding carries an id and a priority, and quotes the code it is about. |
| 0:37 to 0:51 | The review bench result, then the judge marking each finding. | On our review bench, the panel found 11 of 15 planted defects. A judge checks every finding whose quote is in the code. |
| 0:51 to 0:53 | One bar of silence. | |
| 0:53 to 0:59 | A finding the judge rejected returns to the act list: its quote checks out against the code. | A blocking finding that quotes real code stays, whatever the judge says. |
| 0:59 to 1:04 | Round 2: the same finding ids return, each stamped fixed. | Round two keeps the same ids. No moving goalposts. |
| 1:04 to 1:14 | Claude Code drives the loop over MCP: start a review, read the act list, fix. | Your agent drives the loop over MCP: start a review, read the act list, fix, repeat until the verdict is clean or the round limit is reached. |
| 1:14 to 1:23 | Symfony AI and MCP Bundle tiles; the upstream reports. | Built on Symfony AI and the MCP Bundle. Bugs we hit are written up for upstream, with a fix where we could. |
| 1:24 to 1:30 | Logo and repository URL; the octopus bows. | Clone it. Watch a real review without a key. Intakt. |

The figure in the voice-over comes from the README's [review bench](../README.md#review-bench) (measured, 2026-11-16).
