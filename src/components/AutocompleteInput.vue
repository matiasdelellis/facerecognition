<!--
  - @copyright Copyright (c) 2026 Matias De lellis <mati86dl@gmail.com>
  -
  - @author Matias De lellis <mati86dl@gmail.com>
  -
  - @license GNU AGPL version 3 or any later version
  -
  - Simple text input with debounced autocomplete that queries
  - /apps/facerecognition/autocomplete/{query} and lets the user pick from
  - the returned names. Arrow keys move the highlight, Enter either picks
  - the highlighted entry or, when there is no highlight, fires `submit` so
  - the parent can use the typed value as a new name. Escape fires `cancel`.
  -->
<template>
	<div class="facerecognition-autocomplete">
		<input
			ref="input"
			:value="value"
			type="text"
			class="facerecognition-autocomplete__input"
			:placeholder="placeholder"
			:title="title"
			:aria-label="title || placeholder"
			autocomplete="off"
			spellcheck="false"
			role="combobox"
			:aria-expanded="isOpen"
			aria-autocomplete="list"
			@input="onInput"
			@keydown="onKeydown"
			@focus="onFocus"
			@blur="onBlur"
		/>
		<ul
			v-if="isOpen && suggestions.length > 0"
			ref="list"
			class="facerecognition-autocomplete__list"
			role="listbox">
			<li
				v-for="(suggestion, index) in suggestions"
				:key="suggestion"
				:class="{ selected: index === selectedIndex }"
				role="option"
				:aria-selected="index === selectedIndex"
				@mousedown.prevent="select(suggestion)">
				{{ suggestion }}
			</li>
		</ul>
	</div>
</template>

<script>
import { generateUrl } from '@nextcloud/router'

const DEBOUNCE_MS = 150
const MIN_QUERY_LENGTH = 1

export default {
	name: 'AutocompleteInput',

	props: {
		value: {
			type: String,
			default: '',
		},
		placeholder: {
			type: String,
			default: '',
		},
		title: {
			type: String,
			default: '',
		},
	},

	emits: [
		'update:value',
		'submit',
		'cancel',
	],

	data() {
		return {
			suggestions: [],
			isOpen: false,
			selectedIndex: -1,
			debounceTimer: null,
			currentRequest: null,
		}
	},

	beforeUnmount() {
		if (this.debounceTimer) {
			clearTimeout(this.debounceTimer)
			this.debounceTimer = null
		}
		if (this.currentRequest) {
			this.currentRequest.abort()
			this.currentRequest = null
		}
	},

	methods: {
		/** Imperative focus, exposed so the parent can focus on edit. */
		focus() {
			const el = this.$refs.input
			if (el) el.focus()
		},

		onInput() {
			this.$emit('update:value', this.value)
			this.debouncedFetch()
		},

		debouncedFetch() {
			if (this.debounceTimer) clearTimeout(this.debounceTimer)
			this.debounceTimer = setTimeout(() => this.fetchSuggestions(), DEBOUNCE_MS)
		},

		fetchSuggestions() {
			const query = this.value.trim()
			if (query.length < MIN_QUERY_LENGTH) {
				this.suggestions = []
				this.isOpen = false
				this.selectedIndex = -1
				return
			}

			if (this.currentRequest) {
				this.currentRequest.abort()
			}
			const controller = new AbortController()
			this.currentRequest = controller

			fetch(generateUrl('/apps/facerecognition/autocomplete/' + encodeURIComponent(query)), {
				headers: { requesttoken: OC.requestToken },
				credentials: 'same-origin',
				signal: controller.signal,
			}).then((response) => {
				if (!response.ok) {
					throw new Error('Request failed: ' + response.status)
				}
				return response.json()
			}).then((names) => {
				if (this.currentRequest !== controller) return
				this.suggestions = Array.isArray(names) ? names : []
				this.isOpen = this.suggestions.length > 0
				this.selectedIndex = -1
			}).catch((err) => {
				if (err?.name === 'AbortError') return
				this.suggestions = []
				this.isOpen = false
				this.selectedIndex = -1
			}).then(() => {
				if (this.currentRequest === controller) {
					this.currentRequest = null
				}
			})
		},

		select(name) {
			this.$emit('update:value', name)
			this.suggestions = []
			this.isOpen = false
			this.selectedIndex = -1
		},

		onKeydown(event) {
			if (event.key === 'Enter') {
				event.preventDefault()
				event.stopPropagation()
				if (this.selectedIndex >= 0 && this.suggestions[this.selectedIndex]) {
					this.select(this.suggestions[this.selectedIndex])
				}
				this.$emit('submit')
			} else if (event.key === 'ArrowDown') {
				if (this.suggestions.length > 0) {
					event.preventDefault()
					event.stopPropagation()
					this.selectedIndex = (this.selectedIndex + 1) % this.suggestions.length
					this.isOpen = true
				}
			} else if (event.key === 'ArrowUp') {
				if (this.suggestions.length > 0) {
					event.preventDefault()
					event.stopPropagation()
					this.selectedIndex = this.selectedIndex <= 0 ? this.suggestions.length - 1 : this.selectedIndex - 1
					this.isOpen = true
				}
			} else if (event.key === 'Escape') {
				event.preventDefault()
				event.stopPropagation()
				this.isOpen = false
				this.selectedIndex = -1
				this.$emit('cancel')
			} else if (event.key === 'Tab') {
				// Don't capture Tab; the parent might want to move focus.
				this.isOpen = false
				this.selectedIndex = -1
			}
		},

		onFocus() {
			if (this.suggestions.length > 0) {
				this.isOpen = true
			}
		},

		onBlur() {
			// Delay to allow mousedown on a suggestion to win the race against blur.
			setTimeout(() => {
				this.isOpen = false
				this.selectedIndex = -1
			}, 150)
		},
	},
}
</script>

<style scoped>
.facerecognition-autocomplete {
	position: relative;
	flex: 1 1 auto;
	min-width: 0;
}

.facerecognition-autocomplete__input {
	width: 100%;
	box-sizing: border-box;
}

.facerecognition-autocomplete__list {
	position: absolute;
	top: 100%;
	left: 0;
	right: 0;
	z-index: 1000;
	margin: 2px 0 0;
	padding: 0;
	list-style: none;
	background: var(--color-main-background, #fff);
	border: 1px solid var(--color-border, #ccc);
	border-radius: var(--border-radius, 3px);
	max-height: 200px;
	overflow-y: auto;
	box-shadow: 0 2px 8px rgba(0, 0, 0, 0.15);
}

.facerecognition-autocomplete__list li {
	padding: 6px 10px;
	cursor: pointer;
	color: var(--color-main-text, #222);
}

.facerecognition-autocomplete__list li.selected {
	background: var(--color-background-hover, #f5f5f5);
}
</style>
