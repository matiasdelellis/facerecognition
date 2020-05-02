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
			<p>{{ error }}</p>
		</div>
		<div v-if="!isEnabledByUser && !loading" class='emptycontent'>
			<div class='icon icon-contacts-dark'/>
			<h5>{{ t('facerecognition', 'Facial recognition is disabled') }}</h5>
			<p><span v-html="settingsUrl"></span><p/>
		</div>
		<div v-else-if="isProcessed && this.persons.length > 0">
			<ul class='faces-list'>
				<template v-for="person in this.persons">
					<li class='face-entry' :data-id='person.person_id'>
						<img class='face-preview' :src='person.thumb_url' width="32" height="32"/>
						<h5 class='face-name'>{{ person.name }}</h5>
						<a rel="noreferrer noopener" class="icon-rename" target="_blank"/>
					</li>
				</template>
			</ul>
		</div>
		<div v-else-if="isProcessed" class='emptycontent'>
			<div class='icon icon-contacts-dark'/>
			<p>{{ t('facerecognition', 'No people found') }}</p>
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
<style scoped>
.face-entry {
	display: flex;
	align-items: center;
	min-height: 44px;
}
.face-name {
	width: 100%;
	padding: 8px;
}

.face-preview {
	background-color: rgba(210, 210, 210, .75);
	border-radius: 50%;
	height: 32px;
	width: 32px;
}

.icon-rename {
	padding: 14px;
	opacity: 0.7;
}
</style>