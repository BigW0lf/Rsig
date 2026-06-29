"""
raa_scraper.py — Scraper RAA préfectures pour délibérations fiscales
Cible : exonérations TF nouvelles constructions, taux NC votés, exos TEOM spécifiques

Usage:
    python raa_scraper.py [--dep 59] [--year 2025] [--dry-run]

Résultats stockés dans la table PostgreSQL `raa_deliberations`.
"""

import os
import re
import sys
import time
import logging
import argparse
import hashlib
import tempfile
from datetime import datetime
from urllib.parse import urljoin, urlparse

import requests
from bs4 import BeautifulSoup
import pdfplumber
import psycopg2

# ── Config ────────────────────────────────────────────────────────────────────
DB_DSN = "host=localhost dbname=mabase user=postgres password=postgres"
CACHE_DIR = "C:/Temp/raa_cache"
LOG_FILE  = "C:/Temp/raa_scraper.log"

HEADERS = {
    "User-Agent": "Mozilla/5.0 (compatible; RSig-RAAScraper/1.0; recherche academique fiscalite locale)"
}
DELAY_BETWEEN_REQUESTS = 3.0   # secondes — respectueux des serveurs
PDF_MAX_PAGES = 80              # ne pas traiter les PDF > 80 pages (RAA complets)
PDF_MAX_SIZE_MB = 20            # ignorer les gros PDFs (RAA complets consolidés)
RETRY_MAX = 3
RETRY_BACKOFF = [5, 15, 30]    # secondes entre tentatives

# ── Mots-clés fiscaux ─────────────────────────────────────────────────────────
KEYWORDS = [
    # Exo NC TFB
    r"exon[ée]ration.{0,30}(nouvelles?\s+constructions?|construction\s+nouvelle)",
    r"nouvelles?\s+constructions?.{0,40}(taxe\s+fonci[eè]re|TFB|exon[ée]r)",
    r"taux.{0,20}(40|50|60|70|80|90|100)\s*%.{0,30}(fonci[eè]re|NC\b)",
    r"deux\s+ans?.{0,30}(exon[ée]r|nouvelles?\s+constructions?)",
    r"article\s+1383.{0,60}",        # CGI art. 1383 = exo NC TFB
    r"article\s+1385.{0,60}",        # CGI art. 1385 = exo NC TFnB
    # TEOM spécifique
    r"taxe.{0,20}enl[eè]vement.{0,20}ordures?",
    r"TEOM.{0,60}(exon[ée]r|taux|d[ée]lib[ée]r)",
    r"redevance.{0,20}(incitative|d[ée]chets?).{0,40}(exon[ée]r|taux)",
    # Taux TF votés
    r"taux.{0,30}taxe\s+fonci[eè]re.{0,30}(vot[ée]|fix[ée]|adopt[ée])",
    r"(taux\s+communal|taux\s+d[ée]partemental).{0,40}(TFB|TFnB|fonci[eè]re)",
    # Délibérations fiscales générales
    r"d[ée]lib[ée]ration.{0,50}(taxe\s+fonci[eè]re|fiscalit[ée]|imposition)",
    r"exon[ée]ration.{0,40}(logement\s+social|HLM|bail\s+r[ée]el)",
    # ZFU, ZRR, QPV
    r"zone\s+franche.{0,30}exon[ée]r",
    r"zone\s+de\s+revitalisation.{0,30}(exon[ée]r|taxe)",
    r"quartier\s+prioritaire.{0,30}(exon[ée]r|taxe)",
]

KEYWORDS_COMPILED = [re.compile(kw, re.IGNORECASE | re.DOTALL) for kw in KEYWORDS]

