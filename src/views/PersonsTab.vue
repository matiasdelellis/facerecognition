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
	<div :class="{ 'icon-loading': loading }">
		<div v-if="error" class="emptycontent">
			<div class="icon icon-error" />
			<p>{{ error }}</p>
		</div>
		<div v-else-if="!isEnabledByUser && !loading" class='emptycontent'>
			<div class='icon icon-contacts-dark'/>
			<h5>{{ t('facerecognition', 'Facial recognition is disabled') }}</h5>
			<p><span v-html="settingsUrl"></span></p>
		</div>
		<div v-else-if="!isParentEnabled && !loading" class="emptycontent">
			<div class="icon icon-contacts-dark"/>
			<p>{{ t('facerecognition', 'Facial recognition is disabled for this folder') }}</p>
		</div>
		<div v-else-if="!isAllowedFile && !loading" class="emptycontent">
			<div class="icon icon-contacts-dark"/>
			<p>{{ t('facerecognition', 'The type of storage is not supported to analyze your photos') }}</p>
		</div>
		<div v-else-if="isProcessed">
			<template v-if="this.knownPersons.length > 0">
				<ul class='faces-list'>
					<PersonRow v-for="person in this.knownPersons"
						:key="person.person_id"
						:person="person"
					/>
				</ul>
			</template>
			<template v-if="this.unknownPersons.length > 0">
				<ul class='faces-list'>
					<PersonRow v-for="person in this.unknownPersons"
						:key="person.person_id"
						:person="person"
					/>
				</ul>
			</template>
			<template v-if="!this.knownPersons.length && !this.unknownPersons.length">
				<div class='emptycontent'>
					<div class='icon icon-contacts-dark'/>
					<p>{{ t('facerecognition', 'No people found') }}</p>
				</div>
			</template>
		</div>
		<div v-else-if="!isProcessed && !isDirectory && !loading" class='emptycontent'>
			<div class='icon icon-contacts-dark'/>
			<h5>{{ t('facerecognition', 'This image is not yet analyzed') }}</h5>
			<p><span>{{ t('facerecognition', 'Please, be patient') }}</span></p>
		</div>
		<div v-else-if="isDirectory" class='emptycontent'>
			<div class='icon icon-contacts-dark'/>
			<p>
				<input class='checkbox' id='searchPersonsToggle' :checked='isChildrensEnabled' type='checkbox' @change="enableDirectoryCheck($event)"/>
				<label for='searchPersonsToggle'>{{ t('facerecognition', 'Search for persons in the photos of this directory') }}</label>
			</p>
			<p><span>{{ t('facerecognition', 'Photos that are not in the gallery are also ignored') }}</span></p>
			<p><span v-html="faqUrl"></span></p>
		</div>
	</div>
</template>
<script>

import NcAppSidebar from '@nextcloud/vue/dist/Components/NcAppSidebar'
import NcAppSidebarTab from '@nextcloud/vue/dist/Components/NcAppSidebarTab'

import Axios from '@nextcloud/axios'
import { subscribe, unsubscribe } from '@nextcloud/event-bus'

import PersonRow from './PersonRow'

export default {

	name: 'PersonsTabApp',

	components: {
		NcAppSidebar,
		NcAppSidebarTab,
		PersonRow,
	},

	data() {
		return {
			error: '',
			icon: 'icon-contacts-dark',
			loading: true,
			fileInfo: null,
			name: t('facerecognition', 'People'),
			isEnabledByUser: false,
			isAllowedFile: false,
			isParentEnabled: false,
			isProcessed: false,
			isDirectory: false,
			knownPersons: [],
			unknownPersons: [],
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
		faqUrl() {
			return t('facerecognition', 'See <a target="_blank" href="{docsLink}">documentation ↗</a>.', {docsLink: 'https://github.com/matiasdelellis/facerecognition/wiki/FAQ'})
		},
	},

	mounted() {
		subscribe('facerecognition:person:updated', this.handlePersonUpdate)
	},

	beforeDestroy() {
		unsubscribe('facerecognition:person:updated', this.handlePersonUpdate)
	},

	methods: {
		handlePersonUpdate() {
			this.getFacesInfo(this.fileInfo)
		},

		async update(fileInfo) {
			this.resetState()
			this.fileInfo = fileInfo
			this.getFacesInfo()
		},

		resetState() {
			this.loading = true
			this.error = ''
			this.isProcessed = false
			this.knownPersons = []
			this.unknownPersons = []
		},

		async getFacesInfo() {
			const isDirectory = this.fileInfo.isDirectory()
			if (isDirectory) {
				var infoUrl = OC.generateUrl('/apps/facerecognition/folder')
			} else {
				var infoUrl = OC.generateUrl('/apps/facerecognition/file')
			}

			try {
				this.loading = true

				const response = await Axios.get(infoUrl, {
					params: {
						// TODO: replace with proper getFUllpath implementation of our own FileInfo model
						fullpath: (this.fileInfo.path + '/' + this.fileInfo.name).replace('//', '/')
					}
				})
				this.processFacesData(response.data, isDirectory)

				this.loading = false
			} catch (error) {
				this.error = error
				this.loading = false
				console.error('Error loading info of image', error)
			}
		},

		async enableDirectoryCheck(event) {
			const isEnabled = event.target.checked
			var infoUrl = OC.generateUrl('/apps/facerecognition/folder')
			try {
				const response = await Axios.put(infoUrl, {
					// TODO: replace with proper getFUllpath implementation of our own FileInfo model
					fullpath: (this.fileInfo.path + '/' + this.fileInfo.name).replace('//', '/'),
					detection: isEnabled
				})
				this.processFacesData(response.data, true)
			} catch (error) {
				this.error = error
				console.error('Error enabling/disabling directory', error)
			}
		},

		processFacesData(data, isDirectory) {
			this.isDirectory = isDirectory
			this.isEnabledByUser = data.enabled
			this.isAllowedFile = data.is_allowed
			this.isParentEnabled = data.parent_detection
			this.isProcessed = isDirectory ? false : data.is_processed
			this.isChildrensEnabled = !isDirectory ? false : data.descendant_detection
			this.knownPersons = []
			this.unknownPersons = []

			if (!data.enabled)
				return;

			if (!isDirectory) {
				var _self = this;
				data.persons.forEach(function(person) {
					if (person.name != null)
						_self.knownPersons.push(person);
					else
						_self.unknownPersons.push(person);
				});
				this.knownPersons = this.knownPersons.sort(function(a, b) {
					if (a.name > b.name)
						return 1;
					if (a.name < b.name)
						return -1;
					return 0;
				});
			}
		},
	},
}
</script>

<style scoped>

.faces-list {
	padding: 10px 0 15px;
}

.face-entry {
	display: flex;
	align-items: center;
	margin-bottom: 8px;
}

.face-name {
	width: 100%;
	padding: 8px;
}

.unknown-name {
	opacity: .7;
}

.face-preview {
	background-color: rgba(210, 210, 210, .75);
	border-radius: 50%;
	height: 48px;
	width: 48px;
}

.face-preview.unknown-name:hover {
	opacity: 1;
}

.icon-action {
	min-width: 36px;
	min-height: 36px;
	border-radius: 18px;
	opacity: 0.7;
}

.icon-action:hover {
	opacity: 1;
	background-color: rgba(127,127,127,.25) !important;
}

</style>