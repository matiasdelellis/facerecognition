# Face Recognition

Nextcloud app that implement a basic facial recognition system.

The application listens to the creation of new image files, and queues them for
later analysis. A scheduled task (Or admin on demand) take this queue, and
analyze the images for looking faces and if possible identify them by comparing
them with those found in 'user-folder/.faces'.

The app saves the name of the person identified and the position of the face in
the image. If unknown people are found, they also leave them stored, to identify
them later.

## How do users use it?

 1. They save a photo of the familiar face in 'user-folder/.face'. using the
    filename as reference of the person.
 2. Sometime administrator runs the command to analyze the faces.
 3. The user can search the file application using the person's name, and all
    the images containing that person will be displayed.

## Requirements?

The identification is made using these utility written in python:

 * https://github.com/matiasdelellis/face_recognition_cmd

That depends on these libraries:

 * https://github.com/ageitgey/face_recognition

Which in turn depends on the python bindings of dlib => 19.5:

 * http://dlib.net/

Everything is open source. :wink:

## Commands

### face:pre-analyze [user-id]

Search for new files (Or files in an old installation) and queue them for later
analysis with the following command. If `user-id` is supplied just loop over the
files for that user.

### face:analyze [user-id]

Loop over new images in queue and try to find faces on them. If `user-id` is
supplied just loop over the files for that user. Compare these with those found
in 'user-folder/.faces' folder and if there are coinsidence assigns their name
together with the position in the image. If they are unknown, assign them as
such, to identify them later.
This process took a lot of CPU, therefore it is recommended to run each a
reasonable time. Perhaps a cron task configured per day.
