#!/bin/sh
#
# Enables the app and performs the initial setup, just after the installation
# of Nextcloud. Downloading the model needs network access, and can take a
# couple of minutes.
#
set -eu

FACERECOGNITION_MEMORY="${FACERECOGNITION_MEMORY:-1G}"
FACERECOGNITION_MODEL="${FACERECOGNITION_MODEL:-1}"
# 1024x1024. It must fit in the memory assigned above.
FACERECOGNITION_IMAGE_AREA="${FACERECOGNITION_IMAGE_AREA:-1048576}"
NEXTCLOUD_ADMIN_USER="${NEXTCLOUD_ADMIN_USER:-admin}"

if [ "$(id -u)" = 0 ]; then
	run_as() { su -p www-data -s /bin/sh -c "$1"; }
else
	run_as() { sh -c "$1"; }
fi

occ() { run_as "php /var/www/html/occ $1"; }

echo "facerecognition: enabling the app…"
occ "app:enable --force facerecognition"

echo "facerecognition: assigning ${FACERECOGNITION_MEMORY} of memory…"
occ "face:setup --memory ${FACERECOGNITION_MEMORY}"

echo "facerecognition: installing the model ${FACERECOGNITION_MODEL}…"
occ "face:setup --model ${FACERECOGNITION_MODEL}"

# Image area used for the temporary files. Without it, the analysis refuses to
# start, and it is normally configured from the admin settings panel.
echo "facerecognition: using ${FACERECOGNITION_IMAGE_AREA} pixels² of image area…"
occ "config:app:set facerecognition analysis_image_area --value=${FACERECOGNITION_IMAGE_AREA}"

# Each user has to opt in to the analysis from their personal settings. Do it
# for the admin user, so the instance is ready to analyze right away.
echo "facerecognition: enabling the analysis for the user ${NEXTCLOUD_ADMIN_USER}…"
occ "user:setting ${NEXTCLOUD_ADMIN_USER} facerecognition enabled true"

occ "face:setup"
