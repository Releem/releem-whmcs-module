# Repository Gitignore Design

## Goal

Create a small, documented `.gitignore` that removes machine-local noise and protects local secrets without hiding source, documentation, release archives, or project configuration that may need to be committed.

## Rules

The file will contain grouped rules for:

```gitignore
# Local agent and QA artifacts
.agent/
.gstack/
.worktrees/

# Local secrets and environment overrides
.env
.env.*
!.env.example

# PHP dependencies and test output
/vendor/
/coverage/
.phpunit.result.cache
.phpunit.cache/

# Logs and temporary files
*.log
*.tmp
*.swp
*~

# Operating system and editor metadata
.DS_Store
Thumbs.db
.idea/
.vscode/
```

The existing `docs/superpowers/` directory will not be ignored. Archive patterns such as `*.zip` will not be ignored because archives may be intentional distribution artifacts.

## Verification

- Use `git check-ignore -v` to confirm `.agent/`, `.gstack/`, `.worktrees/`, representative environment files, dependency output, logs, and editor metadata match the intended rules.
- Confirm `.env.example` is not ignored.
- Confirm `docs/superpowers/plans/2026-09-07-readme-installation-setup.md` is not ignored.
- Confirm source files, `README.md`, `AGENTS.md`, and test files remain visible to Git.
- Run `git diff --check` and inspect the final `.gitignore`.

## Acceptance Criteria

- Current `.agent/` and `.gstack/` artifacts disappear from ordinary `git status` output.
- The untracked `docs/superpowers/plans/` document remains visible.
- Local secrets and common generated files are ignored.
- No tracked file becomes untracked or is removed.
- No source, documentation, or release-archive wildcard is broadly excluded.
