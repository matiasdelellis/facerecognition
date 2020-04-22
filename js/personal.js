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
    this._clustersByName = undefined;
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
        this.unsetActive();

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
    loadClustersByName: function (personName) {
        var deferred = $.Deferred();
        var self = this;
        $.get(this._baseUrl+'/person/'+personName).done(function (clusters) {
            self._clustersByName = clusters.clusters;
            deferred.resolve();
        }).fail(function () {
            deferred.reject();
        });
        return deferred.promise();
    },
    unsetActive: function () {
        this._cluster = undefined;
        this._clustersByName = undefined;
    },
    getActive: function () {
        return this._cluster;
    },
    getActiveByName: function () {
        return this._clustersByName;
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
        if (this._clusters !== undefined)
            this._clusters.sort(function(a, b) {
                return b.count - a.count;
            });
        if (this._clustersByName !== undefined)
            this._clustersByName.sort(function(a, b) {
                return b.count - a.count;
            });
    },
    getAll: function () {
        return this._clusters;
    },
    updateCluster: function (clusterId, personName) {
        var self = this;
        var deferred = $.Deferred();
        var opt = { name: personName };
        $.ajax({url: this._baseUrl + '/cluster/' + clusterId,
                method: 'PUT',
                contentType: 'application/json',
                data: JSON.stringify(opt)
        }).done(function (data) {
            self.renameCluster(clusterId, personName);
            deferred.resolve();
        }).fail(function () {
            deferred.reject();
        });
        return deferred.promise();
    },
    renameCluster: function (clusterId, personName) {
        this._clusters.forEach(function (cluster) {
            if (cluster.id === clusterId) {
                cluster.name = personName;
            }
        });
    }
};

/*
 * View.
 */
var View = function (persons, similar) {
    this._persons = persons;
    this._similar = similar;
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
    renamePerson: function (personId, personName, faceUrl) {
        var self = this;
        FrDialogs.rename(
            personName,
            faceUrl,
            function(result, name) {
                if (result === true && name) {
                    self._persons.updateCluster(personId, name).done(function() {
                        self._persons.unsetActive();
                        self.renderContent();
                        self._similar.findProposal(personId, name).done(function() {
                            if (self._similar.isEnabled()) {
                                if (self._similar.hasProposal()) {
                                    self.suggestPerson(self._similar.getProposal(), name);
                                } else {
                                    OC.Notification.showTemporary(t('facerecognition', 'There are no more suggestions from similar persons'));
                                }
                            }
                        });
                    }).fail(function () {
                        OC.Notification.showTemporary(t('facerecognition', 'There was an error renaming this person'));
                    });
                }
            }
        );
    },
    suggestPerson: function (proposal, personName) {
        var self = this;
        FrDialogs.suggestPersonName(
            personName,
            this._persons.getById(proposal.id).faces,
            function(valid, state) {
                if (valid === true) {
                    // It is valid must be update the proposals.
                    self._similar.updateProposal(proposal, state).done(function() {
                        if (state === Relation.ACCEPTED) {
                            // Update view with new name.
                            self._persons.renameCluster(proposal.id, personName);
                            self._persons.unsetActive();
                            self.renderContent();
                            // Look for new suggestions based on accepted proposal
                            self._similar.findProposal(proposal.id, personName).done(function() {
                                if (self._similar.hasProposal()) {
                                    self.suggestPerson(self._similar.getProposal(), personName);
                                } else {
                                    OC.Notification.showTemporary(t('facerecognition', 'There are no more suggestions from similar persons'));
                                }
                            });
                        } else {
                            // Suggest cached proposals
                            if (self._similar.hasProposal()) {
                                self.suggestPerson(self._similar.getProposal(), personName);
                            } else {
                                OC.Notification.showTemporary(t('facerecognition', 'There are no more suggestions from similar persons'));
                            }
                        }
                    }).fail(function () {
                        if (state === Relation.ACCEPTED) {
                            OC.Notification.showTemporary(t('facerecognition', 'There was an error renaming this person'));
                        }
                    });
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
            context.clusters = this._persons.getAll();

            context.emptyMsg = t('facerecognition', 'Your friends have not been recognized yet');
            context.emptyHint = t('facerecognition', 'Please, be patient');
        }

        if (this._persons.getActive() !== undefined)
            context.cluster = this._persons.getActive();

        if (this._persons.getActiveByName() !== undefined)
            context.clustersByName = this._persons.getActiveByName();

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
            self.renamePerson(id, person.name, person.faces[0]['thumb-url']);
        });

        $('#facerecognition #show-more-clusters').click(function () {
            var personName = self._persons.getActive().name;
            self._persons.loadClustersByName(personName).done(function () {
                self.renderContent();
            }).fail(function () {
                OC.Notification.showTemporary(t('facerecognition', 'There was an error when trying to find photos of your friend'));
            });
        });

        $('#facerecognition .icon-view-previous').click(function () {
            self._persons.unsetActive();
            self.renderContent();
        });
    }
};

/*
 * Main app.
 */
var persons = new Persons(OC.generateUrl('/apps/facerecognition'));
var similar = new Similar(OC.generateUrl('/apps/facerecognition'));

var view = new View(persons, similar);

view.renderContent();

persons.load().done(function () {
    view.renderContent();
}).fail(function () {
    OC.Notification.showTemporary(t('facerecognition', 'There was an error trying to show your friends'));
});

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