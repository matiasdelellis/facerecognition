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
                var url = OC.generateUrl('/apps/facerecognition/file'),
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
            html = '<table>';
            var arrayLength = data.length;
            for (var i = 0; i < arrayLength; i++) {
                html += '<tr>';
                html += '    <td><div class="face-container">';
                html += '        <div class="face-lozad" data-background-image="/apps/facerecognition/face/thumb/'+data[i].face.id+'" data-id="'+data[i].face.id+'" width="32" height="32">';
                html += '    </div></td>';
                html += '    <td>' + data[i].name + '</td>';
                html += '</tr>';
            }
            html += '</table>';

            this.$el.find('.get-faces').html(html);

            const observer = lozad('.face-lozad');
            observer.observe();
        }

    });

    OCA.Facerecognition = OCA.Facerecognition || {};

    OCA.Facerecognition.FacesTabView = FacesTabView;
})();
