#!/usr/bin/env bash
# Install the nightly backup as a systemd timer on the production host.
# Every night at 02:30 NZ time: database dump and uploaded files
# (public/assets) go to the private backup bucket from infra/prod.yml, then a
# CITANZ/BackupSucceeded metric is sent; an alarm in infra/prod.yml fires when
# it is missing. The server role may only put objects in the bucket and put
# metrics in the CITANZ namespace: it cannot read or delete backups.
#
# Usage (as root, from the repo root; scripts/deploy-prod.sh calls it):
#   scripts/install-backup.sh BUCKET "-f docker-compose.yml -f docker-compose.prod.yml"
# Run once now:  systemctl start citanz-backup.service
# Logs:          journalctl -u citanz-backup.service
# Restore:       see docs/restore.md
set -euo pipefail
cd "$(dirname "$0")/.."
BUCKET=${1:?backup bucket name}
COMPOSE_FILES=${2:?compose file flags}
DIR=$(pwd)

cat > /usr/local/sbin/citanz-backup <<SCRIPT
#!/usr/bin/env bash
# Written by scripts/install-backup.sh; edit that, not this.
set -euo pipefail
cd "$DIR"
REGION=ap-southeast-2
STAMP=\$(date -u +%Y-%m-%dT%H%MZ)
C="docker compose $COMPOSE_FILES"

# Database: consistent InnoDB snapshot without locking the site.
\$C exec -T database sh -c 'MYSQL_PWD="\$MYSQL_ROOT_PASSWORD" mysqldump -uroot --single-transaction --quick --routines --triggers --default-character-set=utf8mb4 "\$MYSQL_DATABASE"' \\
  | gzip -6 \\
  | aws s3 cp --region \$REGION --only-show-errors - "s3://$BUCKET/db/citanz-\$STAMP.sql.gz"

# Uploaded files.
tar -C public -czf - assets \\
  | aws s3 cp --region \$REGION --only-show-errors - "s3://$BUCKET/assets/assets-\$STAMP.tar.gz"

aws cloudwatch put-metric-data --region \$REGION --namespace CITANZ --metric-name BackupSucceeded --value 1
echo "backup \$STAMP done"
SCRIPT
chmod 700 /usr/local/sbin/citanz-backup

cat > /etc/systemd/system/citanz-backup.service <<'UNIT'
[Unit]
Description=CITANZ nightly backup to S3
After=docker.service
Requires=docker.service

[Service]
Type=oneshot
ExecStart=/usr/local/sbin/citanz-backup
UNIT

cat > /etc/systemd/system/citanz-backup.timer <<'UNIT'
[Unit]
Description=CITANZ nightly backup to S3

[Timer]
OnCalendar=*-*-* 02:30:00 Pacific/Auckland
Persistent=true
RandomizedDelaySec=10m

[Install]
WantedBy=timers.target
UNIT

systemctl daemon-reload
systemctl enable --now citanz-backup.timer >/dev/null
systemctl list-timers citanz-backup.timer --no-pager | head -3
