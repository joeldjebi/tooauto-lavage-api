# Systeme de campagnes de reduction TooAuto

## Objectif et perimetre

Ce document decrit l'implementation actuelle des campagnes de reduction dans `tooauto-lavage-api`. Il sert de guide de reprise pour un agent IA ou un developpeur.

Le principe fonctionnel est le suivant:

- l'usager presente une carte de reduction identifiee par `card_code` et `qr_code`;
- l'etablissement cree ses propres campagnes et choisit les prestations concernees;
- plusieurs campagnes peuvent etre actives simultanement pour un meme etablissement;
- lors du scan, la campagne de l'etablissement est appliquee a la carte valide de l'usager;
- chaque application est historisee avec un instantane des prix et de la reduction.

Les etablissements ne generent pas les cartes usagers. La generation est rattachee a la creation de l'abonnement usager.

## Fichiers principaux

| Fichier | Role |
| --- | --- |
| `app/Http/Controllers/API/ReductionCampaignController.php` | CRUD, campagnes actives, verification de carte, application et historique |
| `app/Models/ReductionCampaign.php` | Modele de `reduction_campaigns` |
| `app/Models/ReductionCampaignUsage.php` | Modele de `reduction_campaign_usages` et relations d'historique |
| `app/Models/UserReductionCard.php` | Carte unique scannee par code ou QR code |
| `app/Services/WasabiService.php` | Envoi, suppression et URL temporaire des images |
| `app/Services/ReductionCardService.php` | Attribution des cartes liees au forfait d'un abonnement |
| `app/Http/Controllers/API/UsagerController.php` | Abonnement automatique dans `registerUsager` |
| `routes/api.php` | Routes protegees par `auth:api` |
| `config/services.php` | Configuration de l'abonnement automatique |

## Donnees attendues

Les migrations n'ont volontairement pas ete creees dans ce projet. Les tables sont gerees manuellement. Le code suppose au minimum les colonnes suivantes.

### `reduction_campaigns`

- `id`
- `establishment_type`: `etablissement`, `lavage` ou `station`
- `establishment_id`
- `name`, `image`, `description`
- `product_or_service`
- `discount_type`: valeur persistante `percentage` ou `fixed`
- `discount_value`
- `normal_price`, `promotional_price`
- `date_debut`, `date_fin`
- `quantity_available`, `quantity_used`
- `conditions`, `statut`, `created_by`
- timestamps Laravel

### `reduction_campaign_usages`

- `id`, `reduction_campaign_id`, `user_reduction_card_id`, `user_id`
- instantane: `campaign_name`, `product_or_service`, `discount_type`, `discount_value`
- instantane financier: `normal_price`, `promotional_price`, `montant_initial`, `montant_reduction`, `montant_final`
- `applied_by_id`, `establishment_type`, `establishment_id`, `notes`, `used_at`
- timestamps Laravel

### Tables associees

- `user_reduction_cards`: carte de l'usager et periode de validite
- `reduction_cards`: configurations rattachees aux forfaits pour l'attribution initiale
- `abonnement_usagers`: abonnement qui declenche l'attribution
- `forfait_usagers`: forfait recherche par libelle
- `type_lavages`: prestations d'un lavage, avec `id`, `libelle` et `lavage_id`

## Routes des campagnes

Toutes ces routes utilisent le prefixe `/api/reduction-campaigns` et le middleware `auth:api`.

| Methode | Route | Action |
| --- | --- | --- |
| `GET` | `/` | Liste paginee d'un etablissement |
| `POST` | `/` | Creation d'une campagne |
| `GET` | `/active` | Liste paginee des campagnes actuellement utilisables |
| `POST` | `/verify-card` | Verification d'une carte et apercu de la campagne |
| `POST` | `/apply` | Application transactionnelle et historisation |
| `GET` | `/usages` | Historique filtre d'un etablissement |
| `GET` | `/{reductionCampaign}` | Detail d'une campagne |
| `POST`, `PUT` | `/{reductionCampaign}` | Mise a jour, `POST` facilitant le multipart |
| `DELETE` | `/{reductionCampaign}` | Suppression de la campagne et de son image Wasabi |

Les routes historiques `/api/reduction-cards/*` correspondent a l'ancien systeme et coexistent encore avec les campagnes.

## Creation et mise a jour

La creation doit etre envoyee en `multipart/form-data` lorsqu'une image est presente. Exemple:

```bash
curl -X POST "http://127.0.0.1:8000/api/reduction-campaigns" \
  -H "Authorization: Bearer TOKEN" \
  -H "Accept: application/json" \
  -F "establishment_type=lavage" \
  -F "establishment_id=1" \
  -F "name=Promo lavage" \
  -F "description=Offre sur plusieurs prestations" \
  -F "product_or_service[]=1" \
  -F "product_or_service[]=2" \
  -F "discount_type=percentage" \
  -F "discount_value=20" \
  -F "normal_price=10000" \
  -F "date_debut=2026-09-01" \
  -F "date_fin=2026-09-30" \
  -F "quantity_available=100" \
  -F "conditions=Une utilisation par passage" \
  -F "statut=1" \
  -F "image=@/chemin/image.jpg"
```

