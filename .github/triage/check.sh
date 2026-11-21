#!/usr/bin/env bash
# Checks .triage/proposal.json before anything is posted. Prints one problem
# per line and exits 1 if there are any. SECRETS may hold newline-separated
# values that must never appear in a proposal.
set -uo pipefail

p=.triage/proposal.json
problems=()

if jq -e '.api_error_status == 401' .triage/result.json > /dev/null 2>&1; then
  echo "Claude's login was refused: CLAUDE_CODE_OAUTH_TOKEN has expired or been revoked. See .github/triage/README.md."
  exit 1
fi
if ! jq -e 'type == "object"' "$p" > /dev/null 2>&1; then
  echo 'No proposal: the run failed or ran out of turns.'
  exit 1
fi

while IFS= read -r secret; do
  if [ -n "$secret" ] && grep -qF -- "$secret" "$p"; then
    problems+=('Contains a secret.')
  fi
done <<< "${SECRETS:-}"
if grep -qE 'sk-ant-|gh[opsu]_[A-Za-z0-9]{20}|github_pat_' "$p"; then
  problems+=('Contains something that looks like a token.')
fi

reply=$(jq -r '.reply // ""' "$p")
others=$(grep -oE '(^|[^A-Za-z0-9`/])@[A-Za-z0-9][A-Za-z0-9-]*' <<< "$reply" | sed 's/.*@/@/' | grep -vx '@peteheaney' | sort -u | tr '\n' ' ')
[ -n "$others" ] && problems+=("Mentions someone other than Pete: $others")

mentions=$(grep -qE '(^|[^A-Za-z0-9`/])@peteheaney\b' <<< "$reply" && echo true || echo false)
[ "$mentions" != "$(jq -r '.mention_maintainer' "$p")" ] && problems+=("mention_maintainer doesn't match the reply.")

[ "${#reply}" -gt 4000 ] && problems+=('Reply is over 4,000 characters.')

if jq -e '.close == "duplicate" and .duplicate_of == null' "$p" > /dev/null; then
  problems+=('Closes as a duplicate without saying of what.')
fi
if jq -e '.close != null and .kind == "feature-request"' "$p" > /dev/null; then
  problems+=('Closes a feature request.')
fi

if [ "${#problems[@]}" -gt 0 ]; then
  printf '%s\n' "${problems[@]}"
  exit 1
fi
