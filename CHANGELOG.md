# Changelog
All notable changes to this project will be documented in this file.

## [0.9.40] - 2024-04-24
- Enable PHP 8.3 and NC28
- Add special modes to background_job command that allows images to be analyzed
  in multiple processes to improve speed.
- Add special mode to sync-album command to generate combined albums with multiple
  people. PR #709

## [0.9.31] - 2023-08-24
- Be sure to open the model before getting relevant info. Issue #423

## [0.9.30] - 2023-08-23
- Implement the Chinese Whispers Clustering algorithm in native PHP.
- Open the model before requesting information. Issue #679
- If Imaginary is configured, check that it is accessible before using it.
- If Memories is installed, show people's photos in this app.
- Add face thumbnail when search persons.
- Disable auto rotate for HEIF images in imaginary. Issue #662
- Add the option to print the progress in json format.

## [0.9.20] - 2023-06-14
- Add support for (Now old) Nextcloud 26.
- Add support to NC27 for early testing.
- Clean some code an split great classed to improve maintenance.
- Don't catch Imaginary exceptions. Issue #658
- Update french translation thanks to Jérémie Tarot.

## [0.9.12] - 2023-03-25
- Add support for using imaginary to create the temporary files.
  This add support for images heic, tiff, and many more. Issue #494,
  #215 and #348 among many other reports.
- Memory optimization in face clustering task. Part of issue #339
  In my tests, it reduces between 33% and 39% of memory, and as an
  additional improvement, there was also a reduction in time of around
  19%. There are still several improvements to be made, but it is a
  good step.
- Modernizes the construction of the javascript code. Issue #613
- Fix Unhandled exception and Albums are not being created. Issue #634

## [0.9.11] - 2022-12-28
- Fix migrations on PostgreSQL. Issue #619 and #615
- Fix OCS Api (API V1). Thanks to nkming2

