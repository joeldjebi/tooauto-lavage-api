# Système de Carte de Fidélité Digitale

## Vue d'ensemble

Le nouveau système de fidélité remplace l'ancien système de points par un système de "carte de fidélité digitale" inspiré des tontines traditionnelles. Chaque client a une carte avec des cases à cocher **par véhicule**.

## Fonctionnement

### Structure de la carte
- **Nombre de cases** : 10 cases par défaut (configurable)
- **Cases cochées** : Incrémentées à chaque lavage
- **Récompense** : Quand toutes les cases sont cochées, le client gagne une récompense
- **Par véhicule** : Chaque véhicule a sa propre carte de fidélité

### Cycle de récompense
1. Client fait un lavage → 1 case cochée sur la carte du véhicule
2. Après 10 lavages → Carte complète pour ce véhicule
3. Récompense gagnée → Carte réinitialisée pour ce véhicule
4. Nouveau cycle commence

## Modifications de la base de données

### Table `fidelites`
```sql
-- Structure complète de la table
CREATE TABLE `fidelites` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `usager_id` bigint(20) NOT NULL,           -- ID du client fidélisé
  `lavage_id` bigint(20) DEFAULT NULL,       -- ID du lavage qui ajoute les cases
  `matricule_vehicule` varchar(20) NOT NULL, -- Matricule du véhicule fidélisé
  `cases_remplies` int(11) NOT NULL,         -- Cases cochées actuellement (remplace 'points')
  `total_cases` int(11) DEFAULT 10,          -- Nombre total de cases sur la carte
  `recompenses_gagnees` int(11) DEFAULT 0,   -- Nombre de récompenses gagnées
  `derniere_recompense` timestamp NULL,      -- Date de la dernière récompense
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
);
```

### Relations
- **`usager_id`** → `users.id` (le client qui est fidélisé)
- **`lavage_id`** → `users.id` (le lavage qui ajoute les cases)
- **`matricule_vehicule`** → `vehicules.matricule` (le véhicule fidélisé)

## Nouvelles API Endpoints

### 1. Ajouter une case
```
POST /api/carte-fidelite/add-case
{
    "qrcode": "matricule_du_vehicule"
}
```

**Réponse :**
```json
{
    "success": true,
    "message": "Case ajoutée avec succès à votre carte de fidélité.",
    "recompense_gagnee": false,
    "progression": {
        "cases_remplies": 7,
        "total_cases": 10,
        "cases_restantes": 3,
        "pourcentage": 70.0
    },
    "usager": {
        "id": 1,
        "name": "John Doe",
        "email": "john@example.com"
    },
    "vehicule": {
        "matricule": "ABC123",
        "marque": "Toyota",
        "modele": "Corolla"
    }
}
```

### 2. Consulter la carte (tous les véhicules d'un usager)
```
GET /api/carte-fidelite/carte/{usager_id}
```

### 3. Consulter la carte d'un véhicule spécifique
```
GET /api/carte-fidelite/carte/{usager_id}/{matricule_vehicule}
```

**Réponse :**
```json
{
    "success": true,
    "cartes": [
        {
            "matricule_vehicule": "ABC123",
            "cases_remplies": 7,
            "total_cases": 10,
            "cases_restantes": 3,
            "pourcentage": 70.0,
            "recompenses_gagnees": 2,
            "derniere_recompense": "2025-08-18T14:30:00.000000Z",
            "carte_complete": false
        },
        {
            "matricule_vehicule": "XYZ789",
            "cases_remplies": 3,
            "total_cases": 10,
            "cases_restantes": 7,
            "pourcentage": 30.0,
            "recompenses_gagnees": 0,
            "derniere_recompense": null,
            "carte_complete": false
        }
    ],
    "usager": {
        "id": 1,
        "name": "John Doe",
        "email": "john@example.com"
    }
}
```

### 4. Consulter les récompenses (tous les véhicules)
```
GET /api/carte-fidelite/recompenses/{usager_id}
```

### 5. Consulter les récompenses d'un véhicule spécifique
```
GET /api/carte-fidelite/recompenses/{usager_id}/{matricule_vehicule}
```

**Réponse :**
```json
{
    "success": true,
    "recompenses": {
        "total_gagnees": 5,
        "derniere_recompense": "2025-08-18T14:30:00.000000Z",
        "prochaine_recompense": "8 cases restantes"
    },
    "usager": {
        "id": 1,
        "name": "John Doe",
        "email": "john@example.com"
    }
}
```

### 6. Liste des usagers fidèles
```
GET /api/carte-fidelite/usagers-fideles
```

