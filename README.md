# MAP v1.0 — Markdown for AI Processing

> A structured Markdown convention designed to make content predictable, chunkable,
> and easily consumable by AI systems (LLMs, embeddings, agents).

## What is MAP?

MAP is a project documentation standard that gives AI coding agents the context they
need to work effectively — without re-explanation every session. It defines which
files exist, what each owns, how they stay current, and how agents should use them.

## Core principles

- **Tiered loading** — not all context loads every session; files load when relevant
- **Single ownership** — every file has one clear owner and one update trigger
- **Token efficiency** — lean always-loaded tier, rich conditional tier
- **Self-maintaining** — files update through session rituals and write rules, not manual effort

## File structure

```
project/
├── AGENTS.md                    # MAP instructions — read by all AI agents
├── GEMINI.md                    # Gemini CLI entry point — imports AGENTS.md
├── CLAUDE.md                    # Claude Code wrapper — imports AGENTS.md
├── HANDOFF.example.md           # Session state template (copy to HANDOFF.md)
├── CLAUDE.local.example.md      # Personal rules template (copy to CLAUDE.local.md)
├── README.md                    # This file — MAP v1.0 spec
├── .gitignore
├── .github/
│   └── copilot-instructions.md   # Copilot entry point — imports AGENTS.md
├── .cursor/
│   └── rules/
│       └── agents.mdc            # Cursor entry point — imports AGENTS.md
├── .claude/
│   ├── settings.json            # Claude Code hook configuration
│   └── rules/
│       ├── security.md          # Always-loaded security rules
│       └── testing.md           # Always-loaded testing rules
└── docs/
    ├── STATUS.md                # Project health — Claude-maintained
    ├── MEMORY.example.md        # Learning index template
    ├── ARCHITECTURE.md          # Current system map — Claude-maintained
    ├── ARCHITECTURE_HISTORY.md  # Decision log — append-only, Claude-maintained
    ├── CODE_PATTERNS.md         # Project patterns — Claude-maintained
    ├── SCHEMA.md                # Data layer — Claude-maintained
    ├── BUGS.md                  # Known bugs — Claude-maintained
    ├── TESTING_COVERAGE.md      # Coverage state — Claude-maintained
    ├── DOCKER.md                # Container reference — human-maintained
    ├── SETUP.md                 # Onboarding — human-maintained
    ├── agents/                  # Agent context files (use agent.example.md)
    ├── api/                     # API documentation (use api.example.md)
    ├── integrations/            # Integration docs (use integration.example.md)
    └── memory/                  # Session learnings (gitignored, use *.example.md)
```

## Loading tiers

| Tier | Files | When loaded |
|---|---|---|
| Always | AGENTS.md, HANDOFF.md | Every session |
| Conditional | docs/*.md | When task is relevant |
| Auto-updated | docs/STATUS.md, docs/memory/* | Session end + write rules |

_Note: some tools load additional files every session (e.g. Claude Code auto-loads `.claude/rules/*.md`)_

## Gitignored (developer-local)

```
HANDOFF.md              # Session state — personal
CLAUDE.local.md         # Personal rules — optional
.env                    # Secrets — never commit
.claude/settings.local.json
docs/MEMORY.md          # Learning index — personal
docs/memory/*.md        # Learning files — personal
```

## Getting started

1. Copy this template into your project root
2. Follow `docs/SETUP.md` — it covers all initialization steps including filling in `AGENTS.md`
3. Start a session — type anything to begin fresh, or "continue" to resume

## AI agent compatibility

| Agent | File | Status |
|---|---|---|
| Claude Code | CLAUDE.md | ✓ native |
| OpenAI Codex | AGENTS.md | ✓ native |
| GitHub Copilot | .github/copilot-instructions.md | ✓ imports AGENTS.md |
| Cursor | AGENTS.md + .cursor/rules/ | ✓ native (rules/ for glob scoping) |
| Gemini CLI | GEMINI.md | ✓ imports AGENTS.md |
| Windsurf | AGENTS.md | ✓ native |

## Governance

MAP is developed as an open convention aligned with [AGENTS.md](https://agents.md),
the cross-tool standard stewarded by the Agentic AI Foundation under the Linux Foundation.

## Version

MAP v1.0 — initial release