# ── Carte des préfectures : dep → (base_url, chemin_raa) ─────────────────────
# Format : (domaine, path_index_raa, path_year_template, path_month_template)
# Découverts empiriquement — complétés au fur et à mesure
PREFECTURES = {
    # Format A : dep.gouv.fr/Publications/Recueils.../YEAR/Mois
    "59": ("https://www.nord.gouv.fr",
           "/Publications/Recueils-des-actes-administratifs/RAA-du-departement-du-Nord/{year}/{month}",
           "A"),
    "77": ("https://www.seine-et-marne.gouv.fr",
           "/Publications/Recueils-des-actes-administratifs-RAA/RAA-{year}",
           "B"),
    "69": ("https://www.rhone.gouv.fr",
           "/Publications/Recueil-des-actes-administratifs/RAA-{year}",
           "B"),
    "13": ("https://www.bouches-du-rhone.gouv.fr",
           "/Publications/Recueil-des-actes-administratifs/RAA-{year}",
           "B"),
    "75": ("https://www.prefecturedepolice.interieur.gouv.fr",
           "/Pratique/Recueil-des-actes-administratifs/RAA-{year}",
           "B"),
    "31": ("https://www.haute-garonne.gouv.fr",
           "/Publications/Recueil-des-actes-administratifs/RAA-{year}",
           "B"),
    "33": ("https://www.gironde.gouv.fr",
           "/Publications/Recueil-des-actes-administratifs/RAA-{year}",
           "B"),
    "06": ("https://www.alpes-maritimes.gouv.fr",
           "/Publications/Recueil-des-actes-administratifs/RAA-{year}",
           "B"),
    "34": ("https://www.herault.gouv.fr",
           "/Publications/Recueil-des-actes-administratifs/RAA-{year}",
           "B"),
    "44": ("https://www.loire-atlantique.gouv.fr",
           "/Publications/Recueil-des-actes-administratifs/RAA-{year}",
           "B"),
    "67": ("https://www.bas-rhin.gouv.fr",
           "/Publications/Recueil-des-actes-administratifs/RAA-{year}",
           "B"),
    "76": ("https://www.seine-maritime.gouv.fr",
           "/Publications/Recueil-des-actes-administratifs/RAA-{year}",
           "B"),
    "38": ("https://www.isere.gouv.fr",
           "/Publications/Recueil-des-actes-administratifs/RAA-{year}",
           "B"),
    "35": ("https://www.ille-et-vilaine.gouv.fr",
           "/Publications/Recueil-des-actes-administratifs/RAA-{year}",
           "B"),
    "57": ("https://www.moselle.gouv.fr",
           "/Publications/Recueil-des-actes-administratifs/RAA-{year}",
           "B"),
    "01": ("https://www.ain.gouv.fr",
           "/Publications/Recueil-des-actes-administratifs/RAA-{year}",
           "B"),
    "02": ("https://www.aisne.gouv.fr",
           "/Publications/Recueil-des-actes-administratifs/RAA-{year}",
           "B"),
    "60": ("https://www.oise.gouv.fr",
           "/Publications/Recueil-des-actes-administratifs/RAA-{year}",
           "B"),
    "93": ("https://www.seine-saint-denis.gouv.fr",
           "/Publications/Recueil-des-actes-administratifs/RAA-{year}",
           "B"),
    "92": ("https://www.hauts-de-seine.gouv.fr",
           "/Publications/Recueil-des-actes-administratifs/RAA-{year}",
           "B"),
    "94": ("https://www.val-de-marne.gouv.fr",
           "/Publications/Recueil-des-actes-administratifs/RAA-{year}",
           "B"),
    "95": ("https://www.val-d-oise.gouv.fr",
           "/Publications/Recueil-des-actes-administratifs/RAA-{year}",
           "B"),
    "78": ("https://www.yvelines.gouv.fr",
           "/Publications/Recueil-des-actes-administratifs/RAA-{year}",
           "B"),
    "91": ("https://www.essonne.gouv.fr",
           "/Publications/Recueil-des-actes-administratifs/RAA-{year}",
           "B"),
}

MONTHS_FR = ["Janvier","Fevrier","Mars","Avril","Mai","Juin",
             "Juillet","Aout","Septembre","Octobre","Novembre","Decembre"]


