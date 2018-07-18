<script id="content-tpl" type="text/x-handlebars-template">

{{#if person}}
<div id="group-navigation">
	<div class="with-icon">
		<div class="group-title">
			<div><h1 class="edit-group icon-user">{{name}}</h1></div>
		</div>
		{{#each person}}
			{{#ifCond distance "==" 0}}
				<img class="lozad" data-src="/apps/facerecognition/thumb/{{id}}" width="64" height="64"></img>
			{{/ifCond}}
		{{/each}}
		<div class="group-title">
			<div><h1 class="edit-group icon-user">Suggested</h1></div>
		</div>
		{{#each person}}
			{{#ifCond distance ">" 0}}
				<img class="lozad" data-src="/apps/facerecognition/thumb/{{id}}" width="64" height="64"></img>
			{{/ifCond}}
		{{/each}}
	</div>
</div>
{{else if groups}}
<div id="group-navigation">
	<div class="with-icon">
	{{#each groups}}
		<div class="group-title">
			<div><h1 class="edit-group icon-user">{{@key}}</h1></div>
		</div>
		{{#each this}}
			<img class="lozad" data-src="/apps/facerecognition/thumb/{{id}}" width="64" height="64"></img>
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