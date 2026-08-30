#!/usr/bin/env bash
# ventMail — folyamatos (0-24) headless futtató a Mac minin.
# MIT CSINÁL: kiveszi a backlog következő, minden-előfeltétel-DONE TODO packetjét → külön git worktree +
#             branch → headless Claude Code a packet promptjával ("teszt előbb") → a venture-kit hookok
#             kikényszerítik a gate-eket (commit-kapu maga futtatja a teszteket, push-guard tiltja a main-t)
#             → PR nyit (soha nem mergel). Reggeli összefoglalót ír.
#
# INDÍTÁS (perzisztens, headless):
#   tmux new -s ventmail 'cd ~/Developer/venture/ventmail && ./venture-nightly.sh; bash'
#   (Air-ről:  ssh macmini -t "tmux attach -t ventmail")
#   Két sáv párhuzamosan (M0 UTÁN):
#     tmux new -s vm-a 'cd ~/Developer/venture/ventmail && ./venture-nightly.sh --track A; bash'
#     tmux new -s vm-b 'cd ~/Developer/venture/ventmail && ./venture-nightly.sh --track B; bash'
# vagy cron/launchd egyszeri körre:  ./venture-nightly.sh --once [--track A]
#
# ⚠️ CLAUDE CODE FLAGEK: a headless/non-interaktív hívás pontos flagjei verzióról verzióra változhatnak.
#    Ellenőrizd:  claude --help   és állítsd be a CLAUDE_HEADLESS_ARGS-t alább. A print/headless mód
#    alap hívása:  claude -p "<prompt>"  (stdin-ről is fogad). NE adj auto-merge/main-push jogot.

set -uo pipefail

APP="${HOME}/Developer/venture/ventmail"
WORKROOT="${HOME}/Developer/venture/_worktrees/ventmail"
PROMPTS="${APP}/docs/ventmail-indito-promptok.md"
BACKLOG="${APP}/.claude/work/backlog.md"
SUMMARY_DIR="${APP}/.claude/work/summaries"
BASE_BRANCH="main"
POLL_SECONDS=1800          # két kör közti várakozás folyamatos módban
CLAUDE_BIN="claude"
# Verzió szerint hitelesítendő! Pl. print mód + nem-interaktív engedélykezelés:
CLAUDE_HEADLESS_ARGS="-p"  # + a te verziód permission-flagje, ha kell (lásd: claude --help)

mkdir -p "${WORKROOT}" "${SUMMARY_DIR}"

ONCE=0; TRACK=""
while [ $# -gt 0 ]; do
  case "$1" in
    --once)  ONCE=1; shift ;;
    --track) TRACK="${2:-}"; shift 2 ;;
    *) echo "ismeretlen kapcsoló: $1"; exit 64 ;;
  esac
done

log(){ echo "[$(date '+%F %T')] $*"; }

# Backlog oszlopok:  $1=státusz  $2=packet-id  $3=track  $4=branch  $5=agentek
# Kapuk:
#  - M0 (track M) mindig elsőbbséget élvez: amíg van nyitott M packet, más track nem indul.
#  - C track csak akkor indul, ha minden A és B packet DONE.
next_packet(){
  awk -v want="${TRACK}" '
    $1=="TODO"||$1=="RUNNING"||$1=="PR_OPEN"||$1=="DONE"||$1=="BLOCKED" {
      st=$1; id=$2; tr=$3
      if(tr=="M" && st!="DONE") m_open++
      if((tr=="A"||tr=="B") && st!="DONE") ab_open++
      if(st=="TODO"){
        n++; s_id[n]=id; s_tr[n]=tr
      }
    }
    END{
      for(i=1;i<=n;i++){
        tr=s_tr[i]
        # M0 kapu: amíg nyitott M packet van, csak M indulhat
        if(m_open>0 && tr!="M") continue
        # C kapu: csak ha A és B kész
        if(tr=="C" && ab_open>0) continue
        # track-szűrő (ha kértek)
        if(want!="" && tr!=want && tr!="M") continue
        print s_id[i]; exit
      }
    }
  ' "${BACKLOG}"
}

set_status(){ # $1=packet_id $2=új_státusz
  local id="$1" st="$2"
  # az adott packet sor első oszlopát írja át
  perl -0pi -e "s/^(TODO|RUNNING|PR_OPEN|BLOCKED)(\\s+\\Q$id\\E\\s)/$st\$2/m" "${BACKLOG}"
}