Champs et comportement:

- `product_or_service` accepte une chaine ou une liste. Pour `lavage`, les valeurs numeriques sont controlees dans `type_lavages` avec le `lavage_id` de la campagne, puis stockees sous la forme `1,2`.
- `discount_type` accepte `percentage`, `fixed` ou l'alias entrant `montant`. `montant` est normalise en `fixed` avant stockage.
- `discount_value` represente le pourcentage ou le montant fixe suivant le type.
- `promotional_price` n'est pas pilote par le client. Il est recalcule par le serveur.
- `statut` vaut `1` par defaut. L'activation d'une campagne ne desactive plus les autres campagnes de l'etablissement.
- `quantity_used` vaut `0` a la creation.
- `created_by` provient de `auth('api')->id()`.

Calcul du prix promotionnel:

```text
percentage: normal_price - (normal_price * discount_value / 100)
fixed:      normal_price - discount_value
```

Le resultat est arrondi a deux decimales et ne peut pas devenir negatif. Un pourcentage superieur a 100 et un montant fixe superieur au prix normal sont refuses.

Apres la premiere utilisation, toute tentative de modifier `discount_type`, `discount_value`, `normal_price` ou `promotional_price` est refusee avec HTTP 422.

## Images Wasabi

Le champ `image` doit etre un vrai fichier sous la cle multipart `image`. Une URL, un chemin texte ou du base64 sont refuses.

Formats acceptes: JPEG, PNG, WEBP et GIF. Taille maximale: 2 MiB. La validation utilise aussi `getimagesize()` afin de verifier que le contenu est lisible. Le type `application/octet-stream` est tolere uniquement si le contenu est une image valide et si l'extension est autorisee.

Le fichier est enregistre sous `reduction-campaigns/campaign-...`. La base conserve ce chemin. Les reponses exposent:

- `image`: chemin interne Wasabi;
- `image_url`: URL temporaire signee par `WasabiService::temporaryUrl()` ou `null` si la signature echoue.

Lors d'un remplacement, la nouvelle image est envoyee puis l'ancienne est supprimee. L'echec silencieux de suppression de l'ancien fichier ne bloque pas la mise a jour.

Pour remplacer une image, utiliser `POST /api/reduction-campaigns/{id}` en `multipart/form-data`. Une requete `PUT multipart/form-data` native peut ne pas alimenter les fichiers PHP; le controleur detecte ce cas et retourne maintenant une erreur explicite au lieu de conserver silencieusement l'ancienne image.

```bash
curl -X POST "http://127.0.0.1:8000/api/reduction-campaigns/12" \
  -H "Authorization: Bearer TOKEN" \
  -H "Accept: application/json" \
  -F "image=@/chemin/nouvelle-image.jpg"
```

`WasabiService::uploadFile()` verifie le resultat de l'ecriture et l'existence du fichier distant avant de retourner son chemin. Le controleur met ensuite le nouveau chemin en base, puis supprime l'ancienne image. Si la mise a jour SQL echoue, la nouvelle image est supprimee et l'ancienne reference est preservee.

## Campagnes actives

`activeCampaignQuery()` considere une campagne utilisable lorsque:

- son type et son identifiant correspondent a l'etablissement;
- `statut = 1`;
- `date_debut <= aujourd'hui <= date_fin`;
- `quantity_available` est `NULL`, ou `quantity_used < quantity_available`.

Les resultats sont tries du plus recent au plus ancien. `GET /active` renvoie toutes les campagnes correspondantes sous forme paginee.

Attention: `verifyCard()` et `apply()` appellent encore `first()` sur cette requete. Avec plusieurs campagnes actives, ils choisissent donc implicitement la campagne la plus recemment creee. Pour appliquer une campagne choisie par l'interface, une evolution devra introduire et valider un `campaign_id` dans ces deux endpoints, tout en verifiant qu'il appartient bien a l'etablissement et reste utilisable.

## Verification et application d'une carte

Payload de verification actuel:

```json
{
  "qr_code": "TOOAUTO-REDUCTION-XXXXXXXXXXXXXXXXXX",
  "establishment_type": "lavage",
  "establishment_id": 1
}
```

`card_code` peut remplacer `qr_code`. La carte est refusee si elle est introuvable, inactive, pas encore commencee ou expiree.

Payload d'application actuel:

```json
{
  "card_code": "RC-1709-XXXXXXXX",
  "establishment_type": "lavage",
  "establishment_id": 1,
  "applied_by_id": 12,
  "notes": "Application en caisse"
}
```

L'application execute dans une transaction:

1. recherche et verrouillage de la campagne eligible;
2. nouvelle verification de la quantite;
3. calcul des montants depuis les prix de la campagne;
4. creation d'une ligne dans `reduction_campaign_usages`;
5. increment atomique de `quantity_used`.

L'historique conserve des champs instantanes afin de rester lisible apres une modification ou une suppression de campagne.

## Reponse campagne

`formatCampaign()` uniformise les reponses. En plus des colonnes stockees, il ajoute:

- `image_url`: URL Wasabi signee;
- `product_or_service_ids`: identifiants numeriques extraits;
- `product_or_service_libelles`: libelles resolus dans `type_lavages` pour un lavage;
- `montant_reduction`: difference entre prix normal et prix promotionnel;
- `quantity_remaining`: quantite disponible moins quantite utilisee, ou `null` sans plafond.

Pour `station` et `etablissement`, le code retourne actuellement les valeurs textuelles de `product_or_service`. La resolution depuis `type_prestation_station_services` et `type_de_prestations` n'est pas encore implementee dans ce controleur.

## Historique

`GET /api/reduction-campaigns/usages` exige `establishment_type` et `establishment_id`. Filtres optionnels:

- `date_debut`, `date_fin`;
- `user_id` ou `usager`;
- `campaign_id` ou `campaign`;
- `montant`, `montant_min`, `montant_max`;
- `per_page`, entre 1 et 100.

La recherche `usager` porte sur `name`, `nom`, `prenoms` et `mobile`. Les filtres de montant portent actuellement sur `montant_initial`.

## Abonnement et attribution automatique des cartes

Configuration:

```env
REGISTER_AUTO_ABONNEMENT=true
REGISTER_AUTO_ABONNEMENT_FORFAIT=FREEMIUM
```

`config/services.php` expose ces valeurs via `services.register_auto_abonnement`. Les forfaits autorises sont `FREEMIUM`, `PERSONNEL` et `FAMILLE`.

Dans `UsagerController::registerUsager`, la creation de l'usager, du vehicule, de l'abonnement et des cartes se trouve dans la meme transaction. Lorsque l'option est active:

- le forfait actif est recherche avec `UPPER(TRIM(libelle))`;
- l'abonnement commence aujourd'hui et se termine apres `forfait.duree` mois;
- `statut = 1` et `is_free = 1`;
- `ReductionCardService::assignCardsToSubscription()` attribue toutes les configurations actives du forfait;
- `firstOrCreate` evite un doublon pour `reduction_card_id + abonnement_usager_id`.

En consequence, le code actuel peut creer plusieurs lignes `user_reduction_cards` pour un meme usager si plusieurs configurations `reduction_cards` actives sont rattachees au forfait. Cela differe de l'objectif metier evoque d'une seule carte globale par usager. Cette cardinalite doit etre tranchee explicitement avant de modifier le service ou les contraintes SQL.

Formats generes:

- `card_code`: `RC-ddmm-XXXXXXXX`;
- `qr_code`: `TOOAUTO-REDUCTION-XXXXXXXXXXXXXXXXXX`.

Une erreur d'abonnement ou de carte provoque le rollback complet de la creation de l'usager. Lorsque l'option est desactivee, la reponse historique reste inchangee; lorsqu'elle est active, la reponse contient aussi `abonnement`.

## Limites et points de vigilance

- Plusieurs campagnes actives sont autorisees, mais l'application explicite d'une campagne n'est pas encore definie dans le payload.
- L'objectif d'une carte globale unique par usager n'est pas encore garanti par `ReductionCardService`, qui fonctionne par configuration de reduction et par abonnement.
- Les routes sont authentifiees, mais le controleur ne verifie pas actuellement que l'utilisateur connecte possede `establishment_type + establishment_id`.
- `applied_by_id` vient du payload et n'est pas force a `auth('api')->id()`.
- Le detail et la suppression par route model binding ne sont pas scopes a l'etablissement connecte.
- La suppression de campagne peut entrer en conflit avec une contrainte etrangere d'historique selon la configuration SQL.
- Lors d'une mise a jour d'image, l'ancienne image est supprimee avant de savoir si la mise a jour SQL reussira.
- Il n'existe pas encore de tests metier dedies aux campagnes; la suite actuelle ne contient que les tests d'exemple.

Ces points doivent etre traites comme des constats de l'etat actuel, pas comme des autorisations de modifier le contrat sans demande explicite.

## Verification apres modification

Executer au minimum:

```bash
/opt/homebrew/bin/php -l app/Http/Controllers/API/ReductionCampaignController.php
/opt/homebrew/bin/php artisan test
/opt/homebrew/bin/php artisan route:list --path=reduction-campaigns
```

Ajouter le lint des autres fichiers PHP modifies. Pour les changements d'image, tester un vrai fichier multipart valide, un fichier superieur a 2 MiB, une fausse image et un envoi `application/octet-stream` avec extension valide.
