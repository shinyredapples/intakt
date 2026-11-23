# 5. Let your agent drive

*Chapter 5 of 9 in [the Intakt user guide](README.md).*

## Why click when your agent can

You fixed two findings by hand, committed, pressed Start round 2, and read the list again. It works, but it is the same loop every time: review, read, fix, review again. Your coding agent can run that loop itself. Intakt speaks MCP, so Claude Code can start a review, read the list To fix, fix exactly that, and ask for the next pass.

## What your agent can reach

Intakt runs an MCP server for your own agents at `/mcp/owner`. It offers:

- **Read tools** that need no token: `list_projects`, `review_status`, `review_verdicts` and `review_act_list`.
- **One tool that changes things,** `start_review`. It needs a token, and Claude Code asks you before it calls it.

Each finding your agent reads carries a short title of 3 to 6 words and a kind (security, logic, missing test, tidy-up or other), as the reviewer gave them; findings from before Intakt asked for them have neither. The [MCP reference](../reference/mcp.md) lists every field.

The server answers only on `localhost`. A finding's text comes from the code under review, so your agent treats it as data, not as instructions.

## Connect Claude Code to the demo

The clone already carries a `.mcp.json` that points Claude Code at the demo. The review is live, so add a key first, as in chapter 3.

1. Print the demo's connection line, which holds a token made for you on the first start:

   ```
   docker compose exec intakt php bin/console intakt:sample:show /app/var/data/sample-repository
   ```

   Among its lines is this one:

   ```
   Connect an MCP client: claude mcp add --transport http intakt http://localhost:7781/mcp/owner --header "Authorization: Bearer <token>"
   ```

2. Copy the token after `Bearer` in that line into your shell:

   ```
   export INTAKT_MCP_TOKEN=<token>
   ```

   Or run the printed `claude mcp add` line instead.

3. Start `claude` in the clone and approve the `intakt` MCP server when Claude Code asks.

The `.mcp.json` file reads the token from that variable and never holds it.

## Teach it the loop with the skill

The `intakt-review` skill tells your agent how to run the loop and when to stop.

1. Copy the `skills/intakt-review` folder into your agent's skills folder, for example `~/.claude/skills/intakt-review` for Claude Code.
2. In the clone, say *intakt review the demo*.

## One loop, pass by pass

Here is what your agent does after you say it:

1. **Start.** It calls `start_review` with the sample's project, branch and task. Claude Code asks you to allow the call.
2. **Read.** When the owl is done, it reads the decision and the list To fix.
3. **Fix.** It changes only what the list names, and never widens the change. For the demo, it fixes inside Intakt's container, in a work tree of the sample branch.
4. **Next pass.** It starts the next review round and reads the new list.

You can watch every pass on the dashboard while it runs. After a pass asks for changes, the beaver stands on the Fix step of the path.

![An earlier version of the review page after two passes driven by an agent: the path reads Pass 1, Fix, Pass 2, Fix, Pass 3, Merge, with the beaver standing on the second Fix; the line shows 2 rounds, 16,692 tokens and an estimated cost of 0.0434 USD; the Now box shows review pass 2 checked, both reviewers asking for changes, F1 still to fix, and the owl's call "Changes needed, 2 findings to fix"](../assets/guide/second-pass.webp)

## When it stops

The skill stops on clear rules, never on a feeling:

- the decision is approve,
- only P3 findings remain,
- every open P1 or P2 is one it no longer fixes,
- or it has used 3 rounds (5 when an open finding is about security or data loss).

A finding still open after two rounds is not fixed a third time: the agent reports both sides and goes on with the others. Running out of rounds is not an approval.

At the end you get a short report: the verdict, the rounds used, the findings fixed and left open, and why it stopped.

## On your own project

Outside the demo, the skill fills in the project, branch, base, repository and task from the repository you are in, and asks only for what it cannot find. Intakt must be able to read that repository, so this works with an install started from a clone, not with the demo container ([chapter 9](09-running-it-for-a-team.md#your-project-and-its-panel)). Starting reviews from an agent is off until you turn it on in `.env.local`: set `MCP_OWNER_ACCESS=mcp_tool_write` and a long random `MCP_OWNER_TOKEN`. The demo sets both for you.

The full tool list, the arguments and every error are in [Connecting an agent through MCP](../reference/mcp.md).

Next: [Trends and Status](06-trends-and-status.md)
