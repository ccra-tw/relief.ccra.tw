#!/bin/sh
# 部署：主機自己從 GitHub 拉（比照 it.ccra.tw；主機在內網、沒有對外入口，公開 repo 不掛 self-hosted runner）。
# 用法（root）：sh /opt/relief.ccra.tw/deploy/deploy.sh [--force]
set -eu
REPO=/opt/relief.ccra.tw
WEB=/var/www/relief.ccra.tw
cd "$REPO"
git fetch -q origin main
[ "$(git rev-parse HEAD)" = "$(git rev-parse origin/main)" ] && [ "${1:-}" != "--force" ] && exit 0
git reset -q --hard origin/main
mkdir -p "$WEB" /var/lib/relief
chown www-data:www-data /var/lib/relief
rsync -a --delete --exclude 'tests/' --exclude '.git/' --exclude 'deploy/' --exclude 'docs/' "$REPO/" "$WEB/"
chown -R root:www-data "$WEB"
# schema 版本：用網站的身分跑（讀得到 /etc/relief/db.ini）
sudo -u www-data php "$WEB/bin/migrate.php"
# 每日提醒排程（#9）。cron.d 檔名不可含點，權限 644、root 所有
install -m 644 -o root -g root "$REPO/deploy/relief-reminders.cron" /etc/cron.d/relief-reminders
install -m 644 -o root -g root "$REPO/deploy/relief-backup.cron" /etc/cron.d/relief-backup
touch /var/log/relief-reminders.log && chown www-data:www-data /var/log/relief-reminders.log
logger -t relief-deploy "deployed $(git rev-parse --short HEAD)"
echo "deployed $(git rev-parse --short HEAD)"
