-- Indexes spatiaux GIST (si pas déjà présents)
CREATE INDEX IF NOT EXISTS idx_taux_clean_geom        ON taux_clean        USING GIST (geom);
CREATE INDEX IF NOT EXISTS idx_coeff_loc_final_geom   ON coeff_loc_final   USING GIST (geom);
CREATE INDEX IF NOT EXISTS idx_sections_2025_geom     ON sections_2025     USING GIST (geom);
CREATE INDEX IF NOT EXISTS idx_dossier_acc_geo_geom   ON dossier_acc_geo   USING GIST ((geom::geometry));

-- Indexes attributaires pour les jointures et filtres fréquents
CREATE INDEX IF NOT EXISTS idx_tarifs_pivot_cat       ON tarifs_pivot (categorie);
CREATE INDEX IF NOT EXISTS idx_tarifs_pivot_dep       ON tarifs_pivot (dep);
CREATE INDEX IF NOT EXISTS idx_sections_2025_dep      ON sections_2025 (code_dep);
CREATE INDEX IF NOT EXISTS idx_taux_clean_com         ON taux_clean (com);

-- Statistiques à jour pour le planificateur de requêtes
ANALYZE taux_clean;
ANALYZE coeff_loc_final;
ANALYZE sections_2025;
ANALYZE dossier_acc_geo;
ANALYZE tarifs_pivot;
