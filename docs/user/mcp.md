# Connecting an agent through MCP

Intakt runs an MCP server for your own agents (Claude Code or another MCP client). For now it has one tool, `list_projects`, which returns the names of the projects Intakt is configured for. Tools to read verdicts and the act list and to start a review are planned.

## Reaching the server

The server is served over HTTP at `/mcp/owner` by the same web application as the rest of Intakt, for example `http://localhost:8000/mcp/owner` after `php -S localhost:8000 -t public`. It accepts only requests whose host is `localhost`, `127.0.0.1` or `[::1]`. There is no token yet: nothing it offers can change anything.

With Claude Code: `claude mcp add --transport http intakt http://localhost:8000/mcp/owner`.

## What it can do

- `list_projects` is marked read-only and not destructive, so a client may call it without asking you. It returns `{"projects": ["alpha", "beta"]}`, or an empty list when no project is configured.
- Every tool states whether it may write. A tool that may write is refused unless Intakt's own configuration allows it; what an agent sends in a call cannot change that. No such tool exists yet.

## When something goes wrong

A refused or failing tool answers with an MCP error and a short message, never a stack trace. The details are in the application log.
