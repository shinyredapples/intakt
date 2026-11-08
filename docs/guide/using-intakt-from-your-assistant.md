# Using Intakt from your AI assistant or the command line

Besides the dashboard, you can talk to Intakt from your own AI assistant (such as Claude Code or another agent) or from a terminal. The review command for the terminal is built. The MCP server (task T15 in [the plan](../plan.md)) can list your projects, start a review of a branch, read a review's status and verdicts and its act list, and read a round's findings. Reviewing a pull request instead of a branch is planned.

```mermaid
flowchart LR
  you([You]) --> dash[Dashboard<br/>in the browser]
  you --> ai[Your AI assistant]
  you --> term[A terminal]
  ai -- MCP --> intakt[Intakt]
  term -- "console commands" --> intakt
  dash --> intakt
```

## From your AI assistant (MCP)

**MCP** (Model Context Protocol) is a standard way for AI assistants to use tools. Intakt runs an MCP server, so you can ask your assistant things like "what did the reviewers say about my last review?" or "which projects does Intakt run?", and it calls Intakt's tools to do it. It can also "start a review of my branch" once you have set up the token below.

| Tool | What it does | Changes anything? |
|---|---|---|
| start a review | reviews a branch end to end and reports progress while it waits (built; a pull request is planned) | yes |
| review status | where a review stands (built) | no, read only |
| read verdicts | each reviewer's verdict and findings (built) | no, read only |
| read the act list | what the builder must fix, after the judge step (built) | no, read only |
| list projects | the projects this install runs (built) | no, read only |

Your assistant can also read one thing that is not a tool: the **round findings** (`round_findings`, built), every finding the reviewers of a round reported. It only reads and changes nothing.

How it stays safe:
- **Every tool is labeled** with what it does ("only reads", "changes something"), so your assistant knows before it calls.
- **Starting a review needs a token** you set in Intakt's config, and writing must be switched on there too. Without both, your assistant can read but not start anything ([how to set it up](../user/mcp.md)).
- **Intakt decides, not the model:** whether a tool may write is decided by Intakt's own rules, never by what the assistant asks for.
- **Approvals are not tools:** no MCP tool can approve a merge or a protected change. That stays your click or comment on the forge.

## From a terminal

The review command runs one review and prints the outcome:

```
bin/console intakt:review <branch> --project=intakt --task="<what the branch builds>" --format=json
```

- It prints the verdict, the list of things to fix, and the tokens used with their cost (unknown when a model has no price in your settings), or JSON for scripts. It needs the review workers running on the same machine. The reviewers read a copy of the branch's last commit that Intakt makes for the round and removes afterwards, not the files you have checked out, so you can keep working while they read; reviewing a pull request instead of a branch is planned.
- Its exit code says the outcome: 0 approved, 10 changes needed, 11 blocked (and 1 when you gave it something wrong), so a script or CI job can act on it.
- Stopping it with Ctrl-C removes its copy first. Only a hard kill can leave the copy behind: `git worktree list` shows it as `intakt-review-...`, and `git worktree remove --force <path>` removes it.

Other commands come with the parts they belong to, for example re-running the Symfony AI bug reproductions (`intakt:upstream:run`) or summarizing a branch's change.

## From your own programs

The same events that update the dashboard are available as a small local API with JSON, for your own tools and scripts. Like the dashboard, it reads through Intakt and never changes Intakt's state behind its back.
