#!/usr/bin/env bash
# Run a deploy on an EC2 host through SSM Run Command and wait for the result.
# Used by .github/workflows/deploy.yml. No SSH: the host runs the script itself.
#
# Usage: ssm-deploy.sh <instance-id> <git-sha> <deploy-script>
#   e.g. ssm-deploy.sh i-0123456789abcdef0 "$GITHUB_SHA" scripts/deploy-dev.sh
set -euo pipefail
INSTANCE=${1:?instance id}
SHA=${2:?git sha}
SCRIPT=${3:?deploy script, e.g. scripts/deploy-dev.sh}
REPO_URL=https://github.com/CITANZ/citanz-website-v2.git
APP_DIR=/srv/citanz

[[ $SHA =~ ^[0-9a-f]{40}$ ]] || { echo "bad sha: $SHA" >&2; exit 1; }
[[ $SCRIPT =~ ^scripts/[a-z0-9-]+\.sh$ ]] || { echo "bad script: $SCRIPT" >&2; exit 1; }

# Deploy exactly the commit that triggered the run, from the CITANZ repo.
CMD="set -e; cd $APP_DIR; git remote set-url origin $REPO_URL; git fetch -q origin master; git reset -q --hard $SHA; git log --oneline -1; $SCRIPT"
PARAMS=$(jq -n --arg c "$CMD" '{commands: [$c], executionTimeout: ["2400"]}')

ID=$(aws ssm send-command \
  --instance-ids "$INSTANCE" \
  --document-name AWS-RunShellScript \
  --comment "GitHub Actions deploy ${SHA:0:7}" \
  --timeout-seconds 600 \
  --parameters "$PARAMS" \
  --query Command.CommandId --output text)
echo "SSM command $ID"

# Wait up to 45 minutes (frontend build on a t3.small can take ~10).
STATUS=Pending
for _ in $(seq 1 270); do
  sleep 10
  STATUS=$(aws ssm get-command-invocation --command-id "$ID" --instance-id "$INSTANCE" \
    --query Status --output text 2>/dev/null || echo Pending)
  case $STATUS in Success|Failed|Cancelled|TimedOut) break ;; esac
done

aws ssm get-command-invocation --command-id "$ID" --instance-id "$INSTANCE" \
  --query StandardOutputContent --output text | tail -40
echo "status: $STATUS"
[ "$STATUS" = Success ] || {
  aws ssm get-command-invocation --command-id "$ID" --instance-id "$INSTANCE" \
    --query StandardErrorContent --output text | tail -40 >&2
  exit 1
}
