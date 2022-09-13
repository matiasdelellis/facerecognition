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
	<li class='face-entry' :data-id='person.person_id'>
	<template v-if="person.name">
		<a :href="person.photos_url" :title='seePhotosTitle' target="_blank" rel="noreferrer noopener" style="width: 48px;height: 48px;">
			<img class='face-preview' :src='person.thumb_url' width="48" height="48"/>
		</a>
		<a :href="person.photos_url" :title='seePhotosTitle' target="_blank" rel="noreferrer noopener" class="face-name">
			<h5>{{ person.name }}</h5>
		</a>
		<a :title='wrongPersonTitle' rel="noreferrer noopener" class="icon-action icon-disabled-user" v-on:click="detachFace"/>
	</template>
	<template v-else>
		<template v-if="!editing">
			<a :title='addNamePersonTitle' rel="noreferrer noopener" style="width: 48px;height: 48px;" v-on:click="nameEdit">
				<img class='face-preview unknown-name' :src='person.thumb_url' width="48" height="48"/>
			</a>
			<a :title='addNamePersonTitle' rel="noreferrer noopener" class="face-name unknown-name" v-on:click="nameEdit">
				<h5>{{ t('facerecognition', 'Add name') }}</h5>
			</a>
			<a :title='addNamePersonTitle' rel="noreferrer noopener" target="_blank" class="icon-action icon-rename" v-on:click="nameEdit"/>
		</template>
		<template v-else>
			<a :title='addNamePersonTitle' rel="noreferrer noopener" style="width: 48px;height: 48px;">
				<img class='face-preview unknown-name' :src='person.thumb_url' width="48" height="48"/>
			</a>
			<input ref="input" v-model="newName" :placeholder="addNamePersonTitle" :title="addNamePersonTitle" type="text" class="face-name face-name-input" @keydown.enter="nameSubmit" @keydown.esc="cancelEdit">
			<a :title='addNamePersonTitle' rel="noreferrer noopener" target="_blank" class="icon-action icon-confirm" v-on:click="nameSubmit"/>
		</template>
	</template>
	</li>
</template>

<script>
import Axios from '@nextcloud/axios'
import { emit } from '@nextcloud/event-bus'

export default {

	name: 'PersonRow',

	data() {
		return {
			editing: false,
			newName: "",
		}
	},

	props: {
		person: {
			type: Object,
			required: true,
		},
	},

	computed: {
		seePhotosTitle() {
			return t('facerecognition', 'See other photos')
		},
		addNamePersonTitle() {
			return t('facerecognition', 'Add name')
		},
		wrongPersonTitle() {
			return t('facerecognition', 'This person is not {name}', {name: this.person.name})
		},
	},

	methods: {
		nameEdit() {
			this.newName = ""
			this.editing = true
			this.$nextTick(() => {
				this.$refs.input.focus()
			})
		},

		cancelEdit() {
			event.preventDefault()
			event.stopPropagation()

			this.editing = false
		},

		nameSubmit() {
			event.preventDefault()
			event.stopPropagation()

			this.doNameSubmit(this.person, this.newName)
			this.editing = false
		},

		detachFace() {
			const self = this
			OC.dialogs.confirm(
				t('facerecognition', 'This photo will be separated from the person. If you rename it again, it will only be done on this photo. If you want to change the name of all the photos of this person, you must go to the image view and edit there.'),
				t('facerecognition', 'This person is not {name}', {name: this.person.name}),
				function(success) {
					if (success) {
						self.doDetachFace(self.person)
					}
				}
			)
		},

		doDetachFace(person) {
			Axios.put(OC.generateUrl('/apps/facerecognition/cluster/' + person.person_id + '/detach'), {
				face: person.face_id
			}).then(function (response) {
				emit('facerecognition:person:updated')
			}).catch(function (error) {
				self.error = error
				console.error('There was an error applying that change', error)
			})
		},

		doNameSubmit: function(person, name) {
			const self = this
			Axios.put(OC.generateUrl('/apps/facerecognition/cluster/' + person.person_id), {
				name: name
			}).then(function (response) {
				emit('facerecognition:person:updated')
			}).catch(function (error) {
				self.error = error
				console.error('Error renaming person', error)
			})
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