'use strict';

import { showError, showSuccess } from '@nextcloud/dialogs';

(function () {
    const state = {
        OK: 0,
        FALSE: 1,
        SUCCESS: 2,
        ERROR: 3,
    };

    /*
     * Helpers
     */
    function $(selector) {
        return document.querySelector(selector);
    }

    function show(el) {
        if (el) el.style.display = '';
    }

    function hide(el) {
        if (el) el.style.display = 'none';
    }

    function setText(el, text) {
        if (el) el.textContent = text;
    }

    function getJson(url, params) {
        let finalUrl = url;
        if (params) {
            const qs = new URLSearchParams();
            for (const key in params) {
                qs.append(key, params[key]);
            }
            finalUrl += (url.indexOf('?') === -1 ? '?' : '&') + qs.toString();
        }
        return fetch(finalUrl, {
            headers: { 'OCS-APIRequest': 'true', 'requesttoken': OC.requestToken },
            credentials: 'same-origin',
        }).then((response) => {
            if (!response.ok) {
                throw new Error('Request failed: ' + response.status);
            }
            return response.json();
        });
    }

    function postForm(url, data) {
        const body = new URLSearchParams();
        for (const key in data) {
            body.append(key, data[key]);
        }
        return fetch(url, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
                'OCS-APIRequest': 'true',
                'requesttoken': OC.requestToken,
            },
            credentials: 'same-origin',
            body: body.toString(),
        }).then((response) => {
            if (!response.ok) {
                throw new Error('Request failed: ' + response.status);
            }
            return response.json();
        });
    }

    /*
     * Progress
     */
    function checkProgress() {
        getJson(OC.generateUrl('/apps/facerecognition/process')).then((progress) => {
            const textEl = $('#progress-text');
            const barEl = $('#progress-bar');
            let desc = '';
            if (progress.status) {
                if (progress.processedImages == progress.totalImages) {
                    desc = t('facerecognition', 'The analysis is finished');
                    desc += ' - ';
                    desc += n('facerecognition', '%n image was analyzed', '%n images were analyzed', progress.totalImages);
                } else {
                    const queuedImages = (progress.totalImages - progress.processedImages);
                    const estimatedFinalizeDate = Date.now() + progress.estimatedFinalize * 1000;
                    desc = t('facerecognition', 'Analyzing images');
                    desc += ' - ';
                    desc += n('facerecognition', '%n image detected', '%n images detected', progress.totalImages);
                    desc += ' - ';
                    desc += n('facerecognition', '%n image in queue', '%n images in queue', queuedImages);
                    desc += ' - ';
                    desc += t('facerecognition', 'Ends approximately {estimatedFinalize}', { estimatedFinalize: OC.Util.relativeModifiedDate(estimatedFinalizeDate) });
                }
                setText(textEl, desc);
                if (barEl) {
                    barEl.value = progress.processedImages;
                    barEl.max = progress.totalImages;
                }
            } else {
                if (barEl) barEl.value = 0;
                desc = t('facerecognition', 'The analysis is not started yet');
                desc += ' - ';
                desc += n('facerecognition', '%n image in queue', '%n images in queue', progress.totalImages);
                setText(textEl, desc);
            }
        }).catch(() => {
            /* swallow polling errors */
        });
    }

    /*
     * ImageArea
     */
    function getImageArea() {
        getJson(OC.generateUrl('apps/facerecognition/getappvalue'), {
            type: 'analysis_image_area',
        }).then((data) => {
            if (data.status === state.OK) {
                const imageArea = parseInt(data.value);
                const rangeEl = $('#image-area-range');
                const valueEl = $('#image-area-value');
                if (rangeEl) rangeEl.value = imageArea;
                setText(valueEl, getFourByThreeRelation(imageArea));
            }
        }).catch(() => { /* ignore */ });
    }

    function bindImageArea() {
        const range = $('#image-area-range');
        const value = $('#image-area-value');
        const restore = $('#restore-image-area');
        const save = $('#save-image-area');
        if (!range) return;

        range.addEventListener('input', () => {
            setText(value, getFourByThreeRelation(range.value));
            show(restore);
            show(save);
        });

        if (restore) {
            restore.addEventListener('click', (event) => {
                event.preventDefault();
                getImageArea();
                hide(restore);
                hide(save);
            });
        }

        if (save) {
            save.addEventListener('click', (event) => {
                event.preventDefault();
                postForm(OC.generateUrl('apps/facerecognition/setappvalue'), {
                    type: 'analysis_image_area',
                    value: range.value.toString(),
                }).then((data) => {
                    if (data.status === state.SUCCESS) {
                        showSuccess(t('facerecognition', 'The changes were saved. It will be taken into account in the next analysis.'));
                        hide(restore);
                        hide(save);
                    } else {
                        const suggestedImageArea = parseInt(data.value);
                        range.value = suggestedImageArea;
                        setText(value, getFourByThreeRelation(suggestedImageArea));
                        let message = t('facerecognition', 'The change could not be applied.');
                        message += ' - ' + data.message;
                        showError(message);
                    }
                }).catch(() => { /* ignore */ });
            });
        }
    }

    /*
     * Sensitivity
     */
    function getSensitivity() {
        getJson(OC.generateUrl('apps/facerecognition/getappvalue'), {
            type: 'sensitivity',
        }).then((data) => {
            if (data.status === state.OK) {
                const sensitivity = parseFloat(data.value);
                const rangeEl = $('#sensitivity-range');
                const valueEl = $('#sensitivity-value');
                if (rangeEl) rangeEl.value = sensitivity;
                setText(valueEl, sensitivity);
            }
        }).catch(() => { /* ignore */ });
    }

    function bindSensitivity() {
        const range = $('#sensitivity-range');
        const value = $('#sensitivity-value');
        const restore = $('#restore-sensitivity');
        const save = $('#save-sensitivity');
        if (!range) return;

        range.addEventListener('input', () => {
            setText(value, range.value);
            show(restore);
            show(save);
        });

        if (restore) {
            restore.addEventListener('click', (event) => {
                event.preventDefault();
                getSensitivity();
                hide(restore);
                hide(save);
            });
        }

        if (save) {
            save.addEventListener('click', (event) => {
                event.preventDefault();
                postForm(OC.generateUrl('apps/facerecognition/setappvalue'), {
                    type: 'sensitivity',
                    value: range.value.toString(),
                }).then((data) => {
                    if (data.status === state.SUCCESS) {
                        showSuccess(t('facerecognition', 'The changes were saved. It will be taken into account in the next analysis.'));
                        hide(restore);
                        hide(save);
                    }
                }).catch(() => { /* ignore */ });
            });
        }
    }

    /*
     * Confidence
     */
    function getMinConfidence() {
        getJson(OC.generateUrl('apps/facerecognition/getappvalue'), {
            type: 'min_confidence',
        }).then((data) => {
            if (data.status === state.OK) {
                const confidence = parseFloat(data.value);
                const rangeEl = $('#min-confidence-range');
                const valueEl = $('#min-confidence-value');
                if (rangeEl) rangeEl.value = confidence;
                setText(valueEl, confidence);
            }
        }).catch(() => { /* ignore */ });
    }

    function bindMinConfidence() {
        const range = $('#min-confidence-range');
        const value = $('#min-confidence-value');
        const restore = $('#restore-min-confidence');
        const save = $('#save-min-confidence');
        if (!range) return;

        range.addEventListener('input', () => {
            setText(value, range.value);
            show(restore);
            show(save);
        });

        if (restore) {
            restore.addEventListener('click', (event) => {
                event.preventDefault();
                getMinConfidence();
                hide(restore);
                hide(save);
            });
        }

        if (save) {
            save.addEventListener('click', (event) => {
                event.preventDefault();
                postForm(OC.generateUrl('apps/facerecognition/setappvalue'), {
                    type: 'min_confidence',
                    value: range.value.toString(),
                }).then((data) => {
                    if (data.status === state.SUCCESS) {
                        showSuccess(t('facerecognition', 'The changes were saved. It will be taken into account in the next analysis.'));
                        hide(restore);
                        hide(save);
                    }
                }).catch(() => { /* ignore */ });
            });
        }
    }

    /*
     * Min Cluster Size
     */
    function getMinFacesInCluster() {
        getJson(OC.generateUrl('apps/facerecognition/getappvalue'), {
            type: 'min_faces_in_cluster',
        }).then((data) => {
            if (data.status === state.OK) {
                const noFaces = parseInt(data.value);
                const rangeEl = $('#min-no-faces-range');
                const valueEl = $('#min-no-faces-value');
                if (rangeEl) rangeEl.value = noFaces;
                setText(valueEl, noFaces);
            }
        }).catch(() => { /* ignore */ });
    }

    function bindMinFacesInCluster() {
        const range = $('#min-no-faces-range');
        const value = $('#min-no-faces-value');
        const restore = $('#restore-min-no-faces');
        const save = $('#save-min-no-faces');
        if (!range) return;

        range.addEventListener('input', () => {
            setText(value, range.value);
            show(restore);
            show(save);
        });

        if (restore) {
            restore.addEventListener('click', (event) => {
                event.preventDefault();
                getMinFacesInCluster();
                hide(restore);
                hide(save);
            });
        }

        if (save) {
            save.addEventListener('click', (event) => {
                event.preventDefault();
                postForm(OC.generateUrl('apps/facerecognition/setappvalue'), {
                    type: 'min_faces_in_cluster',
                    value: range.value.toString(),
                }).then((data) => {
                    if (data.status === state.SUCCESS) {
                        showSuccess(t('facerecognition', 'Done'));
                        hide(restore);
                        hide(save);
                    }
                }).catch(() => { /* ignore */ });
            });
        }
    }

    /*
     * Utils
     */
    function getFourByThreeRelation(area) {
        const width = Math.sqrt(area * 4 / 3);
        const height = (width * 3 / 4);
        return Math.floor(width) + 'x' + Math.floor(height) + ' (4x3)';
    }

    /*
     * Bootstrap
     */
    function init() {
        bindImageArea();
        bindSensitivity();
        bindMinConfidence();
        bindMinFacesInCluster();

        getImageArea();
        getSensitivity();
        getMinConfidence();
        getMinFacesInCluster();

        checkProgress();
        window.setInterval(checkProgress, 5000);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
