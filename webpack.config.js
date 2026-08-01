const path = require('path')
const webpackConfig = require('@nextcloud/webpack-vue-config')

webpackConfig.entry = {
    'sidebar': path.join(__dirname, 'src', 'sidebarloader.js'),
    'admin': path.join(__dirname, 'src', 'admin.js'),
    'personal': path.join(__dirname, 'src', 'personal.js'),
    'dialogs': path.join(__dirname, 'src', 'dialogs.js'),
}

// Keep the third-party vendor scripts (js/vendor/) when webpack cleans the
// output directory on every build, otherwise they would be deleted.
webpackConfig.output.clean = { keep: /vendor\// }

module.exports = webpackConfig
