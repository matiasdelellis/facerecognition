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
		'url'  => '/person/{name}',
		'verb' => 'GET'
	],
	// Change name to a person.
	[
		'name' => 'person#updateName',
		'url'  => '/update/{oldName}/{newName}',
		'verb' => 'PUT'
	],
	// Get random unknown.
	[
		'name' => 'face#random',
		'url'  => '/random',
		'verb' => 'GET'
	],
	// Get faces on a fileId
	[
		'name' => 'face#findFile',
		'url'  => '/faces/{fileId}',
		'verb' => 'GET'
	],
	// Get a single face
	[
		'name' => 'face#Get',
		'url'  => '/face/{id}',
		'verb' => 'GET'
	],
	// Get a face Thumb
	[
		'name' => 'face#getThumb',
		'url'  => '/thumb/{id}',
		'verb' => 'GET'
	],

]];