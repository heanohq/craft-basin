#!/usr/bin/env bash
# Runs Claude on .triage/thread.json and writes its proposal to
# .triage/proposal.json. Claude can only read this repository and fetch pages
# from heano.io and Basin's sites; it can't write, run commands or post.
#
# Prints only a summary of the run: logs on a public repository are public.
set -euo pipefail

dir=$(dirname "$0")
# setup-token prints the token across lines, so a pasted secret can carry
# a line break, which isn't allowed in a header.
CLAUDE_CODE_OAUTH_TOKEN=$(tr -d '[:space:]' <<< "${CLAUDE_CODE_OAUTH_TOKEN:-}")
export CLAUDE_CODE_OAUTH_TOKEN

model=${TRIAGE_MODEL:-sonnet}
model=$(tr '[:upper:]' '[:lower:]' <<< "$model")

claude -p "$(cat "$dir/prompt.md")" \
  --model "$model" \
  --max-turns "${TRIAGE_MAX_TURNS:-25}" \
  --tools 'Read,Glob,Grep,WebFetch' \
  --allowedTools 'Read(./**)' 'WebFetch(domain:heano.io)' 'WebFetch(domain:usebasin.com)' \
    'WebFetch(domain:docs.usebasin.com)' 'WebFetch(domain:status.usebasin.com)' \
  --permission-mode dontAsk \
  --setting-sources project \
  --strict-mcp-config \
  --no-session-persistence \
  --output-format json \
  --json-schema "$(cat "$dir/schema.json")" \
  > .triage/result.json || true

jq '{is_error, subtype, num_turns, duration_ms, api_error_status, denied: [.permission_denials[]?.tool_name]}
  + (if .is_error and (.structured_output == null) and (.num_turns <= 1) then {error: (.result // "" | .[0:300])} else {} end)' .triage/result.json
jq -e '.structured_output // empty' .triage/result.json > .triage/proposal.json
