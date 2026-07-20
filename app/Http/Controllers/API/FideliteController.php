<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Fidelite;
use App\Models\Vehicule;
use App\Models\User;
use App\Models\Recompense;
use App\Models\StationLavage;
use App\Models\Lavage;
use App\Models\AttributionVehicule;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;

class FideliteController extends Controller
{
    public function addCase(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'qrcode' => 'required|string|max:20',
            ], [
                'qrcode.required' => 'Le QR code du véhicule est requis.',
                'qrcode.string' => 'Le QR code doit être une chaîne de caractères.',
                'qrcode.max' => 'Le QR code ne peut pas dépasser 20 caractères.'
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation échouée.',
                    'errors' => $validator->errors(),
                ], 422);
            }

            $lavage = auth('api')->user();
            if (!$lavage) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Lavage non authentifié'
                ], 401);
            }

            // Vérifier si le véhicule existe
            $vehicule = Vehicule::where('matricule', $request->qrcode)
            ->with('marque')
            ->first();

            if (!$vehicule) {
                return response()->json([
                    'success' => false,
                    'message' => 'Véhicule non trouvé',
                    'detail' => 'Le QR code "' . $request->qrcode . '" ne correspond à aucun véhicule enregistré dans la base de données.',
                    'suggestion' => 'Vérifiez que le matricule du véhicule est correct et qu\'il est bien enregistré dans le système.'
                ], 404);
            }

            // Récupérer l'usager propriétaire du véhicule
            $usager = User::where('id', $vehicule->user_id)->first();
            if (!$usager) {
                return response()->json([
                    'success' => false,
                    'message' => 'Usager non trouvé pour ce véhicule',
                    'detail' => 'Le véhicule "' . $vehicule->matricule . '" n\'a pas de propriétaire associé.',
                    'suggestion' => 'Vérifiez que l\'usager propriétaire du véhicule existe dans le système.'
                ], 404);
            }

            DB::beginTransaction();
            try {
                // Rechercher ou créer une entrée de fidélité pour cet usager, ce lavage et ce véhicule
                $fidelite = Fidelite::firstOrNew([
                    'usager_id' => $usager->id,
                    'lavage_id' => $lavage->id,
                    'matricule_vehicule' => $vehicule->matricule
                ]);

                // Initialiser les valeurs par défaut si c'est une nouvelle entrée
                if (!$fidelite->exists) {
                    $fidelite->cases_remplies = 0;
                    $fidelite->total_cases = 10; // Par défaut 10 cases
                    $fidelite->recompenses_gagnees = 0;
                }

                // Ajouter une case
                $fidelite->cases_remplies++;

                // Vérifier si la carte est complète
                $recompenseGagnee = false;
                $recompenseAttribuee = null;

                if ($fidelite->isCarteComplete()) {
                    $fidelite->recompenses_gagnees++;
                    $fidelite->derniere_recompense = now();
                    $fidelite->cases_remplies = 0; // Réinitialiser pour la prochaine carte
                    $recompenseGagnee = true;

                    // Attribuer une récompense
                    $recompenseAttribuee = $this->attribuerRecompense($usager, $lavage, $vehicule);
                }

                $fidelite->save();

                DB::commit();

                $message = $recompenseGagnee
                    ? 'Félicitations ! Votre carte de fidélité est complète. Vous avez gagné une récompense !'
                    : 'Case ajoutée avec succès à votre carte de fidélité.';

                $response = [
                    'success' => true,
                    'message' => $message,
                    'fidelite' => $fidelite,
                    'recompense_gagnee' => $recompenseGagnee,
                    'progression' => [
                        'cases_remplies' => $fidelite->cases_remplies,
                        'total_cases' => $fidelite->total_cases,
                        'cases_restantes' => $fidelite->getCasesRestantes(),
                        'pourcentage' => $fidelite->getProgressionPourcentage()
                    ],
                    'usager' => [
                        'id' => $usager->id,
                        'nom' => $usager->nom,
                        'prenoms' => $usager->prenoms
                    ],
                    'vehicule' => [
                        'matricule' => $vehicule->matricule,
                        'marque' => $vehicule->marque->libelle ?? 'Non spécifiée',
                        'modele' => $vehicule->modele ?? 'Non spécifié'
                    ]
                ];

                // Ajouter les détails de la récompense si elle a été attribuée
                if ($recompenseAttribuee) {
                    $response['recompense'] = [
                        'id' => $recompenseAttribuee->id,
                        'type' => $recompenseAttribuee->type_recompense,
                        'type_nom' => $recompenseAttribuee->type_name,
                        'description' => $recompenseAttribuee->description,
                        'valeur' => $recompenseAttribuee->valeur,
                        'date_attribution' => $recompenseAttribuee->date_attribution
                    ];
                }

                return response()->json($response, 200);

            } catch (\Exception $e) {
                DB::rollBack();
                Log::error('Erreur lors de l\'ajout de la case', ['error' => $e->getMessage()]);
                throw $e;
            }

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Une erreur est survenue lors de l\'ajout de la case.',
                'detail' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Attribuer une récompense à un usager
     */
    private function attribuerRecompense($usager, $lavage, $vehicule)
    {
        // Par défaut, attribuer un lavage gratuit
        $typeRecompense = 'lavage_gratuit';
        $description = 'Lavage gratuit offert pour avoir complété votre carte de fidélité';
        $valeur = 0.00;

        // Créer la récompense
        $recompense = Recompense::create([
            'usager_id' => $usager->id,
            'lavage_id' => $lavage->id,
            'matricule_vehicule' => $vehicule->matricule,
            'type_recompense' => $typeRecompense,
            'description' => $description,
            'valeur' => $valeur,
            'statut' => 'attribuee',
            'date_attribution' => now(),
            'utilisee' => false
        ]);

        return $recompense;
    }

    public function getCarteFidelite($usager_id, $matricule_vehicule = null)
    {
        try {
            $lavage = auth('api')->user();
            if (!$lavage) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Lavage non authentifié'
                ], 401);
            }

            // Vérifier que l'usager existe
            $usager = User::where('id', $usager_id)->first();
            if (!$usager) {
                return response()->json([
                    'success' => false,
                    'message' => 'Usager non trouvé',
                    'detail' => 'L\'usager avec l\'ID ' . $usager_id . ' n\'existe pas dans la base de données.'
                ], 404);
            }

            // Construire la requête de base
            $query = Fidelite::where('usager_id', $usager_id)
                           ->where('lavage_id', $lavage->id);

            // Si un matricule est spécifié, filtrer par véhicule
            if ($matricule_vehicule) {
                $query->where('matricule_vehicule', $matricule_vehicule);
            }

            $fidelites = $query->get();

            if ($fidelites->isEmpty()) {
                $message = $matricule_vehicule
                    ? 'Aucune carte de fidélité trouvée pour cet usager et ce véhicule.'
                    : 'Aucune carte de fidélité trouvée pour cet usager.';

                return response()->json([
                    'success' => true,
                    'message' => $message,
                    'cartes' => [],
                    'usager' => [
                        'id' => $usager->id,
                        'nom' => $usager->nom,
                        'prenoms' => $usager->prenoms
                    ]
                ], 200);
            }

            // Formater les cartes de fidélité
            $cartes = $fidelites->map(function ($fidelite) {
                return [
                    'matricule_vehicule' => $fidelite->matricule_vehicule,
                    'cases_remplies' => $fidelite->cases_remplies,
                    'total_cases' => $fidelite->total_cases,
                    'cases_restantes' => $fidelite->getCasesRestantes(),
                    'pourcentage' => $fidelite->getProgressionPourcentage(),
                    'recompenses_gagnees' => $fidelite->recompenses_gagnees,
                    'derniere_recompense' => $fidelite->derniere_recompense,
                    'carte_complete' => $fidelite->isCarteComplete()
                ];
            });

            return response()->json([
                'success' => true,
                'cartes' => $cartes,
                'usager' => [
                    'id' => $usager->id,
                    'nom' => $usager->nom,
                    'prenoms' => $usager->prenoms
                ]
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Une erreur est survenue lors de la récupération de la carte de fidélité.',
                'detail' => $e->getMessage()
            ], 500);
        }
    }

    public function getUsagersFideles()
    {
        try {
            $lavage = auth('api')->user();
            if (!$lavage) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Lavage non authentifié'
                ], 401);
            }

            // Récupérer tous les usagers fidèles pour ce lavage
            $usagersFideles = Fidelite::with('usager')
                                    ->whereHas('usager')
                                    ->where('lavage_id', $lavage->id)
                                    ->orderBy('cases_remplies', 'desc')
                                    ->orderBy('recompenses_gagnees', 'desc')
                                    ->get();

            // Grouper par usager et véhicule
            $groupedFidelites = $usagersFideles->groupBy(['usager_id', 'matricule_vehicule']);

            $result = [];
            foreach ($groupedFidelites as $usagerId => $vehicules) {
                $fideliteUsager = $usagersFideles->firstWhere('usager_id', $usagerId);
                $usager = $fideliteUsager ? $fideliteUsager->usager : null;

                if (!$usager) {
                    continue;
                }

                $vehiculesData = [];
                foreach ($vehicules as $matricule => $fidelite) {
                    $fidelite = $fidelite->first();
                    $vehiculesData[] = [
                        'matricule_vehicule' => $matricule,
                        'progression' => [
                            'cases_remplies' => $fidelite->cases_remplies,
                            'total_cases' => $fidelite->total_cases,
                            'cases_restantes' => $fidelite->getCasesRestantes(),
                            'pourcentage' => $fidelite->getProgressionPourcentage(),
                            'carte_complete' => $fidelite->isCarteComplete()
                        ],
                        'recompenses_gagnees' => $fidelite->recompenses_gagnees
                    ];
                }

                $result[] = [
                    'usager' => [
                        'id' => $usager->id,
                        'nom' => $usager->nom,
                        'prenoms' => $usager->prenoms
                    ],
                    'vehicules' => $vehiculesData
                ];
            }

            return response()->json([
                'success' => true,
                'data' => $result,
                'total_usagers' => count($result)
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Une erreur est survenue lors de la récupération des usagers fidèles.',
                'detail' => $e->getMessage()
            ], 500);
        }
    }

    public function getRecompenses($usager_id, $matricule_vehicule = null)
    {
        try {
            $lavage = auth('api')->user();
            if (!$lavage) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Lavage non authentifié'
                ], 401);
            }

            // Vérifier que l'usager existe
            $usager = User::where('id', $usager_id)->first();
            if (!$usager) {
                return response()->json([
                    'success' => false,
                    'message' => 'Usager non trouvé',
                    'detail' => 'L\'usager avec l\'ID ' . $usager_id . ' n\'existe pas dans la base de données.'
                ], 404);
            }

            // Construire la requête de base
            $query = Fidelite::where('usager_id', $usager_id)
                           ->where('lavage_id', $lavage->id);

            // Si un matricule est spécifié, filtrer par véhicule
            if ($matricule_vehicule) {
                $query->where('matricule_vehicule', $matricule_vehicule);
            }

            $fidelites = $query->get();

            if ($fidelites->isEmpty()) {
                $message = $matricule_vehicule
                    ? 'Aucune carte de fidélité trouvée pour cet usager et ce véhicule.'
                    : 'Aucune carte de fidélité trouvée pour cet usager.';

                return response()->json([
                    'success' => true,
                    'message' => $message,
                    'recompenses' => [
                        'total_gagnees' => 0,
                        'derniere_recompense' => null,
                        'prochaine_recompense' => '10 cases restantes'
                    ],
                    'usager' => [
                        'id' => $usager->id,
                        'nom' => $usager->nom,
                        'prenoms' => $usager->prenoms
                    ]
                ], 200);
            }

            // Calculer les totaux
            $totalRecompenses = $fidelites->sum('recompenses_gagnees');
            $derniereRecompense = $fidelites->max('derniere_recompense');
            $casesRestantes = $fidelites->sum(function ($fidelite) {
                return $fidelite->getCasesRestantes();
            });

            return response()->json([
                'success' => true,
                'recompenses' => [
                    'total_gagnees' => $totalRecompenses,
                    'derniere_recompense' => $derniereRecompense,
                    'prochaine_recompense' => $casesRestantes . ' cases restantes'
                ],
                'usager' => [
                    'id' => $usager->id,
                    'nom' => $usager->nom,
                    'prenoms' => $usager->prenoms
                ]
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Une erreur est survenue lors de la récupération des récompenses.',
                'detail' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Obtenir les récompenses d'un usager
     */
    public function getRecompensesUsager($usager_id)
    {
        try {
            $lavage = auth('api')->user();
            if (!$lavage) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Lavage non authentifié'
                ], 401);
            }

            // Vérifier que l'usager existe
            $usager = User::where('id', $usager_id)->first();
            if (!$usager) {
                return response()->json([
                    'success' => false,
                    'message' => 'Usager non trouvé',
                    'detail' => 'L\'usager avec l\'ID ' . $usager_id . ' n\'existe pas dans la base de données.'
                ], 404);
            }

            // Récupérer toutes les récompenses de l'usager pour ce lavage
            $recompenses = Recompense::where('usager_id', $usager_id)
                                   ->where('lavage_id', $lavage->id)
                                   ->orderBy('date_attribution', 'desc')
                                   ->get();

            // Statistiques des récompenses
            $totalRecompenses = $recompenses->count();
            $recompensesUtilisees = $recompenses->where('utilisee', true)->count();
            $recompensesDisponibles = $recompenses->where('utilisee', false)->where('statut', 'attribuee')->count();

            return response()->json([
                'success' => true,
                'usager' => [
                    'id' => $usager->id,
                    'nom' => $usager->nom,
                    'prenoms' => $usager->prenoms
                ],
                'statistiques' => [
                    'total_recompenses' => $totalRecompenses,
                    'recompenses_utilisees' => $recompensesUtilisees,
                    'recompenses_disponibles' => $recompensesDisponibles
                ],
                'recompenses' => $recompenses->map(function ($recompense) {
                    return [
                        'id' => $recompense->id,
                        'type' => $recompense->type_recompense,
                        'type_nom' => $recompense->type_name,
                        'description' => $recompense->description,
                        'valeur' => $recompense->valeur,
                        'statut' => $recompense->statut,
                        'statut_nom' => $recompense->statut_name,
                        'utilisable' => $recompense->isUtilisable(),
                        'date_attribution' => $recompense->date_attribution,
                        'date_utilisation' => $recompense->date_utilisation,
                        'matricule_vehicule' => $recompense->matricule_vehicule
                    ];
                })
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Une erreur est survenue lors de la récupération des récompenses.',
                'detail' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Utiliser une récompense
     */
    public function utiliserRecompense(Request $request, $recompense_id)
    {
        try {
            $lavage = auth('api')->user();
            if (!$lavage) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Lavage non authentifié'
                ], 401);
            }

            // Récupérer la récompense
            $recompense = Recompense::where('id', $recompense_id)
                                  ->where('lavage_id', $lavage->id)
                                  ->first();

            if (!$recompense) {
                return response()->json([
                    'success' => false,
                    'message' => 'Récompense non trouvée',
                    'detail' => 'La récompense avec l\'ID ' . $recompense_id . ' n\'existe pas ou ne vous appartient pas.'
                ], 404);
            }

            // Vérifier si la récompense est utilisable
            if (!$recompense->isUtilisable()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Récompense non utilisable',
                    'detail' => 'Cette récompense a déjà été utilisée ou n\'est plus disponible.'
                ], 400);
            }

            DB::beginTransaction();
            try {
                // Marquer la récompense comme utilisée
                $recompense->utiliser();

                DB::commit();

                return response()->json([
                    'success' => true,
                    'message' => 'Récompense utilisée avec succès',
                    'recompense' => [
                        'id' => $recompense->id,
                        'type' => $recompense->type_recompense,
                        'type_nom' => $recompense->type_name,
                        'description' => $recompense->description,
                        'valeur' => $recompense->valeur,
                        'statut' => $recompense->statut,
                        'date_utilisation' => $recompense->date_utilisation
                    ]
                ], 200);

            } catch (\Exception $e) {
                DB::rollBack();
                throw $e;
            }

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Une erreur est survenue lors de l\'utilisation de la récompense.',
                'detail' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Attribuer une récompense manuellement
     */
    public function attribuerRecompenseManuelle(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'usager_id' => 'required|exists:users,id',
                'matricule_vehicule' => 'required|string',
                'type_recompense' => 'required|in:lavage_gratuit,reduction_50,reduction_25,bonus_points,service_premium,cadeau',
                'description' => 'nullable|string|max:255',
                'valeur' => 'nullable|numeric|min:0',
                'forcer_attribution' => 'nullable|boolean' // Nouveau paramètre pour forcer l'attribution
            ], [
                'usager_id.exists' => 'L\'usager avec l\'ID :input n\'existe pas dans la base de données.',
                'matricule_vehicule.required' => 'Le matricule du véhicule est obligatoire.',
                'type_recompense.required' => 'Le type de récompense est obligatoire.',
                'type_recompense.in' => 'Le type de récompense doit être l\'un des suivants : lavage_gratuit, reduction_50, reduction_25, bonus_points, service_premium, cadeau.'
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation échouée.',
                    'errors' => $validator->errors(),
                ], 422);
            }

            $lavage = auth('api')->user();
            if (!$lavage) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Lavage non authentifié'
                ], 401);
            }

            // Vérifier que l'usager existe
            $usager = User::where('id', $request->usager_id)->first();
            if (!$usager) {
                return response()->json([
                    'success' => false,
                    'message' => 'Usager non trouvé',
                    'detail' => 'Aucun usager trouvé avec l\'ID ' . $request->usager_id . '.',
                    'suggestion' => 'Vérifiez que l\'ID de l\'usager est correct.'
                ], 404);
            }

            // Vérifier que le véhicule existe
            $vehicule = Vehicule::where('matricule', $request->matricule_vehicule)->first();
            if (!$vehicule) {
                return response()->json([
                    'success' => false,
                    'message' => 'Véhicule non trouvé',
                    'detail' => 'Aucun véhicule trouvé avec le matricule ' . $request->matricule_vehicule . '.',
                    'suggestion' => 'Vérifiez que le matricule du véhicule est correct.'
                ], 404);
            }

            // Vérifier que le véhicule appartient à l'usager
            if ($vehicule->user_id != $request->usager_id) {
                return response()->json([
                    'success' => false,
                    'message' => 'Véhicule n\'appartient pas à cet usager',
                    'detail' => 'Le véhicule ' . $request->matricule_vehicule . ' n\'appartient pas à l\'usager ' . $usager->nom . ' ' . $usager->prenoms . '.',
                    'suggestion' => 'Vérifiez que le matricule correspond bien à l\'usager sélectionné.'
                ], 422);
            }

            // Vérifier la carte de fidélité
            $fidelite = Fidelite::where('usager_id', $usager->id)
                               ->where('lavage_id', $lavage->id)
                               ->where('matricule_vehicule', $vehicule->matricule)
                               ->first();

            // Si pas de carte de fidélité, en créer une
            if (!$fidelite) {
                $fidelite = Fidelite::create([
                    'usager_id' => $usager->id,
                    'lavage_id' => $lavage->id,
                    'matricule_vehicule' => $vehicule->matricule,
                    'cases_remplies' => 0,
                    'total_cases' => 10,
                    'recompenses_gagnees' => 0,
                    'derniere_recompense' => null
                ]);
            }

            // Vérifier si la carte est complète (sauf si forcer_attribution est true)
            if (!$request->forcer_attribution && !$fidelite->isCarteComplete()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Carte de fidélité incomplète',
                    'detail' => 'Le véhicule ' . $vehicule->matricule . ' n\'a que ' . $fidelite->cases_remplies . ' cases remplies sur ' . $fidelite->total_cases . '.',
                    'suggestion' => 'Attendez que la carte soit complète ou utilisez forcer_attribution=true pour une attribution exceptionnelle.',
                    'fidelite' => [
                        'cases_remplies' => $fidelite->cases_remplies,
                        'total_cases' => $fidelite->total_cases,
                        'pourcentage' => $fidelite->getProgressionPourcentage(),
                        'cases_restantes' => $fidelite->getCasesRestantes()
                    ]
                ], 422);
            }

            DB::beginTransaction();
            try {
                // Créer la récompense
                $recompense = Recompense::create([
                    'usager_id' => $usager->id,
                    'lavage_id' => $lavage->id,
                    'matricule_vehicule' => $vehicule->matricule,
                    'type_recompense' => $request->type_recompense,
                    'description' => $request->description ?? 'Récompense attribuée manuellement',
                    'valeur' => $request->valeur ?? 0.00,
                    'statut' => 'attribuee',
                    'date_attribution' => now(),
                    'utilisee' => false
                ]);

                // Si c'est une attribution normale (carte complète), mettre à jour la fidélité
                if ($fidelite->isCarteComplete()) {
                    $fidelite->recompenses_gagnees++;
                    $fidelite->derniere_recompense = now();
                    $fidelite->cases_remplies = 0; // Reset pour la prochaine carte
                    $fidelite->save();
                }

                DB::commit();

                return response()->json([
                    'success' => true,
                    'message' => 'Récompense attribuée avec succès',
                    'recompense' => [
                        'id' => $recompense->id,
                        'type' => $recompense->type_recompense,
                        'type_nom' => $recompense->type_name,
                        'description' => $recompense->description,
                        'valeur' => $recompense->valeur,
                        'date_attribution' => $recompense->date_attribution
                    ],
                    'usager' => [
                        'id' => $usager->id,
                        'nom' => $usager->nom,
                        'prenoms' => $usager->prenoms
                    ],
                    'vehicule' => [
                        'matricule' => $vehicule->matricule
                    ],
                    'fidelite' => [
                        'cases_remplies' => $fidelite->cases_remplies,
                        'total_cases' => $fidelite->total_cases,
                        'recompenses_gagnees' => $fidelite->recompenses_gagnees,
                        'pourcentage' => $fidelite->getProgressionPourcentage()
                    ],
                    'attribution_type' => $request->forcer_attribution ? 'exceptionnelle' : 'normale'
                ], 201);

            } catch (\Exception $e) {
                DB::rollBack();
                throw $e;
            }

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Une erreur est survenue lors de l\'attribution de la récompense.',
                'detail' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Obtenir les récompenses d'un usager par téléphone
     */
    public function getRecompensesByTelephone($telephone)
    {
        try {
            $lavage = auth('api')->user();
            if (!$lavage) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Lavage non authentifié'
                ], 401);
            }

            // Rechercher l'usager par téléphone
            $usager = User::findByTelephone($telephone);
            if (!$usager) {
                return response()->json([
                    'success' => false,
                    'message' => 'Usager non trouvé',
                    'detail' => 'Aucun usager trouvé avec le numéro de téléphone ' . $telephone . '.'
                ], 404);
            }

            // Récupérer toutes les récompenses de l'usager pour ce lavage
            $recompenses = Recompense::where('usager_id', $usager->id)
                                   ->where('lavage_id', $lavage->id)
                                   ->orderBy('date_attribution', 'desc')
                                   ->get();

            // Statistiques des récompenses
            $totalRecompenses = $recompenses->count();
            $recompensesUtilisees = $recompenses->where('utilisee', true)->count();
            $recompensesDisponibles = $recompenses->where('utilisee', false)->where('statut', 'attribuee')->count();

            return response()->json([
                'success' => true,
                'usager' => [
                    'id' => $usager->id,
                    'nom' => $usager->nom,
                    'prenoms' => $usager->prenoms,
                    'telephone' => $usager->telephone_complet
                ],
                'statistiques' => [
                    'total_recompenses' => $totalRecompenses,
                    'recompenses_utilisees' => $recompensesUtilisees,
                    'recompenses_disponibles' => $recompensesDisponibles
                ],
                'recompenses' => $recompenses->map(function ($recompense) {
                    return [
                        'id' => $recompense->id,
                        'type' => $recompense->type_recompense,
                        'type_nom' => $recompense->type_name,
                        'description' => $recompense->description,
                        'valeur' => $recompense->valeur,
                        'statut' => $recompense->statut,
                        'statut_nom' => $recompense->statut_name,
                        'utilisable' => $recompense->isUtilisable(),
                        'date_attribution' => $recompense->date_attribution,
                        'date_utilisation' => $recompense->date_utilisation,
                        'matricule_vehicule' => $recompense->matricule_vehicule
                    ];
                })
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Une erreur est survenue lors de la récupération des récompenses.',
                'detail' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Obtenir les récompenses d'un usager par matricule de véhicule
     */
    public function getRecompensesByMatricule($matricule)
    {
        try {
            $lavage = auth('api')->user();
            if (!$lavage) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Lavage non authentifié'
                ], 401);
            }

            // Rechercher l'usager par matricule
            $usager = User::findByMatricule($matricule);
            if (!$usager) {
                return response()->json([
                    'success' => false,
                    'message' => 'Usager non trouvé',
                    'detail' => 'Aucun usager trouvé avec le matricule de véhicule ' . $matricule . '.'
                ], 404);
            }

            // Récupérer toutes les récompenses de l'usager pour ce lavage
            $recompenses = Recompense::where('usager_id', $usager->id)
                                   ->where('lavage_id', $lavage->id)
                                   ->orderBy('date_attribution', 'desc')
                                   ->get();

            // Statistiques des récompenses
            $totalRecompenses = $recompenses->count();
            $recompensesUtilisees = $recompenses->where('utilisee', true)->count();
            $recompensesDisponibles = $recompenses->where('utilisee', false)->where('statut', 'attribuee')->count();

            // Récupérer les véhicules de l'usager
            $vehicules = $usager->vehicules;

            return response()->json([
                'success' => true,
                'usager' => [
                    'id' => $usager->id,
                    'nom' => $usager->nom,
                    'prenoms' => $usager->prenoms,
                    'telephone' => $usager->telephone_complet
                ],
                'vehicules' => $vehicules->map(function ($vehicule) {
                    return [
                        'matricule' => $vehicule->matricule,
                        'marque' => $vehicule->marque->libelle ?? 'Non spécifiée',
                        'modele' => $vehicule->modele ?? 'Non spécifié'
                    ];
                }),
                'statistiques' => [
                    'total_recompenses' => $totalRecompenses,
                    'recompenses_utilisees' => $recompensesUtilisees,
                    'recompenses_disponibles' => $recompensesDisponibles
                ],
                'recompenses' => $recompenses->map(function ($recompense) {
                    return [
                        'id' => $recompense->id,
                        'type' => $recompense->type_recompense,
                        'type_nom' => $recompense->type_name,
                        'description' => $recompense->description,
                        'valeur' => $recompense->valeur,
                        'statut' => $recompense->statut,
                        'statut_nom' => $recompense->statut_name,
                        'utilisable' => $recompense->isUtilisable(),
                        'date_attribution' => $recompense->date_attribution,
                        'date_utilisation' => $recompense->date_utilisation,
                        'matricule_vehicule' => $recompense->matricule_vehicule
                    ];
                })
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Une erreur est survenue lors de la récupération des récompenses.',
                'detail' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Rechercher un usager par téléphone ou matricule
     */
    public function rechercherUsager(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'telephone' => 'nullable|string',
                'matricule' => 'nullable|string'
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation échouée.',
                    'errors' => $validator->errors(),
                ], 422);
            }

            $lavage = auth('api')->user();
            if (!$lavage) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Lavage non authentifié'
                ], 401);
            }

            $usager = null;
            $methode = '';

            // Rechercher par téléphone
            if ($request->telephone) {
                $usager = User::findByTelephone($request->telephone);
                $methode = 'téléphone';
            }
            // Rechercher par matricule
            elseif ($request->matricule) {
                $usager = User::findByMatricule($request->matricule);
                $methode = 'matricule';
            }
            else {
                return response()->json([
                    'success' => false,
                    'message' => 'Paramètre manquant',
                    'detail' => 'Vous devez fournir soit un numéro de téléphone soit un matricule de véhicule.'
                ], 400);
            }

            if (!$usager) {
                $valeur = $request->telephone ?? $request->matricule;
                return response()->json([
                    'success' => false,
                    'message' => 'Usager non trouvé',
                    'detail' => 'Aucun usager trouvé avec le ' . $methode . ' : ' . $valeur . '.'
                ], 404);
            }

            // Récupérer les informations de fidélité
            $fidelites = Fidelite::where('usager_id', $usager->id)
                               ->where('lavage_id', $lavage->id)
                               ->get();

            // Récupérer les récompenses
            $recompenses = Recompense::where('usager_id', $usager->id)
                                   ->where('lavage_id', $lavage->id)
                                   ->orderBy('date_attribution', 'desc')
                                   ->get();

            return response()->json([
                'success' => true,
                'usager' => [
                    'id' => $usager->id,
                    'nom' => $usager->nom,
                    'prenoms' => $usager->prenoms,
                    'telephone' => $usager->telephone_complet
                ],
                'vehicules' => $usager->vehicules->map(function ($vehicule) {
                    return [
                        'matricule' => $vehicule->matricule,
                        'marque' => $vehicule->marque->libelle ?? 'Non spécifiée',
                        'modele' => $vehicule->modele ?? 'Non spécifié'
                    ];
                }),
                'fidelite' => [
                    'total_cartes' => $fidelites->count(),
                    'total_recompenses_gagnees' => $fidelites->sum('recompenses_gagnees'),
                    'cartes' => $fidelites->map(function ($fidelite) {
                        return [
                            'matricule_vehicule' => $fidelite->matricule_vehicule,
                            'cases_remplies' => $fidelite->cases_remplies,
                            'total_cases' => $fidelite->total_cases,
                            'pourcentage' => $fidelite->getProgressionPourcentage(),
                            'recompenses_gagnees' => $fidelite->recompenses_gagnees
                        ];
                    })
                ],
                'recompenses' => [
                    'total' => $recompenses->count(),
                    'disponibles' => $recompenses->where('utilisee', false)->where('statut', 'attribuee')->count(),
                    'utilisees' => $recompenses->where('utilisee', true)->count(),
                    'liste' => $recompenses->map(function ($recompense) {
                        return [
                            'id' => $recompense->id,
                            'type' => $recompense->type_recompense,
                            'type_nom' => $recompense->type_name,
                            'description' => $recompense->description,
                            'utilisable' => $recompense->isUtilisable(),
                            'date_attribution' => $recompense->date_attribution
                        ];
                    })
                ]
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Une erreur est survenue lors de la recherche.',
                'detail' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Vérifier si une immatriculation scannée existe chez le lavage connecté
     * et retourner les statistiques de lavage du véhicule.
     */
    public function verifierImmatriculationScannee(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'matricule' => 'required_without:immatriculation|string|max:50',
                'immatriculation' => 'required_without:matricule|string|max:50'
            ], [
                'matricule.required_without' => 'Le matricule ou l\'immatriculation est obligatoire.',
                'immatriculation.required_without' => 'Le matricule ou l\'immatriculation est obligatoire.'
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation échouée.',
                    'errors' => $validator->errors(),
                ], 422);
            }

            $lavage = auth('api')->user();
            if (!$lavage) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Lavage non authentifié'
                ], 401);
            }

            $matricule = trim($request->matricule ?? $request->immatriculation);
            $vehicule = Vehicule::with(['marque', 'user'])
                ->where('matricule', $matricule)
                ->first();

            if (!$vehicule) {
                return response()->json([
                    'success' => true,
                    'message' => 'Immatriculation non trouvée.',
                    'existe' => false,
                    'existe_chez_ce_lavage' => false,
                    'matricule' => $matricule,
                    'statistiques' => [
                        'total_lavages' => 0,
                        'lavages_en_cours' => 0,
                        'lavages_termines' => 0,
                        'lavages_annules' => 0,
                        'total_recompenses' => 0,
                        'recompenses_disponibles' => 0,
                        'recompenses_utilisees' => 0
                    ]
                ], 200);
            }

            $stationLavage = StationLavage::where('created_by', $lavage->id)
                ->orWhere('created_by', $lavage->created_by)
                ->first();

            $managerId = $stationLavage ? $stationLavage->created_by : ($lavage->created_by ?? $lavage->id);
            $lavageIds = Lavage::where('created_by', $managerId)
                ->pluck('id')
                ->push($managerId)
                ->push($lavage->id)
                ->filter()
                ->unique()
                ->values();

            $fidelites = Fidelite::where('matricule_vehicule', $vehicule->matricule)
                ->where(function ($query) use ($stationLavage, $lavageIds) {
                    if ($stationLavage) {
                        $query->where('station_lavage_id', $stationLavage->id)
                            ->orWhereIn('lavage_id', $lavageIds);
                    } else {
                        $query->whereIn('lavage_id', $lavageIds);
                    }
                })
                ->get();

            $recompenses = Recompense::where('matricule_vehicule', $vehicule->matricule)
                ->whereIn('lavage_id', $lavageIds)
                ->orderBy('date_attribution', 'desc')
                ->get();

            $attributionQuery = AttributionVehicule::with(['laveurs', 'typeLavage'])
                ->where('matricule_vehicule', $vehicule->matricule);

            if ($stationLavage) {
                $attributionQuery->where('station_lavage_id', $stationLavage->id);
            } else {
                $attributionQuery->whereIn('manager_id', $lavageIds);
            }

            $attributions = $attributionQuery
                ->orderBy('date_attribution', 'desc')
                ->get();

            $vehiculeDansStation = $stationLavage
                && $vehicule->user
                && (int) $vehicule->user->station_de_lavage_id === (int) $stationLavage->id;

            $existeChezCeLavage = $vehiculeDansStation
                || $fidelites->isNotEmpty()
                || $attributions->isNotEmpty();

            $totalLavagesFidelite = $fidelites->sum(function ($fidelite) {
                return ($fidelite->recompenses_gagnees * $fidelite->total_cases) + $fidelite->cases_remplies;
            });

            return response()->json([
                'success' => true,
                'message' => $existeChezCeLavage
                    ? 'Immatriculation trouvée chez ce lavage.'
                    : 'Immatriculation trouvée, mais aucune activité chez ce lavage.',
                'existe' => true,
                'existe_chez_ce_lavage' => $existeChezCeLavage,
                'station_lavage' => $stationLavage ? [
                    'id' => $stationLavage->id,
                    'name' => $stationLavage->name
                ] : null,
                'vehicule' => [
                    'matricule' => $vehicule->matricule,
                    'marque' => $vehicule->marque->libelle ?? 'Non spécifiée',
                    'modele' => $vehicule->modele ?? 'Non spécifié',
                    'couleur' => $vehicule->couleur ?? null,
                    'usager' => $vehicule->user ? [
                        'id' => $vehicule->user->id,
                        'nom' => $vehicule->user->nom,
                        'prenoms' => $vehicule->user->prenoms,
                        'telephone' => $vehicule->user->telephone_complet,
                        'station_de_lavage_id' => $vehicule->user->station_de_lavage_id ?? null
                    ] : null
                ],
                'statistiques' => [
                    'total_lavages' => max($attributions->count(), $totalLavagesFidelite),
                    'lavages_en_cours' => $attributions->where('statut', 'en_cours')->count(),
                    'lavages_termines' => $attributions->where('statut', 'termine')->count(),
                    'lavages_annules' => $attributions->where('statut', 'annule')->count(),
                    'total_cases_fidelite' => $fidelites->sum('cases_remplies'),
                    'total_recompenses' => $recompenses->count(),
                    'recompenses_gagnees' => $fidelites->sum('recompenses_gagnees'),
                    'recompenses_disponibles' => $recompenses->where('utilisee', false)->where('statut', 'attribuee')->count(),
                    'recompenses_utilisees' => $recompenses->where('utilisee', true)->count(),
                    'dernier_lavage' => optional($attributions->first())->date_attribution
                ],
                'fidelite' => [
                    'total_cartes' => $fidelites->count(),
                    'cartes' => $fidelites->map(function ($fidelite) {
                        return [
                            'id' => $fidelite->id,
                            'matricule_vehicule' => $fidelite->matricule_vehicule,
                            'cases_remplies' => $fidelite->cases_remplies,
                            'total_cases' => $fidelite->total_cases,
                            'cases_restantes' => $fidelite->getCasesRestantes(),
                            'pourcentage' => $fidelite->getProgressionPourcentage(),
                            'recompenses_gagnees' => $fidelite->recompenses_gagnees,
                            'derniere_recompense' => $fidelite->derniere_recompense
                        ];
                    })->values()
                ],
                'historique_lavages' => $attributions->take(10)->map(function ($attribution) {
                    return [
                        'id' => $attribution->id,
                        'laveurs' => $attribution->laveurs->map(function ($laveur) {
                            return [
                                'id' => $laveur->id,
                                'nom_complet' => $laveur->first_name . ' ' . $laveur->last_name,
                                'mobile' => $laveur->mobile,
                            ];
                        })->values(),
                        'type_lavage_id' => $attribution->type_lavage_id,
                        'libelle' => $attribution->typeLavage?->libelle,
                        'montant' => $attribution->typeLavage?->montant,
                        'statut' => $attribution->statut,
                        'date_attribution' => $attribution->date_attribution,
                        'date_debut' => $attribution->date_debut,
                        'date_fin' => $attribution->date_fin
                    ];
                })->values()
            ], 200);

        } catch (\Exception $e) {
            Log::error('Erreur lors de la vérification de l\'immatriculation scannée', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Une erreur est survenue lors de la vérification de l\'immatriculation.',
                'detail' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Obtenir la liste des types de récompenses disponibles
     */
    public function getTypesRecompenses()
    {
        try {
            $lavage = auth('api')->user();
            if (!$lavage) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Lavage non authentifié'
                ], 401);
            }

            $types = [
                [
                    'code' => 'lavage_gratuit',
                    'nom' => 'Lavage gratuit',
                    'description' => 'Lavage complet offert gratuitement',
                    'valeur_defaut' => 0.00,
                    'icone' => '🚗',
                    'couleur' => '#28a745'
                ],
                [
                    'code' => 'reduction_50',
                    'nom' => 'Réduction 50%',
                    'description' => 'Réduction de 50% sur le prochain lavage',
                    'valeur_defaut' => 0.50,
                    'icone' => '💰',
                    'couleur' => '#ffc107'
                ],
                [
                    'code' => 'reduction_25',
                    'nom' => 'Réduction 25%',
                    'description' => 'Réduction de 25% sur le prochain lavage',
                    'valeur_defaut' => 0.25,
                    'icone' => '💵',
                    'couleur' => '#17a2b8'
                ],
                [
                    'code' => 'bonus_points',
                    'nom' => 'Bonus points',
                    'description' => 'Points bonus pour la prochaine carte de fidélité',
                    'valeur_defaut' => 2.00,
                    'icone' => '⭐',
                    'couleur' => '#fd7e14'
                ],
                [
                    'code' => 'service_premium',
                    'nom' => 'Service premium',
                    'description' => 'Service de lavage premium offert',
                    'valeur_defaut' => 0.00,
                    'icone' => '👑',
                    'couleur' => '#6f42c1'
                ],
                [
                    'code' => 'cadeau',
                    'nom' => 'Cadeau',
                    'description' => 'Cadeau surprise pour le client fidèle',
                    'valeur_defaut' => 0.00,
                    'icone' => '🎁',
                    'couleur' => '#e83e8c'
                ]
            ];

            return response()->json([
                'success' => true,
                'message' => 'Types de récompenses récupérés avec succès',
                'total_types' => count($types),
                'types' => $types
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Une erreur est survenue lors de la récupération des types de récompenses.',
                'detail' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Obtenir les statistiques des types de récompenses utilisés
     */
    public function getStatistiquesTypesRecompenses()
    {
        try {
            $lavage = auth('api')->user();
            if (!$lavage) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Lavage non authentifié'
                ], 401);
            }

            // Récupérer les statistiques par type de récompense
            $statistiques = Recompense::where('lavage_id', $lavage->id)
                                    ->selectRaw('type_recompense, COUNT(*) as total, SUM(CASE WHEN utilisee = 1 THEN 1 ELSE 0 END) as utilisees')
                                    ->groupBy('type_recompense')
                                    ->get();

            // Créer un tableau avec tous les types (même ceux non utilisés)
            $typesComplets = [
                'lavage_gratuit' => ['nom' => 'Lavage gratuit', 'total' => 0, 'utilisees' => 0, 'disponibles' => 0],
                'reduction_50' => ['nom' => 'Réduction 50%', 'total' => 0, 'utilisees' => 0, 'disponibles' => 0],
                'reduction_25' => ['nom' => 'Réduction 25%', 'total' => 0, 'utilisees' => 0, 'disponibles' => 0],
                'bonus_points' => ['nom' => 'Bonus points', 'total' => 0, 'utilisees' => 0, 'disponibles' => 0],
                'service_premium' => ['nom' => 'Service premium', 'total' => 0, 'utilisees' => 0, 'disponibles' => 0],
                'cadeau' => ['nom' => 'Cadeau', 'total' => 0, 'utilisees' => 0, 'disponibles' => 0]
            ];

            // Remplir avec les données réelles
            foreach ($statistiques as $stat) {
                if (isset($typesComplets[$stat->type_recompense])) {
                    $typesComplets[$stat->type_recompense]['total'] = $stat->total;
                    $typesComplets[$stat->type_recompense]['utilisees'] = $stat->utilisees;
                    $typesComplets[$stat->type_recompense]['disponibles'] = $stat->total - $stat->utilisees;
                }
            }

            // Calculer les totaux généraux
            $totalRecompenses = $statistiques->sum('total');
            $totalUtilisees = $statistiques->sum('utilisees');
            $totalDisponibles = $totalRecompenses - $totalUtilisees;

            return response()->json([
                'success' => true,
                'message' => 'Statistiques des types de récompenses récupérées avec succès',
                'statistiques_generales' => [
                    'total_recompenses' => $totalRecompenses,
                    'total_utilisees' => $totalUtilisees,
                    'total_disponibles' => $totalDisponibles,
                    'taux_utilisation' => $totalRecompenses > 0 ? round(($totalUtilisees / $totalRecompenses) * 100, 2) : 0
                ],
                'par_type' => $typesComplets
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Une erreur est survenue lors de la récupération des statistiques.',
                'detail' => $e->getMessage()
            ], 500);
        }
    }

    // Méthode pour obtenir les statistiques de fidélité du lavage
    public function getStatistiquesFidelite()
    {
        try {
            $lavage = auth('api')->user();
            if (!$lavage) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Lavage non authentifié'
                ], 401);
            }

            // Statistiques globales
            $totalUsagers = Fidelite::where('lavage_id', $lavage->id)->distinct('usager_id')->count();
            $totalVehicules = Fidelite::where('lavage_id', $lavage->id)->distinct('matricule_vehicule')->count();
            $totalRecompenses = Fidelite::where('lavage_id', $lavage->id)->sum('recompenses_gagnees');
            $totalCasesAjoutees = Fidelite::where('lavage_id', $lavage->id)->sum('cases_remplies');

            // Top 5 des usagers les plus fidèles
            $topUsagers = Fidelite::with('usager')
                                ->whereHas('usager')
                                ->where('lavage_id', $lavage->id)
                                ->select('usager_id', 'matricule_vehicule')
                                ->selectRaw('SUM(recompenses_gagnees) as total_recompenses')
                                ->selectRaw('SUM(cases_remplies) as total_cases')
                                ->groupBy('usager_id', 'matricule_vehicule')
                                ->orderBy('total_recompenses', 'desc')
                                ->orderBy('total_cases', 'desc')
                                ->limit(5)
                                ->get();

            return response()->json([
                'success' => true,
                'statistiques' => [
                    'total_usagers' => $totalUsagers,
                    'total_vehicules' => $totalVehicules,
                    'total_recompenses_distribuees' => $totalRecompenses,
                    'total_cases_ajoutees' => $totalCasesAjoutees,
                    'moyenne_cases_par_vehicule' => $totalVehicules > 0 ? round($totalCasesAjoutees / $totalVehicules, 2) : 0
                ],
                'top_usagers' => $topUsagers->filter(function ($fidelite) {
                    return $fidelite->usager !== null;
                })->map(function ($fidelite) {
                    return [
                        'usager' => [
                            'id' => $fidelite->usager->id,
                            'nom' => $fidelite->usager->nom,
                            'prenoms' => $fidelite->usager->prenoms
                        ],
                        'matricule_vehicule' => $fidelite->matricule_vehicule,
                        'recompenses_gagnees' => $fidelite->total_recompenses,
                        'total_cases' => $fidelite->total_cases
                    ];
                })
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Une erreur est survenue lors de la récupération des statistiques.',
                'detail' => $e->getMessage()
            ], 500);
        }
    }

    // Garder l'ancienne méthode pour la compatibilité (mais dépréciée)
    public function addPoints(Request $request)
    {
        return $this->addCase($request);
    }

    public function getPoints($usager_id)
    {
        return $this->getCarteFidelite($usager_id);
    }
}
