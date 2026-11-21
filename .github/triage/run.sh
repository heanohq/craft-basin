#!/usr/bin/env bash
# Runs Claude on .triage/thread.json and writes its proposal to
# .triage/proposal.json. Claude can only read this repository and fetch pages
# from heano.io and usebasin.com; it can't write, run commands or post.
#
# Prints only a summary of the run: logs on a public repository are public.
set -euo pipefail

dir=$(dirname "$0")

claude -p "$(cat "$dir/prompt.md")" \
  --model "${TRIAGE_MODEL:-sonnet}" \
  --max-turns "${TRIAGE_MAX_TURNS:-25}" \
  --tools 'Read,Glob,Grep,WebFetch' \
  --allowedTools 'Read(./**)' 'WebFetch(domain:heano.io)' 'WebFetch(domain:usebasin.com)' \
  --permission-mode dontAsk \
  --setting-sources project \
  --strict-mcp-config \
  --no-session-persistence \
  --output-format json \
  --json-schema "$(cat "$dir/schema.json")" \
  > .triage/result.json || true

jq '{is_error, subtype, num_turns, duration_ms, denied: [.permission_denials[]?.tool_name]}' .triage/result.json
jq -e '.structured_output // empty' .triage/result.json > .triage/proposal.json
