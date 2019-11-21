# Face Recognition

[![Build Status](https://travis-ci.org/matiasdelellis/facerecognition.svg?branch=master)](https://travis-ci.org/matiasdelellis/facerecognition)
[![Scrutinizer Code Quality](https://scrutinizer-ci.com/g/matiasdelellis/facerecognition/badges/quality-score.png?b=master)](https://scrutinizer-ci.com/g/matiasdelellis/facerecognition/?branch=master)
[![Code Coverage](https://scrutinizer-ci.com/g/matiasdelellis/facerecognition/badges/coverage.png?b=master)](https://scrutinizer-ci.com/g/matiasdelellis/facerecognition/?branch=master)
[![Codacy Badge](https://api.codacy.com/project/badge/Grade/4b035bd1283349009ad88235d37ddae1)](https://www.codacy.com/app/stalker314314/facerecognition?utm_source=github.com&amp;utm_medium=referral&amp;utm_content=matiasdelellis/facerecognition&amp;utm_campaign=Badge_Grade)
[![License](https://img.shields.io/badge/license-AGPLv3-blue.svg)](https://www.gnu.org/licenses/agpl-3.0.en.html)

Nextcloud app that implement a basic facial recognition system.

FaceRecognition is a Nextcloud application with a goal of recognizing, analyzing
and aggregating face data in users images, and providing additional
functionalities on top of these information, all with built-in privacy of
Nextcloud. Imagine Google Photos, but only for faces (not detecting objects…)
and in such way that your images never leave your Nextcloud instance. :smiley:

The application listens to the creation of new image files, and queues them for
later analysis. A scheduled task (Or admin on demand) take this queue, and
analyze the images for looking faces and if possible identify them grouping by
similarity.

![App screenshots](/screenshots/facerecognition-clusters-view-small.jpg "App screenshots")

## How to use it?

The administrator must properly configure the application, and once it is
working, the user must accept that he wants to allow the analysis of his images
to discover his friends.
Finally the user can use the application in three ways

 1. In the user settings there is a 'Face Recognition' panel where first of all
    each user must enable the analysis. Once enabled, you will progressively see
    the discovery of your friends, and you can assign them names.
 2. In the file application the user can search by typing your friend's name,
    and it will show all the photos.
 3. In the side panel of the file application, a 'Persons' tab is added where
    you can see a list of your friends in the photo, and rename them.

## Installation

#### Requirements

 * Nextcloud 14+
 * [Dlib PHP bindings](https://github.com/goodspb/pdlib)

Everything is AGPL or Creative Commons. :wink:

#### Manual installation on Ubuntu

```
sudo apt-get update
sudo apt-get install git-core composer
cd nextcloud/apps/   # or whatever is your path to nextcloud
git clone https://github.com/matiasdelellis/facerecognition.git
cd facerecognition/
make
```

If you have it manually installed and want to update to latest from `master`:
```
cd nextcloud/apps/facerecognition/
git pull
make
```

## Commands

### Face analysis

`occ face:background_job [-u user-id] [-t timeout]`

This command will do all the work. It is responsible for searching the images,
analyzing them and clustering faces found in them in groups of similar people.

Beware that this command can take a lot of CPU and memory! Before you put it to
cron job, it is advised to try it out manually first, just to be sure you have
all requirements and you have enough resources on your machine.

Command is designed to be run continuously, so you will want to schedule it with
cron to be executed every once in a while, together with a specified timeout. It
can be run every 15 minutes with timeout of `-t 900` (so, it will stop itself
automatically after 15 minutes and cron will start it again), or once a day with
timeout of 2 hours, like `-t 7200`.

If `user-id` is supplied, it will just loop over files of a given user. Keep in
mind that each user must enable the analysis individually, and otherwise this
command will ignore the user.

If `timeout` is supplied it will stop after the indicated seconds, and continue
in the next execution. Use this value in conjunction with the times of the
scheduled task to distribute the system load during the day.

### Resetting faces

`occ face:reset_all [-u user-id]`

This command will completely wipe out all images, faces and cluster of persons.
It is ideal if you want to start from scratch for any reason. Beware that all
images will have to be analyzed again!

If `user-id` is provided, it will just reset all the information of a particular
user.
