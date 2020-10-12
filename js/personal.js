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
    this._persons = [];
    this._cluster = undefined;
    this._clustersByName = [];
    this._personName = undefined;
    this._person = undefined;
    this._clustersCount = 0;
    this._imagesPersons = [];
    this._unassignedClusters = [];
    this._unassignedCount = 0;
    this._loaded = false;
};

Persons.prototype = {
    load: function () {
        var deferred = $.Deferred();
        var self = this;
        $.get(this._baseUrl+'/persons').done(function (response) {
            self._enabled = response.enabled;
            self._persons = response.persons;
            self._loaded = true;
            deferred.resolve();
        }).fail(function () {
            deferred.reject();
        });
        return deferred.promise();
    },
    loadPerson: function (personName) {
        this.unsetActive();
        var deferred = $.Deferred();
        var self = this;
        $.get(this._baseUrl+'/person/' + personName).done(function (person) {
            self._personName = person.name;
            self._imagesPerson = person.images;
            self._clustersCount = person.clusters;
            for (var _person of self._persons) {
                if (_person.name === personName) {
                    self._person = _person;
                    break;
                }
            }
            deferred.resolve();
        }).fail(function () {
            deferred.reject();
        });
        return deferred.promise();
    },
    loadUnassignedClusters: function () {
        this.unsetActive();
        var deferred = $.Deferred();
        var self = this;
        $.get(this._baseUrl+'/clusters').done(function (clusters) {
            self._unassignedClusters = clusters.clusters;
            self._unassignedClustersCount = clusters.clusters.length;
            self._unassignedClusters.sort(function(a, b) {
                return b.count - a.count;
            });
            deferred.resolve();
        }).fail(function () {
            deferred.reject();
        });
        return deferred.promise();
    },
    loadClustersByName: function (personName) {
        var deferred = $.Deferred();
        var self = this;
        $.get(this._baseUrl+'/clusters/'+personName).done(function (clusters) {
            self._clustersByName = clusters.clusters;
            deferred.resolve();
        }).fail(function () {
            deferred.reject();
        });
        return deferred.promise();
    },
    getPersonName: function() {
        return this._personName;
    },
    getPersonClustersCount: function() {
        return this._clustersCount;
    },
    getPersonImages: function() {
        return this._imagesPerson;
    },
    getUnassignedClustersCount: function () {
        return this._unassignedClustersCount;
    },
    unsetActive: function () {
        this._cluster = undefined;
        this._clustersByName = [];
        this._personName = undefined;
        this._person = undefined;
        this._clustersCount = 0;
        this._imagesPerson = [];
    },
    getActive: function () {
        return this._cluster;
    },
    getActiveByName: function () {
        return this._clustersByName;
    },
    getUnassignedClusters: function () {
        return this._unassignedClusters;
    },
    getById: function (clusterId) {
        var ret = undefined;
        for (var cluster of this._clustersByName) {
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
        if (this._persons !== undefined)
            this._persons.sort(function(a, b) {
                return b.count - a.count;
            });
        if (this._clustersByName !== undefined)
            this._clustersByName.sort(function(a, b) {
                return b.count - a.count;
            });
    },
    getAll: function () {
        return this._persons;
    },
    renamePerson: function (personName, name) {
        var self = this;
        var deferred = $.Deferred();
        var opt = { name: name };
        $.ajax({url: this._baseUrl + '/person/' + personName,
                method: 'PUT',
                contentType: 'application/json',
                data: JSON.stringify(opt)
        }).done(function (person) {
            self._personName = person.name;
            self._imagesPerson = person.images;
            self._clustersCount = person.clusters;
            deferred.resolve();
        }).fail(function () {
            deferred.reject();
        });
        return deferred.promise();
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
            self._clustersByName.forEach(function (cluster) {
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
    this._observer = lozad('.lozad');
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
    renameUnassignedClusterDialog: function () {
        var self = this;
        var unassignedClusters = this._persons.getUnassignedClusters();
        var cluster = unassignedClusters.shift();
        if (cluster === undefined) {
            if (self._persons.getUnassignedClustersCount() > 0)
                self.reload();
            return;
        }
        FrDialogs.rename("", cluster.faces,
            function(result, name) {
                if (result === true) {
                    if (name.length > 0) {
                        self._persons.renameCluster(cluster.id, name).done(function () {
                            self.renameUnassignedClusterDialog();
                        }).fail(function () {
                            OC.Notification.showTemporary(t('facerecognition', 'There was an error renaming this person'));
                        });
                    } else {
                        // Fake ignore.
                        self.renameUnassignedClusterDialog();
                    }
                } else {
                    if (self._persons.getUnassignedClustersCount() > 0)
                        self.reload();
                }
            }
        );
    },
    renderContent: function () {
        this._persons.sortBySize();
        var context = {
            loaded: this._persons.isLoaded(),
            appName: t('facerecognition', 'Face Recognition'),
            welcomeHint: t('facerecognition', 'Here you can see photos of your friends that are recognized'),
            enableDescription: t('facerecognition', 'Analyze my images and group my loved ones with similar faces'),
            loadingMsg: t('facerecognition', 'Looking for your recognized friends'),
            showMoreButton: t('facerecognition', 'Show all groups with the same name'),
            emptyMsg: t('facerecognition', 'The analysis is disabled'),
            emptyHint: t('facerecognition', 'Enable it to find your loved ones'),
            loadingIcon: OC.imagePath('core', 'loading.gif')
        };

        if (this._persons.isEnabled() === true) {
            context.enabled = true;
            context.persons = this._persons.getAll();

            context.emptyMsg = t('facerecognition', 'Your friends have not been recognized yet');
            context.emptyHint = t('facerecognition', 'Please, be patient');
        }

        if (this._persons.getPersonName() !== undefined) {
            setPersonUrl(this._persons.getPersonName());
            context.personName = this._persons.getPersonName();
            context.personImages = this._persons.getPersonImages();
            context.multipleClusters = (this._persons.getPersonClustersCount() > 1);
        }

        if (this._persons.getActive() !== undefined)
            context.cluster = this._persons.getActive();

        if (this._persons.getActiveByName() !== undefined)
            context.clustersByName = this._persons.getActiveByName();

        var html = Handlebars.templates['personal'](context);
        $('#div-content').html(html);

        this._observer.observe();

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

        $('#facerecognition .person-box').click(function () {
            var name = $(this).data('id');
            self._persons.loadPerson(name).done(function () {
                self.renderContent();
            }).fail(function () {
                OC.Notification.showTemporary(t('facerecognition', 'There was an error when trying to find photos of your friend'));
            });
        });

        $('#facerecognition #rename-person').click(function () {
            var person = self._persons._person;
            FrDialogs.rename(
                person.name,
                [person],
                function(result, value) {
                    if (result === true && value) {
                        self._persons.renamePerson (person.name, value).done(function () {
                            self._persons.unsetActive();
                            self.renderContent();
                        }).fail(function () {
                            OC.Notification.showTemporary(t('facerecognition', 'There was an error renaming this person'));
                        });
                    }
                }
            );
        });

        $('#facerecognition #rename-cluster').click(function () {
            var id = $(this).data('id');
            var person = self._persons.getById(id);
            FrDialogs.rename(
                person.name,
                [person.faces[0]],
                function(result, value) {
                    if (result === true && value) {
                        self._persons.renameCluster (id, value).done(function () {
                            self._persons.unsetActive();
                            self.renderContent();
                        }).fail(function () {
                            OC.Notification.showTemporary(t('facerecognition', 'There was an error renaming this cluster of faces'));
                        });
                    }
                }
            );
        });

        $('#facerecognition #show-more-clusters').click(function () {
            var personName = self._persons.getPersonName();
            self._persons.loadClustersByName(personName).done(function () {
                self.renderContent();
            }).fail(function () {
                OC.Notification.showTemporary(t('facerecognition', 'There was an error when trying to find photos of your friend'));
            });
        });

        $('#facerecognition .icon-back').click(function () {
            self._persons.unsetActive();
            self.renderContent();
            if (!self._persons.isLoaded()) {
                self.reload();
            }
            setPersonUrl();
        });
    }
};

/**
 * Get the personName as URL parameter
 */
var getPersonNameUrl = function () {
    var personName = undefined;
    var parser = document.createElement('a');
    parser.href = window.location.href;
    var query = parser.search.substring(1);
    var vars = query.split('&');
    for (var i = 0; i < vars.length; i++) {
        var pair = vars[i].split('=');
        if (pair[0] === 'name') {
            personName = decodeURIComponent(pair[1]);
            break;
        }
    }
    return personName;
};

/**
 *  Change the URL location with personName as parameter
 */
var setPersonNameUrl = function (personName) {
    var cleanUrl = window.location.href.split("?")[0];
    var title = t('facerecognition', 'Face Recogntion');
    if (personName) {
        cleanUrl += '?name=' + personName;
        title += ' - ' + personName;
    }
    window.history.replaceState({}, title, cleanUrl);
    document.title = title;
};

/**
 * Add Helpers to handlebars
 */

Handlebars.registerHelper('noPhotos', function(count) {
    return n('facerecognition', '%n image', '%n images', count);
});

/*
 * Main app.
 */
var persons = new Persons(OC.generateUrl('/apps/facerecognition'));

var view = new View(persons);

view.renderContent();

var personName = getPersonNameUrl();
if (personName !== undefined) {
    persons.loadPerson(personName).done(function () {
        view.renderContent();
    }).fail(function () {
        OC.Notification.showTemporary(t('facerecognition', 'There was an error when trying to find photos of your friend'));
    });
} else {
    persons.load().done(function () {
        view.renderContent();

        persons.loadUnassignedClusters().done(function () {
            if (persons.getUnassignedClustersCount() > 0) {
                var button = $("<button id='show-more-clusters' type='button' class='primary'>" + t('facerecognition', 'There are more persons to recognize') + "</button>");
                $('#optional-buttons-div').append(button);
                button.click(function () {
                    view.renameUnassignedClusterDialog();
                });
                OC.Notification.showTemporary(t('facerecognition', 'More people were found to recognize.'));
           }
        });
    }).fail(function () {
        OC.Notification.showTemporary(t('facerecognition', 'There was an error trying to show your friends'));
    });
}

var egg = new Egg("up,up,down,down,left,right,left,right,b,a", function() {
    if (!OC.isUserAdmin()) {
        OC.Notification.showTemporary(t('facerecognition', 'You must be administrator to configure this feature'));
        return;
    }
    $.ajax({
        type: 'POST',
        url: OC.generateUrl('apps/facerecognition/setappvalue'),
        data: {
            'type': 'obfuscate_faces',
            'value': 'toggle'
        },
        success: function (data) {
            location.reload();
        }
    });
}).listen();

}); // $(document).ready(function () {
})(OC, window, jQuery); // (function (OC, window, $, undefined) {