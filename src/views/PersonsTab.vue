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
		<div v-else-if="isProcessed && this.persons.length > 0">
			<ul class='faces-list'>
				<template v-for="person in this.persons">
					<li class='face-entry' :data-id='person.person_id'>
						<img class='face-preview' :src='person.thumb_url' width="32" height="32"/>
						<h5 v-bind:class="['face-name', person.name ? '' : 'unknown-name']">{{ person.name ? person.name : t('facerecognition', 'Unknown') }}</h5>
						<a v-if="person.photos_url" :href="person.photos_url" rel="noreferrer noopener" class="icon-external" target="_blank"/>
						<a rel="noreferrer noopener" class="icon-rename" target="_blank" v-on:click="renamePerson(person)"/>
					</li>
				</template>
			</ul>
		</div>
		<div v-else-if="isProcessed" class='emptycontent'>
			<div class='icon icon-contacts-dark'/>
			<p>{{ t('facerecognition', 'No people found') }}</p>
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
import Tab from '@nextcloud/vue/dist/Components/AppSidebarTab'
import Axios from '@nextcloud/axios'

export default {

	name: 'PersonsTabApp',

	components: {
		Tab,
	},

	data() {
		return {
			error: '',
			icon: 'icon-contacts-dark',
			loading: true,
			fileInfo: null,
			name: t('facerecognition', 'Persons'),
			isEnabledByUser: false,
			isAllowedFile: false,
			isParentEnabled: false,
			isProcessed: false,
			isDirectory: false,
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
		faqUrl() {
			return t('facerecognition', 'See <a target="_blank" href="{docsLink}">documentation ↗</a>.', {docsLink: 'https://github.com/matiasdelellis/facerecognition/wiki/FAQ'})
		},
	},

	methods: {
		async update(fileInfo) {
			this.resetState()
			this.fileInfo = fileInfo
			this.getFacesInfo()
		},

		resetState() {
			this.loading = true
			this.error = ''
			this.isProcessed = false
			this.persons = []
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

		renamePerson: function(person) {
			const self = this
			if (person.name) {
				FrDialogs.rename(
					person.name,
					[{thumbUrl: person.thumb_url}],
					function(result, newName) {
						if (result === true && newName) {
							var infoUrl = OC.generateUrl('/apps/facerecognition/person/' + person.name)
							Axios.put(infoUrl, {
								name: newName
							}).then(function (response) {
								self.getFacesInfo(self.fileInfo)
							}).catch(function (error) {
								self.error = error
								console.error('Error renaming person', error)
							})
						}
					}
				)
			} else {
				FrDialogs.assignName([{thumbUrl: person.thumb_url}],
					function(result, newName) {
						if (result === true && newName) {
							var infoUrl = OC.generateUrl('/apps/facerecognition/cluster/' + person.person_id)
							Axios.put(infoUrl, {
								name: newName
							}).then(function (response) {
								self.getFacesInfo(self.fileInfo)
							}).catch(function (error) {
								self.error = error
								console.error('Error renaming person', error)
							})
						}
					}
				)
			}
		},

		processFacesData(data, isDirectory) {
			this.isDirectory = isDirectory
			this.isEnabledByUser = data.enabled
			this.isAllowedFile = data.is_allowed
			this.isParentEnabled = data.parent_detection
			this.isProcessed = isDirectory ? false : data.is_processed
			this.isChildrensEnabled = !isDirectory ? false : data.descendant_detection
			this.persons = []

			if (!data.enabled)
				return;

			if (!isDirectory) {
				this.persons = data.persons.sort(function(a, b) {
					if (a.name == b.name)
						return 0;
					if (a.name == null)
						return 1;
					if (b.name == null)
						return -1;
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
.face-entry {
	display: flex;
	align-items: center;
	min-height: 44px;
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
	height: 32px;
	width: 32px;
}

.icon-rename {
	padding: 14px;
	opacity: 0.7;
}
</style>