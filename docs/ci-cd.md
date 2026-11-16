# CI/CD: deploy on merge

Status: **draft for review** (owner: 小吕). Nothing runs until the one-time setup below is done.

## What happens

```
merge a PR into master
   │
   ▼
GitHub Actions: .github/workflows/deploy.yml
   │
   ├─▶ job "dev": get short-lived AWS credentials (OIDC)
   │      → SSM Run Command on the dev EC2:
   │        git reset to the merged commit, run scripts/deploy-dev.sh
   │      → check https://dev.cita.org.nz answers
   │
   ▼
job "production": waits until a person approves it in GitHub
       → same steps on the production EC2 with scripts/deploy-prod.sh
       (off until PROD_ENABLED=true, see below)
```

No SSH and no AWS keys stored in GitHub. The server runs the deploy itself
through AWS Systems Manager, the same way we deploy dev by hand today.

## Files

| File | What it is |
|---|---|
| `.github/workflows/deploy.yml` | The workflow: dev job, then production job with approval |
| `.github/scripts/ssm-deploy.sh` | Sends the deploy command to the EC2 through SSM and waits for the result |
| `infra/github-deploy-role.yml` | CloudFormation for the IAM role GitHub assumes. It can only run `AWS-RunShellScript` on one instance |
| `scripts/deploy-dev.sh` | Runs on the dev server: `.env` from Parameter Store, build, start, daily tasks |

## One-time setup

1. **AWS (Alex, needs IAM):** deploy `infra/github-deploy-role.yml`
   - nonprod: `GitHubEnvironment=dev`, `InstanceId=<dev EC2 id>`
   - production (later): `GitHubEnvironment=production`, `InstanceId=<prod EC2 id>`
   - If the account already has the GitHub OIDC provider, set `CreateOidcProvider=false`.
   - Note the output `RoleArn`.
2. **GitHub (repo admin):** Settings → Environments
   - `dev`: variables `AWS_ROLE_ARN` (from step 1) and `INSTANCE_ID`.
   - `production`: the same two variables, plus **Required reviewers** (who may approve a production deploy).
3. **GitHub:** Settings → Secrets and variables → Actions → Variables:
   `PROD_ENABLED=true`, only when the production server and `scripts/deploy-prod.sh` exist.

## Test it

Actions → Deploy → **Run workflow** (on `master`). The dev job should end green
and the site should show the merged commit.

## Open points

- The frontend is built on the server. Fine for dev; on a 2 GB production server
  it competes with the live site. Later: build in GitHub and ship the result.
- `scripts/deploy-prod.sh` does not exist yet; it comes with the production stack.
