<?php
return ['routes' =>
[
	/*
	 * Persons
	 */
	// Get all face clusters with faces and file asociated.
	[
		'name' => 'person#index',
		'url'  => '/persons',
		'verb' => 'GET'
	],
	// Get all images filtered by Name.
	[
		'name' => 'person#find',
		'url'  => '/person/{personName}',
		'verb' => 'GET'
	],
	// Change name to a person.
	[
		'name' => 'person#updateName',
		'url'  => '/person/{personName}',
		'verb' => 'PUT'
	],
	// Change visibility to a person.
	[
		'name' => 'person#setVisibility',
		'url'  => '/person/{personName}/visibility',
		'verb' => 'POST'
	],
	// Get all names filtered by an query.
	[
		'name' => 'person#autocomplete',
		'url'  => '/autocomplete/{query}',
		'verb' => 'GET'
	],
	/*
	 * Clusters
	 */
	// Get a cluster by Id.
	[
		'name' => 'cluster#find',
		'url'  => '/cluster/{id}',
		'verb' => 'GET'
	],
	// Get all clusters filtered by Name.
	[
		'name' => 'cluster#findByName',
		'url'  => '/clusters/{personName}',
		'verb' => 'GET'
	],
	// Get all clusters unassigned clusters.
	[
		'name' => 'cluster#findUnassigned',
		'url'  => '/clusters',
		'verb' => 'GET'
	],
	// Get all clusters ignored clusters.
	[
		'name' => 'cluster#findIgnored',
		'url'  => '/clustersIgnored',
		'verb' => 'GET'
	],
	// Get the clusters that could be the same person as this one.
	[
		'name' => 'cluster#findSimilar',
		'url'  => '/cluster/{id}/similar',
		'verb' => 'GET'
	],
	// Change visibility to cluster
	[
		'name' => 'cluster#setVisibility',
		'url'  => '/cluster/{id}/visibility',
		'verb' => 'POST'
	],
	// Detach Face from cluster
	[
		'name' => 'cluster#detachFace',
		'url'  => '/cluster/{id}/detach',
		'verb' => 'PUT'
	],
	// Change name to a cluster.
	[
		'name' => 'cluster#updateName',
		'url'  => '/cluster/{id}',
		'verb' => 'PUT'
	],
	/*
	 * Face thumbails
	 */
	// Get a face Thumb
	[
		'name' => 'face#getThumb',
		'url'  => '/face/{id}/thumb/{size}',
		'verb' => 'GET'
	],
	// Get a face Thumb of some person
	[
		'name' => 'face#getPersonThumb',
		'url'  => '/person/{name}/thumb/{size}',
		'verb' => 'GET'
	],
	/*
	 * File and Folders
	 */
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
	/*
	 * Settings
	 */
	// User settings
	[
		'name' => 'settings#setUserValue',
		'url' => '/setuservalue',
		'verb' => 'POST'
	],
	[
		'name' => 'settings#getUserValue',
		'url' => '/getuservalue',
		'verb' => 'GET'
	],
	// App settings
	[
		'name' => 'settings#setAppValue',
		'url' => '/setappvalue',
		'verb' => 'POST'
	],
	[
		'name' => 'settings#getAppValue',
		'url' => '/getappvalue',
		'verb' => 'GET'
	],
	/*
	 * Status of process.
	 */
	// Get process status.
	[
		'name' => 'process#index',
		'url'  => '/process',
		'verb' => 'GET'
	],

	/*
	 * Face Recognition API V2
	 */
	// Get all named persons
	[
		'name' => 'api#getPersonsV2',
		'url' => '/api/2.0/persons',
		'verb' => 'GET',
	],
	// Get all photos associated to a person
	[
		'name' => 'api#getPerson',
		'url' => '/api/2.0/person/{personName}',
		'verb' => 'GET',
	],
	// Change name to a person or hide it
	[
		'name' => 'Api#updatePerson',
		'url'  => '/api/2.0/person/{personName}',
		'verb' => 'PUT'
	],
	// Change name to a cluster or hide it.
	[
		'name' => 'Api#updateCluster',
		'url'  => '/api/2.0/cluster/{clusterId}',
		'verb' => 'PUT'
	],
	// Get all names filtered by an query.
	[
		'name' => 'Api#autocomplete',
		'url'  => '/api/2.0/autocomplete',
		'verb' => 'GET'
	],
	// Get all unassigned clusters to name
	[
		'name' => 'Api#discoverPerson',
		'url'  => '/api/2.0/discover',
		'verb' => 'GET'
	],
	// Detach Face from cluster
	[
		'name' => 'Api#detachFace',
		'url'  => '/api/2.0/face/{faceId}/detach',
		'verb' => 'PUT'
	],
	// List all faces for a given file (used by the manual-face dialog overlay)
	[
		'name' => 'Api#getFacesForFile',
		'url'  => '/api/2.0/file/{fileId}/faces',
		'verb' => 'GET'
	],
	// Name a face that is in no cluster yet, such as a marking saved without a name
	[
		'name' => 'Api#nameFace',
		'url'  => '/api/2.0/face/{faceId}/name',
		'verb' => 'PUT'
	],
	// Delete faces put there by hand, ignore faces, or stop ignoring them; the faces are in the body
	[
		'name' => 'Api#deleteFaces',
		'url'  => '/api/2.0/faces/delete',
		'verb' => 'POST'
	],
	[
		'name' => 'Api#ignoreFaces',
		'url'  => '/api/2.0/faces/ignore',
		'verb' => 'POST'
	],
	[
		'name' => 'Api#unignoreFaces',
		'url'  => '/api/2.0/faces/unignore',
		'verb' => 'POST'
	],
	// Delete regions drawn to be searched; the regions are in the body
	[
		'name' => 'Api#deleteRegions',
		'url'  => '/api/2.0/regions/delete',
		'verb' => 'POST'
	],
	// Add a manually drawn face, attached to a named person cluster or left for the clustering
	[
		'name' => 'Api#addManualFace',
		'url'  => '/api/2.0/face/manual',
		'verb' => 'POST'
	],
	// Queue a region of a photo to be searched for faces again by the background job
	[
		'name' => 'Api#addManualRegion',
		'url'  => '/api/2.0/face/region',
		'verb' => 'POST'
	],

], 'ocs' => [

	/*
	 * OCS Person API V1
	 */
	// Get all named persons
	[
		'name' => 'ocs_api#getPersonsV1',
		'url' => '/api/v1/persons',
		'verb' => 'GET',
	],
	// Get all faces associated to a person
	[
		'name' => 'ocs_api#getFacesByPerson',
		'url' => '/api/v1/person/{name}/faces',
		'verb' => 'GET',
	],

]];
