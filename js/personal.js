(function (OC, window, $, undefined) {
'use strict';

$(document).ready(function () {

const state = {
    OK: 0,
    FALSE: 1,
    SUCCESS: 2,
    ERROR:  3
}

/*
 * Faces in memory handlers.
 */
var Persons = function (baseUrl) {
    this._baseUrl = baseUrl;
    this._enabled = false;
    this._clusters = [];
    this._cluster = undefined;
    this._loaded = false;
};

Persons.prototype = {
    load: function () {
        var deferred = $.Deferred();
        var self = this;
        $.get(this._baseUrl+'/clusters').done(function (response) {
            self._enabled = response.enabled;
            self._clusters = response.clusters;
            self._loaded = true;
            deferred.resolve();
        }).fail(function () {
            deferred.reject();
        });
        return deferred.promise();
    },
    loadCluster: function (id) {
        this.unsetCluster();

        var deferred = $.Deferred();
        var self = this;
        $.get(this._baseUrl+'/cluster/'+id).done(function (cluster) {
            self._cluster = cluster;
            deferred.resolve();
        }).fail(function () {
            deferred.reject();
        });
        return deferred.promise();
    },
    unsetCluster: function () {
        this._cluster = undefined;
    },
    getActive: function () {
        return this._cluster;
    },
    getById: function (clusterId) {
        var ret = undefined;
        for (var cluster of this._clusters) {
            if (cluster.id === clusterId) {
                ret = cluster;
                break;
            }
        };
        return ret;
    },
    isLoaded: function () {
        return this._loaded;
    },
    isEnabled: function () {
        return this._enabled;
    },
    sortBySize: function () {
        this._clusters.sort(function(a, b) {
            return b.count - a.count;
        });
    },
    getAll: function () {
        return this._clusters;
    },
    renameCluster: function (clusterId, personName) {
        var self = this;
        var deferred = $.Deferred();
        var opt = { name: personName };
        $.ajax({url: this._baseUrl + '/cluster/' + clusterId,
                method: 'PUT',
                contentType: 'application/json',
                data: JSON.stringify(opt)
        }).done(function (data) {
            self._clusters.forEach(function (cluster) {
                if (cluster.id === clusterId) {
                    cluster.name = personName;
                }
            });
            deferred.resolve();
        }).fail(function () {
            deferred.reject();
        });
        return deferred.promise();
    }
};

/*
 * View.
 */
var View = function (persons) {
    this._persons = persons;
};

View.prototype = {
    reload: function (name) {
        var self = this;
        this._persons.load().done(function () {
            self.renderContent();
        }).fail(function () {
            OC.Notification.showTemporary(t('facerecognition', 'There was an error trying to show your friends'));
        });
    },
    setEnabledUser: function (enabled) {
        var self = this;
        $.ajax({
            type: 'POST',
            url: OC.generateUrl('apps/facerecognition/setuservalue'),
            data: {
                'type': 'enabled',
                'value': enabled
            },
            success: function () {
                if (enabled) {
                    OC.Notification.showTemporary(t('facerecognition', 'The analysis is enabled, please be patient, you will soon see your friends here.'));
                } else {
                    OC.Notification.showTemporary(t('facerecognition', 'The analysis is disabled. Soon all the information found for facial recognition will be removed.'));
                }
                self.reload();
            }
        });
    },
    renderContent: function () {
        this._persons.sortBySize();
        var context = {
            loaded: this._persons.isLoaded(),
            persons: this._persons.getAll(),
            appName: t('facerecognition', 'Face Recognition'),
            welcomeHint: t('facerecognition', 'Here you can see photos of your friends that are recognized'),
            enableDescription: t('facerecognition', 'Analyze my images and group my loved ones with similar faces'),
            loadingMsg: t('facerecognition', 'Looking for your recognized friends'),
            loadingIcon: OC.imagePath('core', 'loading.gif')
        };

        if (this._persons.isEnabled() === 'true') {
            context.enabled = true;
            context.emptyMsg = t('facerecognition', 'Your friends have not been recognized yet');
            context.emptyHint = t('facerecognition', 'Please, be patient');
        }
        else {
            context.emptyMsg = t('facerecognition', 'The analysis is disabled');
            context.emptyHint = t('facerecognition', 'Enable it to find your loved ones');
        }

        if (this._persons.getActive() !== undefined)
            context.person = this._persons.getActive();

        var html = Handlebars.templates['personal'](context);
        $('#div-content').html(html);

        const observer = lozad('.face-preview');
        observer.observe();

        var self = this;

        $('#enableFacerecognition').click(function() {
            var enabled = $(this).is(':checked');
            if (enabled === false) {
                OC.dialogs.confirm(
                    t('facerecognition', 'You will lose all the information analyzed, and if you re-enable it, you will start from scratch.'),
                    t('facerecognition', 'Do you want to deactivate the grouping by faces?'),
                    function (result) {
                        if (result === true) {
                            self.setEnabledUser (false);
                        } else {
                            $('#enableFacerecognition').prop('checked', true);
                        }
                    },
                    true
                );
            } else {
                self.setEnabledUser (true);
            }
        });

        $('#facerecognition .person-name').click(function () {
            var id = $(this).parent().data('id');
            self._persons.loadCluster(id).done(function () {
                self.renderContent();
            }).fail(function () {
                OC.Notification.showTemporary(t('facerecognition', 'There was an error when trying to find photos of your friend'));
            });
        });

        $('#facerecognition .icon-rename').click(function () {
            var id = $(this).parent().data('id');
            var person = self._persons.getById(id);
            FrDialogs.rename(
                person.name,
                person.faces[0]['thumb-url'],
                function(result, value) {
                    if (result === true && value) {
                        self._persons.renameCluster (id, value).done(function () {
                            self._persons.unsetCluster();
                            self.renderContent();
                        }).fail(function () {
                            OC.Notification.showTemporary(t('facerecognition', 'There was an error renaming this person'));
                        });
                    }
                }
            );
        });
    }
};

/*
 * Main app.
 */
var persons = new Persons(OC.generateUrl('/apps/facerecognition'));

var view = new View(persons);

view.renderContent();

persons.load().done(function () {
    view.renderContent();
}).fail(function () {
    OC.Notification.showTemporary(t('facerecognition', 'There was an error trying to show your friends'));
});


}); // $(document).ready(function () {
})(OC, window, jQuery); // (function (OC, window, $, undefined) {