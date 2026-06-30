"""
raa_scraper_v2.py — Scraper RAA préfectures via HTTP/2 (le WAF préfectoral rejette HTTP/1.1)
Cible : exonérations TF NC, taux NC votés, exos TEOM spécifiques

Usage:
    python raa_scraper_v2.py [--dep 59] [--year 2025] [--dry-run]
    python raa_scraper_v2.py --dep all --year 2025

Python : C:/Users/JulesFAGUET/anaconda3/envs/venv/python.exe
"""

import sys, os, re, time, logging, argparse, hashlib, io
from datetime import datetime
from urllib.parse import urljoin

import httpx
from bs4 import BeautifulSoup
import pdfplumber
import psycopg2

sys.stdout.reconfigure(encoding='utf-8')

# ── Config ────────────────────────────────────────────────────────────────────
DB_DSN    = "host=localhost dbname=mabase user=postgres password=postgres"
CACHE_DIR = "C:/Temp/raa_cache"
LOG_FILE  = "C:/Temp/raa_scraper_v2.log"

HEADERS = {
    "User-Agent": "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36",
    "Accept": "text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,*/*;q=0.8",
    "Accept-Language": "fr-FR,fr;q=0.9,en;q=0.8",
}

DELAY        = 2.0    # secondes entre requêtes
PDF_MAX_PAGES = 80
PDF_MAX_MB    = 100
RETRY_WAITS   = [5, 15, 30]

# ── Mots-clés fiscaux ─────────────────────────────────────────────────────────
KEYWORDS = [
    r"exon[ée]ration.{0,30}(nouvelles?\s+constructions?|construction\s+nouvelle)",
    r"nouvelles?\s+constructions?.{0,40}(taxe\s+fonci[eè]re|TFB|exon[ée]r)",
    r"taux.{0,20}(40|50|60|70|80|90|100)\s*%.{0,30}(fonci[eè]re|NC\b)",
    r"deux\s+ans?.{0,30}(exon[ée]r|nouvelles?\s+constructions?)",
    r"article\s+1383.{0,80}",
    r"article\s+1385.{0,80}",
    r"taxe.{0,20}enl[eè]vement.{0,20}ordures?",
    r"TEOM.{0,80}(exon[ée]r|taux|d[ée]lib[ée]r)",
    r"redevance.{0,20}(incitative|d[ée]chets?).{0,40}(exon[ée]r|taux)",
    r"taux.{0,30}taxe\s+fonci[eè]re.{0,30}(vot[ée]|fix[ée]|adopt[ée])",
    r"d[ée]lib[ée]ration.{0,50}(taxe\s+fonci[eè]re|fiscalit[ée]|imposition)",
    r"exon[ée]ration.{0,40}(logement\s+social|HLM|bail\s+r[ée]el)",
    r"zone\s+franche.{0,30}exon[ée]r",
    r"zone\s+de\s+revitalisation.{0,30}(exon[ée]r|taxe)",
]
KW_RE = [re.compile(kw, re.IGNORECASE | re.DOTALL) for kw in KEYWORDS]

# ── Carte préfectures ─────────────────────────────────────────────────────────
# Format A  = page par mois : domaine + path.format(year=Y, month=Mois)
# Format D  = index paginé par (offset) + sous-pages individuelles avec 1 PDF
# Format AM = comme A mais path annuel, liens mois à extraire dynamiquement
#
# Chaque entrée : (domaine, chemin_template, format, notes)

_STD = "/Publications/Recueil-des-actes-administratifs/RAA-{year}"

