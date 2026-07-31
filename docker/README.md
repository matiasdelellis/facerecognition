# Testing the app with Docker

This directory provides a throwaway Nextcloud instance with the FaceRecognition
app and **all its requirements already satisfied** (the `pdlib` PHP extension,
the `bz2` extension, and a PHP memory limit big enough for the models), so you
can check that the app actually works without touching your own instance.

Everything is built from the working copy of the repository: the assets are
built inside the image with the very same steps of the `make build` rule, so
you do not need node, npm nor composer on your host.

## Usage

From the root of the repository:

```bash
docker compose -f docker/compose.yaml up -d --build
```

The first build takes a while (~10 minutes), as `pdlib` bundles dlib and has to
be compiled. The first start also downloads the model (~100MB), so give it a
couple of minutes before the instance is usable. You can follow the progress
with:

```bash
docker compose -f docker/compose.yaml logs -f app
```

Once it is up, open <http://localhost:8080> and log in as `admin` / `admin`.
The app is already enabled, the model is installed, the image area of the
temporary files is configured, and the analysis is enabled for the `admin`
user, so you only need to:

 1. Upload some photos with faces to the Files app.
 2. Run the analysis (see below).

Any other user has to enable the analysis in *Personal settings → Face
Recognition*, as usual, or you can do it from the command line:

```bash
docker compose -f docker/compose.yaml exec --user www-data app \
    php occ user:setting <uid> facerecognition enabled true
```

To run the analysis for the admin user, with a timeout of 15 minutes:

```bash
docker compose -f docker/compose.yaml exec --user www-data app \
    php occ face:background_job -u admin -t 900 --defer-clustering
```

And any other `occ` command works the same way:

```bash
docker compose -f docker/compose.yaml exec --user www-data app php occ face:progress
docker compose -f docker/compose.yaml exec --user www-data app php occ face:stats
docker compose -f docker/compose.yaml exec --user www-data app php occ face:setup
```

Two defaults of the app are worth remembering when the analysis finds nothing:
images smaller than 512px (`min_image_size`) are skipped, and clusters with
less than 5 faces (`min_faces_in_cluster`) are not shown. For a test instance
with a handful of photos you may want to lower the last one:

```bash
docker compose -f docker/compose.yaml exec --user www-data app \
    php occ config:app:set facerecognition min_faces_in_cluster --value=1
```

To stop it, keeping the instance for the next run:

```bash
docker compose -f docker/compose.yaml stop
```

To remove everything, including the Nextcloud instance and the database:

```bash
docker compose -f docker/compose.yaml down -v
```

## Configuration

All of these are optional, and can be exported before calling `docker compose`,
or written to a `.env` file next to `compose.yaml`:

| Variable | Default | Description |
|---|---|---|
| `NEXTCLOUD_VERSION` | `34` | Major version of the Nextcloud image to test against. |
| `NEXTCLOUD_PORT` | `8080` | Port published on the host. |
| `FACERECOGNITION_MEMORY` | `1G` | Memory assigned with `occ face:setup --memory`. |
| `FACERECOGNITION_MODEL` | `1` | Model installed with `occ face:setup --model`. |
| `FACERECOGNITION_IMAGE_AREA` | `1048576` | Image area (in pixels²) used for the temporary files. It has to fit in the memory assigned above: 1024x1024 for the 1GB of the default. |

For example, to test the app with the model 4:

```bash
FACERECOGNITION_MODEL=4 \
    docker compose -f docker/compose.yaml up -d --build
```

Note that the memory limit of PHP inside the container is 2GB
(`PHP_MEMORY_LIMIT` in the `Dockerfile`), so if you assign more memory to the
app you have to raise it too.

Also note that pdlib is built with `-march=native`, so the resulting image is
tuned for the CPU that built it, and is not meant to be pushed to a registry
and used on other machines.

## Rebuilding after a change

The app is baked into the image, so after changing the sources just rebuild and
recreate the container. The instance and its data are kept in volumes, so
nothing is lost:

```bash
docker compose -f docker/compose.yaml up -d --build
```

Note that `custom_apps` is only populated by the entrypoint of the Nextcloud
image when it is empty, so on an already installed instance you have to copy
the app of the new image over the volume:

```bash
docker compose -f docker/compose.yaml run --rm --no-deps --user root app \
    cp -a /usr/src/nextcloud/custom_apps/facerecognition /var/www/html/custom_apps/
```

Alternatively, uncomment the bind mount in `compose.yaml` to serve the working
copy directly. In that case the assets are *not* built for you, and you have to
run `make` on the host at least once.

## What the image does

 * `docker/Dockerfile`
   * builds the assets (webpack, handlebars templates and the vendored
     javascript) in a `node` stage,
   * installs the `bz2` extension and compiles `pdlib` from the same package
     used by the CI of the project, and asserts that both are loaded,
   * copies the app to `/usr/src/nextcloud/custom_apps/facerecognition`, from
     where the entrypoint of the Nextcloud image installs it.
 * `docker/hooks/post-installation/10-facerecognition.sh` runs
   `occ app:enable facerecognition`, the `occ face:setup` calls, and the
   settings needed to analyze (image area and the analysis of the admin user),
   right after Nextcloud is installed.
 * `docker/compose.yaml` wires the app with a MariaDB database and the regular
   Nextcloud cron container.
