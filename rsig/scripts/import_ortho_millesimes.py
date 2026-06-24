"""
Import millesime_acq_ortho.csv → PostgreSQL table ortho_millesimes_dept

Structure cible (forme longue) :
    code_dept  VARCHAR(3)   -- 01, 2A, 971, etc.
    nom_dept   TEXT
    campagne   VARCHAR(9)   -- 'actuelle' | '2021-2023' | '2016-2020' | ...
    annee_acq  SMALLINT     -- année max d'acquisition dans la campagne

Usage : python import_ortho_millesimes.py
"""
import csv
import psycopg2
import re

CSV_PATH  = r"C:\_DATA\millesime_acq_ortho.csv"
DB_PARAMS = dict(host="localhost", port=5432, dbname="mabase", user="postgres", password="postgres")

CAMPAGNES = {
    "actuelle":  (2024, 2025),
    "2021-2023": (2021, 2023),
    "2016-2020": (2016, 2020),
    "2011-2015": (2011, 2015),
    "2006-2010": (2006, 2010),
    "2000-2005": (2000, 2005),
}

def normalize_dep(s):
    s = s.strip().lstrip("﻿")
    if re.match(r"^\d$", s):
        return "0" + s
    return s

def parse_year(val):
    m = re.match(r"(\d{4})", val.strip())
    return int(m.group(1)) if m else None

def main():
    rows = []
    with open(CSV_PATH, encoding="utf-8-sig", newline="") as f:
        reader = csv.reader(f, delimiter=";")
        header = next(reader)
        # header[0]=Numéro, header[1]=Nom, header[2..]=années 2025→2000
        year_cols = {}
        for i, col in enumerate(header):
            try:
                y = int(col.strip())
                year_cols[y] = i
            except ValueError:
                pass

        for row in reader:
            if not row or not row[0].strip():
                continue
            code = normalize_dep(row[0])
            nom  = row[1].strip()

            for camp, (ymin, ymax) in CAMPAGNES.items():
                annee_max = None
                for y in range(ymax, ymin - 1, -1):
                    idx = year_cols.get(y)
                    if idx is None or idx >= len(row):
                        continue
                    v = parse_year(row[idx])
                    if v is not None:
                        if annee_max is None or v > annee_max:
                            annee_max = v
                if annee_max is not None:
                    rows.append((code, nom, camp, annee_max))

    print(f"{len(rows)} enregistrements à insérer")

    conn = psycopg2.connect(**DB_PARAMS)
    cur  = conn.cursor()

    cur.execute("""
        DROP TABLE IF EXISTS ortho_millesimes_dept;
        CREATE TABLE ortho_millesimes_dept (
            code_dept  VARCHAR(3)  NOT NULL,
            nom_dept   TEXT        NOT NULL,
            campagne   VARCHAR(9)  NOT NULL,
            annee_acq  SMALLINT    NOT NULL,
            PRIMARY KEY (code_dept, campagne)
        );
        CREATE INDEX ON ortho_millesimes_dept (campagne);
        COMMENT ON TABLE ortho_millesimes_dept IS
            'Millésimes acquisition ortho IGN par département et campagne — source C:/_DATA/millesime_acq_ortho.csv';
    """)

    cur.executemany(
        "INSERT INTO ortho_millesimes_dept (code_dept, nom_dept, campagne, annee_acq) VALUES (%s,%s,%s,%s)",
        rows
    )

    conn.commit()
    print("Table ortho_millesimes_dept créée et peuplée.")

    # Contrôle rapide
    cur.execute("SELECT campagne, COUNT(*) FROM ortho_millesimes_dept GROUP BY campagne ORDER BY campagne")
    for camp, cnt in cur.fetchall():
        print(f"  {camp}: {cnt} depts")

    cur.close()
    conn.close()

if __name__ == "__main__":
    main()
