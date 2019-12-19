# Changelog
All notable changes to this project will be documented in this file.

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
