# Mago add-on: Higgsfield

Generate product images and videos with [Higgsfield](https://higgsfield.ai) from the Magento admin
chat. The admin asks for a scene, the assistant writes the prompt, shows what it will cost, and
Higgsfield renders a new image (Nano Banana 2) or a short video (Seedance 2.0 or Kling 3.0) from the
product's main image. A finished image can go straight into the product gallery.

## Compatibility

| | Supported |
|---|---|
| Magento Open Source | 2.4.8 and later (CI: 2.4.8-p5 and 2.4.9) |
| Mage-OS | Releases built on Magento 2.4.8 or later (CI: 3.6.0) |
| PHP | 8.2 to 8.5 (CI: 8.4 and 8.5) |
| Mago core | `mago-assistant/magento2-mago` 1.1 or later |
| Storefront | Admin only. Images it adds to the gallery show up in any theme (Luma, Hyvä) or headless frontend like other product images. |

## What it does

Ask the assistant, in the admin chat panel:

- *Make a lifestyle photo of SKU ABC-123 on a kitchen table, square*
- *Make a 5 second video of JACKET-BLK slowly spinning around*
- *Is that image ready yet?*
- *Add image 1 to the ABC-123 gallery, hidden*
- *Make it the thumbnail and make it visible*

The skill is `product_media`:

| Action | Type | Description |
|---|---|---|
| `generate_image` | write, irreversible | Start an image from the main product image (Nano Banana 2) |
| `generate_video` | write, irreversible | Start a video from the main product image (Seedance 2.0 or Kling 3.0) |
| `check_status` | read | State of a request; saves the result once it is ready |
| `attach_image` | write | Add a generated image to the product gallery |

Every generation spends Higgsfield credits that cannot be refunded, so it goes through Mago's
confirmation flow with the cost Higgsfield quotes for exactly these settings. The assistant starts
one generation per request and never retries a failed one on its own.

## Install

```bash
composer require mago-assistant/magento2-higgsfield
bin/magento module:enable MagoAssistant_Higgsfield
bin/magento setup:upgrade
bin/magento cache:flush
```

The tool works on catalog data, so it is guarded by `Magento_Catalog::products`: an admin role
without access to products cannot use it. Per-user permissions are under Stores › Admin Assistant ›
Skills & Permissions.

## Connect

Stores › Configuration › Mago Assistant › Higgsfield › Connection

| Setting | Default | Scope | What it does |
|---|---|---|---|
| Higgsfield Account | Not connected | Default | Shows which Higgsfield account is connected, with **Connect with Higgsfield**, **Reconnect** and **Disconnect** buttons. There are no other settings. |

There is no API key to paste. The shop registers itself as OAuth client of the Higgsfield MCP server
(dynamic client registration), the admin signs in at Higgsfield and approves, and Higgsfield sends
them back to the configuration page. Behind that:

- Authorization code flow with PKCE (S256) and a one-time `state`, kept in the admin session.
- Client secret, access token and refresh token are stored encrypted in Magento's flag table, so
  a refreshed token is used at once without flushing the config cache. They are never logged and
  never sent anywhere but Higgsfield.
- The access token is refreshed shortly before it expires, and once more when Higgsfield answers
  401. When that fails too, the assistant tells the admin to reconnect.
- **Disconnect** forgets the tokens and the registered client. Without a connection the skill
  refuses every generation before anything is sent.

The configuration ACL resource is `MagoAssistant_Higgsfield::config`.

## How a generation runs

1. The main product image (JPEG, PNG or WebP) is uploaded to Higgsfield storage through a presigned
   URL, so the shop's media URL does not have to be public. The OAuth token is not sent with it.
2. The generation is submitted as one credit-paid job (`count: 1`, no unlimited plan) and the
   assistant gets a `request_id` back.
3. `check_status` asks for the job's state. Once it is completed the output is downloaded to
   `pub/media/mago/higgsfield/<request id>/`, because Higgsfield keeps outputs for about seven days.
   Later checks answer from those files without calling Higgsfield again.
4. `attach_image` adds a stored image to the gallery in the admin store view: hidden on the
   storefront and without image roles unless the admin asks for them. Videos stay available
   through their link.

| Model | Used for | Options |
|---|---|---|
| `nano_banana_2` | images | aspect ratio 1:1 (default), 3:2, 2:3, 4:3, 3:4, 4:5, 5:4, 9:16, 16:9, 21:9; resolution 1k, 2k (default), 4k |
| `seedance_2_0` | videos (default) | 4–15 s (default 5), 16:9 (default), 9:16, 4:3, 3:4, 1:1, 21:9; 480p, 720p (default), 1080p; optional audio |
| `kling3_0` | videos | 3–15 s, 16:9, 9:16 or 1:1; optional sound |

## Troubleshooting

- **"The Higgsfield connection has expired."** The access token could not be refreshed. Click
  **Reconnect** on the configuration page. When the page says Higgsfield gave no refresh token, this
  happens every time the token expires.
- **"The Higgsfield connection was not started from this shop."** The login at Higgsfield was
  started from another admin URL, or the admin session expired halfway. Start it again from the
  configuration page. Reconnecting from a different admin URL registers a new OAuth client, and the
  old connection keeps working until the new login completes.
- **"... has no main image to generate from."** Generations start from the product's main image
  (JPEG, PNG or WebP). Give the product a main image in the default store view.
- **A finished request can't be downloaded any more.** Higgsfield keeps outputs for about seven
  days. Run `check_status` within that time; once downloaded, the files stay in
  `pub/media/mago/higgsfield/`.

## Development

The unit tests run from a Magento install that has the add-on and Mago core installed, with
Magento's unit test configuration (it generates the factories some tests need):

```bash
vendor/bin/phpunit -c dev/tests/unit/phpunit.xml.dist vendor/mago-assistant/magento2-higgsfield/Test/Unit
```

PHPStan and the coding standard run from the add-on's own folder after `composer install`:

```bash
vendor/bin/phpstan analyse
vendor/bin/phpcs
```

CI runs graycoreio's check-extension workflow on every push and pull request: `setup:di:compile`,
the Magento coding standard, unit and integration tests and PHPStan level 8 on the supported
Magento Open Source and Mage-OS versions, plus `composer audit`, a PHP lint on 8.2 to 8.5 and
[zizmor](https://docs.zizmor.sh/) on the workflows themselves.

## Links

- [CHANGELOG](CHANGELOG.md)
- [Security policy](SECURITY.md)
- [License](LICENSE): MIT
