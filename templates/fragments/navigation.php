<!-- translation strings -->
<script id="navigation-tpl" type="text/x-handlebars-template">

	<div id="button-fixed">
		<div><button type="button" id="all-button" class="icon-user"><?php p($l->t('Show all groups'));?></button></div>
	</div>
	{{#each groups}}
		<li class="group {{#if this.active}}active{{/if}}" data-id="{{ @key }}">
		<a href="#" class="icon-user">{{@key}}</a>
		{{#if this.active}}
		<div class="app-navigation-entry-utils">
			<ul>
				<li class="app-navigation-entry-utils-menu-button">
					<button class="icon-rename"></button>
				</li>
			</ul>
		</div>
		<div class="app-navigation-entry-edit">
			<div>
				<input id="input-name" type="text" value='{{@key}}'>
				<input id="rename-cancel" type="submit" value="" class="icon-close">
				<input id="rename-accept" type="submit" value="" class="icon-checkmark">
			</div>
		</div>
		{{/if}}
	{{/each}}

</script>

<ul class="with-icon"></ul>