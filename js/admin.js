'use strict';
$(document).ready(function() {
    const state = {
        OK: 0,
        FALSE: 1,
        SUCCESS: 2,
        ERROR:  3
    }

    function checkProgress() {
        $.get(OC.generateUrl('/apps/facerecognition/process')).done(function (progress) {
            if (progress.status) {
                var estimatedFinalizeDate = Date.now()/1000 + progress.estimatedFinalize;
                var desc = t('facerecognition', 'Analyzing images');
                desc += ' - ';
                desc += t('facerecognition', '{processedImages} of {totalImages} - Ends approximately {estimatedFinalize}',
                         {processedImages: progress.processedImages, totalImages: progress.totalImages, estimatedFinalize: relative_modified_date(estimatedFinalizeDate)});

                $('#progress-text').html(desc);

                $('#progress-text').html(desc);
                $('#progress-bar').attr('value', progress.processedImages);
                $('#progress-bar').attr('max', progress.totalImages);
            } else {
                $('#progress-bar').attr('value', 0);
                var desc = t('facerecognition', 'Stopped');
                desc += ' - ';
                desc += t('facerecognition', '{processedImages} images in queue', {processedImages: (progress.totalImages - progress.processedImages)});

                $('#progress-text').html(desc);
            }
        });
    }

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
            type: 'GET',
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
     * Get initial values.
     */
    getSensitivity();
    checkProgress();

    /*
     * Update progress
     */
    window.setInterval(checkProgress, 5000);

});