$(document).ready(function() {

    function checkProgress() {
        $.get(OC.generateUrl('/apps/facerecognition/process')).done(function (progress) {
            if (progress.status) {
                var desc ="Analyzing images";
                if (progress.queuedone > 0)
                    desc += ' - '+progress.queuedone +' of '+progress.queuetotal+' - Ends approximately '+progress.endtime;
                $('#progress-text').html(desc);

                $('#progress-text').html(desc);
                $('#progress-bar').attr('value', progress.queuedone);
                $('#progress-bar').attr('max', progress.queuetotal);
            } else {
                $('#progress-bar').attr('value', 0);
                var desc = 'Stopped'
                if (progress.queuetotal > 0)
                    desc += ' - '+progress.queuetotal+' in queue -';
                $('#progress-text').html(desc);
            }
        });
    }

    checkProgress();
    window.setInterval(checkProgress, 5000);

});