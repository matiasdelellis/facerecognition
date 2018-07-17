<script id="content-tpl" type="text/x-handlebars-template">

{{#if groups}}
<div id="group-navigation">
	<div class="with-icon">
	{{#each groups}}
		<div class="group-title">
			<div><a class="edit-group icon-user">{{@key}}</a></div>
		</div>
		{{#each this}}
			<img src="/apps/facerecognition/thumb/{{id}}"></img>
		{{/each}}
	{{/each}}
	</div>
</div>
{{else}}
<div class="emptycontent">
	<div class="icon-user svg"></div>
	<h2><?php p($l->t('You still do not have new friends to recognize')); ?></h2>
	<p><?php p($l->t('Please, be pacient')); ?></p>
</div>
{{/if}}


</script>
<div id="div-content"></div>