#!/bin/zsh
# Снимок локального сайта для проверки вёрстки.
# Использование: ./shot.sh [имя] [url-путь]
CHROME="/Applications/Google Chrome.app/Contents/MacOS/Google Chrome"
NAME="${1:-shot}"
PATHPART="${2:-/}"
"$CHROME" --headless --disable-gpu --hide-scrollbars --force-device-scale-factor=1 \
  --window-size=1440,3000 --screenshot="_reference/${NAME}.png" "http://localhost:5173${PATHPART}" 2>/dev/null
echo "saved _reference/${NAME}.png"
