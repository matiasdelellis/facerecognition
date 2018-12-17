'use strict';
var facerecognitionFileListPlugin = {
    attach: function(fileList) {
      if (fileList.id === 'trashbin' || fileList.id === 'files.public') {
        return;
      }
      fileList.registerTabView(new OCA.Facerecognition.FacesTabView());
    }
};
OC.Plugins.register('OCA.Files.FileList', facerecognitionFileListPlugin);
