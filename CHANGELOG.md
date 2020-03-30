# Changelog
All notable changes to this project will be documented in this file.

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
