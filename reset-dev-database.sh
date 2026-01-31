#!/bin/bash
set -e

echo "Resetting development database..."

docker-compose -f docker-compose.dev.yaml rm -s -f -v postgres organizer
docker volume rm offpost_postgres_data_development 2>/dev/null || true
docker-compose -f docker-compose.dev.yaml up -d

echo "Waiting for migrations..."
sleep 5

echo "Migration logs:"
docker-compose -f docker-compose.dev.yaml logs organizer | grep "\[migrate\]"

echo "Done. Database reset complete."
