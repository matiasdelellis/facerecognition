(function() {
    var FacesTabView = OCA.Files.DetailTabView.extend({
        id: 'facerecognitionTabView',
        className: 'tab facerecognitionTabView',

        getLabel: function() {
            return t('facerecognition', 'Persons');
        },

        render: function() {
            var fileInfo = this.getFileInfo();

            if (fileInfo) {
                this.$el.html('<div style="text-align:center; word-wrap:break-word;" class="get-faces"><p><img src="'
                    + OC.imagePath('core','loading.gif')
                    + '"><br><br></p><p>'
                    + t('facerecognition', 'Looking for faces in this image...')
                    + '</p></div>');
                var url = OC.generateUrl('/apps/facerecognition/filefaces'),
                    data = {fullpath: fileInfo.getFullPath()},
                    _self = this;
                $.ajax({
                    type: 'GET',
                    url: url,
                    dataType: 'json',
                    data: data,
                    async: true,
                    success: function(data) {
                        _self.updateDisplay(data);
                    }
                });
            }
        },

        canDisplay: function(fileInfo) {
            if (!fileInfo || fileInfo.isDirectory() || !fileInfo.has('mimetype')) {
                return false;
            }
            var mimetype = fileInfo.get('mimetype');

            return (['image/jpeg', 'image/png'].indexOf(mimetype) > -1);
        },

        updateDisplay: function(data) {

            var html = '<h2>Persons</h2>'
            html += '<ul>';
            var arrayLength = data.length;
            for (var i = 0; i < arrayLength; i++) {
                if (data[i].distance != -1) {
                    html += '<li>'+data[i].name+'</li>';
                }
            }
            html += '</ul>';

            this.$el.find('.get-faces').html(html);
        }

    });

    OCA.Facerecognition = OCA.Facerecognition || {};

    OCA.Facerecognition.FacesTabView = FacesTabView;
})();
