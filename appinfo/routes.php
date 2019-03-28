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
		'name' => 'person#find',
		'url'  => '/person/{id}',
		'verb' => 'GET'
	],
	// Change name to a person.
	[
		'name' => 'person#updateName',
		'url'  => '/person/{id}',
		'verb' => 'PUT'
	],
	// Get a face Thumb
	[
		'name' => 'face#getThumb',
		'url'  => '/face/{id}/thumb/{size}',
		'verb' => 'GET'
	],
	// Get persons from path
	[
		'name' => 'file#getPersonsFromPath',
		'url'  => '/file',
		'verb' => 'GET'
	],
	// App settings
	[
		'name' => 'setting#setAppValue',
		'url' => '/setappvalue',
		'verb' => 'GET'
	],
	[
		'name' => 'setting#getAppValue',
		'url' => '/getappvalue',
		'verb' => 'GET'
	],
	// Get process status.
	[
		'name' => 'process#index',
		'url'  => '/process',
		'verb' => 'GET'
	],

]];