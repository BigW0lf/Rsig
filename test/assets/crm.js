fetch("/api/apo_secteurs/75056")
  .then(response => {
    if (!response.ok) {
      throw new Error("Erreur serveur");
    }
    return response.json();
  })
  .then(data => {
    console.log("Données reçues :", data);

    // Si réponse Dynamics classique
    if (data.value) {
      data.value.forEach(item => {
        console.log("Secteur :", item);
      });
    }
  })
  .catch(error => {
    console.error("Erreur :", error);
  });