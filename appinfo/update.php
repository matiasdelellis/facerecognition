<?php
/*
 * TODO: Move to databse micgrations.
 * https://docs.nextcloud.com/server/latest/developer_manual/app/storage/migrations.html
 */
$config = \OC::$server->getConfig();
$installedVersion = $config->getAppValue('facerecognition', 'installed_version');
if (version_compare($installedVersion, '0.5.8', '<')) {
	$sqls = array(
		"UPDATE `*PREFIX*face_recognition_faces` SET confidence = 1.0;",
		"UPDATE `*PREFIX*face_recognition_faces` SET landmarks = '[]';"
	);
	foreach ($sqls as $sql) {
		$query = \OC_DB::prepare($sql);
		$query->execute();
	}
}
