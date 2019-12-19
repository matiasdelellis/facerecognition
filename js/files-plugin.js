'use strict';
(function() {
    OCA.Facerecognition = OCA.Facerecognition || {}

    OCA.Facerecognition.FileListPlugin = {
        attach: function(fileList) {
            if (fileList.id === 'trashbin' || fileList.id === 'files.public') {
                return;
            }
            fileList.registerTabView(new OCA.Facerecognition.FacesTabView());
        }
    }
})()

OC.Plugins.register('OCA.Files.FileList', OCA.Facerecognition.FileListPlugin)