<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\API\AuthController;
use App\Http\Controllers\API\StationLavageController;
use App\Http\Controllers\API\FideliteController;
use App\Http\Controllers\API\AsignQrCodeController;
use App\Http\Controllers\API\UsagerController;

Route::prefix('auth')->group(function () {
    Route::post('/register', [AuthController::class, 'register']);
    Route::post('/login', [AuthController::class, 'login']);
    Route::post('/forgot-password', [AuthController::class, 'forgotPassword'])->middleware('throttle:3,1');
    Route::post('/reset-password', [AuthController::class, 'resetPassword'])->middleware('throttle:5,1');

    Route::middleware('auth:api')->group(function () {
        Route::get('/me', [AuthController::class, 'me']);
        Route::post('/logout', [AuthController::class, 'logout']);
        Route::post('/update', [AuthController::class, 'update']);
        Route::post('/update-password', [AuthController::class, 'updatePassword']);
    });
});

// Routes pour les stations de lavage
Route::prefix('stations-lavage')->middleware('auth:api')->group(function () {
    Route::get('/', [StationLavageController::class, 'index']);
    Route::post('/', [StationLavageController::class, 'store']);

    // Récupération des données des tables de référence
    Route::get('/type_de_vehicule', [StationLavageController::class, 'typeVehicule']);
    Route::get('/type_de_carburant', [StationLavageController::class, 'typeCarburant']);
    Route::get('/marque', [StationLavageController::class, 'marque']);

    // CRUD station par id (id numérique uniquement)
    Route::get('/{id}', [StationLavageController::class, 'show'])->whereNumber('id');
    Route::put('/{id}', [StationLavageController::class, 'update'])->whereNumber('id');
    Route::delete('/{id}', [StationLavageController::class, 'destroy'])->whereNumber('id');

    Route::post('/register-usager', [UsagerController::class, 'registerUsager']);
    Route::get('/usagers', [UsagerController::class, 'getUsagerByStation']);
});

// Routes pour les usagers
Route::prefix('usagers')->middleware('auth:api')->group(function () {
    Route::post('/register-usager', [UsagerController::class, 'registerUsager']);
    Route::get('/usagers', [UsagerController::class, 'getUsagerByStation']);
	Route::post('/assign-scan', [StationLavageController::class, 'assignByScanLavage']);
	Route::get('/historique/{station_de_lavage_id}', [StationLavageController::class, 'historyByStationDeLavage']);
});

// Routes pour la fidélité (ancien système - déprécié)
Route::prefix('fidelite')->middleware('auth:api')->group(function () {
    Route::post('/add-points', [FideliteController::class, 'addPoints']);
    Route::get('/points/{usager_id}', [FideliteController::class, 'getPoints']);
    Route::get('/usagers-fideles', [FideliteController::class, 'getUsagersFideles']);
});

// Routes pour la carte de fidélité (nouveau système)
Route::prefix('carte-fidelite')->middleware('auth:api')->group(function () {
    Route::post('/add-case', [FideliteController::class, 'addCase']);
    Route::get('/carte/{usager_id}', [FideliteController::class, 'getCarteFidelite']);
    Route::get('/carte/{usager_id}/{matricule_vehicule}', [FideliteController::class, 'getCarteFidelite']);
    Route::get('/recompenses/{usager_id}', [FideliteController::class, 'getRecompenses']);
    Route::get('/recompenses/{usager_id}/{matricule_vehicule}', [FideliteController::class, 'getRecompenses']);
    Route::get('/usagers-fideles', [FideliteController::class, 'getUsagersFideles']);
    Route::get('/statistiques', [FideliteController::class, 'getStatistiquesFidelite']);
});

// Routes pour la gestion des récompenses
Route::prefix('recompenses')->middleware('auth:api')->group(function () {
    Route::get('/usager/{usager_id}', [FideliteController::class, 'getRecompensesUsager']);
    Route::get('/telephone/{telephone}', [FideliteController::class, 'getRecompensesByTelephone']);
    Route::get('/matricule/{matricule}', [FideliteController::class, 'getRecompensesByMatricule']);
    Route::post('/utiliser/{recompense_id}', [FideliteController::class, 'utiliserRecompense']);
    Route::post('/attribuer', [FideliteController::class, 'attribuerRecompenseManuelle']);
    Route::get('/types', [FideliteController::class, 'getTypesRecompenses']);
    Route::get('/statistiques-types', [FideliteController::class, 'getStatistiquesTypesRecompenses']);
});

// Route pour rechercher un usager
Route::prefix('usagers')->middleware('auth:api')->group(function () {
    Route::post('/rechercher', [FideliteController::class, 'rechercherUsager']);
});

// Routes pour la gestion des laveurs (admin seulement)
Route::prefix('laveurs')->middleware('auth:api')->group(function () {
    Route::get('/', [AuthController::class, 'getLaveurs']);
    Route::get('/{laveurId}', [AuthController::class, 'getLaveurDetails']);
    Route::post('/{laveurId}/toggle-status', [AuthController::class, 'toggleLaveurStatus']);
    Route::post('/register', [AuthController::class, 'registerLaveur']);
    Route::get('/actifs', [AuthController::class, 'getLaveursActifs']);
});

// Routes pour l'attribution de véhicules aux laveurs (manager seulement)
Route::prefix('attributions')->middleware('auth:api')->group(function () {
    Route::get('/laveurs-actifs', [AuthController::class, 'getLaveursActifs']);
    Route::post('/attribuer-vehicule', [AuthController::class, 'attribuerVehicule']);
    Route::post('/terminer-lavage/{attributionId}', [AuthController::class, 'terminerLavage']);
    Route::get('/en-cours', [AuthController::class, 'getAttributionsEnCours']);
	Route::get('/type-lavage', [StationLavageController::class, 'typeLavage']);
});