branch_for(){ awk -v id="$1" '$2==id{print $4; exit}' "${BACKLOG}"; }

# a promptot a docs/ventmail-indito-promptok.md-ből húzza ki a packet fejléce alapján (a ``` blokk)
extract_prompt(){ # $1=packet_id (pl. m0-1, a1, b2)
  local id_up; id_up="$(echo "$1" | tr 'a-z' 'A-Z')"
  awk -v h="### ${id_up} " '
    index($0,h)==1 {found=1}
    found && /^```/ {infence=!infence; if(!infence){exit}; next}
    found && infence {print}
  ' "${PROMPTS}"
}

run_packet(){
  local id="$1" br summary prompt wt
  br="$(branch_for "$id")"; [ -z "$br" ] && { log "nincs branch a(z) $id-hoz"; return 1; }
  wt="${WORKROOT}/${TRACK:-all}-${id}"
  log "packet ${id} → branch ${br}"

  ( cd "${APP}" && git fetch --quiet origin && git worktree add -B "${br}" "${wt}" "origin/${BASE_BRANCH}" 2>/dev/null \
      || git worktree add "${wt}" "${br}" 2>/dev/null || true )

  prompt="$(extract_prompt "$id")"
  if [ -z "${prompt}" ]; then log "üres prompt (${id}) — kihagyva"; set_status "$id" BLOCKED; return 1; fi

  set_status "$id" RUNNING

  # headless Claude Code a worktree-ben; a hookok kikényszerítik a "teszt előbb" + gate garanciákat.
  ( cd "${wt}" && printf '%s\n' "${prompt}" | ${CLAUDE_BIN} ${CLAUDE_HEADLESS_ARGS} ) \
      > "${SUMMARY_DIR}/${id}.log" 2>&1
  local rc=$?

  summary="${SUMMARY_DIR}/$(date '+%F')-${id}.md"
  {
    echo "# ${id} — $(date '+%F %T')"
    echo; echo "- branch: \`${br}\`  · exit: ${rc}"
    echo "- log: \`.claude/work/summaries/${id}.log\`"
  } > "${summary}"

  if [ ${rc} -ne 0 ]; then
    log "packet ${id} hibával állt le (rc=${rc}) — BLOCKED, emberi szem kell"
    set_status "$id" BLOCKED
    echo "- **STÁTUSZ: BLOCKED** — nézd meg a logot." >> "${summary}"
    return 1
  fi

  # commit már megtörtént a commit-kapu hookon át (az futtatja a teszteket). Push a feature branchre (main TILOS).
  ( cd "${wt}" && git push -u origin "${br}" ) >> "${SUMMARY_DIR}/${id}.log" 2>&1 || true

  # PR nyit — SOHA nem mergel
  if command -v gh >/dev/null 2>&1; then
    ( cd "${wt}" && gh pr create --base "${BASE_BRANCH}" --head "${br}" \
        --title "feat(${id}): ${br}" \
        --body "Automatikus kör (venture-nightly). Ember review + merge szükséges. Packet: ${id}." ) \
        >> "${SUMMARY_DIR}/${id}.log" 2>&1 && set_status "$id" PR_OPEN \
        && echo "- **STÁTUSZ: PR_OPEN** — reggeli review + merge." >> "${summary}" \
        || { log "gh pr create sikertelen"; set_status "$id" BLOCKED; }
  else
    log "gh nincs telepítve — PR-t kézzel nyiss"; set_status "$id" PR_OPEN
  fi

  log "packet ${id} kész → PR_OPEN"
}

one_round(){
  local id; id="$(next_packet)"
  if [ -z "${id}" ]; then log "nincs futtatható TODO packet (minden PR_OPEN/DONE/BLOCKED)"; return 2; fi
  run_packet "${id}"
}

log "ventMail nightly runner indul (once=${ONCE} track=${TRACK:-mind})"
if [ ${ONCE} -eq 1 ]; then
  one_round; exit $?
fi
while true; do
  one_round; rc=$?
  [ ${rc} -eq 2 ] && log "várakozás új munkára / reggeli mergre…"
  sleep "${POLL_SECONDS}"
done