**Réponse :**
```json
{
    "success": true,
    "data": [
        {
            "usager": {
                "id": 1,
                "name": "John Doe",
                "email": "john@example.com"
            },
            "vehicules": [
                {
                    "matricule_vehicule": "ABC123",
                    "progression": {
                        "cases_remplies": 8,
                        "total_cases": 10,
                        "cases_restantes": 2,
                        "pourcentage": 80.0,
                        "carte_complete": false
                    },
                    "recompenses_gagnees": 2
                },
                {
                    "matricule_vehicule": "XYZ789",
                    "progression": {
                        "cases_remplies": 3,
                        "total_cases": 10,
                        "cases_restantes": 7,
                        "pourcentage": 30.0,
                        "carte_complete": false
                    },
                    "recompenses_gagnees": 0
                }
            ]
        }
    ],
    "total_usagers": 1
}
```

### 7. Statistiques de fidélité
```
GET /api/carte-fidelite/statistiques
```

**Réponse :**
```json
{
    "success": true,
    "statistiques": {
        "total_usagers": 25,
        "total_vehicules": 35,
        "total_recompenses_distribuees": 15,
        "total_cases_ajoutees": 180,
        "moyenne_cases_par_vehicule": 5.14
    },
    "top_usagers": [
        {
            "usager": {
                "id": 1,
                "name": "John Doe",
                "email": "john@example.com"
            },
            "matricule_vehicule": "ABC123",
            "recompenses_gagnees": 3,
            "total_cases": 28
        }
    ]
}
```

## Anciennes API (dépréciées mais compatibles)

Les anciennes routes `/api/fidelite/*` continuent de fonctionner mais utilisent maintenant le nouveau système en arrière-plan.

## Avantages du nouveau système

1. **Visuel** : Plus facile à comprendre pour les clients
2. **Motivant** : Voir le progrès vers la récompense
3. **Flexible** : Nombre de cases configurable
4. **Traditionnel** : S'inspire du système des tontines
5. **Gamification** : Aspect ludique avec les cases à cocher
6. **Statistiques** : Suivi détaillé des performances
7. **Par véhicule** : Chaque véhicule a sa propre progression

## Migration

Pour appliquer les changements à la base de données, exécutez le script SQL :
```sql
-- Voir le fichier modify_fidelites_table.sql
```

## Configuration

Le nombre de cases par carte peut être modifié en changeant la valeur par défaut dans :
- Migration : `total_cases` DEFAULT 10
- Modèle : `$fidelite->total_cases = 10`
- Contrôleur : `$fidelite->total_cases = 10`

## Logique métier

### Ajout d'une case
1. **Authentification** : Le lavage doit être authentifié
2. **Validation** : Le QR code doit correspondre à un véhicule existant
3. **Récupération usager** : L'usager propriétaire du véhicule est identifié
4. **Ajout** : Une case est ajoutée à la carte de fidélité du véhicule spécifique
5. **Vérification récompense** : Si la carte est complète, une récompense est accordée

### Relations importantes
- **`usager_id`** : Identifie le client qui accumule les cases
- **`lavage_id`** : Identifie le lavage qui ajoute les cases (pour le suivi)
- **`matricule_vehicule`** : Identifie le véhicule spécifique fidélisé
- **Carte unique** : Chaque combinaison `usager_id` + `lavage_id` + `matricule_vehicule` a sa propre carte

## Améliorations apportées

### ✅ **Support des véhicules**
- Chaque véhicule a sa propre carte de fidélité
- Suivi individuel par véhicule
- Statistiques par véhicule

### ✅ **Validation renforcée**
- Vérification de l'existence de l'usager avant traitement
- Messages d'erreur plus précis

### ✅ **Données enrichies**
- Informations de l'usager et du véhicule incluses dans toutes les réponses
- Statistiques détaillées pour le lavage

### ✅ **Nouvelle fonctionnalité**
- **Statistiques de fidélité** : Vue d'ensemble des performances
- **Top usagers** : Classement des clients les plus fidèles
- **Métriques** : Total des récompenses distribuées, moyenne des cases par véhicule

### ✅ **Gestion d'erreurs améliorée**
- Vérifications supplémentaires pour éviter les erreurs
- Messages d'erreur plus informatifs

## Cas d'usage

### Scénario 1 : Usager avec un seul véhicule
- Une seule carte de fidélité
- Progression simple et claire

### Scénario 2 : Usager avec plusieurs véhicules
- Une carte par véhicule
- Chaque véhicule peut avoir des récompenses différentes
- Suivi individuel de chaque véhicule

### Scénario 3 : Station de lavage
- Vue d'ensemble de tous les usagers et véhicules
- Statistiques globales et par véhicule
- Gestion des récompenses par véhicule
