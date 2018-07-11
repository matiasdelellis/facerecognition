<?php

// Check if we are a user
OCP\User::checkLoggedIn();

$tmpl = new OCP\Template('facerecognition', 'facerecognition', '');
\OCP\Util::addScript('facerecognition', 'facerecognition');
\OCP\Util::addStyle('facerecognition', 'facerecognition');
$tmpl->printPage();
