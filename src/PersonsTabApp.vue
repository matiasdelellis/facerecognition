<template>
	<div v-if="isEnabledByUser" class="emptycontent">
		<div class="icon icon-contacts-dark"/>
		<h2>{{ t('facerecognition', 'This image is not yet analyzed') }}</h2>
		<p><span>{{ t('facerecognition', 'Please, be patient') }}</span></p>
	</div>
	<div v-else class='emptycontent'>
		<div class='icon icon-contacts-dark'/>
		<h2>{{ t('facerecognition', 'Facial recognition is disabled') }}</h2>
		<p><span v-html="settingsUrl"></span><p/>
	</div>
</template>
<script>
import Tab from '@nextcloud/vue/dist/Components/AppSidebarTab'
export default {
	name: 'PersonsTabApp',
	components: {
		Tab
	},
	props: {
		// fileInfo will be given by the Sidebar
		fileInfo: {
			type: Object,
			default: () => {},
			required: true
		}
	},
	data() {
		return {
			icon: 'icon-user',
			loading: true,
			name: t('facerecognition', 'Persons'),
			isEnabledByUser: false
		}
	},
	computed: {
		/**
		 * Needed to differenciate the tabs
		 * pulled from the AppSidebarTab component
		 *
		 * @returns {string}
		 */
		id() {
			return this.name.toLowerCase().replace(/ /g, '-')
		},
		/**
		 * Returns the current active tab
		 * needed because AppSidebarTab also uses $parent.activeTab
		 *
		 * @returns {string}
		 */
		activeTab() {
			return this.$parent.activeTab
		},
		settingsUrl() {
			return t('facerecognition', 'Open <a target="_blank" href="{settingsLink}">settings ↗</a> to enable it', {settingsLink: OC.generateUrl('settings/user/facerecognition')})
		}
	}
}
</script>