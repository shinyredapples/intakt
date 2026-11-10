# Parity for the takeover

Only behaviors the switch needs ([plan](../plan.md), M6 to M8). Each line: the requirement, then the existing loop's test that shows it today (file, and a test name where one states it). Before takeover each line gets the Intakt test that shows the same behavior (T73). Intakt task in brackets.

## Intake and shaping
- [ ] An `idea` issue is never built, even with the eligibility label. `test_github.py::test_ready_issues_skip_idea_issues_even_when_labelled_ready` [T47]
- [ ] Only trusted authors' task text and `Waits for` notes count. `test_unblock.py::test_an_untrusted_issue_body_does_not_count_but_a_trusted_comment_does` [T47]
- [ ] A task waits until every `Waits for #n` issue is closed; a line naming the task's own pull request is no wait, and a pull request target counts as the task it closes. `test_unblock.py::test_stays_blocked_while_one_referenced_issue_is_open` [T47]
- [ ] Open pull request work goes before new tasks; only urgent goes before it. `test_priority.py` [T47, T56]
- [ ] Projects get free slots in share order; urgent work first; a project at share 0 is paused for builds and reviews. `test_priority.py::test_a_project_with_urgent_work_gets_the_free_build_slot_before_the_share_order`, `test_projects.py::test_a_zero_share_pauses_the_project_for_every_build_pick_even_an_active_build_or_urgent_work`, `test_projects.py::test_a_zero_share_pauses_the_project_for_the_review_lane` [T56, T57]
- [ ] A split creates its parts in order with `Waits for` lines, and the original becomes an umbrella. `test_sizing.py::test_split_creates_the_parts_in_order_with_waits_for_lines` [T48]
- [ ] A task is never split twice; an interrupted split ends on the first part once. `test_sizing.py::test_an_issue_that_was_split_is_never_split_again`, `test_lanes.py::test_a_split_interrupted_anywhere_ends_on_the_first_part_once` [T48]
- [ ] An umbrella closes only on trusted notes when its parts close. `test_unblock.py::test_an_umbrella_closes_only_on_trusted_waits_for_notes` [T48]
- [ ] A `Source:` line gives the builder one verified commit, read-only. `test_source.py::test_the_builders_clone_resolves_the_source_ref_at_the_verified_sha_only` [T46]

## Build and hand-back
- [ ] The builder has no forge credential or signing key; each session gets a clean home and its own caches. `test_build_slot_ops.py` [T44]
- [ ] Builder and reviewer egress is limited to an allowlist; the vendor login is unreadable to the model's commands. `test_review_gpt_egress.py` [T44]
- [ ] A runaway step is killed alone and the kill is recorded as its cause. `test_step_memory.py` [T44]
- [ ] Builds run the project's own PHP, never a fallback. `test_entry_harness.py::test_a_project_without_its_php_record_gets_no_probes` [T45]
- [ ] Issue quotes, CI logs and findings reach the builder prompt only fenced. `test_injection_canary.py::test_the_builder_prompt_fences_issue_quotes_ci_logs_and_findings`, `test_prompt_fencing.py::test_every_prompt_interpolation_is_fenced_or_allowed` [T49]
- [ ] A hand-back never pushes main or force-pushes. `test_no_force.py`, `test_policy.py::test_main_and_ledger_cannot_be_pushed` [T50]
- [ ] Attribution trailers are stripped from builder commits. `test_attribution.py::test_rebase_strips_attribution_from_builder_commits` [T50]
- [ ] A secret in any new commit, message or the pull request text stops the push; the scanner missing fails closed. `test_secret_scan.py::test_a_missing_scanner_fails_closed`, `test_secret_scan.py::test_gitleaks_finds_a_stripe_key_even_when_a_later_commit_deletes_it` [T50]
- [ ] A secret sent back to the builder is never repeated in feedback. `test_round.py::test_a_token_in_the_pr_body_is_sent_back_without_its_value` [T50]
- [ ] Only a green check pushes; the pre-check in the session catches refusals first. `test_fixpass_logic.py`, `test_precheck.py` [T49, T51]
- [ ] Every Evidence item is referenced; a miss goes back once, a second miss goes to the reviewers. `test_evidence.py`, `test_round.py::test_a_second_evidence_miss_in_the_round_goes_to_the_reviewers` [T51]
- [ ] A body over the cap goes back once with its commits kept. `test_round.py::test_a_pr_md_over_the_cap_goes_back_once_to_be_shortened_with_its_commits_kept` [T51]
- [ ] Composer changes beyond routine go to the owner, naming the packages. `test_policy.py`, `test_owner_approval.py::test_composer_changes_beyond_routine_wait_for_the_owner_and_name_the_new_packages` [T52]
- [ ] The orchestrator pushes only signed commits. `test_gitops.py::test_push_refuses_unsigned_commits` [T53]
- [ ] A crash after the push or after opening the pull request neither pushes again nor opens a second one. `test_lanes.py::test_a_crash_after_the_push_neither_pushes_again_nor_opens_a_second_pull_request` [T53]
- [x] Transient git network failures are retried; persistent ones fail with git's message. `test_network_git.py` [T46]
- [ ] Forge calls use the App's installation token; nothing falls back to another credential. `test_app_auth.py` [T37]

