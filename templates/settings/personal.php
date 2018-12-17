<?php
script('facerecognition', 'handlebars');
script('facerecognition', 'lozad');
script('facerecognition', 'personal');
style('facerecognition', 'facerecognition');
?>

<script id="content-tpl" type="text/x-handlebars-template">

<div class="section" id="facerecognition">

	<h2><?php p($l->t('Face Recognition'));?></h2>
	<p class="settings-hint"><?php p($l->t('Here you can see photos of your friends that are recognized'));?></p>
{{#if persons}}
	<div id="persons-navigation">
		{{#each persons}}
			<h2 class="person-title icon-user" data-id="{{this.id}}">
				<span>{{this.name}}</span>
				<a class="title-icon icon-rename"></a>
			</h2>
			<div class="persons-previews">
				{{#each this.faces}}
					<a target="_blank" href="/f/{{file-id}}">
						<div class="face-preview" data-background-image="/apps/facerecognition/face/{{id}}/thumb/50" width="50" height="50"></div>
					</a>
				{{/each}}
			</div>
		{{/each}}
	</div>
{{else if loaded}}
	<div class="emptycontent">
		<div class="icon-user svg"></div>
		<h2><?php p($l->t('Your friends have not been recognized yet'));?></h2>
		<p><?php p($l->t('Please, be patient'));?></p>
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
