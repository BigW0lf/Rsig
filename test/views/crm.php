<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Mini SIG — CRM</title>
    <link rel="stylesheet" href="assets/style.css">
</head>
<body>

<nav>
    <a href="/">Carte</a>
    <a href="/donnees">Données</a>
    <a href="/maj-bdd">Mise à jour BDD</a>
    <a href="/crm" class="active">CRM</a>
    <a href="https://localhost:8443/nifi" target="_blank" class="nifi-link">NiFi</a>
</nav>

<div class="page-content">
    <h1>CRM Dynamics 365</h1>

    <div class="card">
    <h2>Connexion CRM - Secteurs</h2>

    <form id="crmForm">
        <div class="form-row" style="flex-direction:column; align-items:flex-start; gap:10px;">
            <label>Entité (valeur) :</label>
            <input type="text" id="feature" placeholder="Ex: 75056" required>
            <label>Champ :</label>
            <input type="text" id="field" placeholder="Ex: apo_codeinsee" required>
            <label>Table :</label>
            <input type="text" id="table" placeholder="Ex: apo_communes" required>
            <button type="submit" class="btn">Rechercher</button>
        </div>
    </form>

    <div id="result" class="alert alert-info" style="margin-top:16px; font-family: monospace; white-space: pre-wrap;">Résultat affiché ici…</div>
    </div>
</div>

    <script>
        document.getElementById("crmForm").addEventListener("submit", function(e) {
            e.preventDefault();

            const feature = document.getElementById("feature").value;
            const field = document.getElementById("field").value;
            const table = document.getElementById("table").value;

            const resultDiv = document.getElementById("result");
            resultDiv.innerHTML = "Chargement...";

            fetch("/api/dynamics", {
                method: "POST",
                headers: {
                    "Content-Type": "application/json"
                },
                body: JSON.stringify({
                    value: feature,
                    field: field,
                    table: table
                })
            })
            .then(res => res.json())
            .then(data => {
                console.log("Résultat :", data);

                if (data.value && data.value.length > 0) {
                    resultDiv.innerHTML = "<pre>" + JSON.stringify(data.value[0], null, 2) + "</pre>";
                } else {
                    resultDiv.innerHTML = "Aucun résultat trouvé.";
                }
            })
            .catch(err => {
                console.error(err);
                resultDiv.innerHTML = "Erreur serveur.";
            });
        });
    </script>


</body>
</html>