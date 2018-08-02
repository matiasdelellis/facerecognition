(function (OC, window, $, undefined) {
'use strict';

$(document).ready(function () {

/*
 * Faces in memory handlers.
 */
var Persons = function (baseUrl) {
    this._baseUrl = baseUrl;
    this._persons = [];
    this._activePerson = undefined;
    this._selectedFaces = [];
};

Persons.prototype = {
    loadPersons: function () {
        var deferred = $.Deferred();
        var self = this;
        $.get(this._baseUrl+'/persons').done(function (persons) {
            self._persons = persons;
            deferred.resolve();
        }).fail(function () {
            deferred.reject();
        });
        return deferred.promise();
    },
    loadPerson: function (name) {
        var deferred = $.Deferred();
        var self = this;
        $.get(this._baseUrl+'/person/'+name).done(function (person) {
            self._activePerson = person;
            self._selectedFaces = [];
            deferred.resolve();
        }).fail(function () {
            deferred.reject();
        });
        return deferred.promise();
    },
    selectPerson: function (name) {
        var self = this;
        Object.keys(this._persons).forEach(function(key) {
            if (key === name) {
                self._persons[key].active = true;
            }
            else {
                self._persons[key].active = false;
            }
        });
    },
    selectFace: function (id) {
        if (!this._selectedFaces.includes(id))
            this._selectedFaces.push (id);
    },
    unselectFace: function (id) {
        if (this._selectedFaces.includes(id))
            this._selectedFaces = this._selectedFaces.filter(iid => iid != id);
    },
    selectedFace: function (id) {
        return this._selectedFaces.includes(id);
    },
    getAll: function () {
        return this._persons;
    },
    getActive: function () {
        return this._activePerson;
    },
    renameActive: function (newName) {
        var oldName = this._activePerson[0].name;
        var opt = {newName: newName};
        return $.ajax({url: this._baseUrl+'/person/'+oldName,
                       method: 'PUT',
                       contentType: 'application/json',
                       data: JSON.stringify(opt)});
    },
    renameSelection: function (newName) {
        var self = this;
        var deferred = $.Deferred();
        var opt = {newName: newName};
        var requests = [];
        self._selectedFaces.forEach (function(id) {
            requests.push($.ajax({url: self._baseUrl+'/face/'+id,
                                  method: 'PUT',
                                  contentType: 'application/json',
                                  data: JSON.stringify(opt)})
            );
        });
        $.when.apply($,requests).done(function() {
            deferred.resolve(arguments);
        });
        return deferred.promise();
    },
    unsetActive: function () {
        var self = this;
        Object.keys(this._persons).forEach(function(key) {
            self._persons[key].active = false;
        });
        this._activePerson = undefined;
        this._selectedFaces = [];
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
        this._persons.loadPersons().done(function () {
            if (name) {
                self._persons.loadPerson(name).done(function () {
                    self._persons.selectPerson(name);
                    self.render();
                }).fail(function () {
                    alert('D\'Oh!. Could not load faces from person..');
                });
            } else {
                self._persons.unsetActive();
                self.render();
            }
        }).fail(function () {
            alert('D\'Oh!. Could not reload faces..');
        });
    },
    renderContent: function () {
        var source = $('#content-tpl').html();
        var template = Handlebars.compile(source);

        if (this._persons.getActive() !== undefined)
            var html = template({person: this._persons.getActive()});
        else
            var html = template({persons: this._persons.getAll()});

        $('#div-content').html(html);

        const observer = lozad();
        observer.observe();

        var self = this;

        $('#app-content .person-title > a').click(function () {
            var name = $(this).children().data('id');
            self._persons.loadPerson(name).done(function () {
                self._persons.selectPerson(name);
                view.render();
            }).fail(function () {
                alert('D\'Oh!. Could not load faces from person..');
            });
        });

        $("#app-content").on("mouseenter", ".lozad", function() {
            $(this).children(".icon-checkmark").addClass("show-overlay-icon");
            $(this).children(".icon-rename").addClass("show-overlay-icon");
        });
        $("#app-content").on("mouseleave", ".lozad", function() {
            $(this).children(".icon-checkmark").removeClass("show-overlay-icon");
            $(this).children(".icon-rename").removeClass("show-overlay-icon");
        });
        $('#app-content .icon-rename').click(function () {
            var id = parseInt($(this).parent().data('id'), 10);
            if (!self._persons.selectedFace(id)) {
                self._persons.selectFace (id);
                $(this).parent().children('.icon-checkmark').addClass('icon-checkmark-selected');
                $(this).parent().children('.icon-rename').addClass('icon-checkmark-selected');
                $(this).parent().addClass('face-selected');
            }

            OC.dialogs.prompt(
                t('facerecognition', 'Please enter a name to rename the person'),
                t('facerecognition', 'Rename'),
                    function(result, value) {
                        if (result === true && value) {
                            self._persons.renameSelection(value).done (function(person) {
                                self.reload(value);
                            });
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
        });

        $('#app-content .icon-checkmark').click(function () {
            var id = parseInt($(this).parent().data('id'), 10);
            if (!self._persons.selectedFace(id)) {
                self._persons.selectFace (id);
                $(this).addClass('icon-checkmark-selected');
                $(this).parent().addClass('face-selected');
                $(this).parent().children('.icon-rename').addClass('icon-checkmark-selected');
            } else {
                self._persons.unselectFace (id);
                $(this).removeClass('icon-checkmark-selected');
                $(this).parent().removeClass('face-selected');
                $(this).parent().children('.icon-rename').removeClass('icon-checkmark-selected');
            }
        });

    },
    renderNavigation: function () {
        var source = $('#navigation-tpl').html();
        var template = Handlebars.compile(source);
        var html = template({persons: this._persons.getAll(), person: this._persons.getActive()});

        $('#app-navigation ul').html(html);

        var self = this;

        // Show all.
        $('#all-button').click(function () {
            view._persons.unsetActive();
            view.render();
        });
        // load a complete person.
        $('#app-navigation .person > a').click(function () {
            var name = $(this).parent().data('id');
            self._persons.loadPerson(name).done(function () {
                self._persons.selectPerson(name);
                view.render();
            }).fail(function () {
                alert('D\'Oh!. Could not load faces from person..');
            });
        });
        // edit a person.
        $('#app-navigation .icon-rename').click(function () {
            $('#app-navigation .active').addClass('editing');
            var input = $('#app-navigation #input-name')
            input.focus();
            input.select();
        });
        $('#app-navigation #rename-cancel').click(function () {
            $('#app-navigation .active').removeClass('editing');
        });
        $('#app-navigation #rename-accept').click(function () {
            var newName = $('#app-navigation #input-name').val().trim();
            self._persons.renameActive(newName).done(function (data) {
                self.reload(newName);
            });
            $('#app-navigation .active').removeClass('editing');
        });

    },
    render: function () {
        this.renderNavigation();
        this.renderContent();
    }
};

/*
 * Main app.
 */
var persons = new Persons(OC.generateUrl('/apps/facerecognition'));
var view = new View(persons);

persons.loadPersons().done(function () {
    view.render();
}).fail(function () {
    alert('D\'Oh!. Could not load faces..');
});


}); // $(document).ready(function () {
})(OC, window, jQuery); // (function (OC, window, $, undefined) {