# AGENTS.md
_Project: [PROJECT NAME] | Stack: [e.g. Laravel 13, PHP 8.5, PostgreSQL 16, Redis]_
_MAP v1.0 | Last updated: [DATE]_

## Personal rules
Load @CLAUDE.local.md if it exists or equivalent local rules file for your tool — overrides AGENTS.md
If @docs/MEMORY.md does not exist — developer has not run docs/SETUP.md step 3 yet.

## Session start ritual
1. Read @docs/STATUS.md — if it contains only placeholder text, tell developer to fill it in
2. Read @docs/MEMORY.md — if it exists, load @docs/memory/gotchas.md and @docs/memory/shared.md (if exists) and note topic files
3. Read @docs/BUGS.md — note any blocking or high severity bugs before starting work
4. Ask the developer what they want to work on before acting

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
Read @docs/COMPLIANCE.md when touching data classified as sensitive, exports, deletions, or third-party data integrations
Read @docs/BUGS.md when writing tests or modifying areas with known issues
Read @docs/TESTING_COVERAGE.md when writing or reviewing tests
Read @docs/DOCKER.md when running commands or diagnosing environment issues (skip if project has no Docker)
Read @docs/SETUP.md when helping with local dev or onboarding questions
Read @docs/GLOSSARY.md when domain-specific terms or abbreviations are unfamiliar
Read @docs/DESIGN.md when working on UI/frontend code (skip if the project has no UI layer)
Read @docs/memory/[stack].md when writing application code, migrations, config or scripts — past surprises
Read @docs/memory/agents.md when working on agent pipeline (skip if no agents)
Read @docs/memory/database.md when touching the database or schema
Read @docs/memory/testing.md when writing or debugging tests
Read @docs/memory/environment.md when diagnosing environment issues
Read @docs/memory/performance.md when investigating slow behaviour or optimising code
Read @docs/FEATURE_FLAGS.md when starting a new feature or working on flagged code
Working on an agent, API, integration, or component → scan the frontmatter (name + description) across docs/agents/, docs/api/, docs/integrations/, docs/architecture/ for the matching file, then load only that file's full body
Read @docs/qa/[ticket-or-slug].md when reviewing or testing a recently completed feature

## Write rules — do these immediately, without being asked
_Priority order: BUGS.md first, then ARCHITECTURE_HISTORY.md, then others_
- Bug found (any source) → append to docs/BUGS.md; Bug fixed and verified → move to docs/BUGS_ARCHIVE.md
- Branch merged → check docs/BUGS.md and docs/BUGS_ARCHIVE.md for duplicate BUG-N IDs; if found, follow the renumbering procedure documented in docs/BUGS.md
- Architectural decision made → append to docs/ARCHITECTURE_HISTORY.md (hard to reverse or multi-component only)
- New pattern established → check docs/CODE_PATTERNS.md first, only append if not already covered
- Project-specific term, abbreviation, or concept a newcomer wouldn't know → add to docs/GLOSSARY.md so new developers can gain context quickly
- Surprising behaviour → route by topic (all in docs/memory/): [stack].md | database.md | testing.md | environment.md | performance.md | agents.md
- Learning applies to the whole team, not just one machine → also append to docs/memory/shared.md (max 50 entries — remove least-actionable when full)
- Memory file updated → update entry count in docs/MEMORY.md summary table
- Time wasted on a mistake → append to docs/memory/gotchas.md (max 10 entries — remove least-actionable when full)
- Schema changed → update docs/SCHEMA.md immediately
- Architecture changed → update docs/ARCHITECTURE.md to reflect current state
- Tests added or coverage run → update docs/TESTING_COVERAGE.md from command output
- Performance issue discovered → append to docs/memory/performance.md
- New feature started → suggest adding a feature flag before implementing; flag created → append row to docs/FEATURE_FLAGS.md active section; flag removed → move row to removed section
- Agent/API/integration/component changes materially → update its docs/agents|api|integrations|architecture/[name].md file (including its frontmatter description); new docs/architecture/[name].md → also add its row to ARCHITECTURE.md's Component docs table
- Task complete → ask the developer if they want a QA file generated; if yes, check branch name for ticket number (e.g. ABC-123) and create docs/qa/[TICKET].md or docs/qa/[feature-slug].md from the example

## Session end — do this before closing
1. Update docs/STATUS.md — milestone/feature progress, health indicators, project-level next priorities
2. Append a dated entry to docs/METRICS_HISTORY.md — current metrics vs. the previous entry
3. Ask "what did I learn?" — route each learning to docs/memory/ and update MEMORY.md entry counts

## File routing — when in doubt
Project health → docs/STATUS.md | Decisions → docs/ARCHITECTURE_HISTORY.md
Bugs → docs/BUGS.md | Old fixed bugs → docs/BUGS_ARCHIVE.md | Learnings → docs/memory/[topic].md
New agent/API/integration/component docs → scan the target folder's frontmatter for an existing match first, then copy the relevant .example.md and fill in its name/description
QA file → docs/qa/[TICKET].md or docs/qa/[feature-slug].md (generated by Claude on task complete)

## Ask before acting
- Change touches classified/sensitive data handling → confirm against docs/COMPLIANCE.md before proceeding
- Destructive or cross-system action → confirm scope before executing
- Ambiguous requirement → state interpretation and confirm
- Uncertain about framework-specific approach → read relevant docs/ file first, then act
- Otherwise → act then explain

## Hard rules
- IMPORTANT: Never delete files, database records, or data without explicit developer confirmation
- IMPORTANT: Never modify AGENTS.md, CLAUDE.md, GEMINI.md, or .claude/rules/*.md without explicit developer instruction — these are MAP configuration files, not AI-maintained docs
- Use YYYY-MM-DD for all dates in all files
- IMPORTANT: Only update docs/TESTING_COVERAGE.md after running coverage — never estimate without fresh output
- IMPORTANT: Never skip the session start ritual