# ── Logging ───────────────────────────────────────────────────────────────────
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
            id              serial PRIMARY KEY,
            dep             varchar(3),
            annee           smallint,
            date_pub        date,
            numero_raa      varchar(100),
            pdf_url         text,
            pdf_hash        varchar(64),
            titre_section   text,
            commune         varchar(100),
            code_insee      varchar(6),
            type_mesure     varchar(50),   -- 'exo_nc_tfb','exo_nc_tfnb','taux_vote','teom','autre'
            extrait         text,          -- passage pertinent (~500 chars)
            mots_cles       text[],
            traite_le       timestamp DEFAULT now(),
            UNIQUE(pdf_hash, extrait)
        );
        CREATE INDEX IF NOT EXISTS raa_dep_annee_idx ON raa_deliberations(dep, annee);
        CREATE INDEX IF NOT EXISTS raa_insee_idx ON raa_deliberations(code_insee);
        """)
        conn.commit()
    log.info("Table raa_deliberations prête")

def already_processed(conn, pdf_hash):
    with conn.cursor() as cur:
        cur.execute("SELECT 1 FROM raa_deliberations WHERE pdf_hash = %s LIMIT 1", (pdf_hash,))
        return cur.fetchone() is not None

def save_result(conn, dep, annee, date_pub, numero, pdf_url, pdf_hash, titre, commune, code_insee, type_mesure, extrait, mots_cles):
    with conn.cursor() as cur:
        cur.execute("""
            INSERT INTO raa_deliberations
                (dep,annee,date_pub,numero_raa,pdf_url,pdf_hash,titre_section,commune,code_insee,type_mesure,extrait,mots_cles)
            VALUES (%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s)
            ON CONFLICT (pdf_hash, extrait) DO NOTHING
        """, (dep, annee, date_pub, numero, pdf_url, pdf_hash, titre, commune, code_insee, type_mesure, extrait, mots_cles))
        conn.commit()


# ── HTTP helpers ──────────────────────────────────────────────────────────────
session = requests.Session()
session.headers.update(HEADERS)

def fetch_html(url, timeout=20):
    for attempt, wait in enumerate([0] + RETRY_BACKOFF):
        if wait:
            log.info(f"  retry {attempt}/{RETRY_MAX} dans {wait}s…")
            time.sleep(wait)
        try:
            r = session.get(url, timeout=timeout)
            r.raise_for_status()
            return r.text
        except Exception as e:
            log.warning(f"fetch_html {url}: {e}")
            if attempt >= RETRY_MAX - 1:
                return None
    return None

def fetch_pdf_bytes(url, timeout=90):
    for attempt, wait in enumerate([0] + RETRY_BACKOFF):
        if wait:
            log.info(f"  retry PDF {attempt}/{RETRY_MAX} dans {wait}s…")
            time.sleep(wait)
        try:
            r = session.get(url, timeout=timeout, stream=True)
            r.raise_for_status()
            size = int(r.headers.get("content-length", 0))
            if size > PDF_MAX_SIZE_MB * 1024 * 1024:
                log.info(f"PDF trop grand ({size//1024//1024} Mo), ignoré: {url}")
                return None
            data = b""
            for chunk in r.iter_content(65536):
                data += chunk
                if len(data) > PDF_MAX_SIZE_MB * 1024 * 1024:
                    log.info(f"PDF trop grand (stream), ignoré: {url}")
                    return None
            return data
        except Exception as e:
            log.warning(f"fetch_pdf attempt {attempt} {url}: {e}")
            if attempt >= RETRY_MAX - 1:
                return None
    return None


# ── Extraction PDF ─────────────────────────────────────────────────────────────
def extract_pdf_text(pdf_bytes):
    """Extrait le texte d'un PDF en mémoire, max PDF_MAX_PAGES pages."""
    try:
        with tempfile.NamedTemporaryFile(suffix=".pdf", delete=False) as f:
            f.write(pdf_bytes)
            tmp = f.name
        pages_text = []
        with pdfplumber.open(tmp) as pdf:
            if len(pdf.pages) > PDF_MAX_PAGES:
                log.info(f"PDF {len(pdf.pages)} pages > {PDF_MAX_PAGES}, ignoré")
                os.unlink(tmp)
                return None
            for page in pdf.pages:
                t = page.extract_text() or ""
                pages_text.append(t)
        os.unlink(tmp)
        return "\n".join(pages_text)
    except Exception as e:
        log.warning(f"extract_pdf_text: {e}")
        try: os.unlink(tmp)
        except: pass
        return None


