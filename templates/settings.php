<?php

OCP\User::checkAdminUser();

OCP\Util::addScript("facerecognition", "admin");

$tmpl = new OCP\Template('facerecognition', 'fragments/admin');

$requirements = TRUE;
$msg = "";

/*
 * Check basic tools
 */
if (file_exists('/bin/nextcloud-face-recognition-cmd') ||
    file_exists('/usr/bin/nextcloud-face-recognition-cmd'))
{
	$msg .= "nextcloud-face-recognition-cmd is installed as system application";

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
	$msg .= "nextcloud-face-recognition-cmd is installed within nexlcloud application";

	$result = shell_exec (getcwd().'/apps/facerecognition/opt/bin/nextcloud-face-recognition-cmd status');
	$status = json_decode ($result);
	if ($status) {
		$tmpl->assign('dlib-version', $status->{'dlib-version'});
		$tmpl->assign('cuda-support', $status->{'cuda-support'} ? "Is compiled" : "Was not compiled");
		$tmpl->assign('avx-support', $status->{'avx-support'} ? "Is compiled" : "Was not compiled");
		$tmpl->assign('neon-support', $status->{'neon-support'} ? "Is compiled" : "Was not compiled");
	}
}
else
{
	$requirements = FALSE;
	$msg .= "nextcloud-face-recognition-cmd is not installed";
	$tmpl->assign('dlib-version', "Not reported");
	$tmpl->assign('cuda-support', "Not reported");
	$tmpl->assign('avx-support', "Not reported");
	$tmpl->assign('neon-support', "Not reported");
}

/*
 * Check models
 */
if (file_exists(getcwd().'/apps/facerecognition/vendor/models/dlib_face_recognition_resnet_model_v1.dat'))
{
	$tmpl->assign('recognition-model', "dlib_face_recognition_resnet_model_v1.dat");
}
else
{
	$requirements = FALSE;
	$tmpl->assign('recognition-model', "dlib_face_recognition_resnet_model_v1.dat missing");
}

if (file_exists(getcwd().'/apps/facerecognition/vendor/models/shape_predictor_5_face_landmarks.dat'))
{
	$tmpl->assign('landmarking-model', "shape_predictor_5_face_landmarks.dat");
}
else if (file_exists(getcwd().'/apps/facerecognition/vendor/models/shape_predictor_68_face_landmarks.dat'))
{
	$tmpl->assign('landmarking-model', "shape_predictor_68_face_landmarks.dat");
}
else
{
	$requirements = FALSE;
	$tmpl->assign('landmarking-model', "Landmarking model missing. Need shape_predictor_68_face_landmarks.dat or shape_predictor_5_face_landmarks.dat installed on vendor/models dolder");
}

$tmpl->assign('requirements', $requirements);
$tmpl->assign('msg', $msg);

/*
 * Render template
 */
return $tmpl->fetchPage();