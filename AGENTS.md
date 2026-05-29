# AGENTS.md
_Project: [PROJECT NAME] | Stack: [e.g. Laravel 13, PHP 8.5, PostgreSQL 16, Redis]_
_MAP v1.0 | Last updated: [DATE]_

## Personal rules
Load @CLAUDE.local.md if it exists or equivalent local rules file for your tool — overrides AGENTS.md
If @HANDOFF.md or @docs/MEMORY.md do not exist — developer has not run docs/SETUP.md step 3 yet.

## Session type — determine this before anything else
Default is fresh start. Only load HANDOFF.md if first message is "continue" (case-insensitive).
- "continue" or "Continue" → continuation session, follow the continuation ritual below
- Anything else → fresh start session, follow the fresh start ritual below

## Continuation session ritual
1. Read @HANDOFF.md — proceed without re-explanation; flag if >5 days old; if missing treat as fresh start
2. Read @docs/STATUS.md — confirm project health
3. Read @docs/MEMORY.md — if it exists, load @docs/memory/gotchas.md and @docs/memory/shared.md (if exists) and note topic files
4. Read @docs/BUGS.md — note any blocking or high severity bugs before starting work
5. Blockers: HANDOFF.md = session priority; STATUS.md = project-level; reconcile both at session end

## Fresh start session ritual
1. Skip HANDOFF.md entirely
2. Read @docs/STATUS.md — if it contains only placeholder text, tell developer to fill it in
3. Read @docs/MEMORY.md — if it exists, load @docs/memory/gotchas.md and @docs/memory/shared.md (if exists) and note topic files
4. Read @docs/BUGS.md — note any blocking or high severity bugs before starting work
5. Ask the developer what they want to work on before acting

## Commands — fill these in for this project
- Run tests: `[TEST COMMAND]`
- Static analysis: `[STATIC ANALYSIS COMMAND]`
- Start services: `[START COMMAND]`
- Build: `[BUILD COMMAND]`

_If any command above still shows a `[...]` placeholder, detect it by reading composer.json, package.json, and Makefile, then replace the placeholder in this file._

## Load when relevant
Read @docs/ARCHITECTURE.md when working on structure or new features
Read @docs/ARCHITECTURE_HISTORY.md when revisiting an architectural choice
Read @docs/CODE_PATTERNS.md when writing application code, migrations, config or scripts
Read @docs/SCHEMA.md when touching the database or internal service contracts
Read @docs/BUGS.md when writing tests or modifying areas with known issues
Read @docs/TESTING_COVERAGE.md when writing or reviewing tests
Read @docs/DOCKER.md when running commands or diagnosing environment issues (skip if project has no Docker)
Read @docs/SETUP.md when helping with local dev or onboarding questions
Read @docs/GLOSSARY.md when domain-specific terms or abbreviations are unfamiliar
Read @docs/memory/[stack].md when writing application code, migrations, config or scripts — past surprises
Read @docs/memory/agents.md when working on agent pipeline (skip if no agents)
Read @docs/memory/database.md when touching the database or schema
Read @docs/memory/testing.md when writing or debugging tests
Read @docs/memory/environment.md when diagnosing environment issues
Read @docs/agents/[name].md when working on a specific agent
Read @docs/api/[name].md when working on API endpoints
Read @docs/integrations/[name].md when working with an external service
Read @docs/architecture/[name].md when working on a specific subsystem or component
Read @docs/qa/[ticket-or-slug].md when reviewing or testing a recently completed feature

## Write rules — do these immediately, without being asked
_Priority order: BUGS.md first, then ARCHITECTURE_HISTORY.md, then others_
- Bug found (any source) → append to docs/BUGS.md; Bug fixed and verified → move to docs/BUGS_ARCHIVE.md
- Architectural decision made → append to docs/ARCHITECTURE_HISTORY.md (hard to reverse or multi-component only)
- New pattern established → check docs/CODE_PATTERNS.md first, only append if not already covered
- New domain term or abbreviation encountered → add to docs/GLOSSARY.md
- Surprising behaviour → route by topic (all in docs/memory/): [stack].md | database.md | testing.md | environment.md | agents.md
- Memory file updated → update entry count in docs/MEMORY.md summary table
- Time wasted on a mistake → append to docs/memory/gotchas.md (max 10 entries — remove least-actionable when full)
- Schema changed → update docs/SCHEMA.md immediately
- Architecture changed → update docs/ARCHITECTURE.md to reflect current state
- Tests added or coverage run → update docs/TESTING_COVERAGE.md from command output
- Task complete → ask the developer if they want a QA file generated; if yes, check branch name for ticket number (e.g. ABC-123) and create docs/qa/[TICKET].md or docs/qa/[feature-slug].md from the example

## HANDOFF.md — rewrite immediately when
- A task is marked complete or a blocker is discovered/resolved
- Context approaching 70% — rewrite HANDOFF.md before context resets

## Session end — do this before closing
1. Rewrite HANDOFF.md (≤40 lines, update date line 2; if AGENTS.md was modified, update its date on line 3)
2. Update docs/STATUS.md — milestone/feature progress, health indicators, project-level next priorities
3. Ask "what did I learn?" — route each learning to docs/memory/ and update MEMORY.md entry counts

## File routing — when in doubt
Session state → HANDOFF.md | Project health → docs/STATUS.md | Decisions → docs/ARCHITECTURE_HISTORY.md
Bugs → docs/BUGS.md | Old fixed bugs → docs/BUGS_ARCHIVE.md | Learnings → docs/memory/[topic].md
New agent/API/integration/component docs → copy the relevant .example.md in that folder
QA file → docs/qa/[TICKET].md or docs/qa/[feature-slug].md (generated by Claude on task complete)

## Ask before acting
- Destructive or cross-system action → confirm scope before executing
- Ambiguous requirement → state interpretation and confirm
- Uncertain about framework-specific approach → read relevant docs/ file first, then act
- Otherwise → act then explain

## Hard rules
- IMPORTANT: Never delete files, database records, or data without explicit developer confirmation
- Use YYYY-MM-DD for all dates in all files
- IMPORTANT: Only update docs/TESTING_COVERAGE.md after running coverage — never estimate without fresh output
- IMPORTANT: Never skip the session start ritual
