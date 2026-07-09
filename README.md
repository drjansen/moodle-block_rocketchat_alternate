# moodle-block_rocketchat_alternate — Rocket.Chat Block / UI Plugin

This repository contains the **block/UI side** of a two-part Moodle + Rocket.Chat integration.
It provides the visible Rocket.Chat launcher, a slide-in drawer, an iframe-based chat panel,
AJAX unread-count polling, and logout support.

> **This plugin is only one half of the complete setup.**
> The backend/local integration plugin lives in a companion repository — see
> [Multi-repo setup](#multi-repo-setup) below.

## What this plugin does

- Renders a Rocket.Chat iframe panel as a Moodle block (`blocks/rocketchat`)
- Provides a top-navigation messenger button and slide-in drawer that can be injected
  into any Moodle page (see [Drawer injection](#top-bar-drawer-and-button-injection))
- Polls `ajax.php` every 15 seconds to show an unread-message badge in the navbar
- Handles Rocket.Chat logout via `logout.php`
- Supplies AMD JavaScript, Mustache templates, and supporting CSS

## Multi-repo setup

A complete deployment typically requires **both** repositories:

| Repository | Moodle plugin type | Role |
|---|---|---|
| **[drjansen/moodle-block_rocketchat_alternate](https://github.com/drjansen/moodle-block_rocketchat_alternate)** (this repo) | `blocks/rocketchat` | UI / block / drawer / AJAX polling |
| **[drjansen/RocketMoodle_Messenger](https://github.com/drjansen/RocketMoodle_Messenger)** | `local/rocketchat` | Backend / local integration / account linking |

Install both plugins before expecting the full feature set to work.

## Installation

### Prerequisites

- A running Rocket.Chat server configured to allow iframe embedding
  ([Rocket.Chat iframe docs](https://rocket.chat/docs/installation/))
- The companion **local plugin** from
  [drjansen/RocketMoodle_Messenger](https://github.com/drjansen/RocketMoodle_Messenger)
  installed first

### Steps

1. Clone this repo into the `blocks` directory of your Moodle instance:
   ```bash
   git clone https://github.com/drjansen/moodle-block_rocketchat_alternate.git public/blocks/rocketchat
   ```
2. Run `composer install` inside `public/blocks/rocketchat` to install PHP dependencies.
3. Visit the Moodle notifications page (`/admin/index.php`) to complete plugin installation.

For general guidance on installing Moodle plugins, see the
[Moodle plugin installation documentation](http://docs.moodle.org/en/Installing_contributed_modules_or_plugins).

## Deployment-specific configuration

Before using this plugin in your own environment, review and update the following
values in `ajax.php` to match your Rocket.Chat setup:

| Constant | File | Default | Description |
|---|---|---|---|
| `BLOCK_ROCKETCHAT_TEACHER_ROLE` | `ajax.php` | `'your-teacher-role-name'` | The Rocket.Chat global role name used to identify teachers/instructors. Set this to whichever role your Rocket.Chat instance uses for staff (e.g. `'teacher'`, `'instructor'`, `'staff'`). See *Administration > Roles* in your Rocket.Chat instance. |
| `BLOCK_ROCKETCHAT_MC_PREFIX` | `ajax.php` | `'mc_'` | The prefix applied to Moodle-originated room names in Rocket.Chat. Only rooms whose names start with this prefix (plus all direct-message rooms) are shown in the Moodle UI. Change this if your deployment uses a different prefix or no prefix. |

> **Note:** The `vendor/` directory is committed intentionally so the plugin can be
> installed on Moodle servers that do not have Composer available. If you have Composer,
> you can instead remove the `vendor/` directory and run `composer install` yourself.

## Configuration

To allow IFrame-based Single Sign-On, configure your Rocket.Chat instance under
`Administration > General > Restrict access inside any Iframe / X-Frame-Options`.

## Top-bar drawer and button injection

The slide-in chat drawer and top-navigation messenger button are **not automatically
injected** by the block itself. Depending on your deployment, you may need to add the
launcher script to your Moodle theme or a site-wide additional HTML block.

- The ready-to-use injection script is documented in
  [`README-rc-drawer-inject.md`](./README-rc-drawer-inject.md).
- Companion CSS for the drawer and navbar button is in
  [`README_Custom_CSS.md`](./README_Custom_CSS.md).

**Where to add the script / CSS in Moodle:**
1. Go to *Site Administration > Appearance > Additional HTML*.
2. Paste the script snippet into the **Within HEAD** or **End of BODY** section.
3. Add the CSS snippet to a custom SCSS/CSS file in your theme, or paste it into the
   Boost theme's *Raw SCSS* field under *Site Administration > Appearance > Themes > Boost*.

If your theme or deployment already injects this automatically, no further action is needed.

## Usage

Add the block to any Moodle page using the standard block editor. The block renders a
Rocket.Chat panel and provides users with access to their channels and direct messages
from anywhere in Moodle.

## Acknowledgements

This plugin was developed with reference to the work of
**[adpe](https://github.com/adpe)** and the repository
**[adpe/moodle-local_rocketchat](https://github.com/adpe/moodle-local_rocketchat)**,
which served as an inspiration and structural reference for the overall Moodle–Rocket.Chat
integration approach. Credit and thanks to adpe for making that work publicly available.
