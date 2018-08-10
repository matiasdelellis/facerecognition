$(document).ready(function() {

    function checkProgress() {
        $.get(OC.generateUrl('/apps/facerecognition/process')).done(function (progress) {
            if (progress.status) {
                $('#progress-text').html("Analyzing images ("+ progress.queuedone +" of "+progress.queuetotal+")");
                $('#progress-bar').attr('value', progress.queuedone);
                $('#progress-bar').attr('max', progress.queuetotal);
            } else {
                $('#progress-bar').attr('value', 0);
                $('#progress-text').html("Stopped");
            }
        });
    }

    checkProgress();
    window.setInterval(checkProgress, 5000);

});