"""
raa_discover.py — Découverte automatique des URLs RAA pour les 96 préfectures métropolitaines
Teste les patterns connus et détecte le format (A=mois, B=annuel, D=offset)
Sortie : raa_prefectures_discovered.py avec le dict PREFECTURES complet

Usage: python raa_discover.py [--dep 01] [--all]
Python: C:/Users/JulesFAGUET/anaconda3/envs/venv/python.exe
"""

import sys, re, time, json, argparse
from urllib.parse import urljoin

import httpx
from bs4 import BeautifulSoup

sys.stdout.reconfigure(encoding='utf-8')

HEADERS = {
    "User-Agent": "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36",
    "Accept": "text/html,application/xhtml+xml;q=0.9,*/*;q=0.8",
    "Accept-Language": "fr-FR,fr;q=0.9",
}
DELAY         = 3.0
TEST_YEAR     = 2025
MONTHS_FR     = ["Janvier", "Fevrier", "Mars"]
BAN_THRESHOLD = 4   # arrêt immédiat après N "Server disconnected" consécutifs

# ── Domaines préfecturaux : code → domaine gouv.fr ────────────────────────────
# Source : liste officielle des préfectures
DOMAINS = {
    "01": "www.ain.gouv.fr",
    "02": "www.aisne.gouv.fr",
    "03": "www.allier.gouv.fr",
    "04": "www.alpes-de-haute-provence.gouv.fr",
    "05": "www.hautes-alpes.gouv.fr",
    "06": "www.alpes-maritimes.gouv.fr",
    "07": "www.ardeche.gouv.fr",
    "08": "www.ardennes.gouv.fr",
    "09": "www.ariege.gouv.fr",
    "10": "www.aube.gouv.fr",
    "11": "www.aude.gouv.fr",
    "12": "www.aveyron.gouv.fr",
    "13": "www.bouches-du-rhone.gouv.fr",
    "14": "www.calvados.gouv.fr",
    "15": "www.cantal.gouv.fr",
    "16": "www.charente.gouv.fr",
    "17": "www.charente-maritime.gouv.fr",
    "18": "www.cher.gouv.fr",
    "19": "www.correze.gouv.fr",
    "21": "www.cote-d-or.gouv.fr",
    "22": "www.cotes-d-armor.gouv.fr",
    "23": "www.creuse.gouv.fr",
    "24": "www.dordogne.gouv.fr",
    "25": "www.doubs.gouv.fr",
    "26": "www.drome.gouv.fr",
    "27": "www.eure.gouv.fr",
    "28": "www.eure-et-loir.gouv.fr",
    "29": "www.finistere.gouv.fr",
    "2A": "www.haute-corse.gouv.fr",
    "2B": "www.corse-du-sud.gouv.fr",
    "30": "www.gard.gouv.fr",
    "31": "www.haute-garonne.gouv.fr",
    "32": "www.gers.gouv.fr",
    "33": "www.gironde.gouv.fr",
    "34": "www.herault.gouv.fr",
    "35": "www.ille-et-vilaine.gouv.fr",
    "36": "www.indre.gouv.fr",
    "37": "www.indre-et-loire.gouv.fr",
    "38": "www.isere.gouv.fr",
    "39": "www.jura.gouv.fr",
    "40": "www.landes.gouv.fr",
    "41": "www.loir-et-cher.gouv.fr",
    "42": "www.loire.gouv.fr",
    "43": "www.haute-loire.gouv.fr",
    "44": "www.loire-atlantique.gouv.fr",
    "45": "www.loiret.gouv.fr",
    "46": "www.lot.gouv.fr",
    "47": "www.lot-et-garonne.gouv.fr",
    "48": "www.lozere.gouv.fr",
    "49": "www.maine-et-loire.gouv.fr",
    "50": "www.manche.gouv.fr",
    "51": "www.marne.gouv.fr",
    "52": "www.haute-marne.gouv.fr",
    "53": "www.mayenne.gouv.fr",
    "54": "www.meurthe-et-moselle.gouv.fr",
    "55": "www.meuse.gouv.fr",
    "56": "www.morbihan.gouv.fr",
    "57": "www.moselle.gouv.fr",
    "58": "www.nievre.gouv.fr",
    "59": "www.nord.gouv.fr",
    "60": "www.oise.gouv.fr",
    "61": "www.orne.gouv.fr",
    "62": "www.pas-de-calais.gouv.fr",
    "63": "www.puy-de-dome.gouv.fr",
    "64": "www.pyrenees-atlantiques.gouv.fr",
    "65": "www.hautes-pyrenees.gouv.fr",
    "66": "www.pyrenees-orientales.gouv.fr",
    "67": "www.bas-rhin.gouv.fr",
    "68": "www.haut-rhin.gouv.fr",
    "69": "www.rhone.gouv.fr",
    "70": "www.haute-saone.gouv.fr",
    "71": "www.saone-et-loire.gouv.fr",
    "72": "www.sarthe.gouv.fr",
    "73": "www.savoie.gouv.fr",
    "74": "www.haute-savoie.gouv.fr",
    "75": "www.prefecturedepolice.interieur.gouv.fr",
    "76": "www.seine-maritime.gouv.fr",
    "77": "www.seine-et-marne.gouv.fr",
    "78": "www.yvelines.gouv.fr",
    "79": "www.deux-sevres.gouv.fr",
    "80": "www.somme.gouv.fr",
    "81": "www.tarn.gouv.fr",
    "82": "www.tarn-et-garonne.gouv.fr",
    "83": "www.var.gouv.fr",
    "84": "www.vaucluse.gouv.fr",
    "85": "www.vendee.gouv.fr",
    "86": "www.vienne.gouv.fr",
    "87": "www.haute-vienne.gouv.fr",
    "88": "www.vosges.gouv.fr",
    "89": "www.yonne.gouv.fr",
    "90": "www.territoire-de-belfort.gouv.fr",
    "91": "www.essonne.gouv.fr",
    "92": "www.hauts-de-seine.gouv.fr",
    "93": "www.seine-saint-denis.gouv.fr",
    "94": "www.val-de-marne.gouv.fr",
    "95": "www.val-d-oise.gouv.fr",
}

