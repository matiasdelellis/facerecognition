<!--
  - @copyright Copyright (c) 2020 Matias De lellis <mati86dl@gmail.com>
  -
  - @author Matias De lellis <mati86dl@gmail.com>
  -
  - @license GNU AGPL version 3 or any later version
  -
  - This program is free software: you can redistribute it and/or modify
  - it under the terms of the GNU Affero General Public License as
  - published by the Free Software Foundation, either version 3 of the
  - License, or (at your option) any later version.
  -
  - This program is distributed in the hope that it will be useful,
  - but WITHOUT ANY WARRANTY; without even the implied warranty of
  - MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
  - GNU Affero General Public License for more details.
  -
  - You should have received a copy of the GNU Affero General Public License
  - along with this program. If not, see <http://www.gnu.org/licenses/>.
  -
  -->

<template>
	<Tab
		:id="id"
		:icon="icon"
		:name="name"
		:class="{ 'icon-loading': loading }">
		<div id="persons-tab-mount" />
	</Tab>
</template>

<script>
import Tab from '@nextcloud/vue/dist/Components/AppSidebarTab'

export default {
	name: 'PersonsTab',
	components: {
		Tab,
	},
	props: {
		// fileInfo will be given by the Sidebar
		fileInfo: {
			type: Object,
			default: () => {},
			required: true,
		},
	},
	data() {
		return {
			icon: 'icon-user',
			loading: false,
			name: t('facerecognition', 'Persons'),
			tab: null,
			token: null,
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
			return 'persons'
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
	},

	beforeMount() {

	},
	mounted() {
		try {
			OCA.Facerecognition.fileInfo = this.fileInfo
			this.tab = OCA.Facerecognition.newTab()
			this.tab.$mount('#persons-tab-mount')
		} catch (error) {
			console.error('Unable to mount Persons tab', error)
		}
	},
	beforeDestroy() {
		try {
			OCA.Facerecognition.fileInfo = null
			this.tab.$destroy()
		} catch (error) {
			console.error('Unable to unmount Persons tab', error)
		}
	},
}
</script>

<style scoped>
</style>