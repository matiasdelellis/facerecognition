'use strict';
$(document).ready(function() {

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

    checkProgress();
    window.setInterval(checkProgress, 5000);

});