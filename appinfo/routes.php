<?php
return ['routes' => 
[
	// Get all persons with faces and file asociated.
	[
		'name' => 'person#index',
		'url'  => '/persons',
		'verb' => 'GET'
	],
	// Change name to a person.
	[
		'name' => 'person#updateNameV2',
		'url'  => '/personV2/{id}',
		'verb' => 'PUT'
	],
	// Get a face Thumb
	[
		'name' => 'face#getThumbV2',
		'url'  => '/face/{id}/thumb/{size}',
		'verb' => 'GET'
	],
	// Get persons from path
	[
		'name' => 'file#getPersonsFromPath',
		'url'  => '/file',
		'verb' => 'GET'
	],
	// Get process status.
	[
		'name' => 'process#index',
		'url'  => '/process',
		'verb' => 'GET'
	],

]];