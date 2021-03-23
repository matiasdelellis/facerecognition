<?php

 require_once __DIR__ . '/../../../tests/bootstrap.php';

\OC_App::loadApp('facerecognition');

if (!class_exists('PHPUnit_Framework_TestCase') && !class_exists('\PHPUnit\Framework\TestCase')) {
	require_once('PHPUnit/Autoload.php');
}

OC_Hook::clear();
