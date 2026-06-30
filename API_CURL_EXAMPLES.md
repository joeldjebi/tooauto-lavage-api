# Exemples cURL pour l'API de Carte de Fidélité

## Configuration de base
```bash
# URL de base de l'API
BASE_URL="http://localhost:8000/api"

# Token d'authentification (à remplacer par votre token)
TOKEN="votre_token_jwt_ici"

# Headers communs
HEADERS="-H 'Content-Type: application/json' -H 'Authorization: Bearer $TOKEN'"
```

## 1. Ajouter une case à la carte de fidélité

### POST /api/carte-fidelite/add-case
```bash
curl -X POST "$BASE_URL/carte-fidelite/add-case" \
  $HEADERS \
  -d '{
    "qrcode": "ABC123"
  }'
```

**Réponse attendue :**
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
    "nom": "Doe",
    "prenoms": "John"
  },
  "vehicule": {
    "matricule": "ABC123",
    "marque": "Toyota",
    "modele": "Corolla"
  }
}
```

## 2. Consulter la carte de fidélité (tous les véhicules d'un usager)

### GET /api/carte-fidelite/carte/{usager_id}
```bash
# Remplacer {usager_id} par l'ID réel de l'usager
curl -X GET "$BASE_URL/carte-fidelite/carte/1" \
  $HEADERS
```

**Réponse attendue :**
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
    "nom": "Doe",
    "prenoms": "John"
  }
}
```

## 3. Consulter la carte d'un véhicule spécifique

### GET /api/carte-fidelite/carte/{usager_id}/{matricule_vehicule}
```bash
# Remplacer {usager_id} et {matricule_vehicule} par les vraies valeurs
curl -X GET "$BASE_URL/carte-fidelite/carte/1/ABC123" \
  $HEADERS
```

**Réponse attendue :**
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
    }
  ],
  "usager": {
    "id": 1,
    "nom": "Doe",
    "prenoms": "John"
  }
}
```

## 4. Consulter les récompenses (tous les véhicules d'un usager)

### GET /api/carte-fidelite/recompenses/{usager_id}
```bash
# Remplacer {usager_id} par l'ID réel de l'usager
curl -X GET "$BASE_URL/carte-fidelite/recompenses/1" \
  $HEADERS
```

**Réponse attendue :**
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
    "nom": "Doe",
    "prenoms": "John"
  }
}
```

## 5. Consulter les récompenses d'un véhicule spécifique

### GET /api/carte-fidelite/recompenses/{usager_id}/{matricule_vehicule}
```bash
# Remplacer {usager_id} et {matricule_vehicule} par les vraies valeurs
curl -X GET "$BASE_URL/carte-fidelite/recompenses/1/ABC123" \
  $HEADERS
```

**Réponse attendue :**
```json
{
  "success": true,
  "recompenses": {
    "total_gagnees": 2,
    "derniere_recompense": "2025-08-18T14:30:00.000000Z",
    "prochaine_recompense": "3 cases restantes"
  },
  "usager": {
    "id": 1,
    "nom": "Doe",
    "prenoms": "John"
  }
}
```

## 6. Liste des usagers fidèles

### GET /api/carte-fidelite/usagers-fideles
```bash
curl -X GET "$BASE_URL/carte-fidelite/usagers-fideles" \
  $HEADERS
```

**Réponse attendue :**
```json
{
  "success": true,
  "data": [
    {
      "usager": {
        "id": 1,
        "nom": "Doe",
        "prenoms": "John"
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

## 7. Statistiques de fidélité

### GET /api/carte-fidelite/statistiques
```bash
curl -X GET "$BASE_URL/carte-fidelite/statistiques" \
  $HEADERS
```

**Réponse attendue :**
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
        "nom": "Doe",
        "prenoms": "John"
      },
      "matricule_vehicule": "ABC123",
      "recompenses_gagnees": 3,
      "total_cases": 28
    }
  ]
}
```

## Script bash complet pour tester toutes les routes

```bash
#!/bin/bash

# Configuration
BASE_URL="http://localhost:8000/api"
TOKEN="votre_token_jwt_ici"

# Fonction pour faire les requêtes
make_request() {
    local method=$1
    local endpoint=$2
    local data=$3
    
    echo "=== Test: $method $endpoint ==="
    
    if [ -n "$data" ]; then
        curl -X $method "$BASE_URL$endpoint" \
            -H "Content-Type: application/json" \
            -H "Authorization: Bearer $TOKEN" \
            -d "$data" \
            -s | jq '.'
    else
        curl -X $method "$BASE_URL$endpoint" \
            -H "Content-Type: application/json" \
            -H "Authorization: Bearer $TOKEN" \
            -s | jq '.'
    fi
    
    echo ""
}

# Tests des routes
echo "Test des routes de l'API de fidélité"
echo "====================================="

# 1. Ajouter une case
make_request "POST" "/carte-fidelite/add-case" '{"qrcode": "ABC123"}'

# 2. Consulter carte (tous véhicules)
make_request "GET" "/carte-fidelite/carte/1"

# 3. Consulter carte (véhicule spécifique)
make_request "GET" "/carte-fidelite/carte/1/ABC123"

# 4. Consulter récompenses (tous véhicules)
make_request "GET" "/carte-fidelite/recompenses/1"

# 5. Consulter récompenses (véhicule spécifique)
make_request "GET" "/carte-fidelite/recompenses/1/ABC123"

# 6. Liste des usagers fidèles
make_request "GET" "/carte-fidelite/usagers-fideles"

# 7. Statistiques
make_request "GET" "/carte-fidelite/statistiques"

echo "Tests terminés"
```

## Notes importantes

### Authentification
- Toutes les routes nécessitent un token JWT valide
- Le token doit être inclus dans le header `Authorization: Bearer <token>`

### Paramètres à remplacer
- `{usager_id}` : ID numérique de l'usager
- `{matricule_vehicule}` : Matricule du véhicule (ex: "ABC123")
- `votre_token_jwt_ici` : Token JWT obtenu lors de l'authentification

### Codes de réponse HTTP
- `200` : Succès
- `401` : Non authentifié
- `404` : Ressource non trouvée
- `422` : Erreur de validation
- `500` : Erreur serveur

### Outils recommandés
- `jq` : Pour formater les réponses JSON
- `curl` : Pour les requêtes HTTP
- `bash` : Pour automatiser les tests
