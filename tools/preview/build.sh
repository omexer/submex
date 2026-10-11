#!/usr/bin/env bash
# Builds the admin preview into a folder ready to serve. Run from inside a WordPress install,
# with Submex Pro checked out beside Submex, since the preview includes Pro's screens.
set -euo pipefail

here="$(cd "$(dirname "$0")" && pwd)"
plugin="$(cd "$here/../.." && pwd)"
wp="$(cd "$plugin/../../.." && pwd)"
out="${1:-$plugin/.preview}"

for f in "$wp/wp-admin/css/common.min.css" "$plugin/../submex-pro/src"; do
	[ -e "$f" ] || { echo "Missing $f - run this inside a WordPress install with Submex Pro beside Submex." >&2; exit 1; }
done

mkdir -p "$out/css"

( cd "$plugin" && SUBMEX_PREVIEW_OUT="$out" npx wp-scripts build --config "$here/webpack.config.js" )

cp "$wp/wp-admin/css/common.min.css" "$wp/wp-admin/css/forms.min.css" "$wp/wp-includes/css/buttons.min.css" "$plugin/assets/css/admin.css" "$out/css/"

python3 - "$here/index.html.tmpl" "$plugin" "$out/index.html" <<'PY'
import re, sys
template, plugin, target = sys.argv[1:4]
shell = open(plugin + '/includes/Admin/Page_Shell.php').read()
mark = '<svg' + re.search(r"return '<svg(.*?)</svg>';", shell, re.S).group(1) + '</svg>'
mark = re.sub(r"'\s*\.\s*'", '', mark)
version = re.search(r"define\( 'SUBMEX_VERSION', '([^']+)' \)", open(plugin + '/submex.php').read()).group(1)
html = open(template).read().replace('{{MARK}}', mark).replace('{{VERSION}}', version)
left = re.findall(r'\{\{[A-Z]+\}\}', html)
if left:
    sys.exit('Unfilled placeholders: ' + ', '.join(left))
open(target, 'w').write(html)
PY

echo "Built into $out. Serve it with:"
echo "  python3 -m http.server 8765 --directory \"$out\""
echo "then open http://localhost:8765/#dashboard"
