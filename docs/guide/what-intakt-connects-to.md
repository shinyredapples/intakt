# What Intakt connects to

Intakt talks to three kinds of outside service: where your code lives, the AI companies that do the work, and the places it sends you notices. Each connection is a **plugin**: a small, swappable piece, so adding a new service doesn't change the rest of Intakt. Intakt is still being built, so this describes the decided design ([details](../design/05-architecture/02-plugins.md)).

```mermaid
flowchart LR
  subgraph code[Where your code lives]
    gh[GitHub]
    gt[Gitea]
  end
  subgraph ai[AI companies]
    cl[Claude]
    cx[Codex / OpenAI]
    oa[Any OpenAI-compatible service]
  end
  subgraph notify[Notices to you]
    push[Phone and browser push]
    ha[Home Assistant]
    ntfy[ntfy]
    mail[Email]
    hook[Any webhook]
  end
  intakt[Intakt] <--> code
  intakt <--> ai
  intakt --> notify
```

## Where your code lives (forges)

| Forge | What Intakt needs |
|---|---|
| **GitHub** | its own GitHub App, to be created by the setup wizard with one confirmation (planned; for now you create it and set it up through environment variables, see [setting up Intakt](setting-up-intakt.md#installing)), installed only on the repositories you choose |
| **Gitea** | a bot user of its own |

Intakt always acts as **itself**, never as you, so its comments and your approvals can always be told apart. It reads the tasks, opens and updates pull requests, reads CI results, comments and merges. It learns about changes by checking the forge regularly, and checks more often while something is waiting. If GitHub says Intakt has used up its hourly allowance of requests, Intakt stops asking until the time GitHub names, and a pull request whose checks it could not read meanwhile is looked at again then, not treated as broken, as long as Intakt manages to save the reset time; if saving it fails, that pull request is retried later like any other failed one. When less than a fifth of that allowance is left (you can change the share), Intakt reads each project's list of open pull requests no more than once a minute on average and keeps the rest for the checks, reviews and merges that cannot wait. This needs no public address. Notifications from the forge (webhooks) are an optional extra for installs that have one. *(planned)*

## AI companies (vendors)

| Vendor | How you connect it |
|---|---|
| **Claude** (Claude Code) | a subscription's long-lived token (from `claude setup-token`), or an API key; one or more accounts |
| **Codex** (OpenAI) | a subscription login, or an API key; one or more accounts |
| **Any OpenAI-compatible service** | an API key and its address |

Each vendor tells Intakt which models it has, what they cost and what they are good at (building, reviewing, quick sorting), so Intakt can pick a model without special code per company. See [accounts and costs](accounts-and-costs.md) for how spending works.

## Notices to you (notifiers)

| Notifier | Good for |
|---|---|
| **Browser and phone push** | the dashboard installed as an app on your phone |
| **Home Assistant** | your phone through the Home Assistant app, or a light on the wall |
| **ntfy** | simple push to any device |
| **Email** | notices, as a fallback or for a quieter setup |
| **Webhook** | anything else: chat tools, your own scripts |

You choose which kind of notice goes where: approvals and urgent problems to your phone, the rest by email if you like, quiet hours at night, and a fallback if the first one fails. Each notice is sent **once** per item and change, with a link to it on the dashboard. Notices never carry secrets and never approve anything: approving is always a click or a comment by you, on the forge.

## The project's checks (gates)

The fourth kind of plugin is your project's **check command**, the one that runs all its tests and rules (`bin/check`, `make check`, `npm test`, ...). Intakt runs it in a fresh container before anything is pushed. Its files are protected, so no change can quietly weaken it ([why](why-the-ai-cant-wreck-your-project.md)).
