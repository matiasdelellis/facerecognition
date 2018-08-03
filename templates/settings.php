<?php

OCP\User::checkAdminUser();

//OCP\Util::addScript("facerecognition", "admin");

$tmpl = new OCP\Template('facerecognition', 'fragments/admin');

if (file_exists('/bin/nextcloud-face-recognition-cmd') ||
    file_exists('/usr/bin/nextcloud-face-recognition-cmd'))
{
	$tmpl->assign('status', TRUE);
	$tmpl->assign('msg', 'nextcloud-face-recognition-cmd is installed as system application');

	$result = shell_exec ('nextcloud-face-recognition-cmd status');
	$status = json_decode ($result);
	if ($status) {
		$tmpl->assign('dlib-version', $status['dlib-version']);
		$tmpl->assign('cuda-support', $status->{'cuda-support'} ? "Is compiled" : "Was not compiled");
		$tmpl->assign('avx-support', $status->{'avx-support'} ? "Is compiled" : "Was not compiled");
		$tmpl->assign('neon-support', $status->{'neon-support'} ? "Is compiled" : "Was not compiled");
	}
}
else if (file_exists(getcwd().'/apps/facerecognition/opt/bin/nextcloud-face-recognition-cmd'))
{
	$tmpl->assign('status', TRUE);
	$tmpl->assign('msg', 'nextcloud-face-recognition-cmd is installed within nexlcloud application');

	$result = shell_exec (getcwd().'/apps/facerecognition/opt/bin/nextcloud-face-recognition-cmd status');
	$status = json_decode ($result);
	if ($status) {
		$tmpl->assign('dlib-version', $status->{'dlib-version'});
		$tmpl->assign('cuda-support', $status->{'cuda-support'} ? "Is compiled" : "Was not compiled");
		$tmpl->assign('avx-support', $status->{'avx-support'} ? "Is compiled" : "Was not compiled");
		$tmpl->assign('neon-support', $status->{'neon-support'} ? "Is compiled" : "Was not compiled");
	}
	$tmpl->assign('status', TRUE);
	$tmpl->assign('msg', 'nextcloud-face-recognition-cmd is installed within nexlcloud application');
}
else
{
	$tmpl->assign('status', FALSE);
	$tmpl->assign('msg', 'nextcloud-face-recognition-cmd is not installed');
	$tmpl->assign('dlib-version', "Not reported");
	$tmpl->assign('cuda-support', "Not reported");
	$tmpl->assign('avx-support', "Not reported");
	$tmpl->assign('neon-support', "Not reported");
}

return $tmpl->fetchPage();