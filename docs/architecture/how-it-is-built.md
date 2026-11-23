# How it is built

The stack and the interfaces Intakt exposes.

- **PHP 8.5 and Symfony 8.2 (a beta until 8.2.0 is released)**, with the latest stable tools, and Symfony AI used for every model call, agent and memory, on purpose, to find the beta's bugs early.
- **FrankenPHP worker mode** for the dashboard and the MCP server, with Mercure built in for live updates; Messenger consumers and the Scheduler (the watcher tick every minute, started in the image by its own consumer; usage probes, deadlines and the weekly cleanup are planned) run beside it. The local API stays planned.
- **No public resource API in v1.** The interfaces are the dashboard, the CLI, an optional forge webhook endpoint (forge changes are polled by default) and the MCP server.
