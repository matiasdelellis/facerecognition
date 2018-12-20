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
analyze the images for looking faces and if possible identify them by comparing
them with previous images assigned by the user.

![App screenshots](/doc/face-recognition-screenshot.png "App screenshots")

## How to use it?

First of all, the administrator must configure and execute the analysis. Once finished:

 1. In the user settings there is a 'Face Recognition' panel where the user can
    see and rename all the faces of their friends.
 2. In the file application the user can search by typing your friend's name,
    and it will show all the photos.
 3. In the side panel of the file application, a 'Persons' tab is added where
    you can see a list of your friends in the photo, and rename them.

## Requirements?

 * Nextcloud 14+
 * [Dlib PHP bindings](https://github.com/goodspb/pdlib)
 * [Neural models trained for dlib](https://github.com/davisking/dlib-models). :see_no_evil: Do not be scared, they are included and they're free. :smiley:

Everything is open source or Creative Commons. :wink:

## Commands

There is a single command with which the administrator must work.
This process can took a lot of CPU and Memory therefore it is recommended to
run each a reasonable time. Perhaps a cron task configured per day.

### face:background_job [-u user-id] [-t timeout]

This command will do all the work. It is responsible for searching the images,
analyzing them and clustering them in groups of similar people.

If `user-id` is supplied just loop over the files for that user.

if `timeout` is supplied it will stop after the indicated seconds, and continue
in the next execution.
