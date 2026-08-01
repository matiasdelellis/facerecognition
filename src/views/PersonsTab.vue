<!--
  - @copyright Copyright (c) 2020-2026 Matias De lellis <mati86dl@gmail.com>
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
  - along with this program.  If not, see <http://www.gnu.org/licenses/>.
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
						:key="person.cluster_id"
						:person="person"
					/>
				</ul>
			</template>
			<template v-if="this.unknownPersons.length > 0">
				<ul class='faces-list'>
					<PersonRow v-for="person in this.unknownPersons"
						:key="person.cluster_id"
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

import { translate as t, translatePlural as n } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import Axios from '@nextcloud/axios'
import { subscribe, unsubscribe } from '@nextcloud/event-bus'

import PersonRow from './PersonRow.vue'

export default {

	name: 'PersonsTab',

	components: {
		PersonRow,
	},

	props: {
		/** The current node the sidebar was opened for. */
		node: {
			type: Object,
			required: true,
		},
		/** The folder currently shown in the files app (if any). */
		folder: {
			type: Object,
			default: null,
		},
		/** The currently active view. */
		view: {
			type: Object,
			default: null,
		},
		/** Whether this tab is the active one. */
		active: {
			type: Boolean,
			default: false,
		},
	},

	emits: [],

	setup() {
		return { t, n }
	},

	data() {
		return {
			error: '',
			loading: true,
			isEnabledByUser: false,
			isAllowedFile: false,
			isParentEnabled: false,
			isProcessed: false,
			isDirectory: false,
			knownPersons: [],
			unknownPersons: [],
			isChildrensEnabled: false,
		}
	},

	computed: {
		settingsUrl() {
			return t('facerecognition', 'Open <a target="_blank" href="{settingsLink}">settings ↗</a> to enable it', { settingsLink: generateUrl('settings/user/facerecognition') })
		},
		faqUrl() {
			return t('facerecognition', 'See <a target="_blank" href="{docsLink}">documentation ↗</a>.', { docsLink: 'https://github.com/matiasdelellis/facerecognition/wiki/FAQ' })
		},
	},

	watch: {
		// The new sidebar passes a new `node` whenever the user opens a
		// different file or folder, so we re-fetch on that.
		node: {
			immediate: true,
			handler(newNode) {
				if (newNode) {
					this.resetState()
					this.getFacesInfo()
				}
			},
		},
	},

	mounted() {
		subscribe('facerecognition:person:updated', this.handlePersonUpdate)
	},

	beforeUnmount() {
		unsubscribe('facerecognition:person:updated', this.handlePersonUpdate)
	},

	methods: {
		handlePersonUpdate() {
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
			const node = this.node
			if (!node) return

			const isDirectory = node.type === 'folder'
			const infoUrl = generateUrl('/apps/facerecognition/' + (isDirectory ? 'folder' : 'file'))

			try {
				this.loading = true
				const response = await Axios.get(infoUrl, {
					params: { fullpath: node.path },
				})
				this.processFacesData(response.data, isDirectory)
				this.loading = false
			} catch (error) {
				this.error = error?.response?.data?.message || error?.message || ''
				this.loading = false
				console.error('Error loading info of image', error)
			}
		},

		async enableDirectoryCheck(event) {
			const isEnabled = event.target.checked
			const infoUrl = generateUrl('/apps/facerecognition/folder')
			try {
				const response = await Axios.put(infoUrl, {
					fullpath: this.node.path,
					detection: isEnabled,
				})
				this.processFacesData(response.data, true)
			} catch (error) {
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

			if (!data.enabled) {
				return
			}

			if (!isDirectory) {
				data.persons.forEach((person) => {
					if (person.name != null) {
						this.knownPersons.push(person)
					} else {
						this.unknownPersons.push(person)
					}
				})
				this.knownPersons = this.knownPersons.sort((a, b) => {
					if (a.name > b.name) return 1
					if (a.name < b.name) return -1
					return 0
				})
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
