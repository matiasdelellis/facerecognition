/*
 * @copyright 2019-2021 Matias De lellis <mati86dl@gmail.com>
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

	hide: function (faces, callback) {
		return $.when(this._getMessageTemplate()).then(function ($tmpl) {
			var dialogName = 'fr-hidee-dialog';
			var dialogId = '#' + dialogName;
			var $dlg = $tmpl.octemplate({
				dialog_name: dialogName,
				title: t('facerecognition', 'Hide person'),
				message: t('facerecognition', 'You can still see that person in the photos, but assigning a name will only be for that photo.'),
				type: 'none'
			});

			$dlg.append($('<br/>'));

			var div = $('<div/>').attr('style', 'text-align: center');
			$dlg.append(div);

			for (var face of faces) {
				if (face['fileUrl'] !== undefined) {
					div.append($('<a href="' + face['fileUrl'] + '" target="_blank"><img class="face-preview-dialog" src="' + face['thumbUrl'] + '" width="50" height="50"/></a>'));
				} else {
					div.append($('<img class="face-preview-dialog" src="' + face['thumbUrl'] + '" width="50" height="50"/>'));
				}
			}

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
						callback(false);
					}
				}
			}, {
				text: t('facerecognition', 'Hide'),
				click: function () {
					$(dialogId).ocdialog('close');
					if (callback !== undefined) {
						callback(true);
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
						callback(false);
					}
				}
			});
		});
	},

	rename: function (name, faces, callback) {
		return $.when(this._getMessageTemplate()).then(function ($tmpl) {
			var dialogName = 'fr-rename-dialog';
			var dialogId = '#' + dialogName;
			var $dlg = $tmpl.octemplate({
				dialog_name: dialogName,
				title: t('facerecognition', 'Rename person'),
				message: t('facerecognition', 'Please enter a name to rename the person'),
				type: 'none'
			});

			$dlg.append($('<br/>'));

			var div = $('<div/>').attr('style', 'text-align: center');
			$dlg.append(div);

			for (var face of faces) {
				if (face['fileUrl'] !== undefined) {
					div.append($('<a href="' + face['fileUrl'] + '" target="_blank"><img class="face-preview-dialog" src="' + face['thumbUrl'] + '" width="50" height="50"/></a>'));
				} else {
					div.append($('<img class="face-preview-dialog" src="' + face['thumbUrl'] + '" width="50" height="50"/>'));
				}
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

			new AutoComplete({
				input: document.getElementById(dialogName + "-input"),
				lookup (query) {
					return new Promise(resolve => {
						$.get(OC.generateUrl('/apps/facerecognition/autocomplete/' + query)).done(function (names) {
							resolve(names);
						});
					});
				},
				silent: true,
				highlight: false
			});

			$(dialogId + "-input").keydown(function(event) {
				// It only prevents the that change the image when you press arrow keys.
				event.stopPropagation();
				if (event.key === "Enter") {
					// It only prevents the that change the image when you press enter.
					event.preventDefault();
				}
			});

			input.focus();
			input.select();
		});
	},

	detachFace: function (face, oldName, callback) {
		return $.when(this._getMessageTemplate()).then(function ($tmpl) {
			var dialogName = 'fr-detach-face-dialog';
			var dialogId = '#' + dialogName;
			var $dlg = $tmpl.octemplate({
				dialog_name: dialogName,
				title: t('facerecognition', 'This person is not {name}', {name: oldName}),
				message: t('facerecognition', 'Optionally you can assign the correct name'),
				type: 'none'
			});

			$dlg.append($('<br/>'));

			var div = $('<div/>').attr('style', 'text-align: center');
			$dlg.append(div);

			div.append($('<img class="face-preview-dialog" src="' + face['thumbUrl'] + '" width="50" height="50"/>'));

			var input = $('<input/>').attr('type', 'text').attr('id', dialogName + '-input').attr('placeholder', t('facerecognition', 'Please assign a name to this person.'));
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
						callback(false, null);
					}
				},
			}, {
				text: t('facerecognition', 'Save'),
				click: function () {
					$(dialogId).ocdialog('close');
					if (callback !== undefined) {
						callback(true, input.val().trim().length > 0 ? input.val().trim() : null);
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
						callback(false, null);
					}
				}
			});

			new AutoComplete({
				input: document.getElementById(dialogName + "-input"),
				lookup (query) {
					return new Promise(resolve => {
						$.get(OC.generateUrl('/apps/facerecognition/autocomplete/' + query)).done(function (names) {
							resolve(names);
						});
					});
				},
				silent: true,
				highlight: false
			});

			$(dialogId + "-input").keydown(function(event) {
				// It only prevents the that change the image when you press arrow keys.
				event.stopPropagation();
				if (event.key === "Enter") {
					// It only prevents the that change the image when you press enter.
					event.preventDefault();
				}
			});

			input.focus();
		});
	},

	assignName: function (faces, callback) {
		return $.when(this._getMessageTemplate()).then(function ($tmpl) {
			var dialogName = 'fr-assign-dialog';
			var dialogId = '#' + dialogName;
			var $dlg = $tmpl.octemplate({
				dialog_name: dialogName,
				title: t('facerecognition', 'Add name'),
				message: t('facerecognition', 'Please assign a name to this person.'),
				type: 'none'
			});

			$dlg.append($('<br/>'));

			var div = $('<div/>').attr('style', 'text-align: center');
			$dlg.append(div);

			for (var face of faces) {
				if (face['fileUrl'] !== undefined) {
					div.append($('<a href="' + face['fileUrl'] + '" target="_blank"><img class="face-preview-dialog" src="' + face['thumbUrl'] + '" width="50" height="50"/></a>'));
				} else {
					div.append($('<img class="face-preview-dialog" src="' + face['thumbUrl'] + '" width="50" height="50"/>'));
				}
			}

			var input = $('<input/>').attr('type', 'text').attr('id', dialogName + '-input').attr('placeholder', t('facerecognition', 'Please assign a name to this person.'));
			$dlg.append(input);

			$('body').append($dlg);

			// wrap callback in _.once():
			// only call callback once and not twice (button handler and close
			// event) but call it for the close event, if ESC or the x is hit
			if (callback !== undefined) {
				callback = _.once(callback);
			}

			var buttonlist = [{
				text: t('facerecognition', 'Ignore'),
				click: function () {
					$(dialogId).ocdialog('close');
					if (callback !== undefined) {
						callback(true, null);
					}
				},
			}, {
				text: t('facerecognition', 'Skip for now'),
				click: function () {
					$(dialogId).ocdialog('close');
					if (callback !== undefined) {
						callback(true, '');
					}
				},
				defaultButton: false
			}, {
				text: t('facerecognition', 'Save'),
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

			new AutoComplete({
				input: document.getElementById(dialogName + "-input"),
				lookup (query) {
					return new Promise(resolve => {
						$.get(OC.generateUrl('/apps/facerecognition/autocomplete/' + query)).done(function (names) {
							resolve(names);
						});
					});
				},
				silent: true,
				highlight: false
			});

			$(dialogId + "-input").keydown(function(event) {
				// It only prevents the that change the image when you press arrow keys.
				event.stopPropagation();
				if (event.key === "Enter") {
					// It only prevents the that change the image when you press enter.
					event.preventDefault();
				}
			});

			input.focus();
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