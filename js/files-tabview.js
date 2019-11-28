'use strict';
(function() {
    var FacesTabView = OCA.Files.DetailTabView.extend({
        id: 'facerecognitionTabView',
        className: 'tab facerecognitionTabView',

        events: {
            'click .icon-more': '_onPersonClickMore',
            'click a.icon-rename': '_onRenamePerson'
        },

        getLabel: function() {
            return t('facerecognition', 'Persons');
        },

        getIcon: function() {
            return 'icon-contacts-dark';
        },

        render: function() {
            var fileInfo = this.getFileInfo();

            if (fileInfo) {
                this.$el.html('<div style="text-align:center; word-wrap:break-word;" class="get-faces"><p><img src="'
                    + OC.imagePath('core','loading.gif')
                    + '"><br><br></p><p>'
                    + t('facerecognition', 'Looking for faces in this image…')
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

        insertPersonRow: function(person) {
                var html = "<tr data-id='" + person.person_id + "'>";
                html += "    <td>";
                html += "        <div class='face-preview' data-background-image='/index.php/apps/facerecognition/face/" + person.face.id + "/thumb/32' data-id='" + person.face.id + "' width='32' height='32'>";
                html += "    </td>";
                html += "    <td class='name'>" + person.name + "</td>";
                html += "    <td>";
                html += "        <div class='more'>";
                html += "            <span class='icon-more'></span>";
                html += "            <div class='popovermenu'>";
                html += "                <ul>";
                html += "                    <li>";
                html += "                        <a href='#' class='icon-rename'>";
                html += "                            <span>"+ t('facerecognition', 'Rename'); +"</span>";
                html += "                        </a>";
                html += "                    </li>";
                html += "                </ul>";
                html += "            </div>";
                html += "        </div>";
                html += "    </td>";
                html += "</tr>";
                return html;
        },

        updateDisplay: function(data) {
            var html = "";
            if (data.enabled === 'false')
            {
                var openSettingsLink = t('facerecognition', 'Open <a target="_blank" href="{settingsLink}">settings ↗</a> to enable it.',
                                        {settingsLink: OC.generateUrl('settings/user/facerecognition')});
                html += "<div class='emptycontent'>";
                html += "<div class='icon-user svg'></div>";
                html += "<p>" + t('facerecognition', 'Facial recognition is disabled') + "</p>";
                html += "<p><span>" + openSettingsLink + "</span></p>";
                html += "</div>";
            }
            else if (data.is_processed)
            {
                var arrayLength = data.persons.length;
                if (arrayLength > 0)
                {
                    html += "<table class='persons-list'>";
                    for (var i = 0; i < arrayLength; i++) {
                        html += this.insertPersonRow(data.persons[i]);
                    }
                    html += "</table>";
                }
                else
                {
                    html += "<div class='emptycontent'>";
                    html += "<div class='icon-user svg'></div>";
                    html += "<p>"+t('facerecognition', 'No people found')+"</p>";
                    html += "</div>";
                }
            }
            else
            {
                html += "<div class='emptycontent'>";
                html += "<div class='icon-user svg'></div>";
                html += "<p>"+t('facerecognition', 'This image is not yet analyzed')+"</p>";
                html += "<p><span>"+t('facerecognition', 'Please, be patient')+"</span></p>";
                html += "</div>";
            }

            this.$el.find('.get-faces').html(html);

            this.delegateEvents();
            const observer = lozad('.face-preview');
            observer.observe();
        },

        _renamePerson: function (clusterId, personName) {
            var opt = { name: personName };
            var url  = OC.generateUrl('/apps/facerecognition') + '/cluster/' + clusterId;
            var self = this;
            $.ajax({url: url,
                    method: 'PUT',
                    contentType: 'application/json',
                    data: JSON.stringify(opt)})
            .done(function (data) {
                self.render();
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
            var name = $row.find('.name')[0].innerHTML;
            var thumbUrl = $row.find('.face-preview').attr("data-background-image");

            $row.toggleClass('active');
            $row.find('.popovermenu').toggleClass('open');

            var self = this;
            FrDialogs.rename(
                name,
                thumbUrl,
                function(result, value) {
                    if (result === true && value) {
                        self._renamePerson (id, value);
                    }
                }
            );
        }

    });

    OCA.Facerecognition = OCA.Facerecognition || {};

    OCA.Facerecognition.FacesTabView = FacesTabView;
})();
