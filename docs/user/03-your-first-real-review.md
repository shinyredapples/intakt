# 3. Your first real review

*Chapter 3 of 9 in [the Intakt user guide](README.md).*

## From a recording to the real thing

The replay convinced you enough to try. Now Intakt reviews the same sample change live, on models you pay for, and you watch the reviewers work. The sample is already set up: a small repository, a branch with planted mistakes, and a panel of two reviewers.

## Choose a key

A live review calls AI models, so Intakt needs one key. Any one of these works:

- an Anthropic API key,
- an OpenAI API key,
- a Hugging Face token with only the permission "Make calls to Inference Providers".

The calls are billed to whoever owns the key. With one key, both reviewers come from that company. With a Hugging Face token, both go through Hugging Face but run on models from two different AI labs. Chapter 7 says more about mixing providers.

## Add the key in the setup wizard

1. Open <http://localhost:7781/setup?step=3>, step 3 of the setup wizard, **Add an AI account**.
2. Paste the key and press **Keep the key**.
3. Intakt asks the provider one tiny question with the key. Only a key the provider accepts is kept, and the step says what happened.
4. Restart Intakt so it uses the key:

   ```
   docker compose restart intakt
   ```

The page never shows the key again, only its provider and at most its last four characters. If you prefer a file, copy `.env.dist` to `.env.local` and put the key there instead.

![The "First review" part of the setup wizard's last step: the line "No review has run yet: start the review of the change sample-change of the sample project", a Start the first review button and the note "Two reviewers read the sample change; its live page opens"](../assets/guide/first-review-button.webp)

## Start the review

1. Go to step 5, **Ready to start** (<http://localhost:7781/setup?step=5>).
2. Under **First review**, press **Start the first review**.
3. The live review page opens.

Prefer the terminal? `docker compose exec intakt php bin/console intakt:demo` runs the same review and prints the outcome, the list of things to fix and the address of the review in the dashboard.

## What you see while it runs

The top of the page tells the story so far. Under the title comes the task sentence, then a path from **Pass 1** to **Merge**, where the meerkat stands on the pass that is being read. The rounds, tokens and estimated cost are in the **Full record**, which a link at the bottom of the page opens.

On a phone, a long model name in the cost wraps beside the word "Cost:", and the word stays whole.

![An earlier version of a live review page: "Review of sample-change in sample", the task sentence, the meerkat on Pass 1 of the path to Merge, the line with 1 round and 0 tokens so far, a Stop button, and the Now box: 0 of 2 reviewers finished, correctness on Kimi K3.1 Pro and security on DeepSeek V4 Pro both running, "No finding is open" under To fix, and the owl with "The judge has not judged this round yet"](../assets/guide/review-running.webp)

While the round is in review, a box headed **Now** has three parts:

- **Reviewers** counts who has finished and shows each reviewer's role, model and state.
- **To fix** stays empty until the owl has judged.
- **The judge's call** says the owl has not judged yet.

To watch more, open the Full record: its live log shows each reviewer at work, the files it reads, the searches it runs, and finally its verdict and one line per finding.

The live log shows `[redacted]` in place of a recognized credential (a known token or key format) in a searched text or a path. The summary's list of files read does the same for a path.

A reviewer's heading, its role and model, appears with that reviewer's first live update: a tool call, streamed text, an answer or a retry notice. When a reviewer is asked again, the new try's text replaces the old one and the heading already shown stays as it is; a reload shows the model of the latest try. Everything else updates by itself without a reload.

## While you wait

On the board, the sample change sits in **In review** while the panel reads it. On an older 4-core desktop, the demo review took 42 seconds to its verdict (median of ten runs). A provider under load can take longer.

If you change your mind, the **Stop** button beside the title asks the running reviewers to stop. On a phone, Stop sits under the review path. Press it twice: the first press asks you to confirm. If the round has already ended, even when a page you left open still shows the button, Intakt refuses the press and stops nothing. The same goes for a **Stop this reviewer** button on a page you left open after all reviewers have finished: Intakt takes you back to the review with a notice that no reviewer runs, and stops nothing.

## When the owl is done

The Now box goes, and the owl's card and the things to fix take its place. The card gives the owl's call, such as "Changes needed", with "5 to fix". Below it, the findings stand in three groups, **Urgent**, **Should fix** and **Nice to have**, one row each with its short title (its first sentence for an older finding) and its file and line. The rows are the list where your next half hour goes; each one opens its finding's own page.

Next: [Reading the verdict](04-reading-the-verdict.md)
