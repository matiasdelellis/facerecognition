const path = require('path')
const webpackConfig = require('@nextcloud/webpack-vue-config')

webpackConfig.entry = {
    'sidebar': path.join(__dirname, 'src', 'sidebarloader.js'),
    'admin': path.join(__dirname, 'src', 'admin.js'),
    'personal': path.join(__dirname, 'src', 'personal.js'),
}

module.exports = webpackConfig
