"""
raa_schedule.py — Lance raa_discover.py à l'heure programmée, puis raa_scraper_v2.py

Usage: python raa_schedule.py [--heure 21:00]
Python: C:/Users/JulesFAGUET/anaconda3/envs/venv/python.exe

Laisse ce script tourner en arrière-plan dans un terminal.
"""

import sys, os, time, subprocess, logging, argparse
from datetime import datetime

sys.stdout.reconfigure(encoding='utf-8')

PYTHON   = sys.executable
SCRIPTS  = "C:/02_WEBcarto/rsig/scripts"
LOG_FILE = "C:/Temp/raa_schedule.log"

logging.basicConfig(
    level=logging.INFO,
    format="%(asctime)s %(message)s",
    handlers=[
        logging.FileHandler(LOG_FILE, encoding="utf-8", mode="a"),
        logging.StreamHandler(sys.stdout),
    ]
)
log = logging.getLogger(__name__)

def wait_until(h, m):
    now    = datetime.now()
    target = now.replace(hour=h, minute=m, second=0, microsecond=0)
    if target <= now:
        log.info("Heure cible déjà passée, lancement immédiat")
        return
    secs = (target - now).total_seconds()
    log.info(f"En attente jusqu'à {target.strftime('%H:%M:%S')} ({int(secs//60)} min {int(secs%60)} sec)")
    while True:
        remaining = (target - datetime.now()).total_seconds()
        if remaining <= 0:
            break
        if int(remaining) % 300 < 6:
            log.info(f"  attente... {int(remaining//60)} min restantes")
        time.sleep(5)

def run(script, args=""):
    cmd = [PYTHON, os.path.join(SCRIPTS, script)] + (args.split() if args else [])
    log.info(f"Lancement : {' '.join(cmd)}")
    start = time.time()
    proc  = subprocess.run(cmd, capture_output=False)
    elapsed = int(time.time() - start)
    log.info(f"Terminé en {elapsed//3600}h{(elapsed%3600)//60}m — code retour {proc.returncode}")
    return proc.returncode

parser = argparse.ArgumentParser()
parser.add_argument("--heure", default="21:00", help="heure de démarrage HH:MM (défaut 21:00)")
args = parser.parse_args()

h, m = map(int, args.heure.split(":"))

log.info(f"=== RAA Scheduler démarré — déclenchement à {args.heure} ===")
wait_until(h, m)

# ── Etape 1 : découverte des URLs ─────────────────────────────────────────────
log.info(">>> ETAPE 1 : raa_discover.py")
rc = run("raa_discover.py")

if rc != 0:
    log.warning("raa_discover.py s'est arrêté (ban probable). Pause 30 min avant le scraper.")
    time.sleep(1800)
else:
    log.info("Pause 5 min avant le scraper principal...")
    time.sleep(300)

# ── Etape 2 : scraper complet ─────────────────────────────────────────────────
log.info(">>> ETAPE 2 : raa_scraper_v2.py (toutes années)")
for year in [2025, 2024, 2023, 2022]:
    log.info(f"  Année {year}...")
    rc = run("raa_scraper_v2.py", f"--dep all --year {year}")
    if rc != 0:
        log.warning(f"  Année {year} interrompue (ban probable). Pause 45 min...")
        time.sleep(2700)
    else:
        log.info(f"  Pause 10 min avant l'année suivante...")
        time.sleep(600)

log.info("=== Tout terminé ===")
