/*
 * @copyright 2019 Matias De lellis <mati86dl@gmail.com>
 *
 * @author 2019 Matias De lellis <mati86dl@gmail.com>
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
 */

/**
 * this class to ease the usage of jquery dialogs
 */
const FrDialogs = {

	rename: function (name, faces, callback) {
		return $.when(this._getMessageTemplate()).then(function ($tmpl) {
			var dialogName = 'fr-rename-dialog';
			var dialogId = '#' + dialogName;
			var $dlg = $tmpl.octemplate({
				dialog_name: dialogName,
				title: t('facerecognition', 'Rename Person'),
				message: t('facerecognition', 'Please enter a name to rename the person'),
				type: 'none'
			});

			$dlg.append($('<br/>'));

			var div = $('<div/>').attr('style', 'text-align: center');
			$dlg.append(div);

			for (var face of faces) {
				var thumb = $('<img class="face-preview-dialog" src="' + face['thumbUrl'] + '" width="50" height="50"/>');
				div.append(thumb);
			}

			var input = $('<input/>').attr('type', 'text').attr('id', dialogName + '-input').attr('placeholder', name).attr('value', name);
			$dlg.append(input);

			$('body').append($dlg);

			// wrap callback in _.once():
			// only call callback once and not twice (button handler and close
			// event) but call it for the close event, if ESC or the x is hit
			if (callback !== undefined) {
				callback = _.once(callback);
			}

			var buttonlist = [{
				text: t('facerecognition', 'Cancel'),
				click: function () {
					$(dialogId).ocdialog('close');
					if (callback !== undefined) {
						callback(false, input.val().trim());
					}
				}
			}, {
				text: t('facerecognition', 'Rename'),
				click: function () {
					$(dialogId).ocdialog('close');
					if (callback !== undefined) {
						callback(true, input.val().trim());
					}
				},
				defaultButton: true
			}];

			$(dialogId).ocdialog({
				closeOnEscape: true,
				modal: true,
				buttons: buttonlist,
				close: function () {
					// callback is already fired if Yes/No is clicked directly
					if (callback !== undefined) {
						callback(false, input.val());
					}
				}
			});

			input.focus();
			input.select();
		});
	},
	assignName: function (faces, callback) {
		return $.when(this._getMessageTemplate()).then(function ($tmpl) {
			var dialogName = 'fr-assign-dialog';
			var dialogId = '#' + dialogName;
			var $dlg = $tmpl.octemplate({
				dialog_name: dialogName,
				title: t('facerecognition', 'Rename Person'),
				message: t('facerecognition', 'Please assign a name to this person.'),
				type: 'none'
			});

			$dlg.append($('<br/>'));

			var div = $('<div/>').attr('style', 'text-align: center');
			$dlg.append(div);

			for (var face of faces) {
				var thumb = $('<img class="face-preview-dialog" src="' + face['thumbUrl'] + '" width="50" height="50"/>');
				div.append(thumb);
			}

			var input = $('<input/>').attr('type', 'text').attr('id', dialogName + '-input').attr('placeholder', name).attr('value', name);
			$dlg.append(input);

			$('body').append($dlg);

			// wrap callback in _.once():
			// only call callback once and not twice (button handler and close
			// event) but call it for the close event, if ESC or the x is hit
			if (callback !== undefined) {
				callback = _.once(callback);
			}

			var buttonlist = [{
				text: t('facerecognition', 'I am not sure'),
				click: function () {
					$(dialogId).ocdialog('close');
					if (callback !== undefined) {
						callback(true, '');
					}
				},
			}, {
				text: t('facerecognition', 'Rename'),
				click: function () {
					$(dialogId).ocdialog('close');
					if (callback !== undefined) {
						callback(true, input.val().trim());
					}
				},
				defaultButton: true
			}];

			$(dialogId).ocdialog({
				closeOnEscape: true,
				modal: true,
				buttons: buttonlist,
				close: function () {
					// callback is already fired if Yes/No is clicked directly
					if (callback !== undefined) {
						callback(false, '');
					}
				}
			});

			input.focus();
			input.select();
		});
	},
	_getMessageTemplate: function () {
		var defer = $.Deferred();
		if (!this.$messageTemplate) {
			var self = this;
			$.get(OC.filePath('core', 'templates', 'message.html'), function (tmpl) {
				self.$messageTemplate = $(tmpl);
				defer.resolve(self.$messageTemplate);
			})
			.fail(function (jqXHR, textStatus, errorThrown) {
				defer.reject(jqXHR.status, errorThrown);
			});
		} else {
			defer.resolve(this.$messageTemplate);
		}
		return defer.promise();
	}

}