## CI
- [ ] A head with pending CI holds nothing; heads with finished CI go first. `test_lanes_cap2.py::test_admission_prefers_a_head_whose_ci_finished_over_an_older_one_still_running` [T38, T57]
- [ ] Red CI goes back before any reviewer and uses no cycle; the third bounce in a row counts. `test_round.py::test_red_ci_sends_the_head_back_without_using_up_a_review_cycle`, `test_lanes.py::test_the_third_red_ci_bounce_in_a_row_on_a_cycle_counts_as_a_cycle` [T57]
- [ ] The CI excerpt is redacted, fenced and capped. `test_ci_log.py` [T57]
- [ ] A missed CI start is nudged once; an unreadable workflow fails closed. `test_lanes_cap2.py::test_a_stale_pull_request_head_is_nudged_even_when_github_reports_a_conflict`, `test_lanes_cap2.py::test_an_unreadable_ci_workflow_fails_closed_without_a_nudge` [T57]

## Reviews
- [ ] An approval counts only for the exact head and pull request text. `test_round.py::test_an_approval_does_not_survive_a_rewritten_pr_md`, `test_review_binding_race.py` [T59]
- [ ] A failed review never approves and never counts as changes. `test_agents.py::test_any_other_reviewer_failure_is_a_failed_review_never_changes_requested`, `test_agents.py::test_unparsable_verdict_is_not_approval` [T7, T39]
- [ ] Only the vendor's structured codes mark a reviewer account as unavailable. `test_reviewer_accounts.py::test_only_a_closed_set_of_codes_and_statuses_is_an_account_error` [T39]
- [ ] Re-reviews are scoped from saved reviews; a saved approval never counts for a new head. `test_review_history.py::test_a_saved_approval_never_counts_as_a_verdict_of_this_round` [T59]
- [ ] Feedback holds only open findings, merged across reviewers. `test_round.py::test_review_feedback_holds_only_the_open_findings_merged_across_reviewers` [T59]
- [ ] An unchanged head with an outside requirement goes on hold, not to review. `test_holds.py::test_pr_72_the_same_head_with_an_outside_requirement_goes_on_hold_without_a_review` [T59]
- [ ] Evidence only the owner can give reaches status without a send-back. `test_review_process.py::test_a_needs_the_owner_line_reaches_status_and_does_not_send_the_head_back` [T59]
- [ ] A head is booted before any review; a failed boot beats approvals; a crashing gate never stops the review. `test_boot_gate.py::test_an_unchecked_head_is_booted_before_any_review`, `test_boot_gate.py::test_a_failed_or_unchecked_boot_beats_existing_approvals`, `test_boot_gate.py::test_a_crashing_gate_never_stops_the_review` [T58]
- [ ] Probes run in a no-network sandbox with a fixed command surface; infrastructure failures alert, never become findings. `test_probe.py`, `test_probe_alerts.py` [T58]
- [ ] An out-of-limit reviewer gets a same-tier substitute whose verdict counts, and a catch-up is owed. `test_gpt_substitute.py::test_gpt_over_its_limit_gets_the_substitute_review_whose_verdict_counts_and_the_head_merges` [T14, T66]
- [ ] A rejection beside an out-of-limit reviewer goes to the builder without a substitute. `test_gpt_substitute.py::test_a_rejection_alongside_gpt_over_its_limit_goes_back_to_the_builder_without_a_substitute` [T14]
- [ ] A crash during a catch-up neither loses nor doubles it. `test_gpt_substitute.py::test_the_catch_up_issue_number_is_saved_and_a_retry_after_a_crash_finds_only_the_loops_own_issue` [T66]
- [ ] A P1 on two heads is escalated to the strong tier; its verdict is reused on unchanged files. `test_escalation.py` [T61]

## Planning and budget
- [ ] A start is refused only by measured use at its ceiling, never by an estimate. `test_guard.py`, `test_budget_174.py` [T40]
- [ ] A back-off blocks only the account that hit its limit; a crash back-off blocks all. `test_claude_account.py::test_a_budget_backoff_blocks_only_the_account_that_hit_its_limit`, `test_claude_account.py::test_a_crash_backoff_blocks_every_account` [T40]
- [ ] A usage probe outage gives one notice. `test_budget_174.py::test_a_probe_outage_puts_the_ledger_budget_back_and_notifies_once` [T40]
- [ ] A budget back-off and a pause still merge fully approved heads; a drain and a crash back-off stop merges too. Unlike the reference, a pause lets approved heads merge, as the design's [pause control](../design/05-architecture/13-dashboard.md#controls) says. `test_guard.py::test_only_the_shared_budget_back_off_is_a_merge_only_refusal`, `test_merge_only.py::test_a_pause_a_halt_or_a_failure_back_off_still_stops_the_merge` [T40, T62]
- [ ] Unlike the reference, where one item's crash started the crash back-off, the failure back-off starts only on a cause no single item causes: the forge refusing Intakt's credentials (a 401, not a rate limit), a full disk, the shared lock store failing while a head lock is taken or given back, the same error on two different items within an hour, or a hand-over that itself fails, as the design's [failing item](../design/05-architecture/06-budget-and-model-planner.md) rule says; one item's own failure backs off that item alone. `DoctrineInstallWideFailuresTest`, `FailedHeadsTest`, `WatchPullRequestsHandlerTest` [T40]
- [ ] The owner's ceiling override expires and keeps a crash back-off. `test_override.py` [T69]
- [ ] The cheaper tier starts; a label, a sensitive path or an earlier failure starts strong; a P1 raises the next rework. `test_escalation.py::test_an_issue_labelled_model_opus_builds_on_opus_from_cycle_1`, `test_model_choice.py` [T60]
- [ ] No progress for the set time stops a build with its commits kept; the runaway cap stops it regardless. `test_budget_174.py::test_a_round_without_progress_for_n_minutes_stops_with_cause_no_progress`, `test_budget_174.py::test_the_runaway_cap_stops_a_round_whatever_its_progress` [T60]

