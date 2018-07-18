(function (OC, window, $, undefined) {
'use strict';

$(document).ready(function () {

/*
 * Faces in memory handlers.
 */
var Groups = function (baseUrl) {
    this._baseUrl = baseUrl;
    this._groups = [];
    this._activePerson = undefined;
};

Groups.prototype = {
    loadGroups: function () {
        var deferred = $.Deferred();
        var self = this;
        $.get(this._baseUrl+'/groups').done(function (groups) {
            self._groups = groups;
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
    getAll: function () {
        return this._groups;
    },
    getActive: function () {
        return this._activePerson;
    },
    unsetActive: function () {
        this._activePerson = undefined;
    }
};

/*
 * View.
 */
var View = function (groups) {
    this._groups = groups;
    this._active = undefined;
};

View.prototype = {
    renderContent: function () {
        var source = $('#content-tpl').html();
        var template = Handlebars.compile(source);
        if (this._groups.getActive() !== undefined)
            var html = template({person: this._groups.getActive(), name: this._active});
        else
            var html = template({groups: this._groups.getAll()});

        $('#div-content').html(html);

        const observer = lozad();
        observer.observe();
    },
    renderNavigation: function () {
        var source = $('#navigation-tpl').html();
        var template = Handlebars.compile(source);
        var html = template({groups: this._groups.getAll(), person: this._groups.getActive()});

        $('#app-navigation ul').html(html);

        var self = this;

        // Show all.
        $('#all-button').click(function () {
            view._groups.unsetActive();
            view._active = undefined;
            view.render();
        });
        // load a comlete group.
        $('#app-navigation .group > a').click(function () {
            var name = $(this).parent().data('id');
            self._groups.loadPerson(name).done(function () {
                view._active = name;
                view.render();
            }).fail(function () {
                alert('D\'Oh!. Could not load faces from person..');
            });
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
var groups = new Groups(OC.generateUrl('/apps/facerecognition'));
var view = new View(groups);

groups.loadGroups().done(function () {
    view.render();
}).fail(function () {
    alert('D\'Oh!. Could not load faces groups..');
});


}); // $(document).ready(function () {
})(OC, window, jQuery); // (function (OC, window, $, undefined) {