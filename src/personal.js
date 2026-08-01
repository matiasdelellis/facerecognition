(function (OC, window) {
'use strict';

function $(selector, root) {
    return (root || document).querySelector(selector);
}

function $$(selector, root) {
    return Array.from((root || document).querySelectorAll(selector));
}

function getJson(url) {
    return fetch(url, {
        headers: { 'OCS-APIRequest': 'true', 'requesttoken': OC.requestToken },
        credentials: 'same-origin',
    }).then((response) => {
        if (!response.ok) {
            throw new Error('Request failed: ' + response.status);
        }
        return response.json();
    });
}

function requestJson(url, method, body) {
    return fetch(url, {
        method: method,
        headers: {
            'Content-Type': 'application/json',
            'OCS-APIRequest': 'true',
            'requesttoken': OC.requestToken,
        },
        credentials: 'same-origin',
        body: body !== undefined ? JSON.stringify(body) : undefined,
    }).then((response) => {
        if (!response.ok) {
            throw new Error('Request failed: ' + response.status);
        }
        return response.json();
    });
}

function postForm(url, data) {
    const body = new URLSearchParams();
    for (const key in data) {
        body.append(key, data[key]);
    }
    return fetch(url, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
            'OCS-APIRequest': 'true',
            'requesttoken': OC.requestToken,
        },
        credentials: 'same-origin',
        body: body.toString(),
    }).then((response) => {
        if (!response.ok) {
            throw new Error('Request failed: ' + response.status);
        }
        return response.json();
    });
}

