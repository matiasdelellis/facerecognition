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
	// Get groups of faces.
	[
		'name' => 'face#getGroups',
		'url'  => '/groups',
		'verb' => 'GET'
	],
	// Get faces of a person.
	[
		'name' => 'face#getPerson',
		'url'  => '/person/{name}',
		'verb' => 'GET'
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