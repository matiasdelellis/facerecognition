<?php
return ['routes' => 
[
	// Main app.
	[
		'name' => 'page#index',
		'url' => '/',
		'verb' => 'GET'
	],
	// Get all faces.
	[
		'name' => 'face#index',
		'url'  => '/faces',
		'verb' => 'GET'
	],
	// Get persons with some faces.
	[
		'name' => 'person#index',
		'url'  => '/persons',
		'verb' => 'GET'
	],
	// Get all faces of a person.
	[
		'name' => 'person#getFaces',
		'url'  => '/person/{personId}',
		'verb' => 'GET'
	],
	// Change name to a person.
	[
		'name' => 'person#updateName',
		'url'  => '/person/{name}',
		'verb' => 'PUT'
	],
	// Change name to a person.
	[
		'name' => 'person#updateNameV2',
		'url'  => '/personV2/{id}',
		'verb' => 'PUT'
	],
	// Get random unknown.
	[
		'name' => 'face#random',
		'url'  => '/random',
		'verb' => 'GET'
	],
	// Get faces on a file path.
	[
		'name' => 'face#findFile',
		'url'  => '/filefaces',
		'verb' => 'GET'
	],
	// Get a single face
	[
		'name' => 'face#Get',
		'url'  => '/face/{id}',
		'verb' => 'GET'
	],
	// Update a single face
	[
		'name' => 'face#updateName',
		'url'  => '/face/{id}',
		'verb' => 'PUT'
	],
	// Get a face Thumb
	[
		'name' => 'face#getThumb',
		'url'  => '/thumb/{id}',
		'verb' => 'GET'
	],
	// Get a face Thumb
	[
		'name' => 'face#getThumbV2',
		'url'  => '/face/{id}/thumb',
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