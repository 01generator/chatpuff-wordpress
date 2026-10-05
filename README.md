# ChatPuff for WooCommerce

Live chat for your WooCommerce shop: your customers chat from the storefront, and you answer from the WordPress admin or the [ChatPuff](https://chatpuff.com) dashboard.

This plugin connects a shop to ChatPuff. It is deliberately thin: the chat widget and the inbox are served by ChatPuff, so fixes reach every shop without a plugin update. The WordPress.org description is in [readme.txt](readme.txt).

## Requirements

- WordPress 5.3 or newer, WooCommerce 3.8 or newer, PHP 7.4 to 8.5
- HTTPS on the site, and outgoing HTTPS connections from the server to `api.chatpuff.com`

## Connecting a shop

1. Activate the plugin and open **ChatPuff > Settings** (administrators).
2. Click **Connect to ChatPuff**. A ChatPuff page opens: sign in, or create a free account, and confirm the shop.
3. ChatPuff checks that the request really comes from the shop's domain by calling the plugin's REST route, `/wp-json/chatpuff/v1/callback`. The settings page then shows the shop as connected.

On a multisite, each site is connected separately (the shop context is the blog ID). The chat stays hidden until an owner or admin publishes it from the ChatPuff dashboard.

The chat links to the site's privacy policy page (**Settings > Privacy**) where it asks guests for their name and email.

## The chat on the storefront

The plugin enqueues ChatPuff's `loader.js` in the footer of every storefront page, so it works with every theme and page builder that prints the footer scripts. The tag is async, carries `data-cfasync="false"` for Cloudflare's Rocket Loader, and only appears on the domain the site was connected with: a staging copy never shows the live shop's chat.

For a logged-in user, the tag carries a customer token (a JWT signed with the site's connection key, valid for 10 minutes) with the user ID, name and email, so the chat skips its form. Page caches do not keep pages of logged-in users.

On a product page, the tag names the product (its ID, name and address, `data-page`), and the chat sends it to ChatPuff with the customer's messages, so the team and the AI assistant know which product "is this one in stock?" is about. On other pages the chat sends the page's address and title.

## The inbox in the WordPress admin

**ChatPuff > Inbox** shows the same inbox as the ChatPuff dashboard. Users who can manage WooCommerce (shop managers, administrators), or who have the `chatpuff_inbox` capability, can open it. Each person links their WordPress account to their ChatPuff account once, in a ChatPuff window; after that, the plugin asks ChatPuff for 15-minute staff tokens that reach only the shops of this WordPress.

A badge next to **ChatPuff** and **Inbox** in the admin menu shows, on every admin page, how many chats wait for the user in ChatPuff: the chats waiting for someone to take them, and the user's own chats with an unread message from the customer. It is refreshed every minute, and a chime plays when a chat starts waiting (the inbox's **Sound alerts** switch turns it off). The badge appears for users who may open the inbox and have linked their ChatPuff account; a user who has not is left alone for an hour before the plugin asks ChatPuff again.

## Verifying orders in the chat

From 0.5.0 a customer can prove in the chat that an order is theirs before the team talks about it: they type the order number, and ChatPuff emails a code to the billing address on the order; a logged-in customer's own order is verified at once. To do that, ChatPuff asks the plugin about the order on its REST route (`?action=order`), and the plugin answers only when the call is signed with ChatPuff's own key, was made within the last five minutes, and was never seen before. The answer names the order, its customer and the email address on it, nothing else. Shops whose order numbers are not their order IDs (a sequential numbering plugin, for example) map them with the `chatpuff_order_id_from_reference` filter.

## Reports

Once an hour (WP-Cron), and when the settings page opens after an update, the plugin reports its version and the WooCommerce and PHP versions to ChatPuff. The report doubles as the connection's heartbeat.

## Development

The plugin has no runtime dependencies. The development tools come from Composer:

```bash
composer install
vendor/bin/phpcs                  # WordPress coding standards, PHP 7.4 compatibility
vendor/bin/phpstan analyse        # level 8, with WordPress and WooCommerce stubs
php tools/compile-translations.php  # after changing a languages/*.po file
```

Point the plugin at a local ChatPuff with `define( 'CHATPUFF_API_URL', 'http://127.0.0.1:8099' );` in `wp-config.php`.

Translations: `languages/chatpuff.pot` lists the strings; `chatpuff-{locale}.po` holds each language (el, es_ES, it_IT, fr_FR, de_DE and de_DE_formal, both German files in the formal "Sie" of ChatPuff's own German). WordPress.org language packs, once available, take precedence.

## Releases

1. Set the new version in the plugin header, `CHATPUFF_VERSION` (both in `chatpuff.php`) and the `Stable tag` of `readme.txt`, and add its changelog entry to `readme.txt`.
2. Push a tag `vX.Y.Z`. The Release workflow checks that the three versions match, builds `chatpuff-X.Y.Z.zip` from the tracked files without the development files (`.gitattributes`), and publishes it as a GitHub release.

## License

GPL-2.0-or-later. See [LICENSE](LICENSE).
