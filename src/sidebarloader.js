/**
 * @copyright Copyright (c) 2020-2026 Matias De lellis <mati86dl@gmail.com>
 *
 * @author Matias De lellis <mati86dl@gmail.com>
 *
 * @license GNU AGPL version 3 or any later version
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 *
 */

import { defineAsyncComponent, defineCustomElement } from 'vue'
import { getSidebar } from '@nextcloud/files'
import { translate as t } from '@nextcloud/l10n'

const TAB_TAG_NAME = 'facerecognition-files-sidebar-tab'

/**
 * Inline SVG of the MDI "account-multiple" icon, kept here so the tab is
 * self-contained and we don't have to ship a separate icon file.
 */
const TAB_ICON_SVG = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="20" height="20" fill="currentColor"><path d="M16 11c1.66 0 2.99-1.34 2.99-3S17.66 5 16 5c-1.66 0-3 1.34-3 3s1.34 3 3 3m-8 0c1.66 0 2.99-1.34 2.99-3S9.66 5 8 5C6.34 5 5 6.34 5 8s1.34 3 3 3m0 2c-2.33 0-7 1.17-7 3.5V19h14v-2.5c0-2.33-4.67-3.5-7-3.5m8 0c-.29 0-.62.02-.97.05 1.16.84 1.97 1.97 1.97 3.45V19h6v-2.5c0-2.33-4.67-3.5-7-3.5"/></svg>'

/**
 * Decide whether the tab should be shown for the current node. The tab is
 * only useful for files and folders, and the user must have face recognition
 * enabled. The user-enabled check is also done on the server side, so the
 * server response is what gates the actual content, this is just to avoid
 * an unnecessary mount.
 */
function tabEnabled({ node }) {
	if (!node) return false
	return node.type === 'file' || node.type === 'folder'
}

getSidebar().registerTab({
	id: 'facerecognition',
	displayName: t('facerecognition', 'People'),
	iconSvgInline: TAB_ICON_SVG,
	order: 50,
	tagName: TAB_TAG_NAME,
	enabled: tabEnabled,

	async onInit() {
		const PersonsTab = defineAsyncComponent(() => import('./views/PersonsTab.vue'))
		// shadowRoot: false so the global Nextcloud stylesheet still applies.
		const WebComponent = defineCustomElement(PersonsTab, { shadowRoot: false })
		if (!customElements.get(TAB_TAG_NAME)) {
			customElements.define(TAB_TAG_NAME, WebComponent)
		}
	},
})