PREFECTURES = {
    # ── Configurés et vérifiés ─────────────────────────────────────────────
    "02": ("https://www.aisne.gouv.fr",
           "/Publications/Recueil-des-Actes-Administratifs/RAA-Annee-{year}",
           "D", "offset pagination"),
    "33": ("https://www.gironde.gouv.fr",
           "/Publications/Recueil-des-Actes-Administratifs/Recueil-des-Actes-Administratifs-de-l-annee-{year}/{month}",
           "A", ""),
    "59": ("https://www.nord.gouv.fr",
           "/Publications/Recueils-des-actes-administratifs/RAA-du-departement-du-Nord/{year}/{month}",
           "A", ""),
    "77": ("https://www.seine-et-marne.gouv.fr",
           "/Publications/Recueils-des-actes-administratifs-RAA/RAA-{year}",
           "B", ""),
    # ── Chemin standard vérifié ────────────────────────────────────────────
    "06": ("https://www.alpes-maritimes.gouv.fr",      _STD, "B", ""),
    "13": ("https://www.bouches-du-rhone.gouv.fr",     _STD, "B", ""),
    "31": ("https://www.haute-garonne.gouv.fr",        _STD, "B", ""),
    "34": ("https://www.herault.gouv.fr",              _STD, "B", ""),
    "35": ("https://www.ille-et-vilaine.gouv.fr",      _STD, "B", ""),
    "38": ("https://www.isere.gouv.fr",                _STD, "B", ""),
    "44": ("https://www.loire-atlantique.gouv.fr",     _STD, "B", ""),
    "57": ("https://www.moselle.gouv.fr",              _STD, "B", ""),
    "60": ("https://www.oise.gouv.fr",                 _STD, "B", ""),
    "67": ("https://www.bas-rhin.gouv.fr",             _STD, "B", ""),
    "69": ("https://www.rhone.gouv.fr",                _STD, "B", ""),
    "76": ("https://www.seine-maritime.gouv.fr",       _STD, "B", ""),
    "78": ("https://www.yvelines.gouv.fr",             _STD, "B", ""),
    "91": ("https://www.essonne.gouv.fr",              _STD, "B", ""),
    "92": ("https://www.hauts-de-seine.gouv.fr",       _STD, "B", ""),
    "93": ("https://www.seine-saint-denis.gouv.fr",    _STD, "B", ""),
    "94": ("https://www.val-de-marne.gouv.fr",         _STD, "B", ""),
    "95": ("https://www.val-d-oise.gouv.fr",           _STD, "B", ""),
    # ── Chemin standard (à confirmer au premier run) ───────────────────────
    "01": ("https://www.ain.gouv.fr",                  _STD, "B", ""),
    "03": ("https://www.allier.gouv.fr",               _STD, "B", ""),
    "04": ("https://www.alpes-de-haute-provence.gouv.fr", _STD, "B", ""),
    "05": ("https://www.hautes-alpes.gouv.fr",         _STD, "B", ""),
    "07": ("https://www.ardeche.gouv.fr",              _STD, "B", ""),
    "08": ("https://www.ardennes.gouv.fr",             _STD, "B", ""),
    "09": ("https://www.ariege.gouv.fr",               _STD, "B", ""),
    "10": ("https://www.aube.gouv.fr",                 _STD, "B", ""),
    "11": ("https://www.aude.gouv.fr",                 _STD, "B", ""),
    "12": ("https://www.aveyron.gouv.fr",              _STD, "B", ""),
    "14": ("https://www.calvados.gouv.fr",             _STD, "B", ""),
    "15": ("https://www.cantal.gouv.fr",               _STD, "B", ""),
    "16": ("https://www.charente.gouv.fr",             _STD, "B", ""),
    "17": ("https://www.charente-maritime.gouv.fr",    _STD, "B", ""),
    "18": ("https://www.cher.gouv.fr",                 _STD, "B", ""),
    "19": ("https://www.correze.gouv.fr",              _STD, "B", ""),
    "21": ("https://www.cote-d-or.gouv.fr",            _STD, "B", ""),
    "22": ("https://www.cotes-d-armor.gouv.fr",        _STD, "B", ""),
    "23": ("https://www.creuse.gouv.fr",               _STD, "B", ""),
    "24": ("https://www.dordogne.gouv.fr",             _STD, "B", ""),
    "25": ("https://www.doubs.gouv.fr",                _STD, "B", ""),
    "26": ("https://www.drome.gouv.fr",                _STD, "B", ""),
    "27": ("https://www.eure.gouv.fr",                 _STD, "B", ""),
    "28": ("https://www.eure-et-loir.gouv.fr",         _STD, "B", ""),
    "29": ("https://www.finistere.gouv.fr",            _STD, "B", ""),
    "2A": ("https://www.haute-corse.gouv.fr",          _STD, "B", ""),
    "2B": ("https://www.corse-du-sud.gouv.fr",         _STD, "B", ""),
    "30": ("https://www.gard.gouv.fr",                 _STD, "B", ""),
    "32": ("https://www.gers.gouv.fr",                 _STD, "B", ""),
    "36": ("https://www.indre.gouv.fr",                _STD, "B", ""),
    "37": ("https://www.indre-et-loire.gouv.fr",       _STD, "B", ""),
    "39": ("https://www.jura.gouv.fr",                 _STD, "B", ""),
    "40": ("https://www.landes.gouv.fr",               _STD, "B", ""),
    "41": ("https://www.loir-et-cher.gouv.fr",         _STD, "B", ""),
    "42": ("https://www.loire.gouv.fr",                _STD, "B", ""),
    "43": ("https://www.haute-loire.gouv.fr",          _STD, "B", ""),
    "45": ("https://www.loiret.gouv.fr",               _STD, "B", ""),
    "46": ("https://www.lot.gouv.fr",                  _STD, "B", ""),
    "47": ("https://www.lot-et-garonne.gouv.fr",       _STD, "B", ""),
    "48": ("https://www.lozere.gouv.fr",               _STD, "B", ""),
    "49": ("https://www.maine-et-loire.gouv.fr",       _STD, "B", ""),
    "50": ("https://www.manche.gouv.fr",               _STD, "B", ""),
    "51": ("https://www.marne.gouv.fr",                _STD, "B", ""),
    "52": ("https://www.haute-marne.gouv.fr",          _STD, "B", ""),
    "53": ("https://www.mayenne.gouv.fr",              _STD, "B", ""),
    "54": ("https://www.meurthe-et-moselle.gouv.fr",   _STD, "B", ""),
    "55": ("https://www.meuse.gouv.fr",                _STD, "B", ""),
    "56": ("https://www.morbihan.gouv.fr",             _STD, "B", ""),
    "58": ("https://www.nievre.gouv.fr",               _STD, "B", ""),
    "61": ("https://www.orne.gouv.fr",                 _STD, "B", ""),
    "62": ("https://www.pas-de-calais.gouv.fr",        _STD, "B", ""),
    "63": ("https://www.puy-de-dome.gouv.fr",          _STD, "B", ""),
    "64": ("https://www.pyrenees-atlantiques.gouv.fr", _STD, "B", ""),
    "65": ("https://www.hautes-pyrenees.gouv.fr",      _STD, "B", ""),
    "66": ("https://www.pyrenees-orientales.gouv.fr",  _STD, "B", ""),
    "68": ("https://www.haut-rhin.gouv.fr",            _STD, "B", ""),
    "70": ("https://www.haute-saone.gouv.fr",          _STD, "B", ""),
    "71": ("https://www.saone-et-loire.gouv.fr",       _STD, "B", ""),
    "72": ("https://www.sarthe.gouv.fr",               _STD, "B", ""),
    "73": ("https://www.savoie.gouv.fr",               _STD, "B", ""),
    "74": ("https://www.haute-savoie.gouv.fr",         _STD, "B", ""),
    "79": ("https://www.deux-sevres.gouv.fr",          _STD, "B", ""),
    "80": ("https://www.somme.gouv.fr",                _STD, "B", ""),
    "81": ("https://www.tarn.gouv.fr",                 _STD, "B", ""),
    "82": ("https://www.tarn-et-garonne.gouv.fr",      _STD, "B", ""),
    "83": ("https://www.var.gouv.fr",                  _STD, "B", ""),
    "84": ("https://www.vaucluse.gouv.fr",             _STD, "B", ""),
    "85": ("https://www.vendee.gouv.fr",               _STD, "B", ""),
    "86": ("https://www.vienne.gouv.fr",               _STD, "B", ""),
    "87": ("https://www.haute-vienne.gouv.fr",         _STD, "B", ""),
    "88": ("https://www.vosges.gouv.fr",
           "/Publications/Recueil-des-Actes-Administratifs-RAA/RAA-{year}",
           "B", "hint from home"),
    "89": ("https://www.yonne.gouv.fr",                _STD, "B", ""),
    "90": ("https://www.territoire-de-belfort.gouv.fr",
           "/Publications/Le-recueil-des-actes-administratifs/RAA-{year}",
           "B", "hint from home"),
    # 75 Paris : structure différente (PPdP), chemin à vérifier manuellement
    # "75": ("https://www.prefecturedepolice.interieur.gouv.fr", ..., "B", ""),
}

