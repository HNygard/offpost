#!/bin/sh
# Make /php-frontend/vendor match the vendor/ built into this image.
#
# docker-compose mounts organizer/src over /php-frontend and an anonymous volume
# over /php-frontend/vendor, so the image's vendor/ is only copied into that
# volume when the volume is first created. "docker compose up -d" carries the
# volume over when it recreates the container, so without this sync the running
# code keeps the packages from whenever the volume was created, and composer
# changes in new images never take effect.
#
# The Dockerfile keeps a copy of vendor/ in /opt/offpost/vendor with a stamp of
# the installed packages and autoloaders. When the stamp in the volume differs,
# the volume's content is replaced.
set -e

IMAGE_VENDOR="${IMAGE_VENDOR:-/opt/offpost/vendor}"
APP_VENDOR="${APP_VENDOR:-/php-frontend/vendor}"
STAMP=.image-vendor-stamp

if [ ! -f "$IMAGE_VENDOR/$STAMP" ]; then
    echo "ERROR: $IMAGE_VENDOR/$STAMP missing - image built without the vendor copy" >&2
    exit 1
fi

if cmp -s "$IMAGE_VENDOR/$STAMP" "$APP_VENDOR/$STAMP"; then
    echo "vendor/ matches the image"
    exit 0
fi

echo "Updating $APP_VENDOR from the image..."
mkdir -p "$APP_VENDOR"
find "$APP_VENDOR" -mindepth 1 -delete
cp -a "$IMAGE_VENDOR/." "$APP_VENDOR/"
echo "vendor/ updated"
