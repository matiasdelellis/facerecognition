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

	rename: function (name, thumbUrl, callback) {
		return $.when(this._getMessageTemplate()).then(function ($tmpl) {
			var dialogName = 'fr-rename-dialog-content';
			var dialogId = '#' + dialogName;
			var $dlg = $tmpl.octemplate({
				dialog_name: dialogName,
				title: t('facerecognition', 'Rename Person'),
				message: t('facerecognition', 'Please enter a name to rename the person'),
				type: 'none'
			});
			var div = $('<div/>').attr('style', 'display:flex; align-items: center');
			var thumb = $('<img class="face-preview-dialog" src="' + thumbUrl + '" width="50" height="50"/>');
			var input = $('<input/>').attr('type', 'text').attr('id', dialogName + '-input').attr('placeholder', name).attr('value', name);

			div.append(thumb);
			div.append(input);
			$dlg.append(div);

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
					if (callback !== undefined) {
						callback(false, input.val().trim());
					}
					$(dialogId).ocdialog('close');
				}
			}, {
				text: t('facerecognition', 'Rename'),
				click: function () {
					if (callback !== undefined) {
						callback(true, input.val().trim());
					}
					$(dialogId).ocdialog('close');
				},
				defaultButton: true
			}
			];

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
	suggestPersonName: function (name, faces, callback) {
		return $.when(this._getMessageTemplate()).then(function ($tmpl) {
			var dialogName = 'fr-suggest-dialog-content';
			var dialogId = '#' + dialogName;
			var $dlg = $tmpl.octemplate({
				dialog_name: dialogName,
				title: t('facerecognition', 'Suggestions'),
				message: t('facerecognition', 'Is it {personName}? Or a different person?', {personName: name}),
				type: 'none'
			});

			var div = $('<div/>').attr('style', 'display:flex; align-items: center');
			for (var face of faces) {
				var thumb = $('<img class="face-preview-dialog" src="' + face['thumb-url'] + '" width="50" height="50"/>');
				div.append(thumb);
			}
			$dlg.append(div);

			$('body').append($dlg);

			// wrap callback in _.once():
			// only call callback once and not twice (button handler and close
			// event) but call it for the close event, if ESC or the x is hit
			if (callback !== undefined) {
				callback = _.once(callback);
			}

			var buttonlist = [{
				text: t('facerecognition', 'I don\'t know'),
				click: function () {
					if (callback !== undefined) {
						$(dialogId).ocdialog('close');
					}
					callback(false, false);
				},
				defaultButton: false
			},{
				text: t('facerecognition', 'Yes'),
				click: function () {
					if (callback !== undefined) {
						$(dialogId).ocdialog('close');
					}
					callback(true, false);
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
						callback(false, true);
					}
				}
			});
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