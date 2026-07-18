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
  echo "  --force   Overwrite SCAFFOLD_FILES that already exist (backed up to <file>.bak first)"
  exit 1
fi

if [[ ! -d "$TARGET" ]]; then
  echo "Error: '$TARGET' is not a directory"
  exit 1
fi

# ---------------------------------------------------------------------------
# Framework-owned files — always kept in sync with the package stubs, never
# backed up. meta.md is intentionally excluded — it only applies to this
# template repo itself.
# ---------------------------------------------------------------------------
MANAGED_FILES=(
  .cursor/rules/agents.mdc
  docs/MEMORY.example.md
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

# ---------------------------------------------------------------------------
# User-owned scaffold files — copied once, never overwritten without --force,
# and backed up to <file>.bak before any forced overwrite.
# ---------------------------------------------------------------------------
SCAFFOLD_FILES=(
  AGENTS.md
  CLAUDE.md
  GEMINI.md
  .claude/rules/security.md
  .claude/rules/testing.md
  .claude/skills/example-skill/SKILL.md
  .github/copilot-instructions.md
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
  docs/METRICS_HISTORY.md
  docs/SCHEMA.md
  docs/SETUP.md
  docs/STATUS.md
  docs/TESTING_COVERAGE.md
)

# .gitignore lines to merge into the target, grouped — each group's header
# comment is only re-emitted if the whole group is missing; a partially
# present group gets only its missing lines appended, so re-running the
# installer never duplicates a line that's already there.
GITIGNORE_GROUP_1_HEADER="# MAP — developer-specific files (do not commit)"
GITIGNORE_GROUP_1=(".claude/settings.local.json")

GITIGNORE_GROUP_2_HEADER="# Claude personal local rules — developer specific, not shared"
GITIGNORE_GROUP_2=("CLAUDE.local.md")

GITIGNORE_GROUP_3_HEADER="# Claude auto-memory — session/machine specific
# Copy *.example.md files to their non-example versions on first clone"
GITIGNORE_GROUP_3=("docs/MEMORY.md" "docs/memory/*.md" "!docs/memory/*.example.md" "!docs/memory/shared.md")

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
IDENTICAL=0
SYMLINKS=0

# Strips CRLF and surrounding whitespace from each line of $1 so a line
# that's already present but byte-different (e.g. CRLF endings, leading or
# trailing spaces) isn't treated as missing and re-appended as a duplicate —
# matches Installer.php's trim() normalization. Shared by the .gitignore and
# .gitattributes merge steps. Uses `tr -d '\r'` rather than sed's `\r` escape
# for the CR strip — `\r` as a sed regex escape is a GNU extension not
# reliably honored by BSD/macOS sed, while `tr -d` and sed's `[[:space:]]`
# POSIX class both work identically on GNU and BSD.
normalize_lines() {
  tr -d '\r' < "$1" | sed -e 's/^[[:space:]]*//' -e 's/[[:space:]]*$//'
}

# Sets SRC/DST for $1 and returns 1 (after counting MISSING) if the stub
# source doesn't exist, or returns 1 (after counting SYMLINKS) if the
# destination is a symlink — never write through a symlink, since cp follows
# it and could silently write outside the target directory entirely.
# Shared by copy_managed() and copy_scaffold().
resolve_source() {
  local file="$1"
  SRC="$SCRIPT_DIR/stubs/$file"
  DST="$TARGET/$file"

  if [[ ! -f "$SRC" ]]; then
    echo "  [WARN]   source not found — $file"
    ((MISSING++)) || true
    return 1
  fi

  if [[ -L "$DST" ]]; then
    echo "  [WARN]   $file is a symlink — skipped to avoid writing through it"
    ((SYMLINKS++)) || true
    return 1
  fi

  return 0
}

copy_managed() {
  local file="$1"
  resolve_source "$file" || return 0

  if [[ -f "$DST" ]] && cmp -s "$SRC" "$DST"; then
    echo "  [SAME]   $file  (matches template already)"
    ((IDENTICAL++)) || true
    return
  fi

  local existed=0
  [[ -f "$DST" ]] && existed=1

  mkdir -p "$(dirname "$DST")"
  cp "$SRC" "$DST"

  if [[ $existed -eq 1 ]]; then
    echo "  [UPDATE] $file"
  else
    echo "  [COPY]   $file"
  fi
  ((COPIED++)) || true
}

copy_scaffold() {
  local file="$1"
  local src dst
  resolve_source "$file" || return 0
  src="$SRC"
  dst="$DST"

  if [[ -f "$dst" ]]; then
    if [[ $FORCE -eq 0 ]]; then
      echo "  [SKIP]   $file  (already exists — use --force to overwrite)"
      ((SKIPPED++)) || true
      return
    fi

    if cmp -s "$src" "$dst"; then
      echo "  [SAME]   $file  (matches template already, no backup needed)"
      ((IDENTICAL++)) || true
      return
    fi

    cp "$dst" "$dst.bak"
    cp "$src" "$dst"
    echo "  [UPDATE] $file  (backed up to $file.bak)"
    ((COPIED++)) || true
    return
  fi

  mkdir -p "$(dirname "$dst")"
  cp "$src" "$dst"
  echo "  [COPY]   $file"
  ((COPIED++)) || true
}

for file in "${MANAGED_FILES[@]}"; do
  copy_managed "$file"
done

for file in "${SCAFFOLD_FILES[@]}"; do
  copy_scaffold "$file"
done

# ---------------------------------------------------------------------------
# Merge .gitignore
# ---------------------------------------------------------------------------
echo ""
echo "Merging .gitignore..."

GITIGNORE_FILE="$TARGET/.gitignore"
touch "$GITIGNORE_FILE"
# Normalize CRLF and trailing whitespace before matching, so a line that's
# already present but byte-different (e.g. CRLF line endings) isn't treated
# as missing and re-appended as a duplicate.
GITIGNORE_EXISTING="$(normalize_lines "$GITIGNORE_FILE")"

# Takes a group's header followed by its entries as plain positional args
# (not indirect variable-name expansion, which is unreliable across bash
# versions) and appends to GITIGNORE_ADDITIONS only what's actually missing.
add_gitignore_group_if_missing() {
  local header="$1"
  shift
  local entries=("$@")

  local missing=()
  for line in "${entries[@]}"; do
    if ! grep -qxF "$line" <<< "$GITIGNORE_EXISTING"; then
      missing+=("$line")
    fi
  done

  if [[ ${#missing[@]} -eq 0 ]]; then
    return
  fi

  if [[ ${#missing[@]} -eq ${#entries[@]} ]]; then
    GITIGNORE_ADDITIONS+=("$header
$(printf '%s\n' "${entries[@]}")")
  else
    GITIGNORE_ADDITIONS+=("$(printf '%s\n' "${missing[@]}")")
  fi
}

GITIGNORE_ADDITIONS=()
add_gitignore_group_if_missing "$GITIGNORE_GROUP_1_HEADER" "${GITIGNORE_GROUP_1[@]}"
add_gitignore_group_if_missing "$GITIGNORE_GROUP_2_HEADER" "${GITIGNORE_GROUP_2[@]}"
add_gitignore_group_if_missing "$GITIGNORE_GROUP_3_HEADER" "${GITIGNORE_GROUP_3[@]}"

if [[ ${#GITIGNORE_ADDITIONS[@]} -eq 0 ]]; then
  echo "  [SKIP]   .gitignore — MAP entries already present"
else
  {
    for addition in "${GITIGNORE_ADDITIONS[@]}"; do
      echo ""
      echo "$addition"
    done
  } >> "$GITIGNORE_FILE"
  echo "  [UPDATE] .gitignore — MAP entries appended"
fi

# ---------------------------------------------------------------------------
# Merge .gitattributes
# ---------------------------------------------------------------------------
echo ""
echo "Merging .gitattributes..."

GITATTRIBUTES_FILE="$TARGET/.gitattributes"
touch "$GITATTRIBUTES_FILE"
GITATTRIBUTES_EXISTING="$(normalize_lines "$GITATTRIBUTES_FILE")"

MISSING_ATTRS=()
for line in "${GITATTRIBUTES_BLOCK[@]}"; do
  if ! grep -qxF "$line" <<< "$GITATTRIBUTES_EXISTING"; then
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
echo "Done. $COPIED file(s) copied/updated, $SKIPPED skipped, $IDENTICAL already up to date, $MISSING missing from source, $SYMLINKS symlinked destination(s) left untouched."
echo ""
echo "Next steps:"
echo "  1. Edit AGENTS.md line 2 — set project name and stack"
echo "  2. Edit AGENTS.md line 3 — set today's date"
echo "  3. Fill in the Commands section of AGENTS.md (test, build, start)"
echo "  4. Each developer runs the 'cp' commands in docs/SETUP.md step 3"
echo "     to initialize their personal gitignored files (docs/MEMORY.md, etc.)"
echo ""
echo "MAP install complete."