MONTHS_FR = ["Janvier","Fevrier","Mars","Avril","Mai","Juin",
             "Juillet","Aout","Septembre","Octobre","Novembre","Decembre"]

# Charger les préfectures découvertes dynamiquement (sortie de raa_discover.py)
import json as _json
_DISCOVERED = "C:/Temp/raa_discovered.json"
if os.path.exists(_DISCOVERED):
    try:
        with open(_DISCOVERED, encoding="utf-8") as _f:
            for _r in _json.load(_f):
                if _r.get("path") and _r["dep"] not in PREFECTURES:
                    PREFECTURES[_r["dep"]] = (_r["domain"], _r["path"], _r["fmt"], "auto")
    except Exception as _e:
        pass

# ── Logging ───────────────────────────────────────────────────────────────────
os.makedirs(CACHE_DIR, exist_ok=True)
logging.basicConfig(
    level=logging.INFO,
    format="%(asctime)s %(levelname)s %(message)s",
    handlers=[
        logging.FileHandler(LOG_FILE, encoding="utf-8"),
        logging.StreamHandler(sys.stdout),
    ]
)
log = logging.getLogger(__name__)

# ── DB ────────────────────────────────────────────────────────────────────────
def get_db():
    return psycopg2.connect(DB_DSN)

def init_db(conn):
    with conn.cursor() as cur:
        cur.execute("""
        CREATE TABLE IF NOT EXISTS raa_deliberations (
            id            serial PRIMARY KEY,
            dep           varchar(3),
            annee         smallint,
            date_pub      date,
            numero_raa    varchar(100),
            pdf_url       text,
            pdf_hash      varchar(64),
            titre_section text,
            commune       varchar(100),
            code_insee    varchar(6),
            type_mesure   varchar(50),
            extrait       text,
            mots_cles     text[],
            traite_le     timestamp DEFAULT now(),
            UNIQUE(pdf_hash, extrait)
        );
        CREATE INDEX IF NOT EXISTS raa_dep_annee_idx ON raa_deliberations(dep, annee);
        CREATE INDEX IF NOT EXISTS raa_insee_idx ON raa_deliberations(code_insee);
        """)
        conn.commit()
    log.info("Table raa_deliberations prete")

