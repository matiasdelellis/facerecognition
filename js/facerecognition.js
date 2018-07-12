(function (OC, window, $, undefined) {
'use strict';

$(document).ready(function () {

var View = function () {
};

View.prototype = {
    renderContent: function () {
        var source = $('#content-tpl').html();
        var template = Handlebars.compile(source);
        var html = template({});

        $('#div-content').html(html);
    },
    renderNavigation: function () {
        var source = $('#navigation-tpl').html();
        var template = Handlebars.compile(source);
        var html = template({});

        $('#app-navigation ul').html(html);
    },
    render: function () {
        this.renderNavigation();
        this.renderContent();
    }
};

var view = new View();
view.render();

});

})(OC, window, jQuery);