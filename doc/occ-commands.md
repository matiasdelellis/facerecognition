# occ commands

The application adds commands to the [Nextcloud's command-line interface](https://docs.nextcloud.com/server/latest/admin_manual/configuration_server/occ_command.html).
All commands can be run with `occ <command>` from the Nextcloud installation
directory, e.g. `sudo -u www-data php occ face:stats`.

This document describes each command exhaustively, including every option and
its behavior.

## Command index

| Command | Purpose |
| --- | --- |
| [face:setup](#face-setup) | Configure the memory limit and install the recognition model |
| [face:background_job](#face-background_job) | Analyze images, extract faces and cluster them |
| [face:sync-albums](#face-sync-albums) | Create/update photo albums per person in the Photos app |
| [face:reset](#face-reset) | Delete analysis data to start over |
| [face:migrate](#face-migrate) | Migrate faces from one model to another |
| [face:stats](#face-stats) | Show a summary of images, faces and persons |
| [face:progress](#face-progress) | Show the progress of the analysis and an ETA |

All the mutating commands (`face:setup`, `face:background_job`, `face:reset`,
`face:migrate`) use an internal command lock: if another one of these is already
running, the command refuses to start with an error message that states which
command holds the lock.

---

## face:setup

**Description:** Basic application settings, such as maximum memory, and the model used.

```
occ face:setup [-M|--memory MEMORY] [-m|--model MODEL]
```

This command is responsible for making the necessary settings to use the
application. You must perform both settings before continuing with any
application command.

### Options

| Option | Description |
| --- | --- |
| `-M, --memory MEMORY` | The maximum memory assigned for image processing. |
| `-m, --model MODEL` | The identifier number of the model to install. |

### Behavior

* If **no option** is supplied, the command prints the current configuration:
  the minimum and maximum assignable memory, the memory currently assigned, and
  a table of the available models with their id, whether they are the enabled
  one (marked with `*`), name and description.
* If **only** `-M/--memory` is supplied, it establishes the maximum memory used
  for image processing.
* If **only** `-m/--model` is supplied, it installs the pre-trained model for
  facial recognition.
* If **both** are supplied, both operations are performed, memory first and then
  the model.

### The memory value

You can use numbers as bytes (`1073741824` for 1GB), or suffixed with units
(`1024M` or `1G`) but note that it is without space. The value is validated
against the system limits:

* It cannot exceed the available memory, which is computed from the system
  memory and the PHP memory limit (`memory_limit`). The command prints both the
  system memory and the memory assigned to PHP before validating.
* It cannot be lower than the minimum assigned memory defined by the
  application (`SettingsService::MINIMUM_ASSIGNED_MEMORY`).

### The model value

The `MODEL` argument is the numeric identifier of the model. Before installing,
the command checks:

1. That the model id is valid (`Invalid model Id` otherwise).
2. That the PHP dependencies of the model are met. If not, it reports the
   summary of missing requirements and a link to the model documentation.
3. Whether the model files are already installed. In that case it does not
   re-download them, and only sets the model as the default one.

After a successful installation the model is configured as default.

### Examples

```bash
# Show the current configuration
occ face:setup

# Assign 2GB of memory for image processing
occ face:setup -M 2G

# Install the model with id 1 and assign 1GB of memory
occ face:setup -m 1 -M 1G
```

---

## face:background_job

**Description:** Equivalent of cron job to analyze images, extract faces and create clusters from found faces.

```
occ face:background_job [-u|--user_id USER_ID] [-t|--timeout TIMEOUT]
    [-M|--max_image_area MAX_IMAGE_AREA] [--sync-mode] [--analyze-mode]
    [--cluster-mode] [--defer-clustering]
```

This command does all the work. It is responsible for searching the images,
analyzing them and clustering faces found in them in groups of similar people.

**Beware that this command can take a lot of CPU and memory!** Before you put it
on a cron job, it is advised to try it out manually first, just to be sure you
have all requirements and you have enough resources on your machine.

The command is designed to be run continuously, so you will want to schedule it
with cron to be executed every once in a while, together with a specified
timeout.

### Options

| Option | Description |
| --- | --- |
| `-u, --user_id USER_ID` | Analyze faces for the given user only. If not given, analyzes images for all users. |
| `-t, --timeout TIMEOUT` | Sets timeout in seconds for this command. Default is without timeout, e.g. command runs indefinitely. |
| `-M, --max_image_area MAX_IMAGE_AREA` | Caps maximum area (in pixels^2) of the image to be fed to the neural network, effectively lowering needed memory. |
| `--sync-mode` | Execute only the actions related to synchronizing the files: new users, shared or deleted files, etc. |
| `--analyze-mode` | Execute only the action of analyzing the images to obtain the faces and their descriptors. |
| `--cluster-mode` | Execute only the action of face clustering to get the people. |
| `--defer-clustering` | Defer the face clustering at the end of the analysis to get persons in a simple execution of the command. |

### Options precedence

The mode options are mutually exclusive and are evaluated in this order:
`--sync-mode`, `--analyze-mode`, `--cluster-mode`, `--defer-clustering`. If more
than one is given, only the first one in that order takes effect. If none is
given, the command runs in its default mode, which performs all the steps.

### Behavior

* **`USER_ID`**: if supplied, it will just loop over files of a given user. The
  user must exist (`User with id <USER_ID> in unknown.` otherwise). Keep in mind
  that each user must enable the analysis individually, and otherwise this
  command will ignore the user.
* **`TIMEOUT`**: if supplied it will stop after the indicated seconds, and
  continue in the next execution. The timeout must be a positive value in
  seconds. Use this value in conjunction with the times of the scheduled task to
  distribute the system load during the day.
* **`MAX_IMAGE_AREA`**: if supplied, caps the maximum area (in pixels^2) of the
  image fed to the neural network. It must be a positive number. Use this if
  face detection crashes randomly.
* **`--defer-clustering`**: changes the order of execution of the process,
  deferring the face clustering at the end of the analysis to get persons in a
  simple execution of the command.

### Locking

Except in `--analyze-mode`, the command acquires a global lock, so only one
background task can run at a time. In `--analyze-mode` it runs in parallel (no
lock is acquired), which allows running several analyzers concurrently.

### Scheduling examples

It can be run every 15 minutes with timeout of `-t 900` (so it will stop itself
automatically after 15 minutes and cron will start it again), or once a day with
timeout of 2 hours, like `-t 7200`:

```bash
# Run for a single user for at most 15 minutes
occ face:background_job -u new_user -t 900

# Full run, at most 2 hours
occ face:background_job -t 7200

# Only analyze images (no clustering), without lock, for user alice
occ face:background_job -u alice --analyze-mode

# Cap the image area to lower memory usage
occ face:background_job -M 250000
```

---

## face:sync-albums

**Description:** Synchronize the people found with the photo albums.

```
occ face:sync-albums [-u|--user_id USER_ID] [-l|--list_person]
    [-p|--person_name PERSON_NAME] [-m|--mode MODE]
```

This command creates photo albums within the Nextcloud Photos app, with photos
of each person found. The Photos app must be enabled, otherwise the command
fails with `The photos app is disabled.`.

This command is designed to be run regularly to sync any user changes, as this
command is the only one that will update albums. Note that these albums are
editable in the Photos app, and any changes will be ignored and reverted on the
next run of this command.

### Options

| Option | Description |
| --- | --- |
| `-u, --user_id USER_ID` | Sync albums for a given user only. If not given, sync albums for all users. |
| `-l, --list_person` | List all persons defined for the given user_id. |
| `-p, --person_name PERSON_NAME` | Sync albums for a given user and person name(s) (separate using comma). If not used, sync albums for all persons defined by the user. |
| `-m, --mode MODE` | Album creation mode: `album-per-person` (default) or `album-combined`. |

### Behavior

* **`USER_ID`**: if provided, it will just sync albums for this user. The user
  must exist. If not provided, albums are synced for all users.
* **`--list_person`**: requires `-u/--user_id`, otherwise it fails with `List
  option requires option user_id!`. It prints the names of all persons defined
  for the user, comma separated, and exits. It does not sync anything.
* **`PERSON_NAME`**: requires `-u/--user_id`, otherwise it fails with
  `Person_name option requires option user_id!`. It limits the sync to the given
  person names. Multiple names are separated with commas.
* **`MODE`**: only applies when `PERSON_NAME` is given.
  * `album-per-person` (default): creates one album for each given person.
  * `album-combined`: creates one album for all given person names. This mode
    requires at least two persons separated with commas, otherwise it fails with
    `Note parameter mode <MODE> requires at least two persons separated using coma.`.
  * Any other value fails with `Error: invalid value for parameter mode <MODE>.`.

### Examples

```bash
# Sync albums for all users and all their persons
occ face:sync-albums

# Sync albums for a single user
occ face:sync-albums -u alice

# List the persons defined for a user
occ face:sync-albums -u alice --list_person

# Create one album per each of the given persons
occ face:sync-albums -u alice -p "Alice,Friends" -m album-per-person

# Create a single combined album for the given persons
occ face:sync-albums -u alice -p "Alice,Friends" -m album-combined
```

---

## face:reset

**Description:** Resets and deletes everything. Good for starting over. **BEWARE: Next runs of `face:background_job` will re-analyze all images.**

```
occ face:reset [--all] [--model] [--image-errors] [--clustering]
    [-u|--user_id USER_ID]
```

This command can completely wipe out all images, faces and clusters of persons.
It is ideal if you want to start from scratch for any reason.

**This command is not reversible.** Every reset operation asks for confirmation
with `Warning: This command is not reversible. Do you want to continue? [y/N]`.
If you answer anything other than `y`, the operation is aborted and the command
returns an error.

### Options

| Option | Description |
| --- | --- |
| `-u, --user_id USER_ID` | Resets data for a given user only. If not given, resets everything for all users. |
| `--all` | Reset everything. |
| `--model` | Reset current model. |
| `--image-errors` | Reset errors in images to re-analyze again. |
| `--clustering` | Just reset the clustering. |

### Behavior

* **`USER_ID`**: if provided, it will just reset the information of a particular
  user. The user must exist.
* You must specify **at least one** of `--all`, `--model`, `--image-errors` or
  `--clustering`, otherwise the command fails with `You must specify what you
  want to reset`.
* `--all` completely resets the database: all images, faces and clusters, and
  all images must be analyzed again.
* `--model` resets the current model, and all images must be analyzed again.
* `--image-errors` resets only the images that had errors, to try to analyze
  them again.
* `--clustering` resets only the clustering of persons, and only clustering
  needs to be done again.

### Examples

```bash
# Completely wipe out all analysis data
occ face:reset --all

# Reset only the clustering for a single user
occ face:reset --clustering -u alice

# Re-analyze images that failed
occ face:reset --image-errors
```

---

## face:migrate

**Description:** Migrate the faces found in a model and analyze with the current model.

```
occ face:migrate [-m|--model_id MODEL_ID] [-u|--user_id USER_ID]
```

This command allows to migrate the faces obtained in a model to a new one. Note
that the persons names are not migrated, and the user must rename them again.
Always is recommended to analyze from scratch any configured model, but you can
save a lot of time migrating it.

### Options

| Option | Description |
| --- | --- |
| `-m, --model_id MODEL_ID` | The identifier number of the model to migrate. |
| `-u, --user_id USER_ID` | Migrate data for a given user only. If not given, migrate everything for all users. |

### Behavior

* **`MODEL_ID`** is mandatory. If not supplied, the command fails with `You must
  indicate the ID of the model to migrate`.
* The model to migrate must exist (`Invalid model Id` otherwise) and be
  installed (`The model <MODEL_ID> is not installed` otherwise).
* The model to migrate must be **different** from the current one, otherwise it
  fails with `The proposed model <MODEL_ID> to migrate must be other than the
  current one <CURRENT_MODEL_ID>`.
* **`USER_ID`**: if provided, just migrate the faces for the given user. The
  user must exist.
* A user cannot have data in the current model already, otherwise the migration
  is refused with `The user <USER_ID> in current model <CURRENT_MODEL_ID>
  already has data. You cannot migrate to a used model.`
* During the migration, each old face is recomputed with the current model (new
  landmarks and descriptor are computed from the original file, using the old
  face rectangle and confidence), so the source model is still required.
* For each face it prints a progress bar. At the end it reminds you that the
  clusters must be recreated:

```
The faces migration is done. Remember that you must recreate the clusters with the background_job command
```

### Examples

```bash
# Migrate all users from model 1 to the current model
occ face:migrate -m 1

# Migrate only a single user
occ face:migrate -m 1 -u alice
```

---

## face:stats

**Description:** Get a summary of statistics images, faces and persons.

```
occ face:stats [-u|--user_id USER_ID] [-j|--json]
```

This command returns a summary of the number of images, faces, clusters and
persons found. It reports data for the current model only.

### Options

| Option | Description |
| --- | --- |
| `-u, --user_id USER_ID` | Get stats for a given user only. If not given, get stats for all users. |
| `-j, --json` | Print in a json format, useful to analyze it with another tool. |

### Output

By default it prints a table with the columns `User`, `Images`, `Processed`,
`Faces`, `Clusters`, `Persons`, one row per user.

With `--json` it prints a JSON array with one object per user, with the keys
`user`, `images`, `processed`, `faces`, `clusters`, `persons`.

### Examples

```bash
# Table for all users
occ face:stats

# JSON for a single user
occ face:stats -u alice --json
```

---

## face:progress

**Description:** Get the progress of the analysis and an estimated time.

```
occ face:progress [-j|--json]
```

This command returns the progress of the analysis and an estimated time to
complete. It reports data for the current model only.

### Options

| Option | Description |
| --- | --- |
| `-j, --json` | Print in a json format, useful to analyze it with another tool. |

### Output

By default it prints a table with the columns `Images`, `Remaining` and `ETA`.
The `ETA` is estimated from the average processing duration of the already
processed images times the number of remaining images.

With `--json` it prints a JSON array with a single object with the keys
`images`, `remaining` and `eta` (the estimated remaining seconds).

### Examples

```bash
# Table
occ face:progress

# JSON
occ face:progress --json
```