document.addEventListener('DOMContentLoaded', function () {

/*
 * Faces in memory handlers.
 */
function Persons(baseUrl) {
    this._baseUrl = baseUrl;
    this._persons = [];
    this._activePerson = undefined;
    this._clustersByName = [];
    this._unassignedClusters = [];
    this._ignoredClusters = [];
    this._suggestions = {};
    this._suggestionsLoading = {};
    this._loaded = false;
    this._mustReload = false;
}

Persons.prototype = {
    isLoaded: function () {
        return this._loaded;
    },
    mustReload: function () {
        return this._mustReload;
    },
    load: function () {
        const self = this;
        return getJson(this._baseUrl + '/persons').then((response) => {
            self._persons = response.persons.sort(function (a, b) {
                return b.count - a.count;
            });
            self._loaded = true;
            self._mustReload = false;
        });
    },
    loadPerson: function (personName) {
        this.unsetActive();
        const self = this;
        return getJson(this._baseUrl + '/person/' + encodeURIComponent(personName)).then((person) => {
            self._activePerson = person;
        });
    },
    getPersons: function () {
        return this._persons;
    },
    getActivePerson: function () {
        return this._activePerson;
    },
    renamePerson: function (personName, name) {
        const self = this;
        return requestJson(this._baseUrl + '/person/' + encodeURIComponent(personName), 'PUT', { name: name }).then((person) => {
            self._activePerson = person;
            self._mustReload = true;
        });
    },
    setVisibility: function (personName, visibility) {
        const self = this;
        return requestJson(this._baseUrl + '/person/' + encodeURIComponent(personName) + '/visibility', 'POST', { visible: visibility }).then(() => {
            self._mustReload = true;
        });
    },
    loadClustersByName: function (personName) {
        const self = this;
        return getJson(this._baseUrl + '/clusters/' + encodeURIComponent(personName)).then((clusters) => {
            self._clustersByName = clusters.clusters.sort(function (a, b) {
                return b.count - a.count;
            });
        });
    },
    loadUnassignedClusters: function () {
        this._unassignedClusters = [];
        const self = this;
        return getJson(this._baseUrl + '/clusters').then((clusters) => {
            self._unassignedClusters = clusters.clusters.sort(function (a, b) {
                return b.count - a.count;
            });
        });
    },
    loadIgnoredClusters: function () {
        this._ignoredClusters = [];
        const self = this;
        return getJson(this._baseUrl + '/clustersIgnored').then((clusters) => {
            self._ignoredClusters = clusters.clusters.sort(function (a, b) {
                return b.count - a.count;
            });
        });
    },
    getClustersByName: function () {
        return this._clustersByName;
    },
    getUnassignedClusters: function () {
        return this._unassignedClusters;
    },
    getIgnoredClusters: function () {
        return this._ignoredClusters;
    },
    getNamedClusterById: function (clusterId) {
        for (const cluster of this._clustersByName) {
            if (cluster.id === clusterId) {
                return cluster;
            }
        }
        return undefined;
    },
    isSuggestionsLoading: function (clusterId) {
        return !!this._suggestionsLoading[clusterId];
    },
    getSuggestions: function (clusterId) {
        return this._suggestions[clusterId] || null;
    },
    hasSuggestionsLoaded: function (clusterId) {
        return Object.prototype.hasOwnProperty.call(this._suggestions, clusterId);
    },
    loadSimilar: function (clusterId) {
        const self = this;
        self._suggestionsLoading[clusterId] = true;
        return getJson(this._baseUrl + '/cluster/' + clusterId + '/similar').then((response) => {
            self._suggestions[clusterId] = (response.clusters || []).slice().sort(function (a, b) {
                return a.distance - b.distance;
            });
        }).catch((err) => {
            delete self._suggestions[clusterId];
            throw err;
        }).then(() => {
            self._suggestionsLoading[clusterId] = false;
        });
    },
    acceptSuggestion: function (clusterId, suggestion) {
        const self = this;
        const person = this._getPersonOfCluster(clusterId);
        if (!person) {
            return Promise.reject(new Error('Cluster has no person'));
        }
        return requestJson(this._baseUrl + '/cluster/' + suggestion.id, 'PUT', { name: person }).then(() => {
            const list = self._suggestions[clusterId];
            if (list) {
                const idx = list.findIndex(function (s) { return s.id === suggestion.id; });
                if (idx !== -1) list.splice(idx, 1);
            }
            self._mustReload = true;
            return self.loadClustersByName(person);
        });
    },
    rejectSuggestion: function (clusterId, suggestion) {
        const self = this;
        return requestJson(this._baseUrl + '/cluster/' + suggestion.id + '/visibility', 'POST', { visible: false }).then(() => {
            const list = self._suggestions[clusterId];
            if (list) {
                const idx = list.findIndex(function (s) { return s.id === suggestion.id; });
                if (idx !== -1) list.splice(idx, 1);
            }
            self._mustReload = true;
            const person = self._getPersonOfCluster(clusterId);
            if (person) {
                return self.loadClustersByName(person);
            }
        });
    },
    _getPersonOfCluster: function (clusterId) {
        for (const cluster of this._clustersByName) {
            if (cluster.id === clusterId && cluster.name) {
                return cluster.name;
            }
        }
        const active = this._activePerson;
        if (active && active.name) {
            return active.name;
        }
        return null;
    },
    renameCluster: function (clusterId, personName) {
        const self = this;
        return requestJson(this._baseUrl + '/cluster/' + clusterId, 'PUT', { name: personName }).then(() => {
            self._clustersByName.forEach(function (cluster) {
                if (cluster.id === clusterId) {
                    cluster.name = personName;
                }
            });
            self._mustReload = true;
        });
    },
    setClusterVisibility: function (clusterId, visibility) {
        const self = this;
        return requestJson(this._baseUrl + '/cluster/' + clusterId + '/visibility', 'POST', { visible: visibility }).then(() => {
            const index = self._clustersByName.findIndex((cluster) => cluster.id === clusterId);
            if (index !== -1) {
                self._clustersByName.splice(index, 1);
            }
            self._mustReload = true;
        });
    },
    unsetActive: function () {
        this._activePerson = undefined;
        this._clustersByName = [];
        this._suggestions = {};
        this._suggestionsLoading = {};
    }
};

/*
 * View.
 */
function View(persons) {
    this._enabled = OCP.InitialState.loadState('facerecognition', 'user-enabled');
    this._hasUnamed = OCP.InitialState.loadState('facerecognition', 'has-unamed');
    this._hasHidden = OCP.InitialState.loadState('facerecognition', 'has-hidden');
    this._persons = persons;
    this._observer = lozad('.lozad');
}

View.prototype = {
    reload: function () {
        const self = this;
        this._persons.load().then(function () {
            self.renderContent();
        }).catch(function () {
            OC.Notification.showTemporary(t('facerecognition', 'There was an error trying to show your friends'));
        });
    },
    setEnabledUser: function (enabled) {
        const self = this;
        postForm(OC.generateUrl('apps/facerecognition/setuservalue'), {
            type: 'enabled',
            value: enabled,
        }).then(function () {
            if (enabled) {
                OC.Notification.showTemporary(t('facerecognition', 'The analysis is enabled, please be patient, you will soon see your friends here.'));
            } else {
                OC.Notification.showTemporary(t('facerecognition', 'The analysis is disabled. Soon all the information found for facial recognition will be removed.'));
            }
            self._enabled = enabled;
            self.reload();
        }).catch(function () {
            OC.Notification.showTemporary(t('facerecognition', 'There was an error trying to change the analysis state'));
        });
    },
    renameUnassignedClusterDialog: function () {
        const self = this;
        const unassignedClusters = this._persons.getUnassignedClusters();
        const cluster = unassignedClusters.shift();
        if (cluster === undefined) {
            OC.Notification.showTemporary(t('facerecognition', 'You don\'t have more people to recognize.'));
            self.renderContent();
            if (self._persons.mustReload()) {
                self.reload();
            }
            return;
        }
        window.FrDialogs.assignName(cluster.faces, function (result, name) {
            if (result === true) {
                if (name !== null) {
                    if (name.length > 0) {
                        self._persons.renameCluster(cluster.id, name).then(function () {
                            self.renameUnassignedClusterDialog();
                        }).catch(function () {
                            OC.Notification.showTemporary(t('facerecognition', 'There was an error renaming this person'));
                        });
                    } else {
                        self.renameUnassignedClusterDialog();
                    }
                } else {
                    self._persons.setClusterVisibility(cluster.id, false).then(function () {
                        self.renameUnassignedClusterDialog();
                    }).catch(function () {
                        OC.Notification.showTemporary(t('facerecognition', 'There was an error ignoring this person'));
                    });
                }
            } else {
                if (self._persons.mustReload()) {
                    self.reload();
                }
            }
        });
    },
    renameIgnoredClusterDialog: function () {
        const self = this;
        const ignoredClusters = this._persons.getIgnoredClusters();
        const cluster = ignoredClusters.shift();
        if (cluster === undefined) {
            OC.Notification.showTemporary(t('facerecognition', 'You no longer have people ignored'));
            self.renderContent();
            if (self._persons.mustReload()) {
                self.reload();
            }
            return;
        }
        window.FrDialogs.assignIgnored(cluster.faces, function (result, name) {
            if (result === true) {
                if (name !== null) {
                    if (name.length > 0) {
                        self._persons.renameCluster(cluster.id, name).then(function () {
                            self.renameIgnoredClusterDialog();
                        }).catch(function () {
                            OC.Notification.showTemporary(t('facerecognition', 'There was an error renaming this person'));
                        });
                    } else {
                        self.renameIgnoredClusterDialog();
                    }
                } else {
                    self.renameIgnoredClusterDialog();
                }
            } else {
                if (self._persons.mustReload()) {
                    self.reload();
                }
            }
        });
    },
    renderContent: function () {
        const context = {
            loaded: this._persons.isLoaded(),
            appName: t('facerecognition', 'Face Recognition'),
            welcomeHint: t('facerecognition', 'Here you can see photos of your friends that are recognized'),
            enableDescription: t('facerecognition', 'Analyze my images and group my loved ones with similar faces'),
            loadingMsg: t('facerecognition', 'Looking for your recognized friends'),
            showMoreButton: t('facerecognition', 'Review face groups'),
            showIgnoredButton: t('facerecognition', 'Review ignored people'),
            emptyMsg: t('facerecognition', 'The analysis is disabled'),
            emptyHint: t('facerecognition', 'Enable it to find your loved ones'),
            renameHint: t('facerecognition', 'Rename'),
            hideHint: t('facerecognition', 'Hide it'),
            findSimilarHint: t('facerecognition', 'Find similar clusters'),
            suggestionsLoadingMsg: t('facerecognition', 'Looking for similar clusters…'),
            suggestionsEmptyMsg: t('facerecognition', 'No other clusters look like this one.'),
            unknownPersonLabel: t('facerecognition', 'Unknown person'),
            acceptHint: t('facerecognition', 'This is the same person'),
            acceptLabel: t('facerecognition', 'Same person'),
            rejectHint: t('facerecognition', 'This is a different person'),
            rejectLabel: t('facerecognition', 'Different person'),
            loadingIcon: OC.imagePath('core', 'loading.gif')
        };

        if (this._enabled === true) {
            context.enabled = true;
            context.hasUnamed = this._hasUnamed;
            context.hasHidden = this._hasHidden;
            context.persons = this._persons.getPersons();
            context.reviewPeopleMsg = t('facerecognition', 'Review people found');
            context.reviewIgnoredMsg = t('facerecognition', 'Review ignored people');
            context.emptyMsg = t('facerecognition', 'Your friends have not been recognized yet');
            context.emptyHint = t('facerecognition', 'Please, be patient');
        }

        const person = this._persons.getActivePerson();
        if (person !== undefined) {
            context.personName = person.name;
            context.personImages = person.images;
        }

        const clustersByName = this._persons.getClustersByName();
        if (clustersByName.length > 0) {
            context.clustersByName = clustersByName.map(function (cluster) {
                const suggestions = this._persons.getSuggestions(cluster.id);
                return Object.assign({}, cluster, {
                    suggestions: suggestions === null ? undefined : suggestions,
                    suggestionsLoading: this._persons.isSuggestionsLoading(cluster.id) && suggestions === null,
                });
            }.bind(this));
        }

        const html = Handlebars.templates['personal'](context);
        const container = $('#div-content');
        if (container) container.innerHTML = html;

        this._observer.observe();

        if (person !== undefined) {
            setPersonNameUrl(person.name);
        } else {
            setPersonNameUrl();
        }

        this.bindActions();
    },
    bindActions: function () {
        const self = this;
        const root = $('#div-content');
        if (!root) return;

        const enableEl = $('#enableFacerecognition', root);
        if (enableEl) {
            enableEl.addEventListener('click', function () {
                const enabled = this.checked;
                if (enabled === false) {
                    OC.dialogs.confirm(
                        t('facerecognition', 'You will lose all the information analyzed, and if you re-enable it, you will start from scratch.'),
                        t('facerecognition', 'Do you want to deactivate the grouping by faces?'),
                        function (result) {
                            if (result === true) {
                                self.setEnabledUser(false);
                            } else {
                                enableEl.checked = true;
                            }
                        },
                        true
                    );
                } else {
                    self.setEnabledUser(true);
                }
            });
        }

        const showMore = $('#show-more-clusters', root);
        if (showMore) {
            showMore.addEventListener('click', function () {
                const button = this;
                button.style.cursor = 'wait';
                self._persons.loadUnassignedClusters().then(function () {
                    button.style.cursor = '';
                    if (self._persons.getUnassignedClusters().length > 0) {
                        self.renameUnassignedClusterDialog();
                    } else {
                        OC.Notification.showTemporary(t('facerecognition', 'You dont have more people to recognize.'));
                    }
                });
            });
        }

        const showIgnored = $('#show-ignored-clusters', root);
        if (showIgnored) {
            showIgnored.addEventListener('click', function () {
                const button = this;
                button.style.cursor = 'wait';
                self._persons.loadIgnoredClusters().then(function () {
                    button.style.cursor = '';
                    if (self._persons.getIgnoredClusters().length > 0) {
                        self.renameIgnoredClusterDialog();
                    } else {
                        OC.Notification.showTemporary(t('facerecognition', 'You no longer have people ignored'));
                    }
                });
            });
        }

        $$('#facerecognition .file-preview-big', root).forEach(function (preview) {
            preview.addEventListener('click', function (event) {
                const filename = this.dataset.id;
                if (event.ctrlKey) {
                    const file = self._persons.getActivePerson().images.find(function (element) {
                        return element.filename == filename;
                    });
                    window.open(file.fileUrl, '_blank');
                } else {
                    const images = self._persons.getActivePerson().images.map(function (element) {
                        return {
                            basename: element.basename,
                            filename: element.filename,
                            mime: element.mimetype
                        };
                    });
                    OCA.Viewer.open({
                        path: filename,
                        list: images,
                    });
                }
            });
        });

        $$('#facerecognition .face-preview-big', root).forEach(function (preview) {
            preview.addEventListener('click', function () {
                this.style.cursor = 'wait';
                const name = this.parentElement.dataset.id;
                self._persons.loadPerson(name).then(function () {
                    self.renderContent();
                }).catch(function () {
                    OC.Notification.showTemporary(t('facerecognition', 'There was an error when trying to find photos of your friend'));
                });
            });
        });

        const renamePerson = $('#facerecognition #rename-person', root);
        if (renamePerson) {
            renamePerson.addEventListener('click', function () {
                const person = self._persons.getActivePerson();
                window.FrDialogs.rename(person.name, [person], function (result, value) {
                    if (result === true && value) {
                        self._persons.renamePerson(person.name, value).then(function () {
                            self.renderContent();
                        }).catch(function () {
                            OC.Notification.showTemporary(t('facerecognition', 'There was an error renaming this person'));
                        });
                    }
                });
            });
        }

        const hidePerson = $('#facerecognition #hide-person', root);
        if (hidePerson) {
            hidePerson.addEventListener('click', function () {
                const person = self._persons.getActivePerson();
                window.FrDialogs.hide([person], function (result) {
                    if (result === true) {
                        self._persons.setVisibility(person.name, false).then(function () {
                            self._persons.unsetActive();
                            self.reload();
                        }).catch(function () {
                            OC.Notification.showTemporary(t('facerecognition', 'An error occurred while hiding this person'));
                        });
                    }
                });
            });
        }

        $$('#facerecognition #rename-cluster', root).forEach(function (button) {
            button.addEventListener('click', function () {
                const id = this.dataset.id;
                const person = self._persons.getNamedClusterById(id);
                window.FrDialogs.rename(person.name, [person.faces[0]], function (result, value) {
                    if (result === true && value) {
                        self._persons.renameCluster(id, value).then(function () {
                            self.renderContent();
                        }).catch(function () {
                            OC.Notification.showTemporary(t('facerecognition', 'There was an error renaming this cluster of faces'));
                        });
                    }
                });
            });
        });

        $$('#facerecognition #hide-cluster', root).forEach(function (button) {
            button.addEventListener('click', function () {
                const id = this.dataset.id;
                const person = self._persons.getNamedClusterById(id);
                window.FrDialogs.hide([person.faces[0]], function (result) {
                    if (result === true) {
                        self._persons.setClusterVisibility(id, false).then(function () {
                            self.renderContent();
                        }).catch(function () {
                            OC.Notification.showTemporary(t('facerecognition', 'An error occurred while hiding this group of faces'));
                        });
                    }
                });
            });
        });

        $$('#facerecognition #find-similar-cluster', root).forEach(function (button) {
            button.addEventListener('click', function () {
                const id = this.dataset.id;
                const buttonEl = this;
                buttonEl.style.display = 'none';
                self._persons.loadSimilar(id).then(function () {
                    self.renderContent();
                }).catch(function () {
                    buttonEl.style.display = '';
                    OC.Notification.showTemporary(t('facerecognition', 'There was an error looking for similar clusters'));
                });
            });
        });

        $$('#facerecognition .accept-suggestion', root).forEach(function (button) {
            button.addEventListener('click', function (event) {
                event.preventDefault();
                const clusterId = this.dataset.clusterId;
                const suggestionId = parseInt(this.dataset.suggestionId, 10);
                const list = self._persons.getSuggestions(clusterId) || [];
                const suggestion = list.find(function (s) { return s.id === suggestionId; });
                if (!suggestion) return;
                self._persons.acceptSuggestion(clusterId, suggestion).then(function () {
                    self.renderContent();
                }).catch(function () {
                    OC.Notification.showTemporary(t('facerecognition', 'There was an error accepting the suggestion'));
                });
            });
        });

        $$('#facerecognition .reject-suggestion', root).forEach(function (button) {
            button.addEventListener('click', function (event) {
                event.preventDefault();
                const clusterId = this.dataset.clusterId;
                const suggestionId = parseInt(this.dataset.suggestionId, 10);
                const list = self._persons.getSuggestions(clusterId) || [];
                const suggestion = list.find(function (s) { return s.id === suggestionId; });
                if (!suggestion) return;
                self._persons.rejectSuggestion(clusterId, suggestion).then(function () {
                    self.renderContent();
                }).catch(function () {
                    OC.Notification.showTemporary(t('facerecognition', 'There was an error rejecting the suggestion'));
                });
            });
        });

        const reviewClusters = $('#facerecognition #review-person-clusters', root);
        if (reviewClusters) {
            reviewClusters.addEventListener('click', function () {
                this.style.cursor = 'wait';
                const person = self._persons.getActivePerson();
                self._persons.loadClustersByName(person.name).then(function () {
                    self.renderContent();
                }).catch(function () {
                    OC.Notification.showTemporary(t('facerecognition', 'There was an error when trying to find photos of your friend'));
                });
            });
        }

        $$('#facerecognition .icon-back', root).forEach(function (back) {
            back.addEventListener('click', function () {
                self._persons.unsetActive();
                self.renderContent();
                if (self._persons.mustReload() || !self._persons.isLoaded()) {
                    self.reload();
                }
            });
        });
    }
};

/**
 * Get the personName as URL parameter
 */
function getPersonNameUrl() {
    let personName;
    const parser = document.createElement('a');
    parser.href = window.location.href;
    const query = parser.search.substring(1);
    const vars = query.split('&');
    for (let i = 0; i < vars.length; i++) {
        const pair = vars[i].split('=');
        if (pair[0] === 'name') {
            personName = decodeURIComponent(pair[1]);
            break;
        }
    }
    return personName;
}

/**
 *  Change the URL location with personName as parameter
 */
function setPersonNameUrl(personName) {
    let cleanUrl = window.location.href.split('?')[0];
    let title = t('facerecognition', 'Face Recognition');
    if (personName) {
        cleanUrl += '?name=' + encodeURIComponent(personName);
        title += ' - ' + personName;
    }
    window.history.replaceState({}, title, cleanUrl);
    document.title = title;
}

/**
 * Add Helpers to handlebars
 */
Handlebars.registerHelper('noPhotos', function (count) {
    return n('facerecognition', '%n image', '%n images', count);
});

Handlebars.registerHelper('suggestionsTitleFmt', function (name) {
    return t('facerecognition', 'Other clusters that may also be {name}', { name: name });
});

/*
 * Main app.
 */
const persons = new Persons(OC.generateUrl('/apps/facerecognition'));
const view = new View(persons);

const personName = getPersonNameUrl();
if (personName !== undefined) {
    view.renderContent();
    persons.loadPerson(personName).then(function () {
        view.renderContent();
    }).catch(function () {
        OC.Notification.showTemporary(t('facerecognition', 'There was an error when trying to find photos of your friend'));
    });
} else {
    view.renderContent();
    persons.load().then(function () {
        view.renderContent();
    }).catch(function () {
        OC.Notification.showTemporary(t('facerecognition', 'There was an error trying to show your friends'));
    });
}

if (window.Egg) {
    new window.Egg("up,up,down,down,left,right,left,right,b,a", function () {
        if (!OC.isUserAdmin()) {
            OC.Notification.showTemporary(t('facerecognition', 'You must be administrator to configure this feature'));
            return;
        }
        postForm(OC.generateUrl('apps/facerecognition/setappvalue'), {
            type: 'obfuscate_faces',
            value: 'toggle',
        }).then(function () {
            location.reload();
        });
    }).listen();
}

}); // DOMContentLoaded
})(OC, window);