# ── Analyse du texte ──────────────────────────────────────────────────────────
def classify_match(text_around):
    t = text_around.lower()
    if re.search(r"nouvelles?\s+constructions?|article\s*1383|article\s*1385", t, re.I):
        return "exo_nc_tfb"
    if re.search(r"teom|ordures?\s+m[eé]nag[eè]res?|redevance\s+incitative", t, re.I):
        return "teom"
    if re.search(r"taux.{0,20}(vot[ée]|fix[ée]|adopt[ée])", t, re.I):
        return "taux_vote"
    if re.search(r"exon[ée]r", t, re.I):
        return "exo_autre"
    return "autre"

def extract_commune_from_context(text_around):
    """Tente d'extraire le nom de commune du contexte."""
    patterns = [
        r"commune\s+de\s+([A-Z][A-Z\s\-]+?)[\s,\n]",
        r"ville\s+de\s+([A-Z][A-Z\s\-]+?)[\s,\n]",
        r"([A-Z][A-Z\s\-]{3,25})\s*:\s*(d[ée]lib[ée]ration|arr[eê]t[ée]|d[ée]cision)",
    ]
    for pat in patterns:
        m = re.search(pat, text_around)
        if m:
            return m.group(1).strip()[:80]
    return None

def search_keywords_in_text(text, pdf_url, dep, annee, numero, date_pub):
    """Cherche les mots-clés dans le texte, retourne la liste de résultats."""
    results = []
    seen_positions = set()

    for i, kw_re in enumerate(KEYWORDS_COMPILED):
        for m in kw_re.finditer(text):
            pos = m.start()
            # Dédupliquer les hits trop proches (< 300 chars)
            close = any(abs(pos - p) < 300 for p in seen_positions)
            if close:
                continue
            seen_positions.add(pos)

            # Extrait de contexte : 200 chars avant, 300 après
            start = max(0, pos - 200)
            end   = min(len(text), pos + 300)
            extrait = text[start:end].strip().replace("\n", " ")
            extrait = re.sub(r"\s{2,}", " ", extrait)

            commune   = extract_commune_from_context(extrait)
            type_mes  = classify_match(extrait)
            mots_cles = [KEYWORDS[i]]

            results.append({
                "commune":    commune,
                "code_insee": None,
                "type_mes":   type_mes,
                "extrait":    extrait[:800],
                "mots_cles":  mots_cles,
                "titre":      f"RAA {dep} n°{numero}",
            })

    return results


# ── Crawl page index → liste de PDFs ─────────────────────────────────────────
def get_pdf_links_from_page(base_url, page_url):
    """Extrait tous les liens PDF d'une page."""
    html = fetch_html(page_url)
    if not html:
        return []
    soup = BeautifulSoup(html, "html.parser")
    links = []
    for a in soup.find_all("a", href=True):
        href = a["href"]
        full = urljoin(base_url, href)
        # Lien PDF direct ou lien /contenu/telechargement/
        if href.lower().endswith(".pdf") or "/telechargement/" in href or "download" in href.lower():
            title = a.get_text(strip=True) or full
            links.append((full, title))
    return links


# ── Crawl Format A : année > mois > liste PDFs ────────────────────────────────
def crawl_format_a(base_url, path_tpl, dep, year, conn, dry_run):
    """Format : /.../{year}/{month} — 1 page par mois avec liste de PDFs."""
    total_hits = 0
    for month in MONTHS_FR:
        path = path_tpl.format(year=year, month=month)
        page_url = base_url + path
        log.info(f"[{dep}] crawl {page_url}")
        pdf_links = get_pdf_links_from_page(base_url, page_url)
        if not pdf_links:
            continue
        for pdf_url, title in pdf_links:
            hits = process_pdf(pdf_url, title, dep, year, conn, dry_run)
            total_hits += hits
            time.sleep(DELAY_BETWEEN_REQUESTS)
    return total_hits


