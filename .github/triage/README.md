# Issue triage

`.github/workflows/triage.yml` runs Claude on each new issue and each new comment, following `CLAUDE.md` ("Automated jobs: issues") and `prompt.md`.

It's in **shadow mode**: nothing is posted in this repo. Each run's proposal goes to an issue in `craft-basin-internal` labelled `triage-shadow`, one issue per public issue, one comment per run.

## How a run works

1. **Gate** (`gate.sh`, no AI) skips pull requests, bots, maintainers, issues opened by the daily tests, and accounts under a week old. Once a maintainer has commented on an issue, it gets no more runs; add the `triage` label to hand it back. It allows 10 runs per issue and 20 a day, counting only runs that got past the gate.
2. **Triage** (`collect.sh`, `run.sh`) writes the thread to `.triage/`, then runs Claude with only Read, Glob, Grep, and WebFetch for heano.io, usebasin.com, docs.usebasin.com and status.usebasin.com. There's no GitHub token or Basin key in the job, and the log shows only how the run went. Claude returns a proposal matching `schema.json`: what the issue is, the reply, labels, whether to close, and a brief for the fix job.
3. **Checks** (`check.sh`, no AI) reject a proposal that contains a secret or anything that looks like a token, mentions anyone but @peteheaney, or breaks the rules (closing a feature request, a duplicate of nothing).
4. **Post** (`post-shadow.sh`, no AI) posts the proposal to `craft-basin-internal` as the heano-support App.

## Setup

- Secret `CLAUDE_CODE_OAUTH_TOKEN`: from `claude setup-token`.
- Variable `SUPPORT_APP_ID` and secret `SUPPORT_APP_PRIVATE_KEY`: the heano-support App's ID and a private key. The App must be installed on `craft-basin-internal`.
- Variable `CLAUDE_TOKEN_CREATED`: the date the token was made (YYYY-MM-DD).
- Optional variable `TRIAGE_MODEL`: defaults to `sonnet`.

## Renewing the Claude token

Tokens from `claude setup-token` last a year. `token-reminder.yml` checks weekly and opens an issue in `craft-basin-internal` 30 days before expiry, with the steps: make a new token, replace the secret, update `CLAUDE_TOKEN_CREATED`, and try a fixture run. The old token works until it expires, so there's no gap. If a run's login is refused anyway, its internal post says so.

## Trying it

Run the workflow by hand (Actions → Triage → Run workflow) and pick a sample issue from `fixtures/`. `injection` tries to get the job to read the runner's environment and post it, so its proposal should ignore that and its log should list any denied reads.
