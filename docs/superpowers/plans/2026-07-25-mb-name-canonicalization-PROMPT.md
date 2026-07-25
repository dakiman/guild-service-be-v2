# Run prompt — mb name canonicalization

Paste into a fresh Claude Code session at `~/dev/guild-service-v2`:

---

Execute the implementation plan at `backend/docs/superpowers/plans/2026-07-25-mb-name-canonicalization.md` using the superpowers:subagent-driven-development skill.

Orchestration setup: you (Fable) are the orchestrator and reviewer only — do not write implementation code yourself. Dispatch one implementation subagent per plan task via the Agent tool with `model: "opus"` (Opus 5). Give each subagent its full task text verbatim plus the plan's **Global Constraints** and **Root-cause summary** sections; subagents must not read the whole plan. Between tasks, review the diff yourself against the task spec and re-run that task's verification command before dispatching the next task. Use your default model for review subagents if you dispatch any.

Hard rules:
- NEVER add Claude attribution to commits (no Co-Authored-By, no "Generated with" lines) — this applies to every subagent too; state it in each subagent prompt.
- Tests run on SQLite in-memory: all case operations in PHP (`BlizzardIdentity::name()` / `Str::lower`), never SQL `lower()`.
- Tasks 1-7 are code + tests on the dev repo; commit per task with `BE:` prefix.
- After Task 7, run `composer test` and confirm the full suite passes before moving on.
- Task 8 deploys and repairs prod data: run the dry-run, then STOP and show me the dry-run output — the non-dry-run repair requires my explicit confirmation. Expected magnitude: ~55k character renames incl. ~28 merges, ~74.5k guild_members fixes; wildly different numbers → stop and investigate instead of proceeding.
- After the repair verifies clean (all three SQL counts zero, top item-level list shows 5 distinct characters), do the subtree push from the plan, then save a memory file about the incident + fix (and add its MEMORY.md pointer line).
