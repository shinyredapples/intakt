# Security policy

Intakt handles provider keys and GitHub App keys, and it runs reviewers on code it does not trust. A security problem reported in public would put its users at risk before a fix is out, so please report it privately.

## How to report

Use GitHub's private vulnerability reporting: open the repository's **Security** tab and choose **Report a vulnerability**. Only you and the repository's maintainers can see the report.

Never open a public issue, pull request or discussion for a security problem.

## What to include

- The affected version or commit.
- The steps to reproduce the problem.
- The impact: what an attacker can do with it.

## What happens next

- You get an acknowledgement within 7 days.
- You get a fix or a plan for one within 30 days.
- You are credited in the release notes, unless you would rather not be.

## Supported versions

Only the latest release gets security fixes. Please check that the problem still exists there before you report it.

## Scope

In scope:

- Intakt's code.
- Its container image.
- Its default configuration.

Out of scope: third-party services and models, such as a model provider or GitHub itself. Report a problem in one of them to its owner.

For what Intakt defends against, and what it leaves to the people who run it, see the [threat model](docs/design/05-architecture/14-threat-model.md).
