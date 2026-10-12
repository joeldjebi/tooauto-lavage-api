# Fidelite des lavages

Le dispositif de fidelite reste base sur les modeles existants `fidelites` et `recompenses`. Les evolutions ajoutent le seuil configurable, les cartes successives et le journal de passages.

## Configuration du seuil

Un manager configure le nombre de lavages necessaires par etablissement. La valeur est copiee dans `fidelites.total_cases` a la creation de chaque carte; changer le reglage n'affecte donc pas les cartes deja ouvertes.

```http
GET /api/carte-fidelite/configuration
PUT /api/carte-fidelite/configuration
Authorization: Bearer TOKEN_JWT
```

Payload du `PUT`:

```json
{
  "nombre_lavages_requis": 8
}
```

Valeur autorisee: entier entre 1 et 100. Seul un compte de role manager (`role = 1`) peut la modifier. Le defaut reste 10.

## Attribution automatique

La finalisation de `POST /api/attributions/terminer-lavage/{attributionId}` ajoute un passage a la carte du couple usager-vehicule, au sein du lavage/station. Chaque passage est relie a `attribution_vehicules.id` dans `fidelite_passages`; une contrainte unique empeche de compter deux fois la meme attribution.

Quand `cases_remplies` atteint `total_cases`:

1. la carte existante passe a `statut = complete`, en conservant toutes ses cases;
2. une recompense `lavage_gratuit` est creee dans `recompenses`;
3. une nouvelle ligne `fidelites` est creee avec `statut = en_cours` et le meme seuil que la carte terminee.

La reponse de fin de lavage indique `recompense_attribuee`, `nouvelle_carte`, `carte_id`, la progression de la nouvelle carte, et les champs de l'attribution terminee.

## Historique

`GET /api/carte-fidelite/carte/{usager_id}` retourne les cartes de l'usager chez ce lavage. Ajouter `/{matricule_vehicule}` pour filtrer un vehicule. Chaque carte expose son identifiant, son statut, ses dates, sa progression et la liste `passages` contenant la prestation, sa date et son attribution.

Les anciennes lignes `fidelites` sont conservees et recoivent `statut = en_cours` par defaut lors de la migration. L'historique detaille `fidelite_passages` commence avec les passages ajoutes apres le deploiement; les attributions historiques ne sont pas converties automatiquement.

## Endpoint historique add-case

`POST /api/carte-fidelite/add-case` est conserve. Il accepte toujours `qrcode`; un `attribution_id` termine peut etre fourni pour identifier precisement le lavage. Sans identifiant, le controleur rattache le passage a la derniere attribution terminee du jour pour ce vehicule, si elle existe; sinon il enregistre un passage manuel.

Lorsqu'une attribution terminee a deja ete comptee, un nouvel appel avec cette attribution ne cree pas une deuxieme case. Pour le flux standard, l'application doit laisser la finalisation de l'attribution gerer le passage automatiquement.

## Schema ajoute

La migration `2026_10_12_000000_add_configurable_loyalty_cards.php` ajoute `lavages.fidelite_total_cases`, `fidelites.statut`, `fidelites.completed_at` et la table `fidelite_passages`.
