# Changelog
All notable changes to this project will be documented in this file.

## [0.7.3] - 2021-03-17
### Added
- Increase requirements to NC21. See issue #429
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
