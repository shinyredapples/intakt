## Sample repository

`fixtures/sample-repository/` is data, not code; the demo's seed builds a git repository from it (see [The demo](demo-with-docker-compose.md)). `base/` is the tree of the base commit; `pull-request/` holds only the files the branch adds or changes. `issues.json` lists each deliberate issue (`id`, `kind` of bug, missing-test or security, `file`, `description`, and an `evidence` line that appears as an added line in the change). `SampleRepositoryTest` builds the repository with the test `TemporaryRepository` (base on `main`, then the overlay on `sample-change`) and checks that every listed issue is in the diff. It is JavaScript so the PHP gates do not scan it.


### The bench repository

`fixtures/sample-repository/bench/` grows from the same `base/` and holds the changes of the review bench (issue #1938; this part is data and plain code only, the `intakt:bench` command and the runs come later). There are eight changes, each in `bench/<change>/` with a `tree/` (the files the branch adds or changes, the same layout as `pull-request/`), a `task.md` and an `expectations.json`. `BenchRepositoryTest` builds each as its own branch `bench/<change>` on top of `main`. The demo seed reads only `base/` and `pull-request/`, so it is unchanged.

`expectations.json` is a list of planted defects: `id`, `file`, `lines` (`from` and `to`, inclusive, 1-based), `description` and `severity` (`P1`, `P2` or `P3`, what the defect deserves). Five changes plant one defect each (an acceptance item not met, an error path never handled, input that runs commands, an off-by-one boundary, docs that state what the code does not do); three plant none (`[]`), so false alarms are measured. The test checks that every planted line is a line the change adds or changes.

Matching and counting are plain code in `src/Core/Bench/Domain/` and never ask a model:

- `DefectMatcher`: a finding matches a defect when it names the same file and a line inside the range. A finding without a file or line never matches, and there is no tolerance for a nearby line.
- `RunClassifier`: unmatched findings on a defect-free change are false alarms. Unmatched findings on a change with defects are listed in the verdict, not counted.
- Every missed defect gets a cause (`FailureCause`): `harness` when the run failed (a provider or tool failure, `RunOutcome::failed()`), `bench` when the expectation cannot hold (its file is not in the change or its range ends past the file's last line, checked with `ChangeFiles`), otherwise `reviewer` (the model missed it).
- `BenchFigures`: the detection rate (caught over planted) and the false alarm rate (clean runs with at least one false alarm over clean runs) leave out harness failures, which are listed with their reasons. A rate with nothing to count is `null`, not zero.
