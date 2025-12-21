=== Multisite Administration Tools ===
Contributors: axelseaa
Tags: multisite, admintools, network, plugins, themes
Requires at least: 5.8
Tested up to: 6.9
Requires PHP: 7.2
Stable tag: 1.20
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Adds information to the network admin sites, plugins and themes page. Allows you to easily see what theme and plugins are enabled on a site.

== Description ==

The Multisite Administration Tools plugin adds additional columns to the Sites, Plugins and Themes tables in the Network Admin interface.

On the Sites table, two additional columns are added to allow admins to easily view the theme of the site, and also any plugins that are enabled.

On the Themes table, there is an additional column added which allows the administrator to see all sites that are actively using that theme.

On the Plugins table, there is an additional column added which allows the administrator to see all sites that are actively using that plugin.

== Installation ==

This plugin can only be "Network Activated". Install the plugin into the `/wp-content/plugins/` folder, and activate it network wide.

== Changelog ==

= 1.20 =
* Compatibility updates for current WordPress multisite Network Admin screens.
* Improved handling of theme and plugin reporting across sites.
* Performance improvements for larger multisite installs.

= 1.1 =
* Performance tweaks for large multisite installs
* Fix for sites that have no blog name listed

= 1.0 =
* Initial release

== Upgrade Notice ==

= 1.20 =
Compatibility and performance improvements for multisite Network Admin reporting.
