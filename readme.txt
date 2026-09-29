=== ChatPuff live chat for WooCommerce ===
Contributors: chatpuff
Tags: live chat, chat, customer support, woocommerce, help desk
Requires at least: 5.3
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 0.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Live chat with your WooCommerce customers, answered from your WordPress admin, the ChatPuff dashboard or your phone.

== Description ==

ChatPuff adds a live chat to your WooCommerce shop. Your customers chat from any page of the storefront; you and your team answer from the ChatPuff inbox in the WordPress admin, or from the ChatPuff dashboard on your computer or phone.

* **Unlimited live chat on the free plan**, with no limit on conversations or team members.
* **Your team's inbox in WordPress:** accept a chat, reply, transfer it to a colleague and resolve it, without leaving the admin.
* **Customers who are logged in** are recognised: they do not type their name and email again.
* **Six languages** for the chat and the inbox: English, Greek, Spanish, Italian, French and German.
* **Opening hours and holidays**: outside them, the chat takes messages for later.
* **Fast and safe for your shop:** the chat's script loads asynchronously, never slows a page down, and draws itself apart from your theme's styles.

The chat and the inbox are served by ChatPuff, so improvements reach your shop without a plugin update. The plugin itself only connects the shop, adds the chat's script and vouches for who is signed in.

== External services ==

This plugin connects your shop to **ChatPuff** (https://chatpuff.com), a hosted customer-support service operated from Greece, with its data stored in Germany. Without it, the plugin does nothing. What is sent, and when:

* **When an administrator connects the shop:** the site's address and name, its language and time zone, the address ChatPuff calls to check the site (a REST API route of this plugin), a public key created on your server (the private key never leaves it), and the versions of WordPress, WooCommerce, PHP and the plugin.
* **Every hour while connected:** the same versions again, as a heartbeat.
* **On storefront pages:** visitors' browsers load the chat's script from api.chatpuff.com. What a visitor types in the chat (their name, email address and messages) goes to ChatPuff. For a logged-in customer, the page carries a short-lived token, signed by your server, with the customer's user ID, name and email address, so the chat knows who they are.
* **In the WordPress admin:** the inbox's script is loaded from api.chatpuff.com. The plugin asks ChatPuff for 15-minute access tokens for the signed-in user; the first time, it sends their user ID and first name with an initial so they can link their ChatPuff account.

ChatPuff's [terms of service](https://chatpuff.com/terms) and [privacy notice](https://chatpuff.com/privacy) apply. As the shop owner you are the controller of your customers' data; ChatPuff processes it on your behalf under its [data processing agreement](https://chatpuff.com/dpa). Mention the chat in your own privacy policy.

== Installation ==

1. Install and activate the plugin. WooCommerce must be active.
2. Open **ChatPuff > Settings** and click **Connect to ChatPuff**. A ChatPuff page opens: sign in, or create a free account, and confirm the shop.
3. ChatPuff checks that the request really comes from your shop's address, and the settings page shows the shop as connected.
4. Publish the chat from the shop's page in the ChatPuff dashboard. Until then, visitors do not see it.
5. Open **ChatPuff > Inbox** to answer chats. Each person links their WordPress account to their ChatPuff account once.

On a multisite network, connect each site separately.

== Frequently Asked Questions ==

= Who can answer chats in the WordPress admin? =

Users who can manage WooCommerce (shop managers and administrators). To let other users answer chats, give them the `chatpuff_inbox` capability with a role editor. What each person may do in ChatPuff (which shops, which role) is decided in ChatPuff's Team page.

= The chat does not appear on my shop =

* It is not published yet: the settings page says so, and an owner or admin publishes it from the shop's page in ChatPuff.
* A page cache serves pages saved before ChatPuff was installed: clear the cache.
* The site's address is not the one it was connected with (a copy of the shop, such as a staging site). A copy never shows the live shop's chat.

= Connecting fails: ChatPuff could not reach the shop =

ChatPuff checks the shop by calling the address shown on the settings page (`/wp-json/chatpuff/v1/callback`). The site must be online over HTTPS, and nothing may block that address: a maintenance-mode plugin, a security plugin that blocks the REST API for visitors, a firewall, or Cloudflare's bot protection. The settings page explains the Cloudflare rule to add.

= Does the plugin slow my shop down? =

No. The chat's script is small and loads asynchronously; the full chat loads only once it is published. The hourly report runs in the background through WP-Cron.

= Does it work with High-Performance Order Storage and the block checkout? =

Yes. The plugin reads no orders and works with both.

= What happens when I deactivate or delete the plugin? =

Deactivating hides the chat and keeps the connection, so reactivating needs no new connection. Deleting the plugin disconnects the shop from ChatPuff; its chat history stays in ChatPuff.

== Changelog ==

= 0.1.0 =
* First version: connect the shop, the chat on the storefront, logged-in customers recognised, the ChatPuff inbox in the WordPress admin, the hourly report, and six languages.
