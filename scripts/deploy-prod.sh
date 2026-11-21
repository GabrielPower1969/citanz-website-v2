#!/usr/bin/env bash
# Deploy/refresh production (www.cita.org.nz). Runs ON the production EC2 as
# root, through SSM (CI/CD or an administrator), from the repo checkout.
# Usage: scripts/deploy-prod.sh
#
# Settings come from Parameter Store under PARAM_PATH (default /citanz/prod);
# the instance role (infra/prod-ec2-role.yml) can read only that path.
#   required: SS_DATABASE_PASSWORD, OAUTH_ENCRYPTION_KEY, Stripe
#   optional: AWS_ACCESS_KEY_ID, AWS_SECRET_ACCESS_KEY, AWS_ACCESS_KEY_ID_FE,
#             AWS_SECRET_ACCESS_KEY_FE, AWS_BUCKET_NAME, AWS_REGION (CV uploads)
#   written by infra/prod.yml: BACKUP_BUCKET
# A nonprod trial of infra/prod.yml can run this with
#   PARAM_PATH=/citanz/trial SITE_DOMAIN=... APEX_DOMAIN=... MAIL_FROM=... scripts/deploy-prod.sh
set -euo pipefail
cd "$(dirname "$0")/.."
PARAM_PATH=${PARAM_PATH:-/citanz/prod}
DOMAIN=${SITE_DOMAIN:-www.cita.org.nz}
APEX=${APEX_DOMAIN:-cita.org.nz}
MAIL_FROM=${MAIL_FROM:-noreply@cita.org.nz}
REGION=ap-southeast-2
C="docker compose -f docker-compose.yml -f docker-compose.prod.yml"

param(){ aws ssm get-parameter --region $REGION --with-decryption --name "$PARAM_PATH/$1" --query Parameter.Value --output text; }
# Optional parameter: empty if it does not exist; any other error stops the deploy.
param_opt(){
  local out
  if out=$(param "$1" 2>&1); then printf '%s' "$out"
  elif [[ $out == *ParameterNotFound* ]]; then :
  else echo "$out" >&2; return 1; fi
}
# Values go into .env inside double quotes; refuse characters that would break that.
check_value(){
  case $2 in *\"*|*\\*|*\$*|*\`*|*$'\n'*) echo "refusing to deploy: $PARAM_PATH/$1 contains a quote, backslash, \$, backtick or newline" >&2; exit 1 ;; esac
}

echo "== .env from Parameter Store ($PARAM_PATH)"
umask 077
DBPW=$(param SS_DATABASE_PASSWORD); check_value SS_DATABASE_PASSWORD "$DBPW"
OAUTH=$(param OAUTH_ENCRYPTION_KEY); check_value OAUTH_ENCRYPTION_KEY "$OAUTH"
# Stripe: JSON {"public":"pk_...","apiKey":"sk_..."}, read by the site as env "Stripe".
STRIPE=$(param Stripe)
case $STRIPE in *\'*) echo "refusing to deploy: $PARAM_PATH/Stripe contains a single quote" >&2; exit 1 ;; esac
if [ "$PARAM_PATH" = /citanz/prod ]; then
  case $STRIPE in
    *sk_live_*) ;;
    *) echo "refusing to deploy: $PARAM_PATH/Stripe is not a live key" >&2; exit 1 ;;
  esac
fi
BACKUP_BUCKET=$(param BACKUP_BUCKET); check_value BACKUP_BUCKET "$BACKUP_BUCKET"

cat > .env <<ENV
SS_DATABASE_CLASS="MySQLDatabase"
SS_DATABASE_SERVER="database"
SS_DATABASE_USERNAME="root"
SS_DATABASE_PASSWORD="$DBPW"
SS_DATABASE_NAME="citanz"
SS_ERROR_LOG="silverstripe.log"
SS_ENVIRONMENT_TYPE="live"
SS_BASE_URL="https://$DOMAIN"
SS_TRUSTED_PROXY_IPS="*"
OAUTH_ENCRYPTION_KEY="$OAUTH"
Stripe='$STRIPE'
# Mail through the SES API with the instance role (app/_config/email.yml).
SS_SES_REGION="$REGION"
# The server role may only send from @cita.org.nz. Most mail already comes
# from noreply@cita.org.nz; activation and password-recovery mail would use
# the old default sender (admin_email) and be rejected. This sets the sender
# of every mail; recipients are not changed.
SS_SEND_ALL_EMAILS_FROM="$MAIL_FROM"
ENV
# CV uploads (Leo's existing bucket and keys, unchanged for the 1:1 move).
# The SES client ignores these and uses the instance role (SesClientFactory).
for k in AWS_ACCESS_KEY_ID AWS_SECRET_ACCESS_KEY AWS_ACCESS_KEY_ID_FE AWS_SECRET_ACCESS_KEY_FE AWS_BUCKET_NAME AWS_REGION; do
  v=$(param_opt $k)
  if [ -n "$v" ]; then check_value $k "$v"; echo "$k=\"$v\"" >> .env; else echo "note: $PARAM_PATH/$k not set"; fi