## [0.9.10] - 2022-12-12
- Just bump version, to remove beta label and allow installing in NC25
- **Gratitude**: @pulsejet, very kindly accept the integration of this
  application into your super cool photo gallery called [Memories](https://github.com/pulsejet/memories).
  If you didn't know about this project, I invite you to give it a try,
  that will pleasantly surprise you. :tada:
  Thanks again. :smiley:
- New Russian translation thanks to Regardo.

## [0.9.10-beta.2] - 2022-11-17
### Added
- Adds an API version 2 that theoretically is enough for any client.
- Note that can change minimally until we release the stable version.

### Fixed
- Only fix tests.

### Changed
- Use x, y, width, height to save face detections on database.

## [0.9.10-beta.1] - 2022-09-27
### Added
- Support Nextcloud 25
- A little love to the whole application to improve styles and texts.
- Show the image viewer when click come image on our "gallery".
  Is press Control+Click it will open the file as before.
- Don´t allow run two face:commands simultaneously to prevent errors.
- Some optimizations on several queries of main view.
- Add a new command <face:sync-albums> to create photos albums of persons.

### Fixed
- Rephrase I'm not sure button to better indicate what it does. Issue #544

### Changed
- Change Person to people since Persons in a very formal word of lawyers.
- Edit people's names in the same side tab instead of using dialogs.
  This is really forced by changes to the viewer which retains focus, and
  avoids typing anywhere.
  It also has a regression that misses the autocomplete. :disapointed:
- Typo fix. Just use plurals on stats table.
- Show the faces of the latest photos, and sort photos by upload order.

### Translations
- Update German translation thanks to lollos78.
- Update Italian (Italy) translation thanks to lollos78.
- Update of many other translations. Thank you so much everyone.

## [0.9.5] - 2022-05-07
### Added
- Just enable Nextcloud 24

### Fixed
- Fix NotFoundException on NC24. Issue #574

## [0.9.1] - 2021-12-15
### Fixed
- Fix Ignore persons feature. Issue #542

### Translations
- Update Czech translation thanks to Pavel Borecki.
- Update Italian translation thanks to axl84.

## [0.9.0] - 2021-12-13
### Added
- Add an extra step to setup. You must indicate exactly how much memory you want
  to assing for image processing. See occ face:setup --memory doc on readme.
- Adds the option to effectively ignore persons when assigning names. See issue
  #486 #504.
- It also allows you to hide persons that you have already named. Issue #405
- Implement the option of: This is not such a person. Issue #506 and part of
  #158
- Enable NC23

### Translations
- Update some translations. Thank you so much everyone.

## [0.8.5] - 2021-11-20
### Added
- Initial support for php 8. See issue #456
- Add link to show photos of person on sidebar.
- Add static analysis, phpunit and lintian test using github workflow.
- Add an real OCS public API to get all persons. See PR  #512.

### Fixed
- Fix sidebar view when user has disable it.
- Set the Image Area slider to the maximum allowed by the model. See issue #527
- Don't try to force the setCreationTime argument to be DateTime. See PR #526
- Migrate hooks to OCP event listeners. See PR #511

### Translations
- New Czech translation thanks to Pavel Borecki, and update others. Thank you so
  much everyone.

## [0.8.3] - 2021-07-08
### Added
- Initial support for NC22.
- Update translations.

## [0.8.2] - 2021-05-17
### Added
- Add links in thumbnails of rename persons dialogs. Issue #396
- Initial autocomplete feature for names. Issue #306

### Fixed
- Respect .noimage file, since it is also used in Photos. Issue #446
- Fix delete files due some change on ORM with NC21. Issue #471
- Some fixes on make clean.

### Translations
- New Italian translation thanks to axl84, and update others. Thank you so much
  everyone.

## [0.8.1] - 2021-03-18
### Fixed
- Register the Hooks within the Bootstrap mechanism, removing many undesirable
  logs. Similar to https://github.com/nextcloud/server/issues/22590

### Translations
- New Korean (Korea) translation thanks to HyeongJong Choi
- Updating many others translations from Transifex. This time I cannot
  individualize your changes to thank you properly, but thank you very much to
  all the translators.

## [0.8.0] - 2021-03-17
### Added
- Increase the supported version only to NC21. Thanks to @szaimen. See issue #429
- Add support for unified search, being able to search the photos of your loved
  ones from anywhere in nextcloud. Thanks to @dassio. See PR #344
- Add defer-clustering option. It changes the order of execution of the process
  deferring the face clustering at the end of the analysis to get persons in a
  simple execution of the command. Thanks to @cliffalbert. See issue #371

## [0.7.2] - 2020-12-10
### Added
- Add an external model that allows run the photos analysis outside of your
  Nextcoud instance freeing server resources. This is generic and just define a
  reference api, and we share a reference example equivalent to model 1. See
  issue #210, #238, and PR #389.
  See: https://github.com/matiasdelellis/facerecognition-external-model
- Allow setting a custom model path, useful for configurations like Object
  Storage as Primary Storage. This thanks to David Kang. See #381 and #390.
- Add memory info and pdlib version to admin page. PR #385

### Translations
- Some messages improved thanks to Robin @Derkades. They were not translated yet
  and will probably change again. Please be patient.

## [0.7.1] - 2020-11-17
### Added
- Add support for analyzing photos from group folders. Issue #364

### Fixed
- Fixed Prevent error when pretty url is disabled on subfolder #379
- Fix responses when you want to see another user's faces. Issue #352
- Fix enconde name to URL params queries. Issue #359
- Typo in title. Issue #357 and #380. Thanks to @strugee
- Try to improve some messages.

### Translations
- Add Dutch translation thanks to Robin Slot.
- Update Macedonian language thanks to Сашко Тодоров
- Update French language thanks to Tho Vi
- Update Serbian language thanks to Branko Kokanovic.
- Update Spanish language thanks to Matias De lellis.

## [0.7.0] - 2020-10-24
### Added
- Support to Nextcloud 20. Issue #343 and #347. Thanks to xiangbin li.
- Add a dialog to assign names to the new persons found.

### Changed
- The main view of face clusters change switches to a view of persons. We
  consider person to the set of all faces with the same name, regardless of the
  clusters. Fix issue #334 and parts of #134.
- The second viev show all photos of a person. The server thumbnails are reused,
  and therefore the performance is drastically improved. In general fix issue
  #193 and parts of #134.
- Finally, the third view, allows you to see all the clusters as before, only to
  fix name errors if necessary.

### Fixed
- Fix crashed on postWrite and prevent other apps to work. Issue  #341

### Translations
- Update French tranlation thanks to Tho Vi

## [0.6.3] - 2020-08-28
### Changed
- Reduce the minimum system memory to 1GB. Issue #319 and others.

### Fixed
- Fix migration command due 'Invalid datetime format'. Issue #320
- Fix can't change model to 4, migration says model <4> not installed. Issue #318

### Translations
- Update French tranlation thanks to Tho Vi

## [0.6.2] - 2020-08-14
### Added
- Introduduce a new model (Model 4, aka DlibCnnHog5) that is 2 times slower, but
  much more accurate, which now is in testing stages, and we invite you to test
  since probably will be the next recommended model. See PR #313 for details.
- Add face:migrate command that allows to migrate the faces obtained in a model
  to a new one. Still recommended to fully analyze the images when changing
  models, but can save a lot of time migrating them. See PR #309
- Add face:reset --model command to just reset current model.

### Changed
- At least 1000 faces are needed for to make an initial clustering.
- Don't group faces smaller than 40 pixels, which are supposed to be of poor
  quality. This is configurable within an advanced hidden setting. PR #299
- All reset commands require a confirmation to work.
- Hint the 4x3 relation when model recommending memory values.

## Deprecated
- After many analysis, we discourage the use of model 2 (Aka DlibCnn68). We
  still recommend model 1, and model 3 for low-resource devices. You can migrate
  the faces using the new command, but we recommend analyzing them again.

### Fixed
- Fix estimated time in the administration panel. See RP #297
- Fix that removing .nomedia file does not trigger facerecognition when next
  analysis starts. Issue #304
- Fix travis tests and lot of scrutinizer reports.
- Fix that if increase the minimum confidence dont cluster any face in model 3
- Log the system info before return any error. Part of issue #278

### Translations
- Add Macedonian translation thanks to Сашко Тодоров

## [0.6.1] - 2020-06-27
### Changed
- Adjust the appstore makefile rule to ignore vue and teplates files.

### Bug fixes
- Fix dump models table when none was installed yet. Issue #276.
- Fix integer overflow on 32 bit systems.. Issue #278.
- Fix Admin page when not model installed. Issue #284.

### Translations
- Update German (Germany) translations thanks to ProfDrJones.

## [0.6.0] - 2020-05-04
### Added
- Experimental support of External Storage. Issue #212
- Add some documentation about how expect to install, test and use the application
- Optionally you can install Pdlib 1.0.2 from https://github.com/matiasdelellis/pdlib that increase the speed of clustering drastically.
- The sidebar has been rewritten to show it in the Photos application.
- Add php-bzip2 as a necessary dependency to install the models.

### Changed
- Also adds a minimum php memory requirement for HOG. 128 MB.
- Indicate which model is enabled in the summary table of the setup --model command.

### Bug fixes
- Consider the memory configured in php as a dependency on the models.
- Improves the description of errors and prints the links to documentation whenever it can.
- Fix plurals in admin panel. Issue #256.
- Use paginated queries when search persons on file app. Fix part of issue #263.
- Search mainly faces, rather than persons in file view. Fix issue #264.
- Don't compare a good face with a bad one. This greatly improves the quality of the faces clusters.
- Fix that sidebar says no faces were found when just not clustered yet. Issue #255.
- Fix tests on Nextcloud 18 and 19.

### Translations
- New French tranlation thanks to Florian Carpentier
- Update Chinese translation thanks to yui874397188 and Jack Frost
- Update German translation thanks to Johannes Szeibert
- Update Polish translation thanks to Piotr Esse
- Update Spanish translation thanks to Matias De lellis

## [0.5.14] - 2020-03-30
### Bug fixes
- Fix image will be skipped due 'Unable to open file: /tmp/oc_tmp_###'. Issue #242

## [0.5.13] - 2020-03-29
### Added
- Add face:reset --all|--clustering command. Details in README.md file.
- Add face:stats command. Details in README.md file.
- Add face:progress command. Details in README.md file.
- Multiple model support was implemented. Details on Wiki.
- Allow to change the model using the face:setup command.
- Allow configure the supported mimetype. Details on Wiki.

### Changed
- Change to use area of the images instead of memory as the main parameter.
- Don't upsampling image on CNN, better pass a bigger picture.
- Don't install any model by default, just dump list of models.
- Better message and print system/php memory as debug.
- Test: Move to new repo that use reprepro and use bionic.

### Bug fixes
- Fix show not grouped on main view.

### Translations
- Add Portuguese (Brazil) thanks to Marcelo Rovani.
- Update Chinese language thanks to Jack Frost.
- Update German language thanks to Mark Ziegler.
- Update Spanish language thanks to Matias De lellis.

## [0.5.12] - 2020-01-06
### Bug fixes
- Force cast to integer after multiplying by a number. Issue #199, #208
- Fix initial view when enable analysis and still don't analyze anything. Issue #225 and others.
- face-preview: Person photo is blank #226

### Changed
- Change the minimum memory requirement to 2GB. If you have less use Swap.
- Change the minimum memory assigned for analysis to 1.2GB.
- Change the maximum memory assigned for analysis to 8 GB.
- Change the formula to calculate the area according to memory. Issue $220, #176, and others.
- Implement a settings service where to handle everything a little more clean.
- Move FaceManagementService together with the others services.
- Test node binary needed to build handlebars templates. Issue #223 and #217
- Fix some grammatical errors and typos. Issue 224 

### Translations
- Add German translation thanks to Mark Ziegler.
- Update Chinese language thanks to Jack Frost.
- Update Spanish language thanks to Matias De lellis.

## [0.5.11] - 2019-12-20
### Added
- Add custom exclusion folder option beyond the .nomedia file. Issue #171
- Add sidebar to folders which allows to enable/disable these.
- Add support for encrypted storage. Issue #201
- Add experimental support for shared storage. Issue #26
- Add support to Nextcloud 18

### Changed
- Fix travis CI test.
- General cleaning of a lot of code and doc.

### Translations
- Update Chinese language thanks to Jack Frost.
- Update Spanish language thanks to Matias De lellis.

## [0.5.10] - 2019-12-06
### Added
- Add a button to show all clusters with same person name.
- Add a button to go back and show all clusters.

### Changed
- Select the name when open the rename dialog.
- Remove the spaces at both ends of the names before saving them.
- Force delete invalid entries when a file does not exist. Issue #154
- Improve tests broken since adding the face:setup command.
- Move from deprecated database.xml to use DB migrations.
- Try to be consistent in that we work with clusters at least in api/endpoints.
- Fix most of the 'App is not compliant' reports. Issue #72
- Remove Nextcloud 15 support. If you need it, ask for help.

### Translations
- Update Chinese language thanks to Jack Frost.
- Update Serbian language thanks to Branko Kokanovic.
- Update Spanish language thanks to Matias De lellis.

## [0.5.9] - 2019-11-25
### Changed
- Migrate deprecated json_array db type to json.

## [0.5.8] - 2019-11-22
### Added
- A personal settings panel to enable the analysis by each user.
- Progressive discovery of faces in the photos of the users.
- Automatic clustering of similar faces as persons.
- Can view and rename face groups in the personal settings pane.
- A side panel where you can see the persons in a photo and rename them.
- Can search for all the photos where a person appears just by typing the name.
- A admin settings panel to configure the main options.

### Translations
- Added Chinese language thanks to Jack Frost.
- Added Polish language thanks to Olaf Lipinski.
- Added Serbian language thanks to Branko Kokanovic.
- Added Spanish language thanks to Matias De lellis.

## [0.5.4] – 2017-10-04

- Initial release
