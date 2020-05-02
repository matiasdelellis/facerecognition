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
	<Tab :id="id" :icon="icon" :name="name" :class="{ 'icon-loading': loading }">
		<div v-if="error" class="emptycontent">
			<div class="icon icon-error" />
			<h2>{{ error }}</h2>
		</div>
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
	</Tab>
</template>
<script>
import Tab from '@nextcloud/vue/dist/Components/AppSidebarTab'
import Axios from '@nextcloud/axios'
export default {
	name: 'PersonsTabApp',
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
			error: '',
			icon: 'icon-contacts-dark',
			loading: true,
			name: t('facerecognition', 'Persons'),
			isEnabledByUser: false,
			isAllowedFile: false,
			isParentEnabled: false,
			isProcessed: false,
			persons: [],
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
			return 'facerecognition'
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
		},
	},
	watch: {
		fileInfo: {
			immediate: true,
			handler(fileInfo) {
				 this.getFacesInfo(fileInfo)
			}
		},
	},
	methods: {
		async getFacesInfo(fileInfo) {

			if (fileInfo.isDirectory()) {
				var infoUrl = OC.generateUrl('/apps/facerecognition/folder');
			} else {
				var infoUrl = OC.generateUrl('/apps/facerecognition/file');
			}

			try {
				this.loading = true

				const response = await Axios.get(infoUrl, {
					params: {
						// TODO: replace with proper getFUllpath implementation of our own FileInfo model
						fullpath: (fileInfo.path + '/' + fileInfo.name).replace('//', '/')
					}
				})
				this.processFacesData(response.data)

				this.loading = false
			} catch (error) {
				this.error = error
				this.loading = false
				console.error('Error loading the shares list', error)
			}
		},
		processFacesData(data) {
			this.isEnabledByUser = data.enabled
			this.isAllowedFile = data.is_allowed
			this.isParentEnabled = data.parent_detection
			this.isProcessed = data.is_processed
			this.persons = data.persons
		}
	}
}
</script>