# Execution prompt — raider.io seed expansion

Paste this into a fresh session in `~/dev/guild-service-v2`:

---

Execute the implementation plan at `backend/docs/superpowers/plans/2026-07-28-raiderio-seed-expansion.md`. Read it fully first — it is self-contained (exact code, tests, fixtures, expected failures).

**Orchestration:** Use superpowers:subagent-driven-development. You orchestrate and review; dispatch one implementation subagent per task via the Agent tool with `model: "opus"`. Give each subagent only its own task section (paste it verbatim) plus the plan's **Global Constraints** section and the file paths it touches. Tasks run strictly in order 1 → 5; between tasks, review the subagent's diff (`git diff HEAD~1`) against the plan before dispatching the next.

**Task split:**
- Tasks 1–5 (config/DTO plumbing, client, seeder runs-phase, seeder guilds-phase, docs) → Opus subagents, TDD exactly as written, `./vendor/bin/pint --dirty` before each commit, one commit per task with the message given in the plan.
- Task 6 (deploy + verify) → you handle it directly with me: the env file `/srv/dakis/secrets/guild-service-v2.env` is root-owned, so give me the exact `! sudo ...` commands to run. Follow the `guild-service-v2-deploy-gotchas` project memory (3 image tags, PHP-services-only recreate — do NOT recreate the postgres container, `docker compose restart horizon` after).

**Hard rules:**
- No Claude/Anthropic attribution anywhere in commits (no Co-Authored-By, no "Generated with").
- `composer test` (run from `backend/`) must be fully green after Task 4 and before any deploy step.
- Character names are canonical mb-lowercase — never `strtolower()`.
- Don't wipe or casually mutate character rows; only the seeder/config surface changes.
- Finish with the backend subtree push (Task 6 Step 5) after I confirm the dry-run verification looks right.

---
