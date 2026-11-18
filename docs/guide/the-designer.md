# The Designer, in plain words

The Designer is planned: none of this is built yet. The [design](../design/05-architecture/15-design-scoring.md) is the authority.

## What it is for

Code reviewers read code. They are not good at noticing that a button is too small to tap on a phone, that an error message leaves you stuck, or that a page looks broken when the list is empty. The Designer is the team member who looks at what people see.

## When it joins

Only when there is something to look at. Your project has to say it has screens, and which files and labels belong to them. Then the Designer joins a task only when that task touches those files or carries one of those labels. A project without screens, or a task that changes nothing people see, never pays for it.

## Before the work starts

For a task that changes what people see, the Designer first writes down what the screen must do, so the builder does not have to guess:

- the flows: the steps a person takes;
- every state: what the screen shows when it is empty, while it loads, when something fails, and when it worked;
- the words on the screen;
- how it looks at three sizes: phone, tablet and desktop;
- what people using a screen reader, a keyboard or large text need;
- which screens, and which parts of them, the work is meant to change.

This happens even when your project has planning switched off. If the work turns out to change a screen nobody expected it to, it goes back to the Designer for this step before anyone reviews it, and then it is built again.

## Three ways to score the work

**1. Hard checks that stop the work.** Some things can be measured, so a program measures them, not an AI:

- no serious accessibility problems, and text that is easy to read against its background;
- at each of the three sizes, nothing you have to scroll sideways, nothing on top of something else, no text spilling out of its box, and every button at least 44 pixels so a finger can hit it;
- colors, spacing and fonts only from your project's own list of design values;
- the speed and quality scores you set for each page;
- a picture of every screen compared with the current version, so anything that changed by accident is caught, even on a screen the work was meant to change, as long as it is outside the parts the Designer said would change.

If one of these fails, the work goes back to be fixed, like a failing test.

**2. A score card.** The Designer gives each screen a score for clarity, feedback, states, consistency, recovering from errors, and wording. Every score has to point at a spot in a screenshot and say what is wrong there. A low score is a note to fix, not a hard stop. Scores are kept, so you can see on the dashboard when a screen got worse.

**3. Learning your taste.** Some choices have no right answer. For those, the Designer makes a few versions and compares them two at a time, without knowing which is which, to put them in order. The comparing is done by a fresh AI session that only sees the two pictures, not the code, the current version or the session that made them. Now and then you get some of those pairs to pick from, the same blind way. The dashboard shows how often the Designer picks what you picked. While that is low, you are asked about the choices that give a screen its character. As it rises, you are asked less.

## What you need to install

Nothing, unless you want it. The hard checks need extra tools (axe-core for accessibility, Lighthouse for the page scores, and a browser without a window to take the pictures). You switch them on per project, and they run where the checks run. A check whose tool is off does not run, and the dashboard says so instead of calling it passed.

## Can the Designer approve work on its own?

No. The hard checks are programs, not AI. The score card only adds notes for the reviewers' usual process, and the taste ladder only decides what to ask you. Merging still needs the checks, the reviewers and, where the rules say so, you. See [why the AI can't wreck your project](why-the-ai-cant-wreck-your-project.md).
