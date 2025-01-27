#!/bin/bash

# Cronjob:
# */20 * * * * /bin/bash /opt/offpost/app/infrastructure/production/deploy-cronjob.sh >> /opt/offpost/logs/deploy-cronjob.log 2>&1

# Navigate to the project directory
cd /opt/offpost/app || exit

# Fetch the latest changes from the remote repository
git fetch origin main

# Check if there are new commits
LOCAL_HASH=$(git rev-parse HEAD)
REMOTE_HASH=$(git rev-parse origin/main)

if [ "$LOCAL_HASH" != "$REMOTE_HASH" ]; then
  # New commits detected, proceed with deployment
  echo "[$(date)] New commits detected. Pulling changes and redeploying..."

  # Pull the latest changes
  git pull origin main

  # Build organizer image locally (it has its own Dockerfile)
  echo "[$(date)] Building organizer image..."
  docker compose -f docker-compose.prod.yaml build organizer

  # Pull other images from registry
  echo "[$(date)] Pulling other images..."
  docker compose -f docker-compose.prod.yaml pull

  # Restart all services
  echo "[$(date)] Restarting services..."
  docker compose -f docker-compose.prod.yaml up -d

  echo "[$(date)] Deployment completed."
fi
