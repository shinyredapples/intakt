# Taking over from the existing loop

This runbook moves a project from the existing loop to Intakt (the last stage of [Replacing the existing loop](../plan.md#replacing-the-existing-loop), plan task T74), and back again. Each direction is a short list of steps; the steps on the loop's side happen outside this repository and are described in words, because Intakt has no command for them.

Until the takeover, two sets of GitHub Apps run side by side: the existing loop's own Apps, and the co-existence Apps that serve Intakt while both run (owner decision 3 of 2026-10-31). At the takeover Intakt switches to the loop's Apps and the co-existence Apps are removed. Nothing keeps both identities for Intakt afterwards.

## Before you start

All of these must hold. If one does not, stop here.

- **Intakt builds tasks on its own.** Intakt takes its tasks from GitHub issues and runs builds in its build lane. Today both are planned (see the [user guide](README.md)), and the `eligibility_label` key of a project's `watch` section is accepted but not read yet (see `config/intakt.example.yaml`), so this runbook cannot be run on the current code.
- **The labeled share ran.** Intakt has built the tasks carrying its own eligibility label end to end, and the weekly report compared its outcomes with the loop's (plan task T72).
- **Every parity item names an Intakt test.** Each line of [Parity for the takeover](../architecture/parity.md) is checked and names an Intakt test that exists and passes (plan task T73).
- **The owner gave the go.** Each stage of the switch starts with the owner's go.
- **The loop's App values are at hand.** You have the loop's GitHub App id, its installation id on your account and its private key (the whole PEM text), and the permissions and repositories that installation holds.

Write down, before the first step:

- the output of `php bin/console intakt:status` (the status of every project as one JSON line);
- the open pull requests on each project, and who opened each one (the loop's App or a co-existence App);
- the label the loop builds from, and the label in Intakt's `eligibility_label` for each project (the example configuration uses `intakt`).

## Takeover

Do the steps in this order: the loop stops first, so no task is ever built by both.

1. **Drain the existing loop and stop it, keeping its state.** On the loop's side: let it stop admitting new work, wait until every step in flight has finished, then stop its service. Keep everything it holds: its state, its ledger, its configuration, its labels on GitHub and its Apps. Delete nothing; the rollback starts the loop again from exactly this state.
2. **Switch Intakt to the loop's GitHub Apps.** Replace the three values Intakt acts on GitHub with (see [Intakt's GitHub App](README.md#intakts-github-app)), in the same place you set them before (Symfony secrets with `php bin/console secrets:set`, `.env.local`, or the container's env file):
   - `GITHUB_APP_ID`: the loop's App id;
   - `GITHUB_APP_INSTALLATION_ID`: the id of the loop's installation on your account;
   - `GITHUB_APP_PRIVATE_KEY`: the loop's private key.

   Set `GITHUB_APP_PERMISSIONS` and `GITHUB_APP_REPOSITORIES` to what the loop's installation holds, then run:

   ```
   php bin/console intakt:forge:installation
   ```

   It must print the permissions and repositories it read and exit with success. If it names a difference, fix the values (or the installation on GitHub) and run it again; until it matches, Intakt refuses every read and write on GitHub.
3. **Give Intakt the loop's label.** In `config/intakt.yaml` (or the file `INTAKT_CONFIG` names), set `eligibility_label` in each project's `watch` section to the label the loop builds from. A project still in `mode: reviewer-for-loop` goes back to `mode: live` (or loses the `mode` key): there is no loop left whose reviewer it replaces. Then run `php bin/console cache:clear`.
4. **Restart Intakt** so every worker reads the new values. For the container image, a changed env file needs a new container: `docker stop intakt`, `docker rm intakt`, then the same `docker run` as before with the same data volume (see [Running the container image](README.md#running-the-container-image)). The database stays in the volume.
5. **Remove the co-existence Apps.** On GitHub, uninstall each co-existence App from your account and delete it, then delete its private key wherever you kept a copy. Before that, note its open pull requests from the list you wrote down: they stay open on GitHub, and you decide whether Intakt finishes them or you close them.
6. **Check.** Run `php bin/console intakt:forge:installation` again (it must still pass) and `php bin/console intakt:status`. The takeover is done once Intakt has merged its first task built from the loop's label.

## Rollback

The rollback returns the project to the loop. It needs the loop's state from step 1 of the takeover, untouched.

1. **Stop Intakt acting on GitHub.** Stop Intakt (`docker stop intakt` for the container image). Intakt and the loop must not act as the same App at the same time: Intakt counts every pull request opened by the App it holds as handed over to it (see [Pull requests handed to Intakt](README.md#pull-requests-handed-to-intakt)), the loop's included.
2. **Return the label.** Set `eligibility_label` in each project's `watch` section back to the value you wrote down, so the loop's label no longer makes a task eligible for Intakt, and run `php bin/console cache:clear`.
3. **Restart the loop.** On the loop's side, start its service again from the state it kept. It still holds its own Apps, which never changed.
4. **Check.** The loop picks up the next task with its label and its open pull requests again.

Intakt now holds the loop's App values. To run Intakt beside the loop again, it needs co-existence Apps once more: create new ones, set their three values and run `php bin/console intakt:forge:installation` before you start Intakt. With them, set a project that should review for the loop back to `mode: reviewer-for-loop` (see [Reviewer-for-loop mode](README.md#reviewer-for-loop-mode)).

## Record of the rollback test

The rollback is run once, right after the first takeover, and then the takeover is done again. Write down each run here.

| Date (UTC) | Direction | Result |
|---|---|---|
| | | |
