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

    this._persons = [];

    this._activePerson = undefined;

    this._clustersByName = [];

    this._unassignedClusters = [];

    this._enabled = false;
    this._loaded = false;
    this._mustReload = false;
};

Persons.prototype = {
    /*
     * View State
     */
    isEnabled: function () {
        return this._enabled;
    },
    isLoaded: function () {
        return this._loaded;
    },
    mustReload: function() {
        return this._mustReload;
    },
    /*
     * Persons
     */
    load: function () {
        var deferred = $.Deferred();
        var self = this;
        $.get(this._baseUrl+'/persons').done(function (response) {
            self._enabled = response.enabled;
            self._persons = response.persons.sort(function(a, b) {
                return b.count - a.count;
            });
            self._loaded = true;
            self._mustReload = false;
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
        $.get(this._baseUrl+'/person/' + encodeURIComponent(personName)).done(function (person) {
            self._activePerson = person;
            deferred.resolve();
        }).fail(function () {
            deferred.reject();
        });
        return deferred.promise();
    },
    getPersons: function () {
        return this._persons;
    },
    getActivePerson: function () {
        return this._activePerson;
    },
    renamePerson: function (personName, name) {
        var self = this;
        var deferred = $.Deferred();
        var opt = { name: name };
        $.ajax({url: this._baseUrl + '/person/' + encodeURIComponent(personName),
                method: 'PUT',
                contentType: 'application/json',
                data: JSON.stringify(opt)
        }).done(function (person) {
            self._activePerson = person;
            self._mustReload = true;
            deferred.resolve();
        }).fail(function () {
            deferred.reject();
        });
        return deferred.promise();
    },
    setVisibility: function (personName, visibility) {
        var self = this;
        var deferred = $.Deferred();
        var opt = { visible: visibility };
        $.ajax({url: this._baseUrl + '/person/' + encodeURIComponent(personName) + '/visibility',
            method: 'POST',
            contentType: 'application/json',
            data: JSON.stringify(opt)
        }).done(function (data) {
            self._mustReload = true;
            deferred.resolve();
        }).fail(function () {
            deferred.reject();
        });
        return deferred.promise();
    },
    /*
     * Clusters
     */
    loadClustersByName: function (personName) {
        var deferred = $.Deferred();
        var self = this;
        $.get(this._baseUrl+'/clusters/' + encodeURIComponent(personName)).done(function (clusters) {
            self._clustersByName = clusters.clusters.sort(function(a, b) {
                return b.count - a.count;
            });
            deferred.resolve();
        }).fail(function () {
            deferred.reject();
        });
        return deferred.promise();
    },
    loadUnassignedClusters: function () {
        this._unassignedClusters = [];
        var deferred = $.Deferred();
        var self = this;
        $.get(this._baseUrl+'/clusters').done(function (clusters) {
            self._unassignedClusters = clusters.clusters.sort(function(a, b) {
                return b.count - a.count;
            });
            deferred.resolve();
        }).fail(function () {
            deferred.reject();
        });
        return deferred.promise();
    },
    getClustersByName: function () {
        return this._clustersByName;
    },
    getUnassignedClusters: function () {
        return this._unassignedClusters;
    },
    getNamedClusterById: function (clusterId) {
        var ret = undefined;
        for (var cluster of this._clustersByName) {
            if (cluster.id === clusterId) {
                ret = cluster;
                break;
            }
        };
        return ret;
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
            self._mustReload = true;
            deferred.resolve();
        }).fail(function () {
            deferred.reject();
        });
        return deferred.promise();
    },
    setClusterVisibility: function (clusterId, visibility) {
        var self = this;
        var deferred = $.Deferred();
        var opt = { visible: visibility };
        $.ajax({url: this._baseUrl + '/cluster/' + clusterId + '/visibility',
            method: 'POST',
            contentType: 'application/json',
            data: JSON.stringify(opt)
        }).done(function (data) {
            var index = self._clustersByName.findIndex((cluster) => cluster.id === clusterId);
            self._clustersByName.splice(index, 1);
            self._mustReload = true;
            deferred.resolve();
        }).fail(function () {
            deferred.reject();
        });
        return deferred.promise();
    },
    unsetActive: function () {
//        this._persons = [];

        this._activePerson = undefined;

//        this._unassignedClusters = [];

        this._clustersByName = [];
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
    searchUnassignedClusters: function () {
        var self = this;
        self._persons.loadUnassignedClusters().done(function () {
            if (self._persons.getUnassignedClusters().length > 0) {
                var button = $("<button id='show-more-clusters' type='button' class='primary'>" + t('facerecognition', 'Review people found') + "</button>");
                $('#optional-buttons-div').append(button);
                button.click(function () {
                    self.renameUnassignedClusterDialog();
                });
                OC.Notification.showTemporary(t('facerecognition', 'You got some people to recognize'));
            }
        });
    },
    renameUnassignedClusterDialog: function () {
        var self = this;
        var unassignedClusters = this._persons.getUnassignedClusters();
        var cluster = unassignedClusters.shift();
        if (cluster === undefined) {
            self.renderContent();
            if (self._persons.mustReload())
                self.reload();
            return;
        }
        FrDialogs.assignName(cluster.faces,
            function(result, name) {
                if (result === true) {
                    if (name !== null) {
                        if (name.length > 0) {
                            self._persons.renameCluster(cluster.id, name).done(function () {
                                self.renameUnassignedClusterDialog();
                            }).fail(function () {
                                OC.Notification.showTemporary(t('facerecognition', 'There was an error renaming this person'));
                            });
                        } else {
                            self.renameUnassignedClusterDialog();
                        }
                    } else {
                        self._persons.setClusterVisibility(cluster.id, false).done(function () {
                            self.renameUnassignedClusterDialog();
                        }).fail(function () {
                            OC.Notification.showTemporary(t('facerecognition', 'There was an error ignoring this person'));
                        });
                    }
                } else {
                    // Cancelled
                    if (self._persons.mustReload())
                        self.reload();
                }
            }
        );
    },
    renderContent: function () {
        var context = {
            loaded: this._persons.isLoaded(),
            appName: t('facerecognition', 'Face Recognition'),
            welcomeHint: t('facerecognition', 'Here you can see photos of your friends that are recognized'),
            enableDescription: t('facerecognition', 'Analyze my images and group my loved ones with similar faces'),
            loadingMsg: t('facerecognition', 'Looking for your recognized friends'),
            showMoreButton: t('facerecognition', 'Review face groups'),
            emptyMsg: t('facerecognition', 'The analysis is disabled'),
            emptyHint: t('facerecognition', 'Enable it to find your loved ones'),
            renameHint: t('facerecognition', 'Rename'),
            hideHint: t('facerecognition', 'Hide it'),
            loadingIcon: OC.imagePath('core', 'loading.gif')
        };

        if (this._persons.isEnabled() === true) {
            context.enabled = true;
            context.persons = this._persons.getPersons();

            context.emptyMsg = t('facerecognition', 'Your friends have not been recognized yet');
            context.emptyHint = t('facerecognition', 'Please, be patient');
        }

        var person = this._persons.getActivePerson()
        if (person != undefined) {
            context.personName = person.name;
            context.personImages = person.images;
        }

        var clustersByName = this._persons.getClustersByName();
        if (clustersByName.length > 0) {
            context.clustersByName = clustersByName;
        }

        // Render Page.
        var html = Handlebars.templates['personal'](context);
        $('#div-content').html(html);

        // Handle new images
        this._observer.observe();

        // Update title.
        if (person !== undefined) {
            setPersonNameUrl(person.name);
        } else {
            setPersonNameUrl();
        }

        // Share View context
        var self = this;

        /*
         * Actions
         */
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

        $('#facerecognition .file-preview-big').click(function () {
            var filename = $(this).data('id');
            if (window.event.ctrlKey) {
                var file = self._persons.getActivePerson().images.find(function(element) {
                    return element.filename == filename;
                });
                window.open(file.fileUrl, '_blank');
            } else {
                var images = self._persons.getActivePerson().images.map(function(element) {
                    return {
                        basename: element.basename,
                        filename: element.filename,
                        mime:     element.mimetype
                    };
                });
                OCA.Viewer.open({
                    path: filename,
                    list: images,
                });
            }
        });

        $('#facerecognition .face-preview-big').click(function () {
            $(this).css("cursor", "wait");
            var name = $(this).parent().data('id');
            self._persons.loadPerson(name).done(function () {
                self.renderContent();
            }).fail(function () {
                OC.Notification.showTemporary(t('facerecognition', 'There was an error when trying to find photos of your friend'));
            });
        });

        $('#facerecognition #rename-person').click(function () {
            var person = self._persons.getActivePerson();
            FrDialogs.rename(
                person.name,
                [person],
                function(result, value) {
                    if (result === true && value) {
                        self._persons.renamePerson (person.name, value).done(function () {
                            self.renderContent();
                        }).fail(function () {
                            OC.Notification.showTemporary(t('facerecognition', 'There was an error renaming this person'));
                        });
                    }
                }
            );
        });

        $('#facerecognition #hide-person').click(function () {
            var person = self._persons.getActivePerson();
            FrDialogs.hide(
                [person],
                function(result) {
                    if (result === true) {
                        self._persons.setVisibility(person.name, false).done(function () {
                            self._persons.unsetActive();
                            self.reload();
                        }).fail(function () {
                            OC.Notification.showTemporary(t('facerecognition', 'An error occurred while hiding this person'));
                        });
                    }
                }
            );
        });

        $('#facerecognition #rename-cluster').click(function () {
            var id = $(this).data('id');
            var person = self._persons.getNamedClusterById(id);
            FrDialogs.rename(
                person.name,
                [person.faces[0]],
                function(result, value) {
                    if (result === true && value) {
                        self._persons.renameCluster (id, value).done(function () {
                            self.renderContent();
                        }).fail(function () {
                            OC.Notification.showTemporary(t('facerecognition', 'There was an error renaming this cluster of faces'));
                        });
                    }
                }
            );
        });

        $('#facerecognition #hide-cluster').click(function () {
            var id = $(this).data('id');
            var person = self._persons.getNamedClusterById(id);
            FrDialogs.hide(
                [person.faces[0]],
                function(result) {
                    if (result === true) {
                        self._persons.setClusterVisibility(id, false).done(function () {
                            self.renderContent();
                        }).fail(function () {
                            OC.Notification.showTemporary(t('facerecognition', 'An error occurred while hiding this group of faces'));
                        });
                    }
                }
            );
        });

        $('#facerecognition #show-more-clusters').click(function () {
            $(this).css("cursor", "wait");
            var person = self._persons.getActivePerson();
            self._persons.loadClustersByName(person.name).done(function () {
                self.renderContent();
            }).fail(function () {
                OC.Notification.showTemporary(t('facerecognition', 'There was an error when trying to find photos of your friend'));
            });
        });

        $('#facerecognition .icon-back').click(function () {
            self._persons.unsetActive();
            self.renderContent();
            if (self._persons.mustReload() || !self._persons.isLoaded()) {
                self.reload();
            }
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
    var title = t('facerecognition', 'Face Recognition');
    if (personName) {
        cleanUrl += '?name=' + encodeURIComponent(personName);
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

var personName = getPersonNameUrl();
if (personName !== undefined) {
    view.renderContent();
    persons.loadPerson(personName).done(function () {
        view.renderContent();
    }).fail(function () {
        OC.Notification.showTemporary(t('facerecognition', 'There was an error when trying to find photos of your friend'));
    });
} else {
    view.renderContent();
    persons.load().done(function () {
        view.renderContent();
        view.searchUnassignedClusters();
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
