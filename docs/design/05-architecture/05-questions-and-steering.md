# Questions and steering

A build that needs a decision the task does not cover does not guess and does not block a slot:
- It ends with a structured question (one or more choices, with its recommendation), posted as a comment on the issue. The task is parked, and the slot takes the next task.
- The maintainer agent answers first when the design, the plan or an earlier decision settles the question; otherwise the owner answers on the issue, from any device. An answer counts only from a commenter with write access, checked through the forge's permission API on each comment (not from the author association the comment carries), and never from a bot account other than Intakt's own identity and its maintainer's.
- The answer is written into the task's description (its issue body) as a decision, and the task goes back into the queue. A fresh job starts with the task as it now reads.
- Steering comments (new instructions on a task in flight) follow the same trust rule and restart the task at most a set number of times, so a comment thread cannot keep a slot busy forever.
