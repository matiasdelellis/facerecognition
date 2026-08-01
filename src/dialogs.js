/*
 * @copyright 2019-2026 Matias De lellis <mati86dl@gmail.com>
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
 * Vanilla JS replacement of the legacy jQuery dialogs.
 * Uses the native <dialog> element with showModal().
 */
(function (window, document) {
    'use strict';

    function once(fn) {
        let called = false;
        return function () {
            if (called) return;
            called = true;
            return fn.apply(this, arguments);
        };
    }

    function el(tag, props, children) {
        const node = document.createElement(tag);
        if (props) {
            for (const key in props) {
                if (key === 'class') {
                    node.className = props[key];
                } else if (key === 'text') {
                    node.textContent = props[key];
                } else if (key === 'style' && typeof props[key] === 'object') {
                    Object.assign(node.style, props[key]);
                } else {
                    node.setAttribute(key, props[key]);
                }
            }
        }
        if (children) {
            for (const child of children) {
                if (child) node.appendChild(child);
            }
        }
        return node;
    }

    function makeFaceThumb(face) {
        const img = el('img', {
            class: 'face-preview-dialog',
            src: face.thumbUrl,
            width: '50',
            height: '50',
        });
        if (face.fileUrl) {
            return el('a', {
                href: face.fileUrl,
                target: '_blank',
                rel: 'noreferrer noopener',
            }, [img]);
        }
        return img;
    }

    function getAutocomplete(query) {
        return fetch(OC.generateUrl('/apps/facerecognition/autocomplete/' + encodeURIComponent(query)), {
            headers: { 'OCS-APIRequest': 'true', 'requesttoken': OC.requestToken },
            credentials: 'same-origin',
        }).then((response) => {
            if (!response.ok) {
                return [];
            }
            return response.json();
        }).catch(() => []);
    }

    function attachAutocomplete(input) {
        if (typeof window.AutoComplete !== 'function') return;
        new window.AutoComplete({
            input: input,
            lookup(query) {
                return getAutocomplete(query);
            },
            silent: true,
            highlight: false,
        });
    }

    /**
     * Build a dialog scaffold with header, message and a content node.
     * Returns { dialog, content } so the caller can append the body parts.
     */
    function buildDialog(id, title, message) {
        const titleEl = el('h3', { class: 'fr-dialog-title', text: title });
        const messageEl = el('p', { class: 'fr-dialog-message', text: message });
        const content = el('div', { class: 'fr-dialog-content' });
        const dialog = el('dialog', { id: id, class: 'fr-dialog' }, [titleEl, messageEl, content]);
        document.body.appendChild(dialog);
        return { dialog: dialog, content: content };
    }

    function makeButton(label, kind) {
        return el('button', {
            type: 'button',
            class: kind || '',
            text: label,
        });
    }

    /**
     * Show a modal <dialog> with a list of buttons.
     * Resolves with the value the chosen button reports (or null on cancel/close).
     *
     * @param {HTMLDialogElement} dialog
     * @param {Array<{label: string, value: *, primary?: boolean}>} buttons
     * @returns {Promise<*>}
     */
    function showModal(dialog, buttons) {
        const actions = el('div', { class: 'fr-dialog-actions' });
        let resolver;
        const promise = new Promise((resolve) => {
            resolver = resolve;
        });
        const resolveOnce = once(function (value) {
            resolver(value);
        });

        buttons.forEach(function (spec) {
            const btn = makeButton(spec.label, spec.primary ? 'primary' : 'secondary');
            btn.addEventListener('click', function () {
                // Resolve before closing, since dialog.close() synchronously
                // dispatches the 'close' event (which would resolve with null).
                resolveOnce(spec.value);
                if (typeof dialog.close === 'function') {
                    dialog.close(spec.value);
                }
            });
            actions.appendChild(btn);
        });
        dialog.appendChild(actions);

        // ESC / dialog close events
        dialog.addEventListener('close', function () {
            // If a button was clicked, close() was called with its value and the
            // button handler already resolved. Otherwise, the user closed via
            // ESC and we resolve with null.
            resolveOnce(null);
        });

        // Click on backdrop closes (native <dialog> doesn't auto-handle this)
        dialog.addEventListener('click', function (event) {
            if (event.target === dialog) {
                if (typeof dialog.close === 'function') {
                    dialog.close();
                }
            }
        });

        if (typeof dialog.showModal === 'function') {
            dialog.showModal();
        } else {
            // Very old browsers: fall back to a manual overlay
            dialog.setAttribute('open', '');
        }
        return promise;
    }

    function destroyDialog(dialog) {
        if (dialog && dialog.parentNode) {
            dialog.parentNode.removeChild(dialog);
        }
    }

    const FrDialogs = {

        hide: function (faces, callback) {
            const id = 'fr-hide-dialog';
            const built = buildDialog(
                id,
                t('facerecognition', 'Hide person'),
                t('facerecognition', 'You can still see that person in the photos, but assigning a name will only be for that photo.')
            );

            built.content.appendChild(el('br'));
            const thumbs = el('div', { style: { textAlign: 'center' } });
            faces.forEach(function (face) {
                thumbs.appendChild(makeFaceThumb(face));
            });
            built.content.appendChild(thumbs);

            const wrappedCallback = callback !== undefined ? once(function (value) {
                destroyDialog(built.dialog);
                callback(value === true);
            }) : null;

            const promise = showModal(built.dialog, [
                { label: t('facerecognition', 'Cancel'), value: false },
                { label: t('facerecognition', 'Hide'), value: true, primary: true },
            ]);

            if (wrappedCallback) {
                promise.then(function (value) {
                    wrappedCallback(value === true);
                });
            }
            return promise;
        },

        rename: function (name, faces, callback) {
            const id = 'fr-rename-dialog';
            const built = buildDialog(
                id,
                t('facerecognition', 'Rename person'),
                t('facerecognition', 'Please enter a name to rename the person')
            );

            built.content.appendChild(el('br'));
            const thumbs = el('div', { style: { textAlign: 'center' } });
            faces.forEach(function (face) {
                thumbs.appendChild(makeFaceThumb(face));
            });
            built.content.appendChild(thumbs);

            const input = el('input', {
                type: 'text',
                id: id + '-input',
                placeholder: name,
                value: name,
            });
            built.content.appendChild(input);

            attachAutocomplete(input);

            input.addEventListener('keydown', function (event) {
                // Prevent the host app from interpreting the key
                event.stopPropagation();
                if (event.key === 'Enter') {
                    event.preventDefault();
                    const primary = built.dialog.querySelector('button.primary');
                    if (primary) primary.click();
                }
            });

            // Focus and select after the dialog is shown
            requestAnimationFrame(function () {
                input.focus();
                input.select();
            });

            const wrappedCallback = callback !== undefined ? once(function (result, value) {
                destroyDialog(built.dialog);
                callback(result, value);
            }) : null;

            const promise = showModal(built.dialog, [
                { label: t('facerecognition', 'Cancel'), value: 'cancel' },
                { label: t('facerecognition', 'Rename'), value: 'ok', primary: true },
            ]);

            if (wrappedCallback) {
                promise.then(function (value) {
                    if (value === 'ok') {
                        wrappedCallback(true, (input.value || '').trim());
                    } else {
                        wrappedCallback(false, input.value);
                    }
                });
            }
            return promise;
        },

        detachFace: function (face, oldName, callback) {
            const id = 'fr-detach-face-dialog';
            const built = buildDialog(
                id,
                t('facerecognition', 'This person is not {name}', { name: oldName }),
                t('facerecognition', 'Optionally you can assign the correct name')
            );

            built.content.appendChild(el('br'));
            const thumbs = el('div', { style: { textAlign: 'center' } });
            thumbs.appendChild(makeFaceThumb(face));
            built.content.appendChild(thumbs);

            const input = el('input', {
                type: 'text',
                id: id + '-input',
                placeholder: t('facerecognition', 'Please assign a name to this person.'),
            });
            built.content.appendChild(input);

            attachAutocomplete(input);

            input.addEventListener('keydown', function (event) {
                event.stopPropagation();
                if (event.key === 'Enter') {
                    event.preventDefault();
                    const primary = built.dialog.querySelector('button.primary');
                    if (primary) primary.click();
                }
            });

            requestAnimationFrame(function () {
                input.focus();
            });

            const wrappedCallback = callback !== undefined ? once(function (result, value) {
                destroyDialog(built.dialog);
                callback(result, value);
            }) : null;

            const promise = showModal(built.dialog, [
                { label: t('facerecognition', 'Cancel'), value: 'cancel' },
                { label: t('facerecognition', 'Save'), value: 'ok', primary: true },
            ]);

            if (wrappedCallback) {
                promise.then(function (value) {
                    if (value === 'ok') {
                        const trimmed = (input.value || '').trim();
                        wrappedCallback(true, trimmed.length > 0 ? trimmed : null);
                    } else {
                        wrappedCallback(false, null);
                    }
                });
            }
            return promise;
        },

        assignName: function (faces, callback) {
            const id = 'fr-assign-dialog';
            const built = buildDialog(
                id,
                t('facerecognition', 'Add name'),
                t('facerecognition', 'Please assign a name to this person.')
            );

            built.content.appendChild(el('br'));
            const thumbs = el('div', { style: { textAlign: 'center' } });
            faces.forEach(function (face) {
                thumbs.appendChild(makeFaceThumb(face));
            });
            built.content.appendChild(thumbs);

            const input = el('input', {
                type: 'text',
                id: id + '-input',
                placeholder: t('facerecognition', 'Please assign a name to this person.'),
            });
            built.content.appendChild(input);

            attachAutocomplete(input);

            input.addEventListener('keydown', function (event) {
                event.stopPropagation();
                if (event.key === 'Enter') {
                    event.preventDefault();
                    const primary = built.dialog.querySelector('button.primary');
                    if (primary) primary.click();
                }
            });

            requestAnimationFrame(function () {
                input.focus();
            });

            const wrappedCallback = callback !== undefined ? once(function (result, value) {
                destroyDialog(built.dialog);
                callback(result, value);
            }) : null;

            const promise = showModal(built.dialog, [
                { label: t('facerecognition', 'Ignore'), value: 'ignore' },
                { label: t('facerecognition', 'Skip for now'), value: 'skip' },
                { label: t('facerecognition', 'Save'), value: 'ok', primary: true },
            ]);

            if (wrappedCallback) {
                promise.then(function (value) {
                    if (value === 'ok') {
                        wrappedCallback(true, (input.value || '').trim());
                    } else if (value === 'skip') {
                        wrappedCallback(true, '');
                    } else if (value === 'ignore') {
                        wrappedCallback(true, null);
                    } else {
                        wrappedCallback(false, '');
                    }
                });
            }
            return promise;
        },

        assignIgnored: function (faces, callback) {
            const id = 'fr-assign-ignored-dialog';
            const built = buildDialog(
                id,
                t('facerecognition', 'Add name'),
                t('facerecognition', 'Please assign a name to this person.')
            );

            built.content.appendChild(el('br'));
            const thumbs = el('div', { style: { textAlign: 'center' } });
            faces.forEach(function (face) {
                thumbs.appendChild(makeFaceThumb(face));
            });
            built.content.appendChild(thumbs);

            const input = el('input', {
                type: 'text',
                id: id + '-input',
                placeholder: t('facerecognition', 'Please assign a name to this person.'),
            });
            built.content.appendChild(input);

            attachAutocomplete(input);

            input.addEventListener('keydown', function (event) {
                event.stopPropagation();
                if (event.key === 'Enter') {
                    event.preventDefault();
                    const primary = built.dialog.querySelector('button.primary');
                    if (primary) primary.click();
                }
            });

            requestAnimationFrame(function () {
                input.focus();
            });

            const wrappedCallback = callback !== undefined ? once(function (result, value) {
                destroyDialog(built.dialog);
                callback(result, value);
            }) : null;

            const promise = showModal(built.dialog, [
                { label: t('facerecognition', 'Keep ignored'), value: 'ignore' },
                { label: t('facerecognition', 'Save'), value: 'ok', primary: true },
            ]);

            if (wrappedCallback) {
                promise.then(function (value) {
                    if (value === 'ok') {
                        wrappedCallback(true, (input.value || '').trim());
                    } else if (value === 'ignore') {
                        wrappedCallback(true, null);
                    } else {
                        wrappedCallback(false, '');
                    }
                });
            }
            return promise;
        },
    };

    window.FrDialogs = FrDialogs;
})(window, document);
