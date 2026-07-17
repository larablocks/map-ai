#!/usr/bin/env bash
# install.sh — copy MAP template files into an existing project
# Usage: ./install.sh <target-project-path> [--force]
# Run from the map-ai repo root.

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
TARGET=""
FORCE=0

for arg in "$@"; do
  case "$arg" in
    --force) FORCE=1 ;;
    -*) echo "Unknown option: $arg"; exit 1 ;;
    *) TARGET="$arg" ;;
  esac
done

if [[ -z "$TARGET" ]]; then
  echo "Usage: $0 <target-project-path> [--force]"
  echo ""
  echo "  --force   Overwrite files that already exist in the target"
  exit 1
fi

if [[ ! -d "$TARGET" ]]; then
  echo "Error: '$TARGET' is not a directory"
  exit 1
fi

# ---------------------------------------------------------------------------
# Files to copy (relative to repo root). meta.md is intentionally excluded —
# it only applies to this template repo itself.
# ---------------------------------------------------------------------------
FILES=(
  AGENTS.md
  CLAUDE.md
  GEMINI.md
  .claude/rules/security.md
  .claude/rules/testing.md
  .claude/skills/example-skill/SKILL.md
  .github/copilot-instructions.md
  .cursor/rules/agents.mdc
  docs/ARCHITECTURE.md
  docs/ARCHITECTURE_HISTORY.md
  docs/BUGS.md
  docs/BUGS_ARCHIVE.md
  docs/CODE_PATTERNS.md
  docs/COMMANDS.md
  docs/COMPLIANCE.md
  docs/DESIGN.md
  docs/DOCKER.md
  docs/FEATURE_FLAGS.md
  docs/GLOSSARY.md
  docs/MEMORY.example.md
  docs/METRICS_HISTORY.md
  docs/SCHEMA.md
  docs/SETUP.md
  docs/STATUS.md
  docs/TESTING_COVERAGE.md
  docs/agents/agent.example.md
  docs/api/api.example.md
  docs/architecture/architecture.example.md
  docs/integrations/integration.example.md
  docs/qa/qa.example.md
  docs/memory/agents.example.md
  docs/memory/database.example.md
  docs/memory/environment.example.md
  docs/memory/framework.example.md
  docs/memory/gotchas.example.md
  docs/memory/performance.example.md
  docs/memory/shared.example.md
  docs/memory/testing.example.md
)

# .gitignore lines to merge into the target (in order)
GITIGNORE_BLOCK=(
  ".claude/settings.local.json"
  ""
  "# Claude personal local rules — developer specific, not shared"
  "CLAUDE.local.md"
  ""
  "# Claude auto-memory — session/machine specific"
  "# Copy *.example.md files to their non-example versions on first clone"
  "docs/MEMORY.md"
  "docs/memory/*.md"
  "!docs/memory/*.example.md"
  "!docs/memory/shared.md"
)

# .gitattributes lines to merge into the target (in order)
# merge=union lets concurrent appends to these append-only logs combine automatically
# instead of producing conflict markers. See docs/BUGS.md for the post-merge procedure
# for two branches that independently assigned the same BUG-N.
GITATTRIBUTES_BLOCK=(
  "docs/BUGS.md merge=union"
  "docs/BUGS_ARCHIVE.md merge=union"
  "docs/ARCHITECTURE_HISTORY.md merge=union"
  "docs/METRICS_HISTORY.md merge=union"
)

# ---------------------------------------------------------------------------
# Copy files
# ---------------------------------------------------------------------------
echo "Installing MAP into: $TARGET"
echo ""

COPIED=0
SKIPPED=0
MISSING=0

for file in "${FILES[@]}"; do
  src="$SCRIPT_DIR/stubs/$file"
  dst="$TARGET/$file"

  if [[ ! -f "$src" ]]; then
    echo "  [WARN]   source not found — $file"
    ((MISSING++)) || true
    continue
  fi

  if [[ -f "$dst" ]] && [[ $FORCE -eq 0 ]]; then
    echo "  [SKIP]   $file  (already exists — use --force to overwrite)"
    ((SKIPPED++)) || true
    continue
  fi

  mkdir -p "$(dirname "$dst")"
  cp "$src" "$dst"

  if [[ $FORCE -eq 1 ]] && [[ -f "$dst" ]]; then
    echo "  [UPDATE] $file"
  else
    echo "  [COPY]   $file"
  fi
  ((COPIED++)) || true
done

# ---------------------------------------------------------------------------
# Merge .gitignore
# ---------------------------------------------------------------------------
echo ""
echo "Merging .gitignore..."

GITIGNORE_FILE="$TARGET/.gitignore"
touch "$GITIGNORE_FILE"

ADDED=0
for line in "${GITIGNORE_BLOCK[@]}"; do
  # Skip blank lines and comments when checking for duplicates
  if [[ -z "$line" ]] || [[ "$line" == \#* ]]; then
    continue
  fi
  if ! grep -qxF "$line" "$GITIGNORE_FILE"; then
    ADDED=1
    break
  fi
done

if [[ $ADDED -eq 1 ]]; then
  {
    echo ""
    echo "# MAP — developer-specific files (do not commit)"
    for line in "${GITIGNORE_BLOCK[@]}"; do
      echo "$line"
    done
  } >> "$GITIGNORE_FILE"
  echo "  [UPDATE] .gitignore — MAP entries appended"
else
  echo "  [SKIP]   .gitignore — MAP entries already present"
fi

# ---------------------------------------------------------------------------
# Merge .gitattributes
# ---------------------------------------------------------------------------
echo ""
echo "Merging .gitattributes..."

GITATTRIBUTES_FILE="$TARGET/.gitattributes"
touch "$GITATTRIBUTES_FILE"

MISSING_ATTRS=()
for line in "${GITATTRIBUTES_BLOCK[@]}"; do
  if ! grep -qxF "$line" "$GITATTRIBUTES_FILE"; then
    MISSING_ATTRS+=("$line")
  fi
done

if [[ ${#MISSING_ATTRS[@]} -eq 0 ]]; then
  echo "  [SKIP]   .gitattributes — MAP entries already present"
elif [[ ${#MISSING_ATTRS[@]} -eq ${#GITATTRIBUTES_BLOCK[@]} ]]; then
  {
    echo ""
    echo "# MAP — merge-friendly append-only logs"
    for line in "${GITATTRIBUTES_BLOCK[@]}"; do
      echo "$line"
    done
  } >> "$GITATTRIBUTES_FILE"
  echo "  [UPDATE] .gitattributes — MAP entries appended"
else
  # Some entries already present — append only the missing ones so existing
  # lines aren't duplicated.
  {
    echo ""
    for line in "${MISSING_ATTRS[@]}"; do
      echo "$line"
    done
  } >> "$GITATTRIBUTES_FILE"
  echo "  [UPDATE] .gitattributes — MAP entries appended"
fi

# ---------------------------------------------------------------------------
# Summary and next steps
# ---------------------------------------------------------------------------
echo ""
echo "Done. $COPIED file(s) copied, $SKIPPED skipped, $MISSING missing from source."
echo ""
echo "Next steps:"
echo "  1. Edit AGENTS.md line 2 — set project name and stack"
echo "  2. Edit AGENTS.md line 3 — set today's date"
echo "  3. Fill in the Commands section of AGENTS.md (test, build, start)"
echo "  4. Each developer runs the 'cp' commands in docs/SETUP.md step 3"
echo "     to initialize their personal gitignored files (docs/MEMORY.md, etc.)"
echo ""
echo "MAP install complete."
