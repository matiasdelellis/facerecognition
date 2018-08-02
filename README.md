# Face Recognition

Nextcloud app that implement a basic facial recognition system.

The application listens to the creation of new image files, and queues them for
later analysis. A scheduled task (Or admin on demand) take this queue, and
analyze the images for looking faces and if possible identify them by comparing
them with previous images assigned by the user.

The app saves the name of the person identified and the position of the face in
the image. If unknown people are found, they also leave them stored, to identify
them later.

## How do use it?

 1. If it is an old installation with photos included the administrator runs the
    pre-analyze command to search user photos and queue them.
 2. Administrator run the analyze command to search faces on photos.
 3. Administrator run the clustering command to group the faces according to the
    similarity assigning names as 'Person N'.

## How do user use it?

 1. In the main application you can rename and combine the groups. Also can
    select individual faces to separate confused people.
 2. The user can search the file application using the person's name, and all
    the images containing that person will be displayed.

## Requirements?

The identification is made using these utility written in python:

 * https://github.com/matiasdelellis/nextcloud_face_recognition_cmd

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
supplied just loop over the photos for that user. All faces will be identified
as 'unknown' and you will be able to see them in the interface as a single group
This process took a lot of CPU, therefore it is recommended to run each a
reasonable time. Perhaps a cron task configured per day.

### face:clustering [user-id]

Loop the faces found by analyze command and try to group them. If `user-id` is
supplied just loop over the faces for that user. All groups will be identified
as 'Person N' and you will be able to see them in the interface to rename it.
This is a quick process, but it should not be used repeatedly. Keep in mind that
is a destructive process. If you execute it when the user has already renamed
some known people, the names assigned by the user will be lost.

### face:update [user-id]

Loop over new faces found by analize command and try to relate it to one of the
previous groups. If can not find generate a new group as 'Person N'. If
`user-id` is supplied just loop over the files for that user. The new faces will
be showed as suggested by the known groups.
This is a quick process and it is recommended to set up a scheduled task however
it does not make sense to do it every less time than 'analyze' command since it
depends on the data that it provides.
