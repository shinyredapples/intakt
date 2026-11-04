# Connecting an agent through MCP

Intakt runs an MCP server for your own agents (Claude Code or another MCP client). It has four read-only tools: `list_projects`, `review_status`, `review_verdicts` and `review_act_list`, one resource for a round's findings, and one tool that starts a review, `start_review`. Starting a review needs a token that you set.

## Reaching the server

The server is served over HTTP at `/mcp/owner` by the same web application as the rest of Intakt, for example `http://localhost:8000/mcp/owner` after `php -S localhost:8000 -t public`. It accepts only requests whose host is `localhost`, `127.0.0.1` or `[::1]`. The read tools need no token.

With Claude Code: `claude mcp add --transport http intakt http://localhost:8000/mcp/owner`.

## The review server

Intakt runs a second, narrower MCP server for the reviewers of a review, at `/mcp/review` (for example `http://localhost:8000/mcp/review`). It has three read-only tools, `read_file`, `list_directory` and `search_files`, which show the repository exactly as it is in one review round that is still running.

Which round a client sees is fixed by its credential, not by what it sends in a call. `php bin/console intakt:review:issue-credential <round>` prints the server's path and a line `Authorization: Bearer <credential>` for that round; the client sends that header with every request. A request without a valid credential is refused (HTTP 401), and a credential only ever shows its own round, whatever a call's arguments name. The command refuses a round that is unknown or already judged, and the tools refuse a round once it is judged. The credential is signed with `APP_SECRET`: while that is empty, no credential is issued or accepted. It is a secret, so keep it out of logs and committed files.

There is no way to name another folder or branch: paths outside the repository, and `.git`, are refused. `search_files` takes the text to find and, optionally, a file or folder to search in, and sends progress notifications when the client sent a `progressToken`. A review credential can only read: a tool that may write is refused on this server even when `MCP_OWNER_ACCESS` allows writes, and nothing a call sends changes that. Every tool call, allowed or refused, is written to the application log with the tool's name and the access it needs, never with its arguments. Intakt's own reviewers use this server as well: each review sends the credential of its round, to the address in `REVIEW_MCP_BASE_URL` (`.env` default `http://localhost:7780`; the image sets `http://localhost:7781`, the port the container serves on). Outside the image, set it to the address your web server listens on, for example `http://localhost:8000` after `php -S localhost:8000 -t public`. If a reviewer cannot reach the server to list its tools, its review is blocked with the finding `no-tools` and the model is not asked, so no reviewer judges code it could not read; the application log names the failure, and Intakt asks for the tools again only after 60 seconds, so reviews started meanwhile are blocked too. If the server goes away in the middle of a review, or answers a tool call with a failure (an HTTP error such as `401` or `500`, an unreadable answer, or an internal error) instead of the tool's result, that review is blocked the same way, but there is no wait: the next tool call, of the next review, goes to the server again at once. Your own agents use the owner server above; the review server does not list its tools there.

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
