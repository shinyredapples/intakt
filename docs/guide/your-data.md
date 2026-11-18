# Your data: backups, upgrading, moving and uninstalling

Everything Intakt knows lives in one place, so it is easy to back up, move or remove. Intakt is still being built, so this describes the decided design ([overview](../design/05-architecture/01-overview.md), [setup](../design/05-architecture/11-setup.md)); the exact commands arrive with the container image (plan task T22).

## What Intakt keeps, and where

```mermaid
flowchart TD
  vol[(The intakt data volume)] --> db[The database<br/>SQLite: tasks, queue, the record,<br/>budgets, your AI accounts, project memory]
  vol --> cfg[The config file<br/>projects, panels, routing]
  vol --> keys[Its keys and secrets<br/>the GitHub App key, its signing key, the secrets vault's key;<br/>the vault: AI tokens, logins and API keys, encrypted;<br/>the AI key typed on the setup page, not encrypted, readable by Intakt only]
  vol --> mirror[A copy of each repository<br/>rebuilt from GitHub if lost]
  vol --> cache[Caches<br/>job images, package downloads]
```

| Part | What's in it | If you lose it |
|---|---|---|
| **The database** | every task's state, the record of every decision, budgets, the AI accounts and controls you set in the admin area, project memory | Intakt forgets its history and you add your accounts again; your code and pull requests on GitHub are untouched |
| **The config file** | your settings: projects, panels, routing | you redo the setup wizard |
| **Its keys and secrets** | the App's private key, the signing key and the key of the secrets vault; the vault itself with the AI tokens, logins and API keys you saved, encrypted; the AI key you typed on the setup page, in a file of its own that is not encrypted but that only Intakt's own user can read | you reconnect the App and paste new tokens or log in again in the admin area |
| **The repository copies** | a mirror of each project | nothing: rebuilt from GitHub |
| **Caches** | images and downloads | nothing: rebuilt, just slower once |

**What is not in Intakt:** your code, issues, pull requests, reviews and approvals all live on GitHub (or Gitea). Intakt never holds the only copy of anything of yours.

In the container image, all of this lives in the volume `/app/var/data` (see [Running the container image](../user/README.md#running-the-container-image)).

## Backing up

Back up the data volume. Two ways:
- **Quick and safe:** pause Intakt from the dashboard and wait until nothing is running, then stop the container (`docker stop intakt`), copy the volume and start it again (`docker start intakt`). A pause alone is not enough: approved work still merges during a pause, which writes to the volume, and a pause ends by itself at the time you chose.
- **Without pausing:** take a consistent copy of the database with SQLite's own backup command, and copy the config file, the keys and the secrets vault next to it.

Treat the backup like a password manager's file: it holds keys. Store it encrypted.

## Upgrading

A new version of Intakt may change how its database is laid out. It does that by itself when it starts, and it only goes forward: an older version cannot read data a newer one has changed. So today, keep a copy from before the update.

1. Pause Intakt and wait until nothing is running.
2. Stop the container (`docker stop intakt`) and copy the data volume, as in [Backing up](#backing-up).
3. Update to the new version and start it on the volume. It updates the data on its first start.
4. Keep the copy until the new version has run a normal day.

**If something is wrong:** stop the new version and go back to the previous version on the copy you made in step 2. Never start the previous version on the data the new version has already updated.

Planned: these steps go away when Intakt updates itself (the updater, [updates](the-engine-room.md#draining-and-updates)). A new version then only adds to the data, so the version you ran before can still read and write it, and there are three cases, which are not the same:

- **An ordinary update** needs nothing from you: no pause, no waiting, no copy. The old and the new version run side by side on the same data for a while: Intakt checks the new version beside the old one, tries it on one builder slot first, then restarts once on it; running jobs finish on the version they started with, and new jobs use the new one. If the update fails, Intakt goes back to the previous version by itself, on the same data, and keeps everything recorded since the update (a job that finished, a merge). Do not restore a copy to undo an update: the copy lacks what happened after it was made.
- **An update that needs a drain** is the rare one whose release notes say so; the dashboard says why. When it changes how jobs are kept apart, Intakt lets each builder slot finish its job before moving it over; it needs no copy and rolls back the same way. When it changes stored data so the old version could not read it, Intakt pauses all work and copies the data first, and if it fails before work resumes, Intakt goes back to the old version on that copy; nothing was accepted since the pause, so nothing is lost.
- **A backup is for a broken volume**, not for an update: a lost disk, a damaged database, a volume deleted by mistake. Take one regularly, as in [Backing up](#backing-up); taking one before an update is a sensible habit, not a step of it. Restoring a copy loses what was recorded after it, so restore one only when the data itself is gone or damaged, and then start the version that wrote the copy or a newer one on it.

An install that is several versions behind does not jump to the newest one: Intakt installs the versions in between first, one at a time, because a version is only tested to go back to the versions it may be installed from directly.

Intakt's own changes are checked this way today: the version before it writes data, the new version starts on a copy of that data and must read the same, and the version before it starts again on the untouched original. A change to the database migrations, a stored class, the image or the locked dependencies is checked before it is merged; every change is checked again on the main branch right after it is merged. The test that the automatic rollback relies on (each older version it may go back to reading and writing data the new version wrote) arrives with the updater.

## Moving to another machine

1. Pause Intakt and wait until nothing is running.
2. Stop the old container (`docker stop intakt`), so nothing writes to the volume while you copy it and the pause can't run out and start new work.
3. Copy the data volume to the new machine.
4. Start Intakt there with the same volume; it picks up where it stopped.
5. Remove the old container, so two copies never run against the same repositories.

## Uninstalling

1. Pause Intakt; let running jobs finish.
2. Stop and remove the container, and delete the data volume (and its backups, if you no longer want them).
3. On GitHub, uninstall and delete Intakt's App, so nothing holds access to your repositories any more.
4. Log out the AI subscriptions or revoke the API keys you gave it.

Branches Intakt created and pull requests it opened stay on GitHub until you delete them; its comments stay too, signed by its App.
