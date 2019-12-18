<?php
return ['routes' => 
[
	// Get all face clusters with faces and file asociated.
	[
		'name' => 'person#index',
		'url'  => '/clusters',
		'verb' => 'GET'
	],
	// Get all clusters filtered by Name.
	[
		'name' => 'person#findByName',
		'url'  => '/person/{personName}',
		'verb' => 'GET'
	],
	// Get a cluster by Id.
	[
		'name' => 'person#find',
		'url'  => '/cluster/{id}',
		'verb' => 'GET'
	],
	// Change name to a person.
	[
		'name' => 'person#updateName',
		'url'  => '/cluster/{id}',
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
	// Get folder preferences
	[
		'name' => 'file#getFolderOptions',
		'url'  => '/folder',
		'verb' => 'GET'
	],
	// Set folder preferences
	[
		'name' => 'file#setFolderOptions',
		'url'  => '/folder',
		'verb' => 'PUT'
	],
	// User settings
	[
		'name' => 'setting#setUserValue',
		'url' => '/setuservalue',
		'verb' => 'POST'
	],
	[
		'name' => 'setting#getUserValue',
		'url' => '/getuservalue',
		'verb' => 'GET'
	],
	// App settings
	[
		'name' => 'setting#setAppValue',
		'url' => '/setappvalue',
		'verb' => 'POST'
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