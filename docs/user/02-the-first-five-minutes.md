# 2. The first five minutes

*Chapter 2 of 9 in [the Intakt user guide](README.md).*

## Before you trust it, watch it

You do not hand your code to a stranger on their word. So before Intakt reads anything of yours, you watch it review someone else's change. Intakt ships a recording of a real review, and it plays back on your own dashboard without calling any model. You need no key and no account.

## What you need

- Docker (or Podman).
- A terminal and a browser.
- About five minutes.

## Start Intakt

1. Clone the repository and start it:

   ```
   git clone https://github.com/shinyredapples/intakt.git && cd intakt
   docker compose up -d --wait
   ```

   The command returns once the dashboard can be opened. No file needs editing first.

2. Open <http://localhost:7781> in your browser.

Until a review has run, the dashboard opens on its first-run page, **See a real review**.

![The first-run page in dark mode: the octopus with its baton above "See a real review, 1 minute, no key", a large Play the demo run button, the line "Two AI labs read a change, then the owl decides what to fix", and three steps: Read by two AI labs (the meerkat), Check by the owl, To fix, only what matters (the beaver); below starts "Use your own project"](../assets/guide/first-run.webp)

## Play the recorded review

1. Press **Play the demo run**.
2. The dashboard takes you to the review page as the first stage begins.
3. Watch. The recording plays one and a half times as fast as the real review ran.

The recording is a real review of a small sample change, by two reviewers on models from two AI labs, Claude and GPT. The change adds a shipping cost by weight and a report of a customer's orders. It hides a few mistakes on purpose.

## Follow the caption bar

While the recording plays, a caption bar at the bottom of every page says what is happening and links to the review. It walks through five stages, counted with dots:

1. The change arrives.
2. The reviewers read it.
3. The first findings come in.
4. The owl weighs them.
5. The round is judged, with its list of what to fix.

On a desktop you can pause the caption. It closes by itself eight seconds after the last stage, or you can close it.

![The board after the demo run: the sleeping octopus, the status line "Quiet, nobody working", the columns Waiting on you, In review and Done, with the demo review in Done marked Replay and Changes requested; at the bottom the caption bar reads "Checked: To fix lists what still needs changing" with Open the review, Pause and Close](../assets/guide/board.webp)

## A replay is never a verdict

Every round you play this way carries a **Replay** label. Its reviewers judged the sample change at another time, so their verdict says nothing about the code in front of you. Intakt always counts a replay as blocked, with that reason.

The recording lives in its own project, `demo-replay`, and you can play it again from <http://localhost:7781/demo>.

## When you are done for the day

To stop the demo, run `docker compose down`. Add `-v` to remove its data too.

## What you have seen

In five minutes you watched two labs read a change, saw the owl cut their findings down to one list, and learned where each part sits on the screen. Next, Intakt reviews for real.

Next: [Your first real review](03-your-first-real-review.md)
