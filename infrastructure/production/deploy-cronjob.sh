#!/bin/bash

# Cronjob:
# */10 * * * * /bin/bash /opt/offpost/app/infrastructure/production/deploy-cronjob.sh >> /opt/offpost/logs/deploy-cronjob.log 2>&1

# The whole script is one { } block ending in exit: bash reads the block before running it,
# so the git pull below can rewrite this file without the running copy reading the new one.
{

# Navigate to the project directory
cd /opt/offpost/app || exit

COMPOSE="docker compose -f docker-compose.prod.yaml"

# Scheduled tasks are paused during "up -d" so they are not cut off when containers restart.
# /opt/offpost/run is mounted into the cron container (docker-compose.prod.yaml), where
# cron-scheduler/curl-cron.sh holds a shared lock on curl-cron.lock while it runs and stops
# before its next task once scheduled-tasks-paused exists.
RUN_DIR=/opt/offpost/run
PAUSE_FILE="$RUN_DIR/scheduled-tasks-paused"
CRON_LOCK="$RUN_DIR/curl-cron.lock"
# How long to wait for running tasks before deploying anyway (and alerting admin)
PAUSE_WAIT_SECONDS=600
# How long to wait for the organizer to answer after "up -d" (it runs migrations first)
STARTUP_WAIT_SECONDS=180

# Only one deploy at a time: waiting for tasks can take longer than the 10 minute interval
exec 8>/tmp/offpost-deploy-cronjob.lock || exit
if ! flock -n 8; then
  echo "[$(date)] Previous deploy still running, skipping this run."
  exit
fi

# A deploy must never be blocked by the pause machinery: without a writable RUN_DIR
# (e.g. docker created the mount directory as root), deploy without pausing.
CAN_PAUSE=1
if ! { mkdir -p "$RUN_DIR" && touch "$CRON_LOCK"; } 2>/dev/null; then
  CAN_PAUSE=0
fi

# We hold the deploy lock, so no deploy is running: a pause file left now is stale (a deploy
# killed with SIGKILL or a reboot skips the EXIT trap below). Tasks resume within one interval.
if [ -e "$PAUSE_FILE" ]; then
  rm -f "$PAUSE_FILE" && echo "[$(date)] Removed stale pause file left by an interrupted deploy."
fi

# Email the admins (organizer/src/bin/notify-admin.php). When the organizer container is down
# (e.g. "up -d" failed), fall back to a one-off container from the organizer image.
notify_admin() {
  $COMPOSE exec -T organizer php bin/notify-admin.php deploy-cronjob "$1" \
    || $COMPOSE run --rm --no-deps -T organizer php bin/notify-admin.php deploy-cronjob "$1" \
    || echo "[$(date)] Could not notify admin."
}

# Stop the deploy and alert. The EXIT trap resumes scheduled tasks. Not retried: HEAD is
# already pulled, so the next run sees no new commits; pushing a fix deploys again.
fail() {
  echo "[$(date)] Deploy FAILED: $1"
  notify_admin "Deploy of $LOCAL_HASH..$REMOTE_HASH failed: $1. Not retried; push a fix to deploy again. organizer/src is bind-mounted, so the pulled PHP code is live even if the image build failed. See /opt/offpost/logs/deploy-cronjob.log."
  git rev-parse HEAD > organizer/src/git-sha.txt
  exit 1
}

pause_scheduled_tasks() {
  if [ "$CAN_PAUSE" != 1 ]; then
    echo "[$(date)] WARNING: $RUN_DIR is not writable, deploying without pausing scheduled tasks."
    return
  fi
  echo "[$(date)] Pausing scheduled tasks..."
  # Never leave tasks paused, also when the deploy fails
  trap 'rm -f "$PAUSE_FILE"' EXIT
  touch "$PAUSE_FILE"
  if flock -x -w "$PAUSE_WAIT_SECONDS" "$CRON_LOCK" true; then
    echo "[$(date)] Scheduled tasks stopped."
  else
    echo "[$(date)] Scheduled tasks still running after ${PAUSE_WAIT_SECONDS}s, deploying anyway."
    notify_admin "Scheduled tasks were still running after ${PAUSE_WAIT_SECONDS}s when deploying. Deployed anyway, so running tasks were cut off. Change PAUSE_WAIT_SECONDS in infrastructure/production/deploy-cronjob.sh if the limit is too short."
  fi
}

wait_for_organizer() {
  echo "[$(date)] Waiting for organizer to answer..."
  local waited=0
  # Any HTTP status means Apache is up, which the entrypoint starts after migrations.
  # curl prints 000 when nothing answers; exec prints nothing when cron is not running.
  until [[ "$($COMPOSE exec -T cron curl -s -o /dev/null -w '%{http_code}' http://organizer/)" =~ ^[1-5][0-9][0-9]$ ]]; do
    if [ "$waited" -ge "$STARTUP_WAIT_SECONDS" ]; then
      return 1
    fi
    sleep 5
    waited=$((waited + 5))
  done
}

resume_scheduled_tasks() {
  if [ "$CAN_PAUSE" != 1 ]; then
    return
  fi
  rm -f "$PAUSE_FILE"
  echo "[$(date)] Scheduled tasks resumed."
}

# Fetch the latest changes from the remote repository
git fetch origin main

# Check if there are new commits
LOCAL_HASH=$(git rev-parse HEAD)
REMOTE_HASH=$(git rev-parse origin/main)

if [ "$LOCAL_HASH" != "$REMOTE_HASH" ]; then
  # New commits detected, proceed with deployment
  echo "[$(date)] New commits detected. Pulling changes and redeploying..."

  # Pull the latest changes
  git pull origin main || fail "git pull"

  # Build organizer image locally (it has its own Dockerfile)
  echo "[$(date)] Building organizer image..."
  $COMPOSE build organizer || fail "building organizer image"
  echo "[$(date)] Building cron image..."
  $COMPOSE build cron || fail "building cron image"

  # Pull other images from registry
  echo "[$(date)] Pulling other images..."
  $COMPOSE pull || fail "pulling images"

  pause_scheduled_tasks

  # Restart all services
  echo "[$(date)] Restarting services..."
  $COMPOSE up -d || fail "docker compose up -d"

  wait_for_organizer || fail "organizer not answering ${STARTUP_WAIT_SECONDS}s after up -d"

  resume_scheduled_tasks

  echo "[$(date)] Deployment completed."
fi

# Record the deployed commit so the web app can show it (organizer/src/class/GitVersion.php).
# organizer/src is bind-mounted into the container, so no rebuild is needed to pick it up.
# Runs on every invocation, not only on deploy, so the file also appears on the first run
# after this line was added (the deploy that pulled it still ran the old script).
git rev-parse HEAD > organizer/src/git-sha.txt

exit
}