# ── Crawl Format B : page annuelle avec tous les PDFs ─────────────────────────
def crawl_format_b(base_url, path_tpl, dep, year, conn, dry_run):
    """Format : /.../RAA-{year} — 1 page avec tous les PDFs de l'année."""
    path = path_tpl.format(year=year)
    page_url = base_url + path
    log.info(f"[{dep}] crawl {page_url}")
    pdf_links = get_pdf_links_from_page(base_url, page_url)
    total_hits = 0
    for pdf_url, title in pdf_links:
        hits = process_pdf(pdf_url, title, dep, year, conn, dry_run)
        total_hits += hits
        time.sleep(DELAY_BETWEEN_REQUESTS)
    return total_hits


# ── Traitement d'un PDF ───────────────────────────────────────────────────────
def process_pdf(pdf_url, title, dep, year, conn, dry_run):
    """Télécharge, analyse et stocke les résultats pour un PDF."""
    # Filtrer les PDFs nominatifs et non pertinents
    title_lower = title.lower()
    if any(x in title_lower for x in ["nominatif", "personnel", "arrêté de police",
                                       "voirie", "marché public", "permis de construire"]):
        return 0

    log.info(f"  PDF: {title[:60]} — {pdf_url}")

    if dry_run:
        log.info("    [DRY-RUN] pas de téléchargement")
        return 0

    pdf_bytes = fetch_pdf_bytes(pdf_url)
    if not pdf_bytes:
        return 0

    pdf_hash = hashlib.sha256(pdf_bytes).hexdigest()

    # Skip déjà traité
    if already_processed(conn, pdf_hash):
        log.info("    déjà traité, ignoré")
        return 0

    text = extract_pdf_text(pdf_bytes)
    if not text:
        return 0

    # Filtre rapide : le PDF contient-il un mot-clé fiscal ?
    if not any(kw in text.lower() for kw in ["taxe fonci", "exonér", "exoner", "teom", "nouvelles construct", "délibér", "deliber"]):
        log.info("    pas de contenu fiscal, ignoré")
        return 0

    results = search_keywords_in_text(text, pdf_url, dep, year, title, None)

    if not results:
        return 0

    log.info(f"    {len(results)} hit(s) trouvé(s)")

    for r in results:
        save_result(
            conn, dep, year, None, title, pdf_url, pdf_hash,
            r["titre"], r["commune"], r["code_insee"],
            r["type_mes"], r["extrait"], r["mots_cles"]
        )

    return len(results)


# ── Main ──────────────────────────────────────────────────────────────────────
def main():
    parser = argparse.ArgumentParser(description="Scraper RAA préfectures — délibérations fiscales")
    parser.add_argument("--dep",      help="Département(s) séparés par virgule (ex: 59,77). Défaut: tous")
    parser.add_argument("--year",     type=int, default=2025, help="Année RAA (défaut: 2025)")
    parser.add_argument("--dry-run",  action="store_true", help="Crawl sans téléchargement ni BDD")
    args = parser.parse_args()

    os.makedirs(CACHE_DIR, exist_ok=True)

    conn = None
    if not args.dry_run:
        try:
            conn = get_db()
            init_db(conn)
        except Exception as e:
            log.error(f"Connexion DB impossible: {e}")
            sys.exit(1)

    deps_to_crawl = [d.strip() for d in args.dep.split(",")] if args.dep else list(PREFECTURES.keys())

    total = 0
    for dep in deps_to_crawl:
        if dep not in PREFECTURES:
            log.warning(f"Département {dep} non configuré, ignoré")
            continue

        base_url, path_tpl, fmt = PREFECTURES[dep]
        log.info(f"\n{'='*60}\nDépartement {dep} — {base_url} — format {fmt}\n{'='*60}")

        try:
            if fmt == "A":
                hits = crawl_format_a(base_url, path_tpl, dep, args.year, conn, args.dry_run)
            else:
                hits = crawl_format_b(base_url, path_tpl, dep, args.year, conn, args.dry_run)
            log.info(f"[{dep}] {hits} résultat(s) stocké(s)")
            total += hits
        except Exception as e:
            log.error(f"[{dep}] erreur: {e}")

        time.sleep(2)

    log.info(f"\nTotal : {total} délibérations fiscales trouvées")

    if conn:
        conn.close()


if __name__ == "__main__":
    main()
