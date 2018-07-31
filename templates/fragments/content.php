<script id="content-tpl" type="text/x-handlebars-template">

{{#if person}}
<div id="persons-navigation">
	<div class="with-icon">
		<div class="person-title">
			<div><h2 class="edit-person icon-user">{{person.1.name}}</h2></div>
		</div>
		<div class="persons-previews">
			{{#each person}}
				{{#ifCond distance "==" 0}}
					<img class="lozad" data-src="/apps/facerecognition/thumb/{{id}}" width="64" height="64"></img>
				{{/ifCond}}
			{{/each}}
		</div>
		<div class="person-title">
			<div><h1 class="edit-person icon-user">Suggested</h1></div>
		</div>
		<div class="persons-previews">
			{{#each person}}
				{{#ifCond distance ">" 0}}
					<img class="lozad" data-src="/apps/facerecognition/thumb/{{id}}" width="64" height="64"></img>
				{{/ifCond}}
			{{/each}}
		</div>
	</div>
</div>
{{else if persons}}
<div id="persons-navigation">
	<div class="with-icon">
	{{#each persons}}
		<div class="person-title">
			<div><h2 class="edit-person icon-user">{{@key}}</h2></div>
		</div>
		<div class="persons-previews">
			{{#each this}}
				<img class="lozad" data-src="/apps/facerecognition/thumb/{{id}}" width="64" height="64"></img>
			{{/each}}
		</div>
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