# Patterns de chemins à tester (par ordre de probabilité)
PATH_PATTERNS = [
    # Format B standard (le plus courant)
    ("/Publications/Recueil-des-actes-administratifs/RAA-{year}", "B"),
    ("/Publications/Recueil-des-Actes-Administratifs/RAA-{year}", "B"),
    ("/Publications/Recueils-des-actes-administratifs/RAA-{year}", "B"),
    ("/Publications/Recueil-des-actes-administratifs-RAA/RAA-{year}", "B"),
    ("/Publications/Recueils-des-actes-administratifs-RAA/RAA-{year}", "B"),
    # Variantes avec tiret différent
    ("/Publications/Recueil-des-actes-administratifs/Recueil-{year}", "B"),
    # Format avec "Annee"
    ("/Publications/Recueil-des-Actes-Administratifs/RAA-Annee-{year}", "D"),
    ("/Publications/Recueil-des-actes-administratifs/RAA-Annee-{year}", "D"),
    # Format A Nord
    ("/Publications/Recueils-des-actes-administratifs/RAA-du-departement-du-Nord/{year}/Janvier", "A_test"),
    # Format Gironde (annuel → sous-pages mois)
    ("/Publications/Recueil-des-Actes-Administratifs/Recueil-des-Actes-Administratifs-de-l-annee-{year}", "AM"),
    # Autres variantes vues
    ("/Politiques-publiques/Recueil-des-actes-administratifs/RAA-{year}", "B"),
    ("/Actions-de-l-Etat/Recueil-des-actes-administratifs/RAA-{year}", "B"),
    ("/content/download", None),  # certains sites mettent les PDFs directement
]

