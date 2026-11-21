#!/usr/bin/env bash
# Decides whether an issue event gets a triage run, and enforces the caps.
#
# Needs GH_TOKEN (actions: read, issues: read), GH_REPO, GITHUB_EVENT_NAME, GITHUB_EVENT_PATH,
# GITHUB_RUN_ID and GITHUB_OUTPUT. Writes `run` (true or false), `reason`, and
# `note` (issue-cap or daily-cap) on the run that uses up a cap, so it's
# reported once.
set -euo pipefail

ISSUE_CAP=${ISSUE_CAP:-10}
DAILY_CAP=${DAILY_CAP:-20}
MIN_ACCOUNT_AGE_DAYS=${MIN_ACCOUNT_AGE_DAYS:-7}
WORKFLOW=${WORKFLOW:-triage.yml}
TRIAGE_JOB=${TRIAGE_JOB:-Triage}

event=$GITHUB_EVENT_PATH
out() { echo "$1=$2" >> "$GITHUB_OUTPUT"; }
skip() { echo "No triage run: $1"; out run false; out reason "$1"; exit 0; }

number=$(jq -r '.issue.number' "$event")

if jq -e '.issue.pull_request' "$event" > /dev/null; then
  skip 'pull request'
fi

if [ "$GITHUB_EVENT_NAME" = issue_comment ]; then
  author=.comment
else
  author=.issue
fi
login=$(jq -r "$author.user.login" "$event")
type=$(jq -r "$author.user.type" "$event")
association=$(jq -r "$author.author_association" "$event")

if [ "$type" = Bot ] || [[ "$login" == *'[bot]' ]]; then
  skip 'written by a bot'
fi
case $association in
  OWNER | MEMBER | COLLABORATOR) skip 'written by a maintainer' ;;
esac
if jq -e '.issue.labels[].name | select(. == "daily-tests" or . == "openapi-watch")' "$event" > /dev/null; then
  skip 'opened by an automated job'
fi

# Once a maintainer has replied, the conversation is theirs, unless they
# hand it back with the `triage` label.
if ! jq -e '.issue.labels[].name | select(. == "triage")' "$event" > /dev/null; then
  maintainer=$(gh api --paginate "repos/$GH_REPO/issues/$number/comments?per_page=100" \
    --jq '.[] | select(.user.type != "Bot" and (.author_association == "OWNER" or .author_association == "MEMBER" or .author_association == "COLLABORATOR")) | .user.login' | head -1)
  if [ -n "$maintainer" ]; then
    skip 'a maintainer has replied'
  fi
fi

created=$(gh api "users/$login" --jq '.created_at')
if jq -en --arg c "$created" --argjson d "$MIN_ACCOUNT_AGE_DAYS" '(now - ($c | fromdateiso8601)) < $d * 86400' > /dev/null; then
  skip "account less than $MIN_ACCOUNT_AGE_DAYS days old"
fi

# Runs of this workflow since a time, other than this one and test runs
# started by hand, whose triage job ran (wasn't skipped by this gate).
# Prints "id<TAB>title" per run.
runs_since() {
  gh api --paginate "repos/$GH_REPO/actions/workflows/$WORKFLOW/runs?created=%3E%3D$1&per_page=100" \
    --jq ".workflow_runs[] | select(.id != $GITHUB_RUN_ID and .event != \"workflow_dispatch\") | [.id, .display_title] | @tsv" |
    while IFS=$'\t' read -r id title; do
      conclusion=$(gh api "repos/$GH_REPO/actions/runs/$id/jobs" \
        --jq ".jobs[] | select(.name == \"$TRIAGE_JOB\") | (.conclusion // .status)")
      if [ -n "$conclusion" ] && [ "$conclusion" != skipped ]; then
        printf '%s\t%s\n' "$id" "$title"
      fi
    done
}

issue_created=$(jq -r '.issue.created_at' "$event")
issue_runs=$(runs_since "$issue_created" | awk -F'\t' -v t="Triage #$number" '$2 == t' | wc -l | tr -d ' ')
if [ "$issue_runs" -ge "$ISSUE_CAP" ]; then
  skip "$ISSUE_CAP runs on this issue already"
fi

day_ago=$(jq -rn 'now - 86400 | strftime("%Y-%m-%dT%H:%M:%SZ")')
daily_runs=$(runs_since "$day_ago" | wc -l | tr -d ' ')
if [ "$daily_runs" -ge "$DAILY_CAP" ]; then
  skip "$DAILY_CAP runs in the last 24 hours already"
fi

if [ $((issue_runs + 1)) -eq "$ISSUE_CAP" ]; then
  out note issue-cap
elif [ $((daily_runs + 1)) -eq "$DAILY_CAP" ]; then
  out note daily-cap
fi

echo "Triage run $((issue_runs + 1)) on #$number, $((daily_runs + 1)) in the last 24 hours."
out run true
out reason ''