def already_processed(conn, pdf_hash):
    with conn.cursor() as cur:
        cur.execute("SELECT 1 FROM raa_deliberations WHERE pdf_hash=%s LIMIT 1", (pdf_hash,))
        return cur.fetchone() is not None

def save_result(conn, dep, annee, date_pub, numero, url, pdf_hash, titre, commune, code_insee, type_mesure, extrait, mots_cles):
    with conn.cursor() as cur:
        cur.execute("""
            INSERT INTO raa_deliberations
              (dep,annee,date_pub,numero_raa,pdf_url,pdf_hash,titre_section,commune,
               code_insee,type_mesure,extrait,mots_cles)
            VALUES (%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s)
            ON CONFLICT (pdf_hash,extrait) DO NOTHING
        """, (dep,annee,date_pub,numero,url,pdf_hash,titre,commune,code_insee,type_mesure,extrait,mots_cles))
        conn.commit()

# ── Détection ban WAF ─────────────────────────────────────────────────────────
_consec_failures = 0
_BAN_THRESHOLD   = 5   # 5 échecs consécutifs = ban, arrêt immédiat

class BanDetected(Exception):
    pass

def _record_failure():
    global _consec_failures
    _consec_failures += 1
    if _consec_failures >= _BAN_THRESHOLD:
        raise BanDetected(f"Ban WAF détecté ({_consec_failures} échecs consécutifs) — arrêt.")

def _record_success():
    global _consec_failures
    _consec_failures = 0

# ── HTTP ──────────────────────────────────────────────────────────────────────
def make_client():
    return httpx.Client(http2=True, follow_redirects=True, timeout=25, headers=HEADERS)

