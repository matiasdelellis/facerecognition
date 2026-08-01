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
	<li class='face-entry' :data-id='person.cluster_id'>
		<template v-if="person.name">
			<a :href="person.photos_url" :title='seePhotosTitle' target="_blank" rel="noreferrer noopener" class="face-thumb">
				<img class='face-preview' :src='person.thumb_url' width="48" height="48"/>
			</a>
			<a :href="person.photos_url" :title='seePhotosTitle' target="_blank" rel="noreferrer noopener" class="face-name">
				<h5>{{ person.name }}</h5>
			</a>
			<a :title='wrongPersonTitle' rel="noreferrer noopener" class="icon-action icon-disabled-user" v-on:click="detachFace($event)"/>
		</template>
		<template v-else>
			<template v-if="!editing">
				<a :title='addNamePersonTitle' rel="noreferrer noopener" class="face-thumb" v-on:click="nameEdit($event)">
					<img class='face-preview unknown-name' :src='person.thumb_url' width="48" height="48"/>
				</a>
				<a :title='addNamePersonTitle' rel="noreferrer noopener" class="face-name unknown-name" v-on:click="nameEdit($event)">
					<h5>{{ addNamePersonTitle }}</h5>
				</a>
				<a :title='addNamePersonTitle' rel="noreferrer noopener" target="_blank" class="icon-action icon-rename" v-on:click="nameEdit($event)"/>
			</template>
			<template v-else>
				<a :title='addNamePersonTitle' rel="noreferrer noopener" class="face-thumb">
					<img class='face-preview unknown-name' :src='person.thumb_url' width="48" height="48"/>
				</a>
				<AutocompleteInput
					ref="autocomplete"
					:value="newName"
					:placeholder="addNamePersonTitle"
					:title="addNamePersonTitle"
					@update:value="newName = $event"
					@submit="nameSubmit"
					@cancel="cancelEdit" />
				<a :title='addNamePersonTitle' rel="noreferrer noopener" target="_blank" class="icon-action icon-confirm" v-on:click="confirmFromClick($event)"/>
			</template>
		</template>
	</li>
</template>

<script>
import Axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { translate as t } from '@nextcloud/l10n'
import { emit } from '@nextcloud/event-bus'

import AutocompleteInput from '../components/AutocompleteInput.vue'

export default {

	name: 'PersonRow',

	components: {
		AutocompleteInput,
	},

	props: {
		person: {
			type: Object,
			required: true,
		},
	},

	setup() {
		return { t }
	},

	data() {
		return {
			editing: false,
			newName: '',
		}
	},

	computed: {
		seePhotosTitle() {
			return t('facerecognition', 'See other photos')
		},
		addNamePersonTitle() {
			return t('facerecognition', 'Add name')
		},
		wrongPersonTitle() {
			return t('facerecognition', 'This person is not {name}', { name: this.person.name })
		},
	},

	methods: {
		nameEdit(event) {
			if (event) {
				event.preventDefault()
				event.stopPropagation()
			}
			this.newName = ''
			this.editing = true
			this.$nextTick(() => {
				const ac = this.$refs.autocomplete
				if (ac) ac.focus()
			})
		},

		cancelEdit() {
			this.editing = false
			this.newName = ''
		},

		/**
		 * Triggered by the autocomplete's `submit` event (Enter key) and the
		 * confirm icon click. Forwards the typed name to the server.
		 */
		nameSubmit() {
			const name = this.newName.trim()
			if (name === '') {
				return
			}
			this.doNameSubmit(this.person, name)
			this.editing = false
		},

		/** Click on the confirm icon: stop propagation so the row click does not fire. */
		confirmFromClick(event) {
			if (event) {
				event.preventDefault()
				event.stopPropagation()
			}
			this.nameSubmit()
		},

		detachFace(event) {
			if (event) {
				event.preventDefault()
				event.stopPropagation()
			}
			OC.dialogs.confirm(
				t('facerecognition', 'This photo will be separated from the person. If you rename it again, it will only be done on this photo. If you want to change the name of all the photos of this person, you must go to the image view and edit there.'),
				t('facerecognition', 'This person is not {name}', { name: this.person.name }),
				(success) => {
					if (success) {
						this.doDetachFace(this.person)
					}
				}
			)
		},

		doDetachFace(person) {
			Axios.put(generateUrl('/apps/facerecognition/cluster/' + person.cluster_id + '/detach'), {
				face: person.face_id,
			}).then(() => {
				emit('facerecognition:person:updated')
			}).catch((error) => {
				console.error('There was an error applying that change', error)
			})
		},

		doNameSubmit(person, name) {
			Axios.put(generateUrl('/apps/facerecognition/cluster/' + person.cluster_id), {
				name: name,
			}).then(() => {
				emit('facerecognition:person:updated')
			}).catch((error) => {
				console.error('Error renaming person', error)
			})
		},
	},
}
</script>

<style scoped>

.face-entry {
	display: flex;
	align-items: center;
	margin-bottom: 8px;
}

.face-thumb {
	flex: 0 0 auto;
	width: 48px;
	height: 48px;
	display: inline-block;
}

.face-name {
	flex: 1 1 auto;
	padding: 8px;
	min-width: 0;
}

.face-name h5 {
	margin: 0;
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
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
	cursor: pointer;
}

.icon-action:hover {
	opacity: 1;
	background-color: rgba(127,127,127,.25) !important;
}

</style>
