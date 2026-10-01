# YouTube Channel Videos

A lightweight WordPress plugin that fetches **every video from a YouTube channel** (by `@handle` or Channel ID) and displays them as a responsive, paginated grid via a simple shortcode.

## Features

- Fetches all uploads from any YouTube channel using the official YouTube Data API v3
- Accepts a channel `@handle` (e.g. `@example`) or a raw Channel ID (`UC...`)
- Paginated video gallery — no need to load hundreds of videos at once
- Results are cached (configurable duration) to avoid burning API quota
- Clean, separated codebase: PHP logic, CSS, and HTML markup each live in their own file
- No jQuery/React dependency — plain PHP + CSS, works with any theme

## File Structure

```
youtube-channel-videos/
├── youtube-channel-videos.php   # Plugin bootstrap, settings page, YouTube API logic, shortcode
├── assets/
│   └── style.css                # Front-end styles (grid + pagination)
└── includes/
    └── content.php               # HTML markup for the video grid + pagination
```

## Installation

1. Download or clone this repository into your site's `wp-content/plugins/` directory.
2. Activate **YouTube Channel Videos** from the WordPress admin **Plugins** page.
3. Go to **Settings → YouTube Channel Videos** and add your YouTube Data API v3 key ([get one here](https://console.cloud.google.com/apis/credentials)).
4. (Optional) Set a default channel, cache duration, videos-per-page, and grid columns.

## Usage

Basic:

```
[youtube_channel_videos channel="@example"]
```

All attributes:

```
[youtube_channel_videos channel="@example" count="0" per_page="12" columns="4" order="date" show_desc="no"]
```

| Attribute    | Default            | Description                                                        |
|--------------|---------------------|----------------------------------------------------------------------|
| `channel`    | setting default     | `@handle` or a raw Channel ID (`UC...`)                              |
| `count`      | setting default (0) | Total videos in the pool before pagination. `0` = every video fetched |
| `per_page`   | setting default (12)| Videos shown per pagination page                                     |
| `columns`    | setting default (4) | Grid columns, 1–6                                                     |
| `order`      | `date`               | `date` (newest first) or `oldest`                                    |
| `show_desc`  | `no`                 | `yes` to show a trimmed description under each video title           |

Pagination works via a `?ycv_page=N` query parameter (reloads the page, no JavaScript required), and is namespaced per shortcode instance so multiple galleries on the same page paginate independently.

## Requirements

- WordPress 5.0+
- A YouTube Data API v3 key (free tier from Google Cloud Console)

## License

GPL v2 or later

## Author

Syed Noman Ali — [syednomanali.vercel.app](https://syednomanali.vercel.app/)
