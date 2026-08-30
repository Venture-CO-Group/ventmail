#!/usr/bin/env bash
# ventMail — egyszeri bootstrap a Mac minin (headless dev host)
# Előfeltétel: git, gh (GitHub CLI, bejelentkezve), node, php 8.3, composer, docker, tmux,
#              Claude Code telepítve (`claude --version`), Tailscale az SSH-hoz.
# FONTOS: a Claude Code gyakran frissül — a headless flageket a venture-nightly.sh tetején
#         állítod be, és `claude --help`-pel hitelesíted. Ez a script NEM mergel és NEM pushol main-re.

set -euo pipefail

REPO_SSH="git@github.com:venture-co/ventmail.git"
ROOT="${HOME}/Developer/venture"
APP="${ROOT}/ventmail"
KIT="${ROOT}/venture-kit"
KIT_MARKETPLACE="https://github.com/venture-co/venture-kit"   # HTTPS, ahogy a kit elvárja

echo "==> mappák"
mkdir -p "${ROOT}"

echo "==> repo klón (ha még nincs)"
if [ ! -d "${APP}/.git" ]; then
  git clone "${REPO_SSH}" "${APP}"
fi
cd "${APP}"

echo "==> venture-kit plugin + repó-szintű rules"
# A plugin marketplace-t HTTPS URL-lel add hozzá; a pontos parancsokat a Claude Code verziód szerint
# futtasd (interaktívan: /plugin marketplace add <URL> ; /plugin install venture-kit).
# A kit NEM szállít .claude/rules/-t (repó-szintű) — az install.sh másolja a helyére a templates/rules-ból.
if [ -x "${KIT}/install.sh" ]; then
  bash "${KIT}/install.sh"
else
  echo "  ! venture-kit install.sh nem található (${KIT}) — telepítsd a kitet, majd futtasd újra."
fi

echo "==> a pack fájljainak ellenőrzése"
for f in CLAUDE.md PHASE.md docs/ventmail-specifikacio.md docs/ventmail-fejlesztesi-terv.md \
         docs/ventmail-indito-promptok.md .claude/work/backlog.md; do
  [ -f "$f" ] && echo "  ok  $f" || echo "  HIÁNYZIK  $f  (másold be a packből)"
done

echo "==> .claude/venture.conf ajánlott értékek (írd felül, ha kell)"
cat > .claude/venture.conf <<'CONF'
VENTURE_GATE=on
VENTURE_TEST_CMD="php artisan test --parallel"
VENTURE_GUARD_WRITE=on
VENTURE_FORMAT=on
VENTURE_REQUIRE_TESTS=on
CONF
echo "  írva: .claude/venture.conf"

echo "==> PHP/JS függőségek (ha van composer.json / package.json)"
[ -f composer.json ] && composer install || echo "  (composer.json még nincs — az M0-1 architect packet hozza létre a skeletont)"
[ -f package.json ] && npm install || true

echo "==> docker (dev) — ha van compose fájl"
[ -f docker-compose.yml ] && docker compose up -d || echo "  (docker-compose.yml a devops packetből jön)"

echo
echo "KÉSZ. Következő lépés:"
echo "  1) Interaktív első kör (Air-ről vagy SSH-n):  cd ${APP} && claude"
echo "     majd a fő szálban:  /fazis-terv M0   →   illeszd be az M0-1 promptot a docs/ventmail-indito-promptok.md-ből"
echo "  2) Folyamatos körök indítása a Mac minin:      ./venture-nightly.sh   (tmux-ban, lásd a fájl fejlécét)"