class BanDetected(Exception):
    pass

_consec_failures = 0

def make_client():
    return httpx.Client(http2=True, follow_redirects=True, timeout=20, headers=HEADERS)

def get(client, url):
    global _consec_failures
    try:
        r = client.get(url)
        if r.status_code == 200:
            _consec_failures = 0
            return r.text
        return None
    except Exception as e:
        if "disconnected" in str(e).lower() or "connection" in str(e).lower():
            _consec_failures += 1
            if _consec_failures >= BAN_THRESHOLD:
                raise BanDetected(f"Ban WAF détecté ({_consec_failures} échecs consécutifs)")
        return None

def has_pdf_links(html, base_url):
    """Retourne True si la page contient des liens PDF ou des sous-pages RAA."""
    if not html:
        return False, "no_html"
    pdfs = re.findall(r'href="[^"]+\.pdf"', html, re.I)
    if pdfs:
        return True, f"direct_pdfs:{len(pdfs)}"
    # Sous-pages RAA (format D)
    subs = re.findall(r'href="[^"]*RAA[^"]*\d{4}[^"]*"', html, re.I)
    if subs:
        return True, f"subpages:{len(subs)}"
    # Liens vers mois (format AM)
    months = re.findall(r'href="[^"]*(?:Janvier|Fevrier|Mars|Avril|Juin)[^"]*"', html, re.I)
    if months:
        return True, f"month_links:{len(months)}"
    return False, "no_pdfs"

def detect_format(html, domain, path, year):
    """Affine le format détecté en analysant le contenu."""
    if not html:
        return "B"
    # Pagination offset -> format D
    if '(offset)' in html:
        return "D"
    # Liens par mois -> format AM
    month_links = re.findall(r'href="([^"]*(?:Janvier|Fevrier|Mars|Avril|Mai|Juin|Juillet|Aout|Septembre|Octobre|Novembre|Decembre)[^"]*)"', html, re.I)
    if month_links and any(str(year) in l for l in month_links):
        return "AM"
    # Sous-pages individuelles -> format D
    subpage_pat = re.compile(r'href="(/[^"]*RAA[^"]*(?:special|spécial|\d{3})[^"]*)"', re.I)
    if subpage_pat.search(html):
        return "D"
    return "B"

def discover_dep(client, dep, domain, year=TEST_YEAR):
    base = f"https://{domain}"
    result = {"dep": dep, "domain": base, "path": None, "fmt": None, "info": "not_found"}

    # D'abord tester la page d'accueil pour trouver un lien RAA
    home = get(client, base)
    if home:
        # Chercher un lien RAA dans la page d'accueil
        raa_links = re.findall(r'href="(/[^"]*(?:[Rr]ecueil|RAA)[^"]*)"', home)
        if raa_links:
            result["info"] = f"raa_hint_from_home:{raa_links[0][:60]}"

    for path_tpl, hint_fmt in PATH_PATTERNS:
        if "{year}" in path_tpl:
            path = path_tpl.format(year=year)
        else:
            path = path_tpl

        url = base + path
        html = get(client, url)
        time.sleep(0.5)

        if not html:
            continue

        found, detail = has_pdf_links(html, url)
        if found:
            fmt = detect_format(html, domain, path, year)
            # Pour format A, reconstruire le template avec {year}/{month}
            if hint_fmt == "A_test":
                path_tpl = path_tpl.replace("/Janvier", "/{month}")
                fmt = "A"
            elif fmt == "AM":
                # Trouver le vrai template avec mois
                m = re.search(r'href="(/[^"]*' + str(year) + r'/([^/"]+))"', html)
                if m:
                    path_tpl = m.group(1).replace(m.group(2), "{month}")
                    fmt = "A"
            elif fmt == "D":
                # Garder le path sans {year} résolu si format D
                path_tpl = path_tpl if "{year}" in path_tpl else path_tpl.replace(str(year), "{year}")

            result.update({
                "path": path_tpl if "{year}" in path_tpl else path.replace(str(year), "{year}"),
                "fmt": fmt,
                "info": detail,
            })
            print(f"  [{dep}] TROUVE {fmt}: {path[:70]} ({detail})")
            return result

        # Même si pas de PDFs directs, garder comme candidat si 200
        if html and not result["path"]:
            result["info"] = f"200_no_pdfs:{path[:50]}"

    # Dernier recours : chercher via la page principale
    if home:
        raa_links = re.findall(r'href="(/[^"]*(?:[Rr]ecueil|RAA)[^"]*' + str(year) + r'[^"]*)"', home)
        if raa_links:
            path = raa_links[0]
            html = get(client, base + path)
            if html:
                found, detail = has_pdf_links(html, base + path)
                if found:
                    fmt = detect_format(html, domain, path, year)
                    result.update({
                        "path": path.replace(str(year), "{year}"),
                        "fmt": fmt,
                        "info": f"found_via_home:{detail}",
                    })
                    print(f"  [{dep}] TROUVE via home {fmt}: {path[:70]}")
                    return result

    print(f"  [{dep}] NON TROUVE ({result['info']})")
    return result

