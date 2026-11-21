#!/usr/bin/env bash
# Shadow mode: posts what a triage run would have done to an issue in the
# internal repository, one internal issue per public one, one comment per run.
# Nothing is posted in the public repository.
#
# Needs GH_TOKEN (issues: write on INTERNAL_REPO), INTERNAL_REPO, RUN_URL,
# TRIAGE_RESULT (the triage job's result), and either ISSUE, ISSUE_TITLE and
# ISSUE_URL, or FIXTURE. Optional: PROPOSAL (base64 JSON), PROBLEMS (from
# check.sh), NOTE (from gate.sh).
set -euo pipefail

if [ -n "${FIXTURE:-}" ]; then
  title="Triage test: $FIXTURE"
  about="Test run on the \`$FIXTURE\` fixture in \`.github/triage/fixtures\`."
else
  title="Triage: craft-basin#$ISSUE"
  about="Shadow triage of $ISSUE_URL ($ISSUE_TITLE). Each comment is one run, showing what it would have done in the public repo. Nothing was posted there."
fi

gh label create triage-shadow --repo "$INTERNAL_REPO" --color c5def5 \
  --description 'What the triage job would have done' > /dev/null 2>&1 || true

number=$(gh issue list --repo "$INTERNAL_REPO" --state all --label triage-shadow --limit 200 \
  --json number,title --jq ".[] | select(.title == $(jq -Rn --arg t "$title" '$t')) | .number" | head -1)
if [ -z "$number" ]; then
  url=$(gh issue create --repo "$INTERNAL_REPO" --title "$title" --label triage-shadow --body "$about")
  number=${url##*/}
fi

# Mentions in a quoted reply would notify people from the internal repo.
defuse() { sed -E 's/(^|[^A-Za-z0-9`])@([A-Za-z0-9][A-Za-z0-9-]*)/\1`@\2`/g'; }
quote() { sed 's/^/> /'; }

{
  echo "**[Run]($RUN_URL)**: $TRIAGE_RESULT"
  echo
  if [ -n "${PROPOSAL:-}" ]; then
    p=$(base64 -d <<< "$PROPOSAL")
    jq -r '
      "**\(.kind)**, \(.confidence) confidence"
      + (if .close then " · would close as \(.close)" + (if .duplicate_of then " of #\(.duplicate_of)" else "" end) else "" end)
      + (if (.add_labels | length) > 0 then " · +" + (.add_labels | join(" +")) else "" end)
      + (if (.remove_labels | length) > 0 then " · −" + (.remove_labels | join(" −")) else "" end)
      + (if .mention_maintainer then " · mentions Pete" else "" end)
      + (if .handoff then " · hands off to \(.handoff.job)" else "" end)' <<< "$p"
    echo
    echo '**Summary**'
    echo
    jq -r '.summary' <<< "$p" | defuse
    echo
    echo '**Reply it would post**'
    echo
    if jq -e '.reply' <<< "$p" > /dev/null; then
      jq -r '.reply' <<< "$p" | defuse | quote
    else
      echo '_None._'
    fi
    if jq -e '.handoff' <<< "$p" > /dev/null; then
      echo
      echo '**Hand-off brief**'
      echo
      jq -r '.handoff.brief' <<< "$p" | defuse | quote
    fi
  fi
  if [ -n "${PROBLEMS:-}" ]; then
    echo
    echo '**Rejected by the checks, so in live mode nothing would be posted:**'
    echo
    defuse <<< "$PROBLEMS" | sed 's/^/- /'
  fi
  case ${NOTE:-} in
    issue-cap) echo; echo '_This was the last run allowed on this issue. Live, the job would now mention Pete and stop._' ;;
    daily-cap) echo; echo '_This was the last run allowed in 24 hours. Later events get no run until the count drops._' ;;
  esac
} > /tmp/triage-comment.md

gh issue comment "$number" --repo "$INTERNAL_REPO" --body-file /tmp/triage-comment.md > /dev/null
echo "Posted to $INTERNAL_REPO#$number"
