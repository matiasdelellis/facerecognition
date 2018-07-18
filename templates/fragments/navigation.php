<!-- translation strings -->
<script id="navigation-tpl" type="text/x-handlebars-template">

	<div id="button-fixed">
		<div><button type="button" id="all-button" class="icon-user"><?php p($l->t('Show all groups'));?></button></div>
	</div>
	{{#each groups}}
		<li class="group {{#if active}}active{{/if}}" data-id="{{ @key }}">
		<a href="#" class="icon-user">{{@key}}</a>
		<div class="app-navigation-entry-utils">
			<ul>
				<li class="app-navigation-entry-utils-menu-button">
					<button class="icon-rename"></button>
				</li>
			</ul>
		</div>
		<div class="app-navigation-entry-edit">
			<form>
				<input type="text" value='{{@key}}'>
				<input type="submit" value="" class="icon-close">
				<input type="submit" value="" class="icon-checkmark">
			</form>
		</div>
	{{/each}}

</script>

<ul class="with-icon"></ul>