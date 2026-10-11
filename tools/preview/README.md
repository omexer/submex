# Admin screen preview

Every React admin screen as a plain page, with fixture data in place of the REST API, so it can
be opened and screenshotted without a running WordPress admin.

Tests prove a screen shows the right text. This is for seeing whether it looks right.

```bash
tools/preview/build.sh
```

```bash
python3 -m http.server 8765 --directory .preview
```

Open `http://localhost:8765/#dashboard`. The other screens are `#list`, `#detail`, `#reports`
and `#health`; add `?setup=done` before the hash to see Home once setup is finished.

It must run inside a WordPress install, because it borrows wp-admin's stylesheets, and with
Submex Pro checked out beside Submex, because Pro's screens are included.

What it does not show: WordPress's own sidebar and admin bar. Look at the real admin once before
a release — in particular the header bar sitting under the admin bar, and where notices land.

Development only. `.preview/` is ignored, and nothing here ships.
