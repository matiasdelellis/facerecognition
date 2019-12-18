'use strict';
(function() {
    var FacesTabView = OCA.Files.DetailTabView.extend({
        id: 'facerecognitionTabView',
        className: 'tab facerecognitionTabView',

        events: {
            'click .icon-more': '_onPersonClickMore',
            'click a.icon-rename': '_onRenamePerson',
            'click #searchPersonsToggle': '_onSearchPersonsToggle'
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
                this.$el.html(this.getLoadingTemplate());
                if (fileInfo.isDirectory())
                    var url = OC.generateUrl('/apps/facerecognition/folder');
                else
                    var url = OC.generateUrl('/apps/facerecognition/file');
                var data = {fullpath: fileInfo.getFullPath()};
                var self = this;
                $.ajax({
                    type: 'GET',
                    url: url,
                    dataType: 'json',
                    data: data,
                    async: true,
                    success: function(data) {
                        self.updateDisplay(fileInfo, data);
                    }
                });
            }
        },

        canDisplay: function(fileInfo) {
            if (!fileInfo  || !fileInfo.has('mimetype')) {
                return false;
            }
            var mimetype = fileInfo.get('mimetype');

            return (['image/jpeg', 'image/png'].indexOf(mimetype) > -1 || fileInfo.isDirectory());
        },

        getLoadingTemplate: function () {
            var html = '<div style="text-align:center; word-wrap:break-word;" class="get-faces"><p><img src="';
            html += OC.imagePath('core','loading.gif');
            html +='"><br><br></p><p>';
            html += t('facerecognition', 'Looking for faces in this image…');
            html += '</p></div>';
            return html;
        },

        getPersonRowTemplate: function(person) {
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

        getImageTemplate: function (persons) {
            var html = "";
            var arrayLength = persons.length;
            if (arrayLength > 0) {
                html += "<table class='persons-list'>";
                for (var i = 0; i < arrayLength; i++) {
                    html += this.getPersonRowTemplate(persons[i]);
                }
                html += "</table>";
            }
            else {
                html += "<div class='emptycontent'>";
                html += "<div class='icon-user svg'></div>";
                html += "<p>"+t('facerecognition', 'No people found')+"</p>";
                html += "</div>";
            }
            return html;
        },

        getUserDisabledTemplate: function () {
            var openSettingsLink = t('facerecognition', 'Open <a target="_blank" href="{settingsLink}">settings ↗</a> to enable it',
                                    {settingsLink: OC.generateUrl('settings/user/facerecognition')});
            var html = "";
            html += "<div class='emptycontent'>";
            html += "<div class='icon-user svg'></div>";
            html += "<p>" + t('facerecognition', 'Facial recognition is disabled') + "</p>";
            html += "<p><span>" + openSettingsLink + "</span></p>";
            html += "</div>";
            return html;
        },

        getFolderDisabledTemplate: function () {
            var html = "";
            html += "<div class='emptycontent'>";
            html += "<div class='icon-user svg'></div>";
            html += "<p>" + t('facerecognition', 'Facial recognition is disabled for this folder') + "</p>";
            html += "</div>";
            return html;
        },

        getFolderTemplate: function (data) {
            var openDocsLink = t('facerecognition', 'See <a target="_blank" href="{docsLink}">documentation ↗</a>.',
                                 {docsLink: 'https://github.com/matiasdelellis/facerecognition/wiki/FAQ'});
            var html = "";
            html += "<div class='emptycontent'>";
            html += "<div class='icon-user svg'></div>";
            html += "<p>";
            html += "<input class='checkbox' id='searchPersonsToggle'";
            if (data.descendant_detection)
                html += "checked='checked'";
            html += "type='checkbox'>";
            html += "<label for='searchPersonsToggle'>" + t('facerecognition', 'Search for persons in the photos of this directory') + "</label>";
            html += "</p>";
            html += "<p><span>" + t('facerecognition', 'Photos that are not in the gallery are also ignored') + "</span></p>";
            html += "<p><span>" + openDocsLink + "</span></p>";
            html += "</div>";
            return html;
        },

        getNotAllowedTemplate: function () {
            var html = "";
            html += "<div class='emptycontent'>";
            html += "<div class='icon-user svg'></div>";
            html += "<p>" + t('facerecognition', 'The type of storage is not supported to analyze your photos') + "</p>";
            html += "</div>";
            return html;
        },

        getEmptyTemplate: function () {
            var html = "";
            html += "<div class='emptycontent'>";
            html += "<div class='icon-user svg'></div>";
            html += "<p>"+t('facerecognition', 'This image is not yet analyzed')+"</p>";
            html += "<p><span>"+t('facerecognition', 'Please, be patient')+"</span></p>";
            html += "</div>";
            return html;
        },

        updateDisplay: function(fileInfo, data) {
            var html = "";
            if (data.enabled === 'false') {
                html += this.getUserDisabledTemplate();
            }
            else if (!data.is_allowed) {
                html += this.getNotAllowedTemplate();
            }
            else if (!data.parent_detection) {
                html += this.getFolderDisabledTemplate();
            }
            else if (fileInfo.isDirectory()) {
                html += this.getFolderTemplate(data);
            }
            else if (data.is_processed) {
                html += this.getImageTemplate (data.persons);
            }
            else {
                html += this.getEmptyTemplate();
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
        },

        _onSearchPersonsToggle: function (event) {
            var _self = this;
            var url  = OC.generateUrl('/apps/facerecognition') + '/folder';
            var data = {
                fullpath: this.getFileInfo().getFullPath(),
                detection: $(event.target).is(':checked')
            };
            $.ajax({
                url: url,
                method: 'PUT',
                contentType: 'application/json',
                data: JSON.stringify(data)
            }).done(function (data) {
                _self.updateDisplay(_self.getFileInfo(), data);
            });
        }

    });

    OCA.Facerecognition = OCA.Facerecognition || {};

    OCA.Facerecognition.FacesTabView = FacesTabView;
})();
