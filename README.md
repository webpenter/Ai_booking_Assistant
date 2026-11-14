# AI Viewing-Booking Assistant for Houzez

A WordPress plugin that adds a chat agent to [Houzez](https://favethemes.com/) property listings. Visitors chat in plain language; the assistant checks the listing agent's **real Google Calendar** and books, reschedules or cancels property viewings, with no back-and-forth emails.

> Built by [WebPenter](https://github.com/webpenter). Requires the Houzez theme (not included).

## Demo

<video src="https://github.com/webpenter/Ai_booking_Assistant/raw/main/docs/demo.mp4" controls muted width="100%"></video>

A visitor chats, picks a free slot from the agent's Google Calendar, and books a viewing. If the player doesn't load, [download the video](docs/demo.mp4).

## Features

- **Conversational booking**: visitors say "Thursday afternoon works" and the assistant offers real free slots.
- **Live Google Calendar availability** via OAuth: no double bookings, events created in the agent's calendar.
- **Book, reschedule, cancel** from the same chat; confirmation emails go to buyer and agent.
- **Smart slot engine**: working hours, weekdays, viewing length, buffer between viewings, minimum notice, booking horizon (all configurable).
- **Email-confirmation hold**: a slot is held for a few minutes until the buyer confirms by email.
- **Abuse protection**: per-IP and per-email limits, daily caps, bot checks, rate limiting.
- **Virtual or in-person** viewings, buyer time-zone aware.
- **Agent dashboard** with a calendar view of bookings.
- **Secrets encrypted** at rest; keys can also live in `wp-config.php` constants.

## How it works

1. The visitor opens the chat on a listing; the plugin resolves the listing's agent.
2. Free slots are computed from the agent's Google Calendar (busy times) and your rules.
3. A Groq-hosted LLM handles the conversation only. It can **suggest** actions, but booking is executed by plain server code that validates every slot, so the model can never invent an appointment.
4. The booking is saved, the calendar event is created, and emails are sent.

## Requirements

- WordPress 5.8+, PHP 7.4+, the [Houzez](https://favethemes.com/) theme (with its `property` and `houzez_agent` post types)
- HTTPS on your site (required by Google OAuth)
- A free [Groq API key](https://console.groq.com/keys)
- A Google Cloud project with an OAuth client (Calendar API enabled)

## Setup: add it to your Houzez site

No theme files are edited. The plugin adds itself to Houzez automatically.

### 1. Download and install

1. Click **Code → Download ZIP** on this repo, or run `git clone https://github.com/webpenter/Ai_booking_Assistant.git`.
2. Make sure the folder is named `houzez-ai-booking-agent`. If you downloaded the ZIP, rename the extracted folder `Ai_booking_Assistant-main` to this and zip it again.
3. In WordPress go to **Plugins → Add New → Upload Plugin**, upload the ZIP and click **Activate**.
   You can also copy the folder to `wp-content/plugins/houzez-ai-booking-agent/` over FTP and activate it.

On activation the plugin creates its database table and a **Viewing Calendar** page for your agents.

### 2. Get a Groq API key

Create a key at [console.groq.com/keys](https://console.groq.com/keys). It powers the chat conversation.

### 3. Create the Google OAuth client

1. In [Google Cloud Console](https://console.cloud.google.com/), create a project and enable the **Google Calendar API**.
2. Go to **APIs & Services → OAuth consent screen** and configure it.
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

### 5. Connect each agent's calendar

Each Houzez agent connects their own Google Calendar, either:
- from the front-end **Viewing Calendar** page (agents see it in the Houzez dashboard menu), or
- from wp-admin by editing the agent profile.

The chat only appears on a listing whose assigned agent has a connected calendar.

### 6. See it on a listing

Open any property whose agent is connected. The booking chat is injected into the Houzez sidebar on desktop, and under the description on mobile. To place it elsewhere, use the shortcode:

```
[houzez_booking_button]
[houzez_booking_button listing="123"]
```

Booked viewings appear under **Viewings** in wp-admin and on the agent's calendar page.

## Settings

All under **Settings → AI Viewing Booking → Viewing rules**:

| Setting | Default | What it does |
|---|---|---|
| Business hours | 09:00 to 17:00 | Window in which viewings can be offered |
| Days | Mon to Fri | Days of the week available |
| Viewing length | 30 min | Duration of each viewing |
| Buffer between viewings | 15 min | Gap kept before and after each viewing |
| Minimum notice | 4 hours | How soon a viewing can be booked |
| Bookable days ahead | 30 | How far in the future visitors can book |
| Days the chat offers at a time | 14 | How many days of slots the chat shows |
| Max upcoming viewings per email / IP | 2 | Anti-abuse limit |
| Max chat bookings per agent per day | 8 | Daily cap |
| Slot hold while awaiting confirmation | 15 min | How long a slot is held until the buyer confirms by email |

## Tests

```bash
php tests/run.php          # slot-calculation unit tests
php tests/integration.php  # integration tests
```

## Structure

```
includes/   chat, booking service, slot calculator, Google OAuth/calendar, Groq client, settings, crypto
assets/     chat widget CSS/JS
templates/  agent dashboard calendar
tests/      CLI tests
```

## License

MIT. See [LICENSE](LICENSE).
