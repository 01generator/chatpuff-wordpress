# Security

Please report security problems in this plugin, or in ChatPuff itself, to **support@chatpuff.com**, not in a public issue. Include the plugin version, what you found and how to reproduce it. We answer within a few working days and credit you when a fix is published, if you wish.

The plugin updates through WordPress; there is no self-updater.

## How the plugin protects a shop

- Connecting creates an Ed25519 key pair on your server. The private key never leaves it, and it is stored encrypted with a key derived from the site's security keys in `wp-config.php`, so a database dump alone does not reveal it. ChatPuff keeps only the public key.
- Every call to ChatPuff is signed, time-limited and single-use.
- Only administrators (`manage_options`) connect or disconnect the site; the inbox needs the `chatpuff_inbox` capability. Every admin action checks a nonce.
- A copy of a connected site, such as a staging site, never acts for the live shop: the connection works only on the address it was made for.
- ChatPuff decides on its side what each person may see and do. The plugin cannot grant access to other shops, paid features or other organizations.
