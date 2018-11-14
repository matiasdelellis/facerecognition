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
            html = '<table class="persons-list">';
            var arrayLength = data.length;
            for (var i = 0; i < arrayLength; i++) {
                html += '<tr data-id="' + data[i].person_id + '">';
                html += '    <td><div class="face-container">';
                html += '        <div class="face-lozad" data-background-image="/apps/facerecognition/face/thumb/'+data[i].face.id+'" data-id="'+data[i].face.id+'" width="32" height="32">';
                html += '    </div></td>';
                html += '    <td>' + data[i].name + '</td>';
                html += '    <td class="more">'
                html += '        <div>';
                html += '            <a class="icon icon-more"></a>';
                html += '            <div class="popovermenu">';
                html += '                <ul>';
                html += '                    <li>';
                html += '                        <a href="#" class="icon-edit">';
                html += '                            <span>Rename</span>';
                html += '                        </a>';
                html += '                    </li>';
                html += '                </ul>';
                html += '            </div>';
                html += '        </div>';
                html += '    </td>';
                html += '</tr>';
            }
            html += '</table>';

            this.$el.find('.get-faces').html(html);

            this.$el.on('click', '.icon-more', _.bind(this._onPersonClickMore, this));
            this.$el.on('click', 'a.icon-edit', _.bind(this._onRenamePerson, this));

            const observer = lozad('.face-lozad');
            observer.observe();
        },

        _renamePerson: function (personId, personName) {
            var opt = { name: personName };
            var url  = OC.generateUrl('/apps/facerecognition') + '/personV2/' + personId;
            $.ajax({url: url,
                    method: 'PUT',
                    contentType: 'application/json',
                    data: JSON.stringify(opt)})
            .done(function (data) {
                this.render();
            });
        },

        _onPersonClickMore: function (event) {
            event.stopPropagation();
            var $target = $(event.target);
            var $row = $target.closest('tr');
            $row.toggleClass('active');
            $row.find('.popovermenu').toggleClass('open');
        },

        _onRenamePerson: function (event) {
            var $target = $(event.target);
            var $row = $target.closest('tr');
            var id = $row.data('id');
            $row.toggleClass('active');
            $row.find('.popovermenu').toggleClass('open');

            var self = this;
            OC.dialogs.prompt(
                t('facerecognition', 'Please enter a name to rename the person'),
                t('facerecognition', 'Rename'),
                function(result, value) {
                    if (result === true && value) {
                        self._renamePerson (id, value);
                    }
                },
                true,
                t('facerecognition', 'Rename Person'),
                false
            ).then(function() {
                var $dialog = $('.oc-dialog:visible');
                var $buttons = $dialog.find('button');
                $buttons.eq(0).text(t('facerecognition', 'Cancel'));
                $buttons.eq(1).text(t('facerecognition', 'Rename'));
            });
        }

    });

    OCA.Facerecognition = OCA.Facerecognition || {};

    OCA.Facerecognition.FacesTabView = FacesTabView;
})();