## Merge and protection
- [ ] The merge is a fast-forward of exactly the reviewed head; main moving after the checks fails it; a lost push response stays covered. `test_fixpass_git.py::test_publish_merge_refuses_a_head_that_is_not_a_fast_forward`, `test_fixpass_git.py::test_publish_merge_push_is_rejected_when_main_moves_after_the_checks`, `test_fixpass_git.py::test_publish_merge_whose_response_was_lost_stays_covered` [T62]
- [ ] A crash after publishing, closing or cleaning up never publishes twice. `test_lanes.py::test_a_crash_after_publishing_closing_or_cleaning_up_never_publishes_twice` [T62]
- [ ] Protected paths come from the full change and per-commit lists; a crafted merge and a submodule count. `test_crafted_merge.py` [T54]
- [ ] A risky workflow change is not pushed before the owner approves it; unreadable forms ask. `test_workflow_risk.py::test_a_form_the_scan_does_not_read_asks`, `test_owner_approval.py` [T55]
- [ ] Only the owner's approval of exactly this head counts; Intakt never submits a review. `test_owner_approval.py::test_only_the_owners_approval_of_exactly_this_head_counts`, `test_owner_approval.py::test_the_loop_never_submits_a_review` [T64]
- [ ] A head waiting for the owner holds no build slot; an ordinary change never waits. `test_owner_approval.py::test_a_head_waiting_for_the_owner_holds_no_build_slot`, `test_owner_approval.py::test_an_ordinary_change_never_waits_for_the_owner` [T64]
- [ ] At the owner-wait cap a head is queued and asked once a place frees. `test_maintainer_ask_once.py::test_at_the_owner_wait_cap_the_head_is_queued_then_asked_once_a_place_frees` [T64]
- [ ] A conflict after the owner's approval parks it; an unchanged resolution merges without a new ask. `test_maintainer_ask_once.py::test_a_conflict_after_the_owners_approval_parks_it_and_an_unchanged_resolution_merges_without_a_new_ask` [T64]
- [ ] Owner and maintainer pull requests are reviewed on every head and merge like a task's. `test_maintainer_merge.py::test_a_labelled_pull_request_goes_through_the_gate_and_merges_like_a_loop_merge`, `test_maintainer_unprotected.py` [T65]
- [ ] A third party's priority label on a pull request is ignored. `test_maintainer_priority.py::test_a_third_partys_priority_label_or_one_on_a_pull_request_anyone_else_opened_is_ignored` [T65]
- [ ] Main is watched: unsigned, unknown key or rewrite halts; the owner's push is one notice. `test_watchdog.py::test_unsigned_commit_still_halts`, `test_watchdog.py::test_force_push_detected_as_rewritten`, `test_watchdog.py::test_owner_signed_commit_is_a_notice_not_a_halt` [T63]

## Operations
- [ ] Every non-merge outcome records a cause. `test_causes.py` [T67]
- [ ] Every notice has a kind; only owner actions push; a failed push is reported, not raised. `test_notify.py::test_every_notice_goes_through_notify_owner_with_a_kind`, `test_notify.py::test_notify_reports_failure_without_raising` [T67]
- [ ] A crash between a step's end and its hand-over only completes the hand-over. `test_lanes.py::test_a_crash_between_the_end_and_the_hand_over_only_completes_the_hand_over` [T67]
- [ ] Status shows pause, drain, parked items and steps. `test_cli.py::test_status_reports_pause_and_last_round`, `test_cli.py::test_status_lists_parked_issues` [T68]
- [ ] Drain stops build admission on every slot while the review lane runs; unlike the reference, drain wins over pause, because a drain also stops merges and the strictest hold decides. `test_lanes.py::test_drain_stops_build_admission_on_both_slots_while_the_review_lane_runs`, `test_guard.py::test_pause_wins_over_drain` [T56, T69]
- [ ] A crash in one build slot is recovered without touching another slot's step. `test_build_slots.py::test_a_crash_in_one_slot_is_recovered_without_touching_the_other_slots_step` [T56]
- [ ] Each project's prompts and posted text name only that project. `test_project_neutral_text.py` [T49]