done
# root + www-data (gid 33 in the web container) only
chown 0:33 .env && chmod 640 .env
mkdir -p .local
sed -e "s|__DOMAIN__|$DOMAIN|" -e "s|__APEX__|$APEX|" docker/Caddyfile.prod.template > .local/Caddyfile
umask 022

echo "== metadata guard"
# Same as scripts/deploy-dev.sh: the instance allows 2 hops to the EC2
# metadata service so the web container can use the instance role; this rule
# blocks every other container. systemd re-adds it whenever Docker starts.
WEB_IP=172.30.0.10
RULE="DOCKER-USER -d 169.254.169.254/32 ! -s $WEB_IP/32 -j REJECT"
cat > /etc/systemd/system/citanz-imds-guard.service <<UNIT
[Unit]
Description=Let only the CITANZ web container reach the EC2 metadata service
After=docker.service
Requires=docker.service
PartOf=docker.service

[Service]
Type=oneshot
RemainAfterExit=yes
ExecStart=/bin/sh -c 'iptables -C $RULE 2>/dev/null || iptables -I $RULE'

[Install]
WantedBy=docker.service
UNIT
systemctl daemon-reload
systemctl enable citanz-imds-guard.service >/dev/null
systemctl restart citanz-imds-guard.service

# 2 GB RAM is tight for webpack + MySQL; add 2 GB swap once
if ! swapon --show | grep -q /swapfile; then
  [ -f /swapfile ] || { fallocate -l 2G /swapfile && chmod 600 /swapfile && mkswap /swapfile >/dev/null; }
  swapon /swapfile; grep -q /swapfile /etc/fstab || echo '/swapfile none swap sw 0 0' >> /etc/fstab
fi

echo "== build + start"
# The site's log file (SS_ERROR_LOG). The web server runs as www-data (33) and
# cannot create files in the checkout; without this file every logged warning
# or error turns into a fatal error (HTTP 500). root (sake, daily tasks) can
# still write to it.
touch silverstripe.log && chown 33:33 silverstripe.log && chmod 640 silverstripe.log
$C build web
$C up -d database web
$C exec -T web sh docker/composer-seed/seed.sh
$C exec -T web composer install --no-interaction --no-progress --no-dev 2>&1 | tail -2

echo "== OAuth (member login)"
# Signing keys for SS_ENVIRONMENT_TYPE=live; made once, stay on the box (git-ignored).
# Copy them from SiteHost at cutover so existing member sessions stay valid.
# (Single quotes on purpose in the next two commands: they expand inside the containers.)
# shellcheck disable=SC2016
$C exec -T web sh -c 'd=app/certs/live; mkdir -p $d; [ -f $d/private.key ] || { openssl genrsa -out $d/private.key 2048 2>/dev/null && openssl rsa -in $d/private.key -pubout -out $d/public.key 2>/dev/null; }; chown www-data $d/*.key; chmod 600 $d/*.key'
# shellcheck disable=SC2016
CLIENT_ID=$($C exec -T database sh -c 'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" mysql -uroot -N "$MYSQL_DATABASE" -e "SELECT GUID FROM OAuth_Client WHERE Revoked=0 ORDER BY ID LIMIT 1"' 2>/dev/null || true)
printf 'VUE_APP_OAUTH_CLIENT_ID=%s\nVUE_APP_OAUTH_CLIENT_SECRET=\n' "$CLIENT_ID" > .env.local
[ -n "$CLIENT_ID" ] || echo "WARNING: no OAuth client in the DB yet; member login will not work until the data is loaded"

echo "== frontend"
$C run --rm --user 0:0 node
$C exec -T web composer vendor-expose 2>&1 | tail -1
$C exec -T web vendor/bin/sake dev/build flush=all >/dev/null
$C up -d --force-recreate caddy

echo "== nightly backup (systemd timer)"
scripts/install-backup.sh "$BACKUP_BUCKET" "-f docker-compose.yml -f docker-compose.prod.yml"

# Daily tasks, once scripts/install-cron.sh is in (the daily tasks PR).
if [ -x scripts/install-cron.sh ]; then
  echo "== daily tasks (systemd timers)"
  scripts/install-cron.sh "-f docker-compose.yml -f docker-compose.prod.yml"
fi
echo "done -> https://$DOMAIN"