def fetch_html(client, url):
    # Un seul essai — pas de retry sur Server disconnected (= ban)
    try:
        r = client.get(url)
        if r.status_code == 200:
            _record_success()
            return r.text
        if r.status_code == 404:
            return None
        log.warning(f"  HTTP {r.status_code} pour {url}")
        _record_failure()
        return None
    except Exception as e:
        if "disconnected" in str(e).lower() or "connection" in str(e).lower():
            log.warning(f"  connexion échouée {url}: {e}")
            _record_failure()
        else:
            log.warning(f"  fetch_html {url}: {e}")
        return None

def fetch_pdf_bytes(client, url):
    try:
        r = client.get(url, timeout=90)
        if r.status_code == 200:
            _record_success()
            return r.content
        _record_failure()
        return None
    except Exception as e:
        log.warning(f"  fetch_pdf {url}: {e}")
        _record_failure()
        return None

def find_pdf_links(html, base_url, max_mb=PDF_MAX_MB):
    """Retourne liste de (titre, url_pdf) depuis une page HTML."""
    soup = BeautifulSoup(html, "html.parser")
    results = []
    seen = set()
    for a in soup.find_all("a", href=True):
        href = a["href"]
        if not href.lower().endswith(".pdf"):
            continue
        if not href.startswith("http"):
            href = urljoin(base_url, href)
        if href in seen:
            continue
        seen.add(href)
        title = a.get_text(strip=True) or a.get("title", "") or href.split("/")[-1]
        # Filtre taille si mentionnée dans le texte voisin
        context = str(a.parent)
        m = re.search(r"(\d+[,\.]\d+)\s*[Mm][bo]", context)
        if m:
            size_mb = float(m.group(1).replace(",", "."))
            if size_mb > max_mb:
                log.info(f"  PDF trop gros ({size_mb} Mb): {title[:50]}")
                continue
        results.append((title, href))
    return results

# ── Extraction PDF ────────────────────────────────────────────────────────────
def extract_pdf_pages(pdf_bytes):
    pages = []
    try:
        with pdfplumber.open(io.BytesIO(pdf_bytes)) as pdf:
            if len(pdf.pages) > PDF_MAX_PAGES:
                log.info(f"  PDF trop long ({len(pdf.pages)} pages)")
                return []
            for i, page in enumerate(pdf.pages):
                txt = page.extract_text() or ""
                if txt.strip():
                    pages.append((i + 1, txt))
    except Exception as e:
        log.warning(f"  Erreur lecture PDF: {e}")
    return pages

def scan_text(text):
    hits = []
    for kw_re in KW_RE:
        for m in kw_re.finditer(text):
            start = max(0, m.start() - 200)
            end   = min(len(text), m.end() + 300)
            extrait = text[start:end].replace("\n", " ").strip()
            hits.append((kw_re.pattern[:40], extrait))
    return hits

def classify(kw_pattern):
    p = kw_pattern.lower()
    if "1383" in p or "nouvelles" in p:
        return "exo_nc_tfb"
    if "1385" in p:
        return "exo_nc_tfnb"
    if "teom" in p or "ordures" in p or "incitativ" in p:
        return "teom"
    if "taux" in p and "fonci" in p:
        return "taux_vote"
    if "zone" in p:
        return "zone_exo"
    return "autre"

def guess_commune(text_around):
    for pat in [
        r"commune\s+(?:de\s+|d[eu]\s+)?([A-Z][A-Z\-\s]{2,30})",
        r"(COMMUNE|MAIRIE)\s+DE\s+([A-Z][A-Z\-\s]{2,30})",
        r"VILLE\s+DE\s+([A-Z][A-Z\-\s]{2,25})",
    ]:
        m = re.search(pat, text_around)
        if m:
            return m.group(m.lastindex).strip()[:80]
    return None

def guess_date_pub(titre):
    mois_map = {"janvier":1,"fevrier":2,"fev":2,"mars":3,"avril":4,"mai":5,"juin":6,
                "juillet":7,"aout":8,"septembre":9,"sept":9,"octobre":10,"oct":10,
                "novembre":11,"nov":11,"decembre":12,"dec":12}
    m = re.search(r"(\d{1,2})\s+(\w+)\s+(\d{4})", titre or "")
    if m:
        mois = mois_map.get(m.group(2).lower())
        if mois:
            try:
                return datetime(int(m.group(3)), mois, int(m.group(1))).date()
            except:
                pass
    return None

