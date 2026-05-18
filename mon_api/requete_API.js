
async function chargerDonnees() {
    const url = "https://data.economie.gouv.fr/api/explore/v2.1/catalog/datasets/fiscalite-locale-des-particuliers-geo/records?select=taux_plein_teom&where=exercice=2024&and&dep=75";
    const response = await fetch(url);
    const data = await response.json();

    const records = data.results;

    const headerRow = document.getElementById("header");
    const body = document.getElementById("body");

    headerRow.innerHTML = "";
    body.innerHTML = "";

    if (records.length === 0) return;

    // Créer les en-têtes automatiquement
    Object.keys(records[0]).forEach(key => {
        const th = document.createElement("th");
        th.textContent = key;
        headerRow.appendChild(th);
    });

    // Remplir les lignes
    records.forEach(record => {
        const tr = document.createElement("tr");
        Object.values(record).forEach(value => {
            const td = document.createElement("td");
            td.textContent = value;
            tr.appendChild(td);
        });
        body.appendChild(tr);
    });
}


https://admin.powerplatform.microsoft.com/settingredirect/9a7fd682-e375-4298-87ec-b8dafff609d6/appusers


result = processing.run(
    "native:aggregate",
    {
        'INPUT': 'C:/02_WEBcarto/Data/PCI_vecteur_section.shp',

        'GROUP_BY': '"section" || \'_\' || "code_insee" || \'_\' || "code_com" || \'_\' || "com_abs" || \'_\' || "code_arr"',

        'AGGREGATES': [
            {'aggregate':'first_value','input':'"section"','name':'section','type':10,'type_name':'text'},
            {'aggregate':'first_value','input':'"code_dep"','name':'code_dep','type':10,'type_name':'text'},
            {'aggregate':'first_value','input':'"code_com"','name':'code_com','type':10,'type_name':'text'},
            {'aggregate':'first_value','input':'"code_insee"','name':'code_insee','type':10,'type_name':'text'}
        ],

        'GEOMETRY_AGGREGATE': 'union',

        'OUTPUT': 'TEMPORARY_OUTPUT'
    }
)
QgsProject.instance().addMapLayer(result['OUTPUT'])