'use strict';
$(document).ready(function() {
    const state = {
        OK: 0,
        FALSE: 1,
        SUCCESS: 2,
        ERROR:  3
    }

    /*
     * Progress
     */
    function checkProgress() {
        $.get(OC.generateUrl('/apps/facerecognition/process')).done(function (progress) {
            if (progress.status) {
                var desc = '';
                if (progress.processedImages == progress.totalImages) {
                    desc = t('facerecognition', 'The analysis is finished');
                    desc += ' - ';
                    desc += n('facerecognition', '%n image was analyzed', '%n images were analyzed', progress.totalImages);
                } else {
                    var queuedImages = (progress.totalImages - progress.processedImages);
                    var estimatedFinalizeDate = Date.now() + progress.estimatedFinalize*1000;
                    desc = t('facerecognition', 'Analyzing images');
                    desc += ' - ';
                    desc += n('facerecognition', '%n image detected', '%n images detected', progress.totalImages);
                    desc += ' - ';
                    desc += n('facerecognition', '%n image in queue', '%n images in queue', queuedImages);
                    desc += ' - ';
                    desc += t('facerecognition', 'Ends approximately {estimatedFinalize}', {estimatedFinalize: OC.Util.relativeModifiedDate(estimatedFinalizeDate)});
                }
                $('#progress-text').html(desc);
                $('#progress-bar').attr('value', progress.processedImages);
                $('#progress-bar').attr('max', progress.totalImages);
            } else {
                $('#progress-bar').attr('value', 0);
                var desc = t('facerecognition', 'The analysis is not started yet');
                desc += ' - ';
                desc += n('facerecognition', '%n image in queue', '%n images in queue', progress.totalImages);
                $('#progress-text').html(desc);
            }
        });
    }


    /*
     * ImageArea
     */
    function getImageArea() {
        $.ajax({
            type: 'GET',
            url: OC.generateUrl('apps/facerecognition/getappvalue'),
            data: {
                'type': 'analysis_image_area',
            },
            success: function (data) {
                if (data.status === state.OK) {
                    var imageArea = parseInt(data.value);
                    $('#image-area-range').val(imageArea);
                    $('#image-area-value').html(getFourByThreeRelation(imageArea));
                }
            }
        });
    }

    $('#image-area-range').on('input', function() {
        $('#image-area-value').html(getFourByThreeRelation(this.value));
        $('#restore-image-area').show();
        $('#save-image-area').show();
    });

    $('#restore-image-area').on('click', function(event) {
        event.preventDefault();
        getImageArea();

        $('#restore-image-area').hide();
        $('#save-image-area').hide();
    });

    $('#save-image-area').on('click', function(event) {
        event.preventDefault();
        var imageArea = $('#image-area-range').val().toString();
        $.ajax({
            type: 'POST',
            url: OC.generateUrl('apps/facerecognition/setappvalue'),
            data: {
                'type': 'analysis_image_area',
                'value': imageArea
            },
            success: function (data) {
                if (data.status === state.SUCCESS) {
                    OC.Notification.showTemporary(t('facerecognition', 'The changes were saved. It will be taken into account in the next analysis.'));
                    $('#restore-image-area').hide();
                    $('#save-image-area').hide();
                }
                else {
                    var suggestedImageArea = parseInt(data.value);
                    $('#image-area-range').val(suggestedImageArea);
                    $('#image-area-value').html(getFourByThreeRelation(suggestedImageArea));
                    var message = t('facerecognition', 'The change could not be applied.');
                    message += " - " + data.message;
                    OC.Notification.showTemporary(message);
                }
            }
        });
    });


    /*
     * Sensitivity
     */
    function getSensitivity() {
        $.ajax({
            type: 'GET',
            url: OC.generateUrl('apps/facerecognition/getappvalue'),
            data: {
                'type': 'sensitivity',
            },
            success: function (data) {
                if (data.status === state.OK) {
                    var sensitivity = parseFloat(data.value);
                    $('#sensitivity-range').val(sensitivity);
                    $('#sensitivity-value').html(sensitivity);
                }
            }
        });
    }

    $('#sensitivity-range').on('input', function() {
        $('#sensitivity-value').html(this.value);
        $('#restore-sensitivity').show();
        $('#save-sensitivity').show();
    });

    $('#restore-sensitivity').on('click', function(event) {
        event.preventDefault();
        getSensitivity();

        $('#restore-sensitivity').hide();
        $('#save-sensitivity').hide();
    });

    $('#save-sensitivity').on('click', function(event) {
        event.preventDefault();
        var sensitivity = $('#sensitivity-range').val().toString();
        $.ajax({
            type: 'POST',
            url: OC.generateUrl('apps/facerecognition/setappvalue'),
            data: {
                'type': 'sensitivity',
                'value': sensitivity
            },
            success: function (data) {
                if (data.status === state.SUCCESS) {
                    OC.Notification.showTemporary(t('facerecognition', 'The changes were saved. It will be taken into account in the next analysis.'));
                    $('#restore-sensitivity').hide();
                    $('#save-sensitivity').hide();
                }
            }
        });
    });

    /*
     * Confidence
     */
    function getMinConfidence() {
        $.ajax({
            type: 'GET',
            url: OC.generateUrl('apps/facerecognition/getappvalue'),
            data: {
                'type': 'min_confidence',
            },
            success: function (data) {
                if (data.status === state.OK) {
                    var confidence = parseFloat(data.value);
                    $('#min-confidence-range').val(confidence);
                    $('#min-confidence-value').html(confidence);
                }
            }
        });
    }

    $('#min-confidence-range').on('input', function() {
        $('#min-confidence-value').html(this.value);
        $('#restore-min-confidence').show();
        $('#save-min-confidence').show();
    });

    $('#restore-min-confidence').on('click', function(event) {
        event.preventDefault();
        getMinConfidence();

        $('#restore-min-confidence').hide();
        $('#save-min-confidence').hide();
    });

    $('#save-min-confidence').on('click', function(event) {
        event.preventDefault();
        var confidence = $('#min-confidence-range').val().toString();
        $.ajax({
            type: 'POST',
            url: OC.generateUrl('apps/facerecognition/setappvalue'),
            data: {
                'type': 'min_confidence',
                'value': confidence
            },
            success: function (data) {
                if (data.status === state.SUCCESS) {
                    OC.Notification.showTemporary(t('facerecognition', 'The changes were saved. It will be taken into account in the next analysis.'));
                    $('#restore-min-confidence').hide();
                    $('#save-min-confidence').hide();
                }
            }
        });
    });

    /*
     * Utils
     */
    function getFourByThreeRelation(area) {
        var width = Math.sqrt(area * 4 / 3);
        var height = (width * 3  / 4);
        return Math.floor(width) + 'x' + Math.floor(height) + ' (4x3)';
    }


    /*
     * Get initial values.
     */
    getImageArea();
    getSensitivity();
    getMinConfidence();

    checkProgress();

    /*
     * Update progress
     */
    window.setInterval(checkProgress, 5000);

});