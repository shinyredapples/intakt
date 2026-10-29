# Why the AI can't wreck your project

Intakt lets AI models write and review code for your project. This page explains, in plain words, why a model that goes wrong cannot do real damage. It describes the design ([design index](../design/README.md)); the parts arrive in the order of [the plan](../plan.md).

## Prompt injection and third-party text

A model does what the text in front of it suggests, and it cannot reliably tell your instructions from instructions someone hid in an issue, a comment, a log or a library's source code. This is called prompt injection, and no one can rule it out. So Intakt does not rely on its models resisting it. Instead:

- **Plain code makes the decisions that matter.** A model can suggest, write and review, but merging, approving a change to the project's rules, counting a review and picking the model for a job are done or checked by ordinary code that a clever sentence cannot talk around. A change that touches the rules needs two reviewers from different vendors who agree it only tightens them, or else your own approval.
- **Each model has only the tools its job needs.** A reviewer can only read the code and run small tests without network. A builder works in a throwaway container without your passwords or keys, and what it hands back is checked before anything is pushed. The helper that settles routine questions has its own narrow account that cannot merge or approve.
- **Only people you trust steer.** Intakt builds only tasks written by people with write access to the project, and counts labels, answers and instructions only from them, checked with the forge on every event. Comments from anyone else are ignored.
- **Copied text stays marked as foreign.** When you paste text from an outside source into a task (a bug report from a user, a forum post), put it between `<!-- external -->` and `<!-- /external -->`. Intakt then shows it to models as material to look at, not as instructions to follow.
- **Libraries are not trusted either.** Code in your dependencies is treated like any other outside text: something to examine, never something to obey.

The technical version, role by role, is the [threat model](../design/05-architecture/14-threat-model.md).
