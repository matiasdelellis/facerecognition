(function (OC, window, $, undefined) {
'use strict';

$(document).ready(function () {

/*
 * Faces in memory handlers.
 */
var Persons = function (baseUrl) {
    this._baseUrl = baseUrl;
    this._persons = [];
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
    getAll: function () {
        return this._persons;
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
            self.render();
        }).fail(function () {
            alert('D\'Oh!. Could not reload faces..');
        });
    },
    renderContent: function () {
        var source = $('#content-tpl').html();
        var template = Handlebars.compile(source);

        var html = template({persons: this._persons.getAll()});

        $('#div-content').html(html);

        const observer = lozad();
        observer.observe();
    }
};

/*
 * Main app.
 */
var persons = new Persons(OC.generateUrl('/apps/facerecognition'));

var view = new View(persons);

view.renderContent();

persons.loadPersons().done(function () {
    view.renderContent();
}).fail(function () {
    alert('D\'Oh!. Could not load faces..');
});


}); // $(document).ready(function () {
})(OC, window, jQuery); // (function (OC, window, $, undefined) {