def main():
    parser = argparse.ArgumentParser()
    parser.add_argument("--dep", default=None, help="tester un seul dep (ex: 01)")
    parser.add_argument("--all", action="store_true", help="tester tous les 96 deps")
    parser.add_argument("--skip-known", action="store_true", help="ignorer les deps deja configures dans v2")
    args = parser.parse_args()

    KNOWN = {"01","02","06","13","31","33","34","35","38","44","57","59","60","67","69","76","77","78","91","92","93","94","95"}

    if args.dep:
        deps_to_test = [args.dep.zfill(2).upper()]
    elif args.all:
        deps_to_test = list(DOMAINS.keys())
        if args.skip_known:
            deps_to_test = [d for d in deps_to_test if d not in KNOWN]
    else:
        # Par défaut : les non-configurés seulement
        deps_to_test = [d for d in DOMAINS.keys() if d not in KNOWN]

    print(f"Découverte de {len(deps_to_test)} départements...")
    print("="*60)

    client = make_client()
    results = []
    found_count = 0

    for i, dep in enumerate(deps_to_test):
        domain = DOMAINS.get(dep)
        if not domain:
            print(f"  [{dep}] domaine inconnu")
            continue

        print(f"[{i+1}/{len(deps_to_test)}] {dep} - {domain}")
        try:
            r = discover_dep(client, dep, domain)
        except BanDetected as e:
            print(f"\nARRET — {e}")
            print(f"Dernier dep : {dep}. Relancer quand le ban est leve.")
            break
        results.append(r)
        if r["path"]:
            found_count += 1
        time.sleep(DELAY)

    # Afficher résumé
    print(f"\n{'='*60}")
    print(f"Résultat : {found_count}/{len(deps_to_test)} trouvés")
    print()

    # Générer le code Python à copier dans raa_scraper_v2.py
    found = [r for r in results if r["path"]]
    not_found = [r for r in results if not r["path"]]

    print("# ── A AJOUTER dans PREFECTURES de raa_scraper_v2.py ────")
    for r in found:
        fmt_str = f'"{r["fmt"]}"'
        print(f'    "{r["dep"]}": ("{r["domain"]}",')
        print(f'           "{r["path"]}",')
        print(f'           {fmt_str}, ""),')

    print()
    print("# ── NON TROUVES (à vérifier manuellement) ───────────────")
    for r in not_found:
        print(f'    # "{r["dep"]}": {r["domain"]} -> {r["info"]}')

    # Sauvegarder le résultat JSON
    out_path = "C:/Temp/raa_discovered.json"
    with open(out_path, "w", encoding="utf-8") as f:
        json.dump(results, f, ensure_ascii=False, indent=2)
    print(f"\nRésultats JSON sauvegardés dans {out_path}")

if __name__ == "__main__":
    main()
