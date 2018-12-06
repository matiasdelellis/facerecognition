<?php
script('facerecognition', 'handlebars');
script('facerecognition', 'lozad');
script('facerecognition', 'personal');
style('facerecognition', 'facerecognition');
?>

<script id="content-tpl" type="text/x-handlebars-template">

<div class="section" id="facerecognition">

	<h2>Face Recognition</h2>
{{#if persons}}
	<p class="settings-hint">Here you can see the photos of your friends that we have recognized</p>
	<div id="persons-navigation">
		{{#each persons}}
			<div class="person-title">
				<h2 class="edit-person icon-user" data-id="{{this.id}}">{{this.name}}</h2>
			</div>
			<div class="persons-previews">
				{{#each this.faces}}
					<div class="person-container">
						<a target="_blank" href="/f/{{file-id}}">
							<div class="lozad" data-background-image="/apps/facerecognition/face/{{id}}/thumb" width="50" height="50"></div>
						</a>
					</div>
				{{/each}}
			</div>
		{{/each}}
	</div>
{{else}}
	<div class="emptycontent">
		<div class="icon-user svg"></div>
		<h2><?php p($l->t('Looking for your recognized friends')); ?></h2>
		<img class="loadingimport" src="<?php p(image_path('core', 'loading.gif')); ?>"/>
	</div>
{{/if}}

</div>

</script>
<div id="div-content"></div>
