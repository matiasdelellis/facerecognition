(function (OC, window, $, undefined) {
'use strict';

$(document).ready(function () {

/*
 * Faces in memory handlers.
 */
var Groups = function (baseUrl) {
    this._baseUrl = baseUrl;
    this._groups = [];
};

Groups.prototype = {
    loadGroups: function () {
        var deferred = $.Deferred();
        var self = this;
        $.get(this._baseUrl).done(function (groups) {
            self._groups = groups;
            deferred.resolve();
        }).fail(function () {
            deferred.reject();
        });
        return deferred.promise();
    },
    getAll: function () {
        return this._groups;
    }
};

/*
 * View.
 */
var View = function (groups) {
    this._groups = groups;
};

View.prototype = {
    renderContent: function () {
        var source = $('#content-tpl').html();
        var template = Handlebars.compile(source);
        var html = template({groups: this._groups.getAll()});

        $('#div-content').html(html);
    },
    renderNavigation: function () {
        var source = $('#navigation-tpl').html();
        var template = Handlebars.compile(source);
        var html = template({groups: this._groups.getAll()});

        $('#app-navigation ul').html(html);
    },
    render: function () {
        this.renderNavigation();
        this.renderContent();
    }
};

/*
 * Main app.
 */
var groups = new Groups(OC.generateUrl('/apps/facerecognition/groups'));
var view = new View(groups);

groups.loadGroups().done(function () {
    view.render();
}).fail(function () {
    alert('D\'Oh!. Could not load faces groups..');
});


}); // $(document).ready(function () {
})(OC, window, jQuery); // (function (OC, window, $, undefined) {