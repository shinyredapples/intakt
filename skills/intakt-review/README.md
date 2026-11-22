# The intakt-review skill

An agent skill that runs the Intakt review loop for you: say "intakt review" and your agent starts a review of the current change, fixes only what the act list names, reviews again and stops by fixed rules with a short report.

Install it after connecting the MCP server (`claude mcp add --transport http intakt ...`, see the README): copy this folder into your agent's skills folder, for example `~/.claude/skills/intakt-review` for Claude Code, or the skills folder of Codex.

For the demo, run the agent in the folder holding Intakt's `compose.yaml` and say "intakt review the demo": the skill uses the sample's values and makes its fixes inside the container, where the sample repository lives.

The agent can start reviews only when the server's token and write access are set ([Connecting an agent through MCP](../../docs/reference/mcp.md)). A test pins every tool and argument the skill names to the server's tool list.
