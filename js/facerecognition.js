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
    getAll: function () {
        return this._persons;
    },
    getActive: function () {
        return this._activePerson;
    },
    unsetActive: function () {
        var self = this;
        Object.keys(this._persons).forEach(function(key) {
            self._persons[key].active = false;
        });
        this._activePerson = undefined;
    }
};

/*
 * View.
 */
var View = function (persons) {
    this._persons = persons;
    this._active = undefined;
};

View.prototype = {
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
            view._active = undefined;
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
        });
        $('#app-navigation #rename-cancel').click(function () {
            $('#app-navigation .active').removeClass('editing');
        });
        $('#app-navigation #rename-accept').click(function () {
            var oldName = self._persons.getActive()[0].name;
            console.log("Old Value: " + oldName);
            console.log("New Value: " + $('#app-navigation #input-name').val());
            //var json = { name: oldName};
            //$.post(OC.generateUrl('/apps/facerecognition/rename/')+name, );
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