# ── Traitement d'un PDF ───────────────────────────────────────────────────────
def process_pdf(client, dep, year, titre, pdf_url, dry_run, conn):
    url_hash = hashlib.sha256(pdf_url.encode()).hexdigest()[:16]
    cache_path = f"{CACHE_DIR}/{url_hash}.pdf"

    if dry_run:
        log.info(f"  [DRY] {titre[:70]}")
        return 0

    if conn and already_processed(conn, url_hash):
        log.info(f"  deja traite: {titre[:60]}")
        return 0

    if os.path.exists(cache_path):
        with open(cache_path, "rb") as f:
            pdf_bytes = f.read()
        log.info(f"  cache: {titre[:60]}")
    else:
        log.info(f"  dl: {titre[:70]}")
        pdf_bytes = fetch_pdf_bytes(client, pdf_url)
        if not pdf_bytes:
            return 0
        if len(pdf_bytes) / 1024 / 1024 > PDF_MAX_MB:
            log.info(f"  trop gros ({len(pdf_bytes)//1024//1024} Mo)")
            return 0
        with open(cache_path, "wb") as f:
            f.write(pdf_bytes)

    pdf_hash = hashlib.sha256(pdf_bytes).hexdigest()

    pages = extract_pdf_pages(pdf_bytes)
    count = 0
    for page_num, page_text in pages:
        hits = scan_text(page_text)
        for kw_pattern, extrait in hits:
            commune  = guess_commune(extrait)
            type_m   = classify(kw_pattern)
            date_pub = guess_date_pub(titre)
            if conn:
                save_result(conn, dep, year, date_pub, titre, pdf_url,
                            pdf_hash, f"p.{page_num}", commune, None,
                            type_m, extrait[:800], [kw_pattern])
            else:
                print(f"  MATCH [{type_m}] p.{page_num} commune={commune}: {extrait[:130]}")
            count += 1

    if count:
        log.info(f"  {count} extraits: {titre[:60]}")
    return count

def process_page_with_pdfs(client, dep, year, page_url, dry_run, conn):
    """Charge une page HTML, extrait les PDFs et les traite."""
    html = fetch_html(client, page_url)
    if not html:
        return 0
    pdfs = find_pdf_links(html, page_url)
    count = 0
    for titre, pdf_url in pdfs:
        count += process_pdf(client, dep, year, titre, pdf_url, dry_run, conn)
        time.sleep(DELAY)
    return count

# ── Crawl format A (pages par mois) ──────────────────────────────────────────
def crawl_format_a(client, dep, domain, path_tpl, year, dry_run, conn):
    total = 0
    for month in MONTHS_FR:
        url = domain + path_tpl.format(year=year, month=month)
        log.info(f"[{dep}] mois {month}: {url}")
        html = fetch_html(client, url)
        if not html:
            continue
        pdfs = find_pdf_links(html, url)
        log.info(f"  {len(pdfs)} PDFs")
        for titre, pdf_url in pdfs:
            total += process_pdf(client, dep, year, titre, pdf_url, dry_run, conn)
            time.sleep(DELAY)
    return total

# ── Crawl format B (page annuelle avec PDFs directs ou sous-pages) ────────────
def crawl_format_b(client, dep, domain, path_tpl, year, dry_run, conn):
    url = domain + path_tpl.format(year=year)
    log.info(f"[{dep}] page annuelle: {url}")
    html = fetch_html(client, url)
    if not html:
        log.warning(f"[{dep}] page inaccessible: {url}")
        return 0

    pdfs = find_pdf_links(html, url)
    if pdfs:
        log.info(f"  {len(pdfs)} PDFs directs")
        total = 0
        for titre, pdf_url in pdfs:
            total += process_pdf(client, dep, year, titre, pdf_url, dry_run, conn)
            time.sleep(DELAY)
        return total

    # Pas de PDFs directs — chercher des sous-pages avec PDFs
    soup = BeautifulSoup(html, "html.parser")
    subpages = []
    for a in soup.find_all("a", href=True):
        href = a["href"]
        if not href.startswith("http"):
            href = urljoin(url, href)
        if domain in href and href != url and (str(year) in href or "raa" in href.lower()):
            subpages.append(href)
    subpages = list(dict.fromkeys(subpages))
    log.info(f"  {len(subpages)} sous-pages potentielles")

    total = 0
    for sp_url in subpages:
        total += process_page_with_pdfs(client, dep, year, sp_url, dry_run, conn)
        time.sleep(DELAY)
    return total

# ── Crawl format D (index paginé par offset → sous-pages → 1 PDF) ─────────────
def crawl_format_d(client, dep, domain, path_tpl, year, dry_run, conn):
    index_url = domain + path_tpl.format(year=year)
    log.info(f"[{dep}] index pagine: {index_url}")

    # Collecte toutes les sous-pages via pagination (offset)
    all_subpages = []
    seen = set()
    offset = 0
    while True:
        url = f"{index_url}/(offset)/{offset}" if offset > 0 else index_url
        html = fetch_html(client, url)
        if not html:
            break
        # Chercher les liens vers des sous-pages RAA de l'année
        links = re.findall(
            r'href="(' + re.escape(path_tpl.format(year=year)) + r'/[^"]+)"',
            html
        )
        # Aussi chercher avec le domaine complet
        links += re.findall(
            r'href="(' + re.escape(index_url) + r'/[^"]+)"',
            html
        )
        new = [l for l in links if l not in seen and not l.endswith(str(year))]
        seen.update(links)
        for l in new:
            full = domain + l if l.startswith("/") else l
            if full not in all_subpages:
                all_subpages.append(full)
        log.info(f"  offset {offset}: {len(new)} nouveaux, total {len(all_subpages)}")
        if not new:
            break
        offset += 10
        time.sleep(DELAY)

    log.info(f"  {len(all_subpages)} sous-pages RAA")
    total = 0
    for sp_url in all_subpages:
        total += process_page_with_pdfs(client, dep, year, sp_url, dry_run, conn)
        time.sleep(DELAY)
    return total

# ── Main ──────────────────────────────────────────────────────────────────────
def main():
    parser = argparse.ArgumentParser()
    parser.add_argument("--dep", default="59", help="code dep (ex: 59) ou 'all'")
    parser.add_argument("--year", type=int, default=2025)
    parser.add_argument("--dry-run", action="store_true")
    parser.add_argument("--no-db", action="store_true")
    args = parser.parse_args()

    conn = None
    if not args.dry_run and not args.no_db:
        try:
            conn = get_db()
            init_db(conn)
        except Exception as e:
            log.warning(f"DB inaccessible, mode console: {e}")

    deps = list(PREFECTURES.keys()) if args.dep == "all" else [args.dep.zfill(2)]
    client = make_client()
    grand_total = 0

    for dep in deps:
        if dep not in PREFECTURES:
            log.warning(f"dep {dep} non configure")
            continue
        domain, path_tpl, fmt, notes = PREFECTURES[dep]
        log.info(f"\n{'='*60}\nDep {dep} | {domain} | format {fmt}\n{'='*60}")
        try:
            if fmt == "A":
                n = crawl_format_a(client, dep, domain, path_tpl, args.year, args.dry_run, conn)
            elif fmt == "B":
                n = crawl_format_b(client, dep, domain, path_tpl, args.year, args.dry_run, conn)
            elif fmt == "D":
                n = crawl_format_d(client, dep, domain, path_tpl, args.year, args.dry_run, conn)
            else:
                n = 0
            log.info(f"[{dep}] {n} extraits fiscaux")
            grand_total += n
        except BanDetected as e:
            log.error(f"ARRET — {e}")
            log.error(f"Dernier dep en cours : {dep}. Relancer avec --dep {dep} quand le ban est leve.")
            break
        except Exception as e:
            log.error(f"[{dep}] erreur: {e}", exc_info=True)
        time.sleep(DELAY * 2)

    log.info(f"\nTotal: {grand_total} extraits fiscaux")
    if conn:
        conn.close()

if __name__ == "__main__":
    main()
