# AI Viewing-Booking Assistant for WordPress

An AI chat assistant that books appointments on **any WordPress listing site**. Visitors chat in plain language; the assistant checks the owner's **real Google Calendar** and books, reschedules or cancels viewings, tours, visits or appointments, with no back-and-forth emails.

Works with **Houzez, WPResidence, RealHomes, Listeo, Homey** and any custom listing theme or post type.

> Built by [WebPenter](https://github.com/webpenter).

## Demo

https://github.com/user-attachments/assets/297f40f2-95b5-4547-b832-9ea2af205cd7

## Features

- **Conversational booking**: visitors say "Thursday afternoon works" and the assistant offers real free slots.
- **Live Google Calendar availability** via OAuth: no double bookings, events are created in the owner's calendar.
- **Book, reschedule and cancel** from the same chat; confirmation emails go to the visitor and the owner.
- **Works with any theme**: auto-detects Houzez, WPResidence, RealHomes, Listeo and Homey; falls back to "the listing's author owns the calendar" for everything else.
- **Any kind of booking**: change the word used in chat and emails to *viewing, tour, appointment, visit...*
- **Flexible placement**: inside the theme sidebar, a floating card, or anywhere with a shortcode.
- **Smart slot engine**: working hours, weekdays, duration, buffer between bookings, minimum notice, booking horizon.
- **Email-confirmation hold**: a slot is held for a few minutes until the visitor confirms by email.
- **Abuse protection**: per-IP and per-email limits, daily caps, bot checks, rate limiting.
- **Virtual or in-person** bookings, visitor time-zone aware.
- **Owner dashboard** with a calendar page and a bookings list in wp-admin.
- **Secrets encrypted** at rest; keys can also live in `wp-config.php` constants.
- **Extensible**: filters let developers add a theme, post type or calendar-owner logic without editing the plugin.

## Theme support

| Theme | Listing post type | Who owns the calendar |
|---|---|---|
| **Houzez** (fully tested) | `property` | The listing's Houzez agent (`houzez_agent`) |
| **WPResidence** | `estate_property` | The listing's agent (`estate_agent`) |
| **RealHomes** | `property` | The listing author |
| **Listeo** | `listing` | The listing author (host) |
| **Homey** | `listing` | The listing author (host) |
| **Any other theme** | `property`, `estate_property` or `listing` (or choose your own) | The listing author, or a custom agent post type |

Houzez is the tested adapter. The others use the documented field names for each theme, so check the widget on one listing after setup.

## How it works

1. The visitor opens the chat on a listing; the plugin works out who owns that listing's calendar.
2. Free slots are computed from the owner's Google Calendar (busy times) and your rules.
3. A Groq-hosted LLM handles the conversation only. It can **suggest** actions, but booking is executed by plain server code that validates every slot, so the model can never invent an appointment.
4. The booking is saved, the calendar event is created, and emails are sent.

## Requirements

- WordPress 5.8+ and PHP 7.4+
- Any theme with a listing post type (see the table above)
- HTTPS on your site (required by Google OAuth)
- A free [Groq API key](https://console.groq.com/keys)
- A Google Cloud project with an OAuth client (Google Calendar API enabled)

## Setup

No theme files are edited.

### 1. Download and install

1. Click **Code → Download ZIP** on this repo, or run `git clone https://github.com/webpenter/Ai_booking_Assistant.git`.
2. Put the files in a folder (for example `ai-booking-assistant`) and zip it so that the folder is the top level of the ZIP.
3. In WordPress go to **Plugins → Add New → Upload Plugin**, upload the ZIP and click **Activate**.
   You can also copy the folder to `wp-content/plugins/` over FTP and activate it.

On activation the plugin creates its database table and a **Viewing Calendar** page where owners connect their calendar.

### 2. Get a Groq API key

Create a key at [console.groq.com/keys](https://console.groq.com/keys). It powers the chat conversation.

### 3. Create the Google OAuth client

1. In [Google Cloud Console](https://console.cloud.google.com/), create a project and enable the **Google Calendar API**.
2. Go to **APIs & Services → OAuth consent screen** and configure it. Publish it to **In production**, otherwise Google expires the connection every 7 days.
3. Go to **Credentials → Create credentials → OAuth client ID → Web application**.
4. Under **Authorised redirect URIs** paste the Redirect URI shown on the plugin's settings page. It looks like `https://your-site.com/wp-json/houzez-booking/v1/oauth/callback`.
5. Copy the **Client ID** and **Client secret**.

### 4. Configure the plugin

Open **Settings → AI Viewing Booking** in WordPress admin and enter the Google Client ID, Client secret and Groq API key. Leaving a secret field blank keeps the saved value.

You can also keep the keys out of the database by defining them in `wp-config.php`:

```php
define( 'HZB_GOOGLE_CLIENT_ID', '...' );
define( 'HZB_GOOGLE_CLIENT_SECRET', '...' );
define( 'HZB_GROQ_API_KEY', '...' );
```

### 5. Check the theme and placement

Still on the settings page, under **Theme and placement**:
- **Detected theme** shows which adapter is in use.
- **Listing post types**: tick the post types that should get the chat, or tick none to auto-detect.
- **Placement**: *Auto* (inside the theme sidebar, else under the content), *Floating card* (bottom right), or *Only where I place the shortcode*.
- **Placement CSS selector** (optional): put the card at the top of a specific element, for example `.listing-sidebar`.
- **Word for a booking**: viewing, tour, appointment, visit...
- **Dashboard menu selector** (optional): adds a "Viewing Calendar" link to your theme's user-dashboard menu.
- **Custom agent post type / meta key** (advanced): for a theme whose agents are their own post type.

### 6. Connect each owner's calendar

Every agent or host connects their own Google Calendar, either:
- from the **Viewing Calendar** page the plugin created (add `[hzb_calendar_connect]` to any page if you want it elsewhere), or
- from wp-admin, by editing the agent profile (agent-based themes such as Houzez and WPResidence).

The chat only appears on a listing whose owner has a connected calendar.

### 7. See it on a listing

Open any listing whose owner is connected. To place the chat manually, use the shortcode:

```
[viewing_booking]
[viewing_booking listing="123"]
```

(`[houzez_booking_button]` still works as an alias.) Bookings appear under **Viewings** in wp-admin.

## Settings

All under **Settings → AI Viewing Booking → Viewing rules**:

| Setting | Default | What it does |
|---|---|---|
| Business hours | 09:00 to 17:00 | Window in which bookings can be offered |
| Days | Mon to Fri | Days of the week available |
| Booking length | 30 min | Duration of each booking |
| Buffer between bookings | 15 min | Gap kept before and after each booking |
| Minimum notice | 4 hours | How soon a booking can be made |
| Bookable days ahead | 30 | How far in the future visitors can book |
| Days the chat offers at a time | 14 | How many days of slots the chat shows |
| Max upcoming bookings per email / IP | 2 | Anti-abuse limit |
| Max chat bookings per owner per day | 8 | Daily cap |
| Slot hold while awaiting confirmation | 15 min | How long a slot is held until the visitor confirms by email |

## For developers

Hooks to adapt the plugin to a theme without editing it:

| Filter | Purpose |
|---|---|
| `hzb_theme_family` | Force a theme family (`houzez`, `wpresidence`, `listeo`, `homey`, `realhomes`, `generic`) |
| `hzb_adapters` | Add or change a theme adapter (post types, agent meta key, facts the assistant may quote) |
| `hzb_listing_types` | Change which post types get the chat |
| `hzb_listing_owners` | Change which calendar owner serves a listing |
| `hzb_owner_post_types` | Add post types that can own a calendar |
| `hzb_placement_selectors` | Add CSS selectors used to place the card |
| `hzb_client_ip` | Read the real visitor IP behind a proxy or CDN |

## Tests

```bash
php tests/run.php          # slot-calculation unit tests
php tests/integration.php  # integration tests
```

## Structure

```
includes/   chat, booking service, slot calculator, Google OAuth/calendar, Groq client,
            theme adapters (class-listings), calendar owners (class-owner), settings, crypto
assets/     chat widget CSS/JS
templates/  owner dashboard calendar
tests/      CLI tests
```

## License

MIT. See [LICENSE](LICENSE).
