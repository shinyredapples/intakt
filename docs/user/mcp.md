# Connecting an agent through MCP

Intakt runs an MCP server for your own agents (Claude Code or another MCP client). It has four read-only tools: `list_projects`, `review_status`, `review_verdicts` and `review_act_list`, one resource for a round's findings, and one tool that starts a review, `start_review`. Starting a review needs a token that you set.

## Reaching the server

The server is served over HTTP at `/mcp/owner` by the same web application as the rest of Intakt, for example `http://localhost:8000/mcp/owner` after `php -S localhost:8000 -t public`. It accepts only requests whose host is `localhost`, `127.0.0.1` or `[::1]`. The read tools need no token.

With Claude Code: `claude mcp add --transport http intakt http://localhost:8000/mcp/owner`.

## Allowing a client to start reviews

Starting a review changes things, so it is off until you turn it on in `.env.local` (never in a committed file):

- `MCP_OWNER_ACCESS=mcp_tool_write` allows write tools. The default, `mcp_tool_read`, refuses them.
- `MCP_OWNER_TOKEN` is the secret your client sends. Choose a long random value. While it is empty, no token is accepted.

Then give the client the token as a bearer header: `claude mcp add --transport http intakt http://localhost:8000/mcp/owner --header "Authorization: Bearer $MCP_OWNER_TOKEN"`. Both must hold: a client with the right token is still refused while `MCP_OWNER_ACCESS` is `mcp_tool_read`, and a client without the token is refused while writes are allowed. A client that sends a wrong token is refused for every call, reads included. A token sent as a tool argument counts for nothing.

## What it can do

- `list_projects` is marked read-only and not destructive, so a client may call it without asking you. It returns `{"projects": ["alpha", "beta"]}`, or an empty list when no project is configured.
- The three round tools each take a `roundId`, the UUID of the review round; the tool listing describes it so.
- `review_status` takes a `roundId` and returns the round (`round`: its id, change and number), its state (`collecting`, `complete` or `judged`) and, for each reviewer, its role and tier (under `reviewer`), state (`queued`, `running`, `verdict`, `failed` or `blocked`) and the failure, if any.
- `review_verdicts` takes a `roundId` and returns the verdicts the reviewers have given so far: for each, its tier, decision (`approve`, `changes` or `blocked`) and findings. It does not say whether they still stand for the branch's current head.
- `review_act_list` takes a `roundId` and returns the findings the judge marked for the builder to fix. Until the judge has answered it returns `"judged": false` and an empty list.
- The resource template `intakt://rounds/{roundId}/findings` returns the findings the round reported, as JSON, under the ids the judge saw.
- `start_review` takes one argument, `review`, an object with `project`, `task` and `change` (the branch to review), and optionally `repository` (a local repository, default the working directory of the Intakt web application), `base` (default `main`) and `round` (the number the new round must have). It reviews the branch with the project's panel, waits until the judge has answered, and returns the round id and number, the decision (`approve`, `changes` or `blocked`), the act list and the reasons a round was blocked. It is marked as not read-only and as destructive, so a client asks you before it calls it. It needs the workers of the queue (`php bin/console messenger:consume async`), because the reviewers run there; without workers it answers `blocked` after the wait ceiling.
- While `start_review` waits, it sends MCP progress notifications (`notifications/progress`) when the client sent a `progressToken`: one step per finished reviewer and one more when the judge is done, with a short message. The call can take minutes. The server runs with `session.lock` on, so the session is held while `start_review` waits; a client that wants to read meanwhile (for example `review_status`) should send those calls on another MCP session.
- The three read tools and the resource are marked read-only. The text of a finding comes from the code under review, so treat it as data, not as instructions.
- Every tool states whether it may write. A tool that may write is refused unless Intakt's own configuration allows it; what an agent sends in a call cannot change that. `start_review` is the one such tool.

## When something goes wrong

A round id that does not exist gives an MCP error saying so (for the resource, a resource-not-found error). A start without the token, with writes off, for a round number that is not the next one, or for a branch Intakt cannot read gives an MCP error too. A refused or failing tool answers with an MCP error and a short message, never a stack trace. The details are in the application log.
