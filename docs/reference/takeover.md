# Taking over from the build loop that developed Intakt

This runbook moves a project from that loop to Intakt (the last stage of [Replacing the earlier loop](../plan.md#replacing-the-earlier-loop), plan task T74), and back again. Each direction is a short list of steps; the steps on the loop's side happen outside this repository and are described in words, because Intakt has no command for them.

Until the takeover, two sets of GitHub Apps run side by side: the earlier loop's own Apps, and the co-existence Apps that serve Intakt while both run (owner decision 3 of 2026-10-31). At the takeover Intakt switches to the loop's Apps and the co-existence Apps are removed. Nothing keeps both identities for Intakt afterwards.

## Before you start

All of these must hold. If one does not, stop here.

- **Intakt builds tasks on its own.** Intakt takes its tasks from GitHub issues and runs builds in its build lane. Today both are planned (see the [user guide](user-manual.md)), and the `eligibility_label` key of a project's `watch` section is read only by the code that chooses the next task, which nothing calls yet (see `config/intakt.example.yaml`), so this runbook cannot be run on the current code.
- **The labeled share ran.** Intakt has built the tasks carrying its own eligibility label end to end, and the weekly report compared its outcomes with the loop's (plan task T72).
- **Every parity item names an Intakt test.** Each line of [Parity for the takeover](../architecture/parity.md) is checked and names an Intakt test that exists and passes (plan task T73).
- **The owner gave the go.** Each stage of the switch starts with the owner's go.
- **The loop's App values are at hand.** You have the loop's GitHub App id, its installation id on your account and its private key (the whole PEM text, in a file such as `loop-app.pem`), and the permissions and repositories that installation holds.

Write down, before the first step:

- the output of `php bin/console intakt:status` (the status of every project as one JSON line);
- the open pull requests on each project, and who opened each one (the loop's App or a co-existence App);
- the label the loop builds from, and the label in Intakt's `eligibility_label` for each project (`intakt` when the key is left out, as in the example configuration);
- which projects run in `mode: reviewer-for-loop`, and for each one how the loop waits for Intakt's review (for example a required `intakt/review` status check on the branch, or a setting of the loop that hands its review to Intakt).

## Takeover

Do the steps in this order: the loop and Intakt both stop before the pull requests are settled, so no task is ever built by both and no pull request opens while you settle them.

1. **Drain the earlier loop and stop it, keeping its state.** On the loop's side: let it stop admitting new work, wait until every step in flight has finished, then stop its service. Keep everything it holds: its state, its ledger, its configuration, its labels on GitHub and its Apps. Delete nothing; the rollback starts the loop again from exactly this state.
2. **Drain Intakt and stop it.** Intakt has no drain command yet (nothing places the `drain` hold, see [Cooldowns, pauses and outage notices](user-manual.md#cooldowns-pauses-and-outage-notices)), so drain it by hand: remove Intakt's eligibility label from every open issue that has no build in flight, so no new task becomes eligible. Write down every issue you remove the label from, and every issue that keeps Intakt's label because a build is in flight: step 5 gives the ones still open the loop's label. Then wait until every build and review in flight has finished (for the reviews, the board's status line says "Quiet · nobody working", or the Status page lists no reviewer at work). Then stop its service, or the container (`docker stop intakt`), so no worker opens a pull request or acts on GitHub while you settle the pull requests and change its values.
3. **Settle every open pull request of both identities.** Intakt keeps the answer it once recorded about who opened a pull request and does not ask GitHub again (see [Pull requests handed to Intakt](user-manual.md#pull-requests-handed-to-intakt)): a pull request the loop opened stays one Intakt does not take, also after Intakt holds the loop's App, and a pull request a co-existence App opened stays recorded as Intakt's own after that App is gone. So with both stopped, merge or close each open pull request on your list, the loop's and the co-existence Apps' alike, also one a build of step 2 opened. A closed one's task is built again under the loop's App once Intakt takes it, if its issue is still open and carries the loop's label: an issue of the loop keeps that label, and an issue that carries Intakt's old label (the issue of a closed co-existence App pull request, or of a build of step 2) gets the loop's label in step 5, so add it to the list of step 2 if it is not on it. Go on only when no pull request opened by either identity is open.
4. **Switch Intakt to the loop's GitHub Apps.** Replace the three values Intakt acts on GitHub with (see [Intakt's GitHub App](user-manual.md#intakts-github-app)):
   - `GITHUB_APP_ID`: the loop's App id;
   - `GITHUB_APP_INSTALLATION_ID`: the id of the loop's installation on your account;
   - `GITHUB_APP_PRIVATE_KEY`: the loop's private key.

   Set `GITHUB_APP_PERMISSIONS` and `GITHUB_APP_REPOSITORIES` to what the loop's installation holds.

   Without the container, set them where you set them before (Symfony secrets with `php bin/console secrets:set GITHUB_APP_PRIVATE_KEY loop-app.pem`, or `.env.local`; if you dumped the environment into `.env.local.php`, dump it again), then run, before you start Intakt again:

   ```
   php bin/console intakt:forge:installation
   ```

   For the container image, put the two ids, the permissions and the repositories in `intakt.env`. Docker's `--env-file` reads one line per value, so it cannot hold the multiline PEM. Export the key in your shell instead and give `-e` only its name, so Docker takes the value from your shell's environment and the key never appears in the arguments of the `docker` command, which every user of the machine can read (for example with `ps`). Check the new values with a one-off container that runs only the check, before the long-running one starts:

   ```
   export GITHUB_APP_PRIVATE_KEY="$(cat loop-app.pem)"
   docker run --rm --entrypoint php --env-file intakt.env \
     -e GITHUB_APP_PRIVATE_KEY intakt bin/console intakt:forge:installation
   ```

   It must print the permissions and repositories it read and exit with success. If it names a difference, fix the values (or the installation on GitHub) and run it again; until it matches, Intakt refuses every read and write on GitHub. Do not start Intakt before it passes.
5. **Give Intakt the loop's label.** In `config/intakt.yaml` (or the file `INTAKT_CONFIG` names), set `eligibility_label` in each project's `watch` section to the label the loop builds from. From then on Intakt's old label makes no task eligible, so now, before Intakt starts in step 6, put the loop's label on every issue on the list of step 2 that is still open (the ones you took Intakt's label from, the ones that kept it for a build in flight, and the ones step 3 added). Otherwise such an issue is eligible for nothing and is never built. Remove Intakt's old label from the issues that still carry it, or leave it: nothing reads it after this step. A project still in `mode: reviewer-for-loop` goes back to `mode: live` (or loses the `mode` key): there is no loop left whose reviewer it replaces. A live project publishes no `intakt/review` status (only `mode: reviewer-for-loop` does, see [Reviewer-for-loop mode](user-manual.md#reviewer-for-loop-mode)), so if you made `intakt/review` a required status check on such a project's branch, remove it from the branch's required checks now, or no pull request on that branch can merge. Without the container, run `php bin/console cache:clear`.

   For the container image, the project file is the one on the data volume that `INTAKT_CONFIG` names in `intakt.env` (for example `INTAKT_CONFIG=/app/var/data/intakt.yaml`). Edit that file, in the folder you mounted at `/app/var/data` or with `docker cp intakt.yaml intakt:/app/var/data/intakt.yaml`. Do not edit `config/intakt.yaml` inside the container: it is lost with the container at the `docker rm` of step 6. Nothing else is needed here: the container builds its cache again from that file on every start, before any worker runs (step 6).
6. **Start Intakt.** Start its service again, or the container: `docker rm intakt`, then the same `docker run` as before with the same data volume (see [Running the container image](user-manual.md#running-the-container-image)), from the shell where you exported the key in step 4, with `-e GITHUB_APP_PRIVATE_KEY` (the name alone) added. `intakt.env` must still set `INTAKT_CONFIG`, so this start reads the label and the mode of step 5. The database stays in the volume.
7. **Remove the co-existence Apps.** Only now, with no open pull request of theirs left (step 3): on GitHub, uninstall each co-existence App from your account and delete it, then delete its private key wherever you kept a copy.
8. **Check.** Run `php bin/console intakt:forge:installation` again (`docker exec intakt php bin/console intakt:forge:installation` for the container; it must still pass) and `php bin/console intakt:status`. The takeover is done once Intakt has merged its first task built from the loop's label.

## Rollback

The rollback returns the project to the loop. It needs the loop's state from step 1 of the takeover, untouched.

1. **Drain Intakt and stop it.** Drain it by hand as in step 2 of the takeover (this time remove the loop's label, which Intakt now builds from, from every open issue without a build in flight), then stop it (`docker stop intakt` for the container image). Intakt and the loop must not act as the same App at the same time: Intakt counts every pull request opened by the App it holds as handed over to it (see [Pull requests handed to Intakt](user-manual.md#pull-requests-handed-to-intakt)), the loop's included.
2. **Return the label.** Set `eligibility_label` in each project's `watch` section back to the value you wrote down, so the loop's label no longer makes a task eligible for Intakt. Without the container, run `php bin/console cache:clear`. For the container image, edit the project file on the data volume as in step 5 of the takeover (`docker cp` also works on the stopped container) and run nothing: the returned label applies at Intakt's next start, which builds the cache again from that file before any worker runs. Put the loop's label back on the issues step 1 took it from, so the loop builds them. The project's `mode` stays `live`: `mode: reviewer-for-loop` needs co-existence Apps, which no longer exist (see the end of this section).
3. **Give the loop its own reviewer back.** For each project you wrote down as `mode: reviewer-for-loop`, the loop waited for Intakt's `intakt/review` status, which no stopped Intakt publishes. Before the loop starts, undo that hand-off on the loop's side: turn its own reviewer on again. Leave `intakt/review` off the branch's required status checks (step 5 of the takeover removed it).
4. **Restart the loop.** On the loop's side, start its service again from the state it kept. It still holds its own Apps, which never changed.
5. **Check.** The loop picks up the next task with its label and its open pull requests again, and an open pull request of a project that was in `mode: reviewer-for-loop` gets the loop's own review and moves on to merge. A pull request Intakt opened during the takeover was opened as the loop's App, so the loop now treats it as its own: let the loop finish it, or close it.

Intakt still holds the loop's App values. Remove Intakt's copy of them (the three `GITHUB_APP_*` values of step 4 of the takeover, wherever you set them), or remove its container (`docker rm intakt`), and keep it stopped until co-existence Apps exist again. To run Intakt beside the loop again, it needs co-existence Apps once more: create new ones, set their three values and run `php bin/console intakt:forge:installation` before you start Intakt. With them, set a project that should review for the loop back to `mode: reviewer-for-loop` (see [Reviewer-for-loop mode](user-manual.md#reviewer-for-loop-mode)).

## Record of the rollback test

The rollback is run once, right after the first takeover, and then the takeover is done again. Write down each run here.

| Date (UTC) | Direction | Result |
|---|---|---|
| | | |
