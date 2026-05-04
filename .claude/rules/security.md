# rules/security.md
_Human-maintained — always loaded by Claude Code every session_

## Secrets
- IMPORTANT: Never commit secrets, API keys, or credentials
- Never log sensitive values — mask in all output
- Environment variables for all external service credentials
- .env is gitignored — .env.example contains only placeholder values

## Input handling
- Validate and sanitise all external input before use
- Never trust data from queues, webhooks, or external APIs without validation

## Dependencies
- Flag any new dependency additions for review
- Do not add packages with known critical vulnerabilities
