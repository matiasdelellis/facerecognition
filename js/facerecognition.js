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
    selectPerson: function (name) {
        var self = this;
        Object.keys(this._groups).forEach(function(key) {
            if (key === name) {
                self._groups[key].active = true;
            }
            else {
                self._groups[key].active = false;
            }
        });
    },
    getAll: function () {
        return this._groups;
    },
    getActive: function () {
        return this._activePerson;
    },
    unsetActive: function () {
        var self = this;
        Object.keys(this._groups).forEach(function(key) {
            self._groups[key].active = false;
        });
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
            var html = template({person: this._groups.getActive()});
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
        // load a complete group.
        $('#app-navigation .group > a').click(function () {
            var name = $(this).parent().data('id');
            self._groups.loadPerson(name).done(function () {
                self._groups.selectPerson(name);
                view.render();
            }).fail(function () {
                alert('D\'Oh!. Could not load faces from person..');
            });
        });
        // edit a group.
        $('#app-navigation .icon-rename').click(function () {
            $('#app-navigation .active').addClass('editing');
        });
        $('#app-navigation #rename-cancel').click(function () {
            $('#app-navigation .active').removeClass('editing');
        });
        $('#app-navigation #rename-accept').click(function () {
            console.log("Value: " + $('#app-navigation #input-name').val());
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
var groups = new Groups(OC.generateUrl('/apps/facerecognition'));
var view = new View(groups);

groups.loadGroups().done(function () {
    view.render();
}).fail(function () {
    alert('D\'Oh!. Could not load faces groups..');
});


}); // $(document).ready(function () {
})(OC, window, jQuery); // (function (OC, window, $, undefined) {