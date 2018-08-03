<?php

OCP\User::checkAdminUser();

//OCP\Util::addScript("facerecognition", "admin");

$tmpl = new OCP\Template('facerecognition', 'fragments/admin');

if (file_exists('/bin/nextcloud-face-recognition-cmd') ||
    file_exists('/usr/bin/nextcloud-face-recognition-cmd')) {
	$tmpl->assign('status', TRUE);
	$tmpl->assign('msg', 'nextcloud-face-recognition-cmd installed as a system application');
}
else if (file_exists($this->appManager->getAppPath('facerecognition').'/opt/bin/nextcloud-face-recognition-cmd')) {
	$tmpl->assign('status', TRUE);
	$tmpl->assign('msg', 'nextcloud-face-recognition-cmd is installed within nexlcloud application');
}
else {
	$tmpl->assign('status', TRUE);
	$tmpl->assign('msg', 'nextcloud-face-recognition-cmd is not installed');
}

return $tmpl->fetchPage();