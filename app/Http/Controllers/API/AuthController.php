<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\StationLavage;
use App\Models\PasswordResetCode;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Str;
use App\Models\Lavage;
use App\Models\Vehicule;
use App\Models\AttributionVehicule;
use App\Models\Type_lavage;
use App\Models\Fidelite;
use App\Models\Recompense;
use App\Services\SmsService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class AuthController extends Controller
{
    public function __construct(
        protected SmsService $smsService
    ) {}

    public function login(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'mobile' => 'required|string|regex:/^[0-9]{10}$/',
            'password' => 'required|string|min:6',
        ]);

        if ($validator->fails()) {
            return response()->json(['error' => $validator->errors()], 422);
        }

        $credentials = $request->only('mobile', 'password');

        // Vérifier d'abord si l'utilisateur existe
        $user = Lavage::where('mobile', $request->mobile)->first();

        if (!$user) {
            return response()->json(['error' => 'Utilisateur non trouvé'], 404);
        }

        // Vérifier si le compte est actif
        if ($user->statut != 1) {
            return response()->json(['error' => 'Compte désactivé'], 403);
        }

        // Log pour débogage
        \Log::info('Tentative de connexion', [
            'mobile' => $request->mobile,
            'password_provided' => $request->password,
            'password_hash' => $user->password
        ]);

        // Tenter l'authentification
        if (!$token = Auth::guard('api')->attempt($credentials)) {
            return response()->json(['error' => 'Mot de passe incorrect'], 401);
        }

        return $this->respondWithToken($token);
    }

    /**
     * Envoyer un code de réinitialisation par SMS.
     */
    public function forgotPassword(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'indicatif' => 'required|string|max:10',
            'mobile' => 'required|string|max:30',
        ]);

        if ($validator->fails()) {
            return response()->json(['error' => $validator->errors()], 422);
        }

        $indicatif = $this->normalizeIndicatif($request->indicatif);
        $mobile = trim($request->mobile);
        $lavage = $this->findLavageByPhone($indicatif, $mobile);
        $smsResponse = null;

        if ($lavage) {
            $code = (string) random_int(100000, 999999);

            PasswordResetCode::where('indicatif', $indicatif)
                ->where('mobile', $mobile)
                ->whereNull('used_at')
                ->update(['used_at' => now()]);

            PasswordResetCode::create([
                'indicatif' => $indicatif,
                'mobile' => $mobile,
                'code' => Hash::make($code),
                'expires_at' => now()->addMinutes(10),
            ]);

            $message = strtoupper("Votre code de reinitialisation TOO AUTO : " . $code);
            $smsResponse = $this->smsService->send($message, $indicatif . $mobile);
        }

        return response()->json([
            'message' => 'Si ce numéro existe, un code de réinitialisation a été envoyé.',
            'lavage_found' => (bool) $lavage,
            'sms_response' => $smsResponse,
        ]);
    }

    /**
     * Réinitialiser le mot de passe avec le code reçu par SMS.
     */
    public function resetPassword(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'indicatif' => 'required|string|max:10',
            'mobile' => 'required|string|max:30',
            'code' => 'required|string|size:6',
            'password' => 'required|string|min:6',
            'password_confirmation' => 'required|string|same:password',
        ], [
            'password_confirmation.same' => 'La confirmation du mot de passe ne correspond pas au nouveau mot de passe.',
        ]);

        if ($validator->fails()) {
            return response()->json(['error' => $validator->errors()], 422);
        }

        $indicatif = $this->normalizeIndicatif($request->indicatif);
        $mobile = trim($request->mobile);
        $lavage = $this->findLavageByPhone($indicatif, $mobile);

        if (! $lavage) {
            throw ValidationException::withMessages([
                'mobile' => ['Code invalide ou expiré.'],
            ]);
        }

        $resetCode = PasswordResetCode::where('indicatif', $indicatif)
            ->where('mobile', $mobile)
            ->whereNull('used_at')
            ->latest()
            ->first();

        if (! $resetCode || $resetCode->expires_at->isPast()) {
            throw ValidationException::withMessages([
                'code' => ['Code invalide ou expiré.'],
            ]);
        }

        if ($resetCode->attempts >= 5) {
            $resetCode->used_at = now();
            $resetCode->save();

            throw ValidationException::withMessages([
                'code' => ['Nombre de tentatives dépassé. Veuillez demander un nouveau code.'],
            ]);
        }

        if (! Hash::check($request->code, $resetCode->code)) {
            $resetCode->increment('attempts');

            throw ValidationException::withMessages([
                'code' => ['Code invalide ou expiré.'],
            ]);
        }

        $lavage->password = Hash::make($request->password);
        $lavage->save();

        $resetCode->used_at = now();
        $resetCode->save();

        return response()->json([
            'message' => 'Mot de passe réinitialisé avec succès.',
        ]);
    }

    protected function respondWithToken($token)
    {
		$userId = Auth::guard('api')->user()->id;
		$StationLavage = StationLavage::where('created_by', $userId)->first();

        return response()->json([
            'access_token' => $token,
            'token_type' => 'bearer',
            'expires_in' => Auth::guard('api')->factory()->getTTL() * 60,
            'user' => Auth::guard('api')->user(),
			'station_lavage' => $StationLavage
        ]);
    }

    public function register(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'first_name' => 'required|string|max:100',
            'last_name' => 'required|string|max:100',
            'mobile' => 'required|string|unique:lavages|regex:/^[0-9]{10}$/',
            'email' => 'nullable|email|unique:lavages',
            'password' => 'required|string|min:6',
        ]);

        if ($validator->fails()) {
            return response()->json(['error' => $validator->errors()], 422);
        }

        $password = Hash::make($request->password);

        $lavage = Lavage::create([
            'first_name' => $request->first_name,
            'last_name' => $request->last_name,
            'mobile' => $request->mobile,
            'email' => $request->email,
            'password' => $password,
            'role' => 1,
            'statut' => 1
        ]);

        // Log pour débogage
        \Log::info('Nouvel utilisateur créé', [
            'mobile' => $request->mobile,
            'password_hash' => $password
        ]);

        return response()->json([
            'message' => 'Inscription réussie',
            'user' => $lavage
        ]);
    }

    public function me()
    {
        return response()->json(Auth::guard('api')->user());
    }

    public function update(Request $request)
    {
        try {
            $user = Auth::guard('api')->user();

            if (!$user) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Utilisateur non authentifié'
                ], 401);
            }

            $validator = Validator::make($request->all(), [
                'first_name' => 'sometimes|string|max:100',
                'last_name' => 'sometimes|string|max:100',
                'email' => 'sometimes|email|unique:lavages,email,' . $user->id,
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation échouée.',
                    'errors' => $validator->errors(),
                ], 422);
            }

            $data = [];

            if ($request->has('first_name')) {
                $data['first_name'] = $request->first_name;
            }

            if ($request->has('last_name')) {
                $data['last_name'] = $request->last_name;
            }

            if ($request->has('email')) {
                $data['email'] = $request->email;
            }

            $user->update($data);

            return response()->json([
                'success' => true,
                'message' => 'Profil mis à jour avec succès',
                'user' => $user
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Une erreur est survenue lors de la mise à jour du profil.',
                'dev' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Mettre à jour le mot de passe de l'utilisateur authentifié
     */
    public function updatePassword(Request $request)
    {
        try {
            $user = Auth::guard('api')->user();

            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'Utilisateur non authentifié'
                ], 401);
            }

            // Valider la requête
            $validator = Validator::make($request->all(), [
                'current_password' => 'required|string',
                'new_password' => 'required|string|min:6',
                'new_password_confirmation' => 'required|string|same:new_password',
            ], [
                'current_password.required' => 'Le mot de passe actuel est obligatoire.',
                'new_password.required' => 'Le nouveau mot de passe est obligatoire.',
                'new_password.min' => 'Le nouveau mot de passe doit contenir au moins 6 caractères.',
                'new_password_confirmation.required' => 'La confirmation du mot de passe est obligatoire.',
                'new_password_confirmation.same' => 'La confirmation du mot de passe ne correspond pas au nouveau mot de passe.',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation échouée.',
                    'errors' => $validator->errors(),
                ], 422);
            }

            // Vérifier que le mot de passe actuel est correct
            if (!Hash::check($request->current_password, $user->password)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Le mot de passe actuel est incorrect.'
                ], 422);
            }

            // Vérifier que le nouveau mot de passe est différent de l'ancien
            if (Hash::check($request->new_password, $user->password)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Le nouveau mot de passe doit être différent de l\'ancien mot de passe.'
                ], 422);
            }

            // Mettre à jour le mot de passe
            $user->password = Hash::make($request->new_password);
            $user->save();

            // Log de l'action
            \Log::info('Mot de passe mis à jour', [
                'user_id' => $user->id,
                'user_name' => $user->first_name . ' ' . $user->last_name,
                'user_mobile' => $user->mobile,
                'updated_at' => now()
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Mot de passe mis à jour avec succès'
            ], 200);

        } catch (\Exception $e) {
            \Log::error('Erreur lors de la mise à jour du mot de passe', [
                'user_id' => Auth::guard('api')->id(),
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Une erreur est survenue lors de la mise à jour du mot de passe.',
                'detail' => $e->getMessage()
            ], 500);
        }
    }

    public function logout()
    {
        Auth::guard('api')->logout();
        return response()->json(['message' => 'Déconnexion réussie']);
    }

    public function registerLaveur(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'first_name' => 'required|string|max:100',
            'last_name' => 'required|string|max:100',
            'mobile' => 'required|string|unique:lavages|regex:/^[0-9]{10}$/',
            'email' => 'nullable|email|unique:lavages'
        ]);

        if ($validator->fails()) {
            return response()->json(['error' => $validator->errors()], 422);
        }

        $currentUser = Auth::guard('api')->user();
        if (!$currentUser || $currentUser->role != 1) {
            return response()->json([
                'success' => false,
                'message' => 'Accès non autorisé. Seuls les administrateurs peuvent créer des laveurs.'
            ], 403);
        }
        $stationLavage = StationLavage::where('created_by', $currentUser->id)->first();
        if (!$stationLavage) {
            return response()->json([
                'success' => false,
                'message' => 'Station de lavage non trouvée. Seuls les administrateurs peuvent créer des laveurs.'
            ], 404);
        }

        $paswordRandom = Str::random(6);

        $passwordRegister = Hash::make($paswordRandom);

        $lavage = Lavage::create([
            'first_name' => $request->first_name,
            'last_name' => $request->last_name,
            'mobile' => $request->mobile,
            'email' => $request->email,
            'password' => $passwordRegister,
            'role' => 2,
            'statut' => 1,
            'created_by' => $currentUser->id
        ]);

        // Log pour débogage
        \Log::info('Nouvel utilisateur créé', [
            'mobile' => $request->mobile,
            'password_hash' => $passwordRegister
        ]);

        $message = "Votre mot de passe est : " . $paswordRandom;
        $mobile = $request->mobile;
        $this->sendMessagePassword($message, $mobile);

        return response()->json([
            'message' => 'Inscription réussie',
            'user' => $lavage
        ]);
    }

	public function sendMessagePassword($message, $reciever)
	{
        $response = $this->smsService->send($message, '225' . $reciever);

        \Log::info('SMS MTarget envoyé', [
            'receiver' => $reciever,
            'response' => $response,
        ]);

        return response()->json([
            'message' => 'Message envoyé avec succès',
            'body' => $response,
        ], 200);
	}

    /**
     * Activer ou désactiver un laveur
     */
    public function toggleLaveurStatus(Request $request, $laveurId)
    {
        try {
            // Vérifier que l'utilisateur connecté est un admin (role = 1)
            $currentUser = Auth::guard('api')->user();
            if (!$currentUser || $currentUser->role != 1) {
                return response()->json([
                    'success' => false,
                    'message' => 'Accès non autorisé. Seuls les administrateurs peuvent modifier le statut des laveurs.'
                ], 403);
            }

            // Valider la requête
            $validator = Validator::make($request->all(), [
                'action' => 'required|in:activate,deactivate',
                'reason' => 'nullable|string|max:255'
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation échouée.',
                    'errors' => $validator->errors(),
                ], 422);
            }

            // Trouver le laveur
            $laveur = Lavage::where('id', $laveurId)
                           ->where('role', 2) // Seuls les laveurs (role = 2)
                           ->first();

            if (!$laveur) {
                return response()->json([
                    'success' => false,
                    'message' => 'Laveur non trouvé',
                    'detail' => 'Aucun laveur trouvé avec l\'ID ' . $laveurId . '.'
                ], 404);
            }

            // Vérifier que l'utilisateur ne désactive pas son propre compte
            if ($laveur->id == $currentUser->id) {
                return response()->json([
                    'success' => false,
                    'message' => 'Action non autorisée',
                    'detail' => 'Vous ne pouvez pas modifier votre propre statut.'
                ], 403);
            }

            $oldStatus = $laveur->statut;
            $action = $request->action;

            // Mettre à jour le statut
            if ($action === 'activate') {
                $laveur->statut = 1; // Actif
                $message = 'Laveur activé avec succès';
                $statusText = 'actif';
            } else {
                $laveur->statut = 0; // Inactif
                $message = 'Laveur désactivé avec succès';
                $statusText = 'inactif';
            }

            $laveur->save();

            // Log de l'action
            \Log::info('Statut laveur modifié', [
                'admin_id' => $currentUser->id,
                'admin_name' => $currentUser->first_name . ' ' . $currentUser->last_name,
                'laveur_id' => $laveur->id,
                'laveur_name' => $laveur->first_name . ' ' . $laveur->last_name,
                'laveur_mobile' => $laveur->mobile,
                'old_status' => $oldStatus,
                'new_status' => $laveur->statut,
                'action' => $action,
                'reason' => $request->reason ?? 'Aucune raison spécifiée'
            ]);

            return response()->json([
                'success' => true,
                'message' => $message,
                'laveur' => [
                    'id' => $laveur->id,
                    'first_name' => $laveur->first_name,
                    'last_name' => $laveur->last_name,
                    'mobile' => $laveur->mobile,
                    'email' => $laveur->email,
                    'statut' => $laveur->statut,
                    'statut_text' => $statusText,
                    'role' => $laveur->role,
                    'created_at' => $laveur->created_at,
                    'updated_at' => $laveur->updated_at
                ],
                'action_performed' => [
                    'action' => $action,
                    'old_status' => $oldStatus,
                    'new_status' => $laveur->statut,
                    'performed_by' => $currentUser->first_name . ' ' . $currentUser->last_name,
                    'performed_at' => now(),
                    'reason' => $request->reason
                ]
            ], 200);

        } catch (\Exception $e) {
            \Log::error('Erreur lors de la modification du statut laveur', [
                'laveur_id' => $laveurId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Une erreur est survenue lors de la modification du statut.',
                'detail' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Obtenir la liste des laveurs avec leur statut
     */
    public function getLaveurs(Request $request)
    {
        try {
            // Vérifier que l'utilisateur connecté est un admin
            $currentUser = Auth::guard('api')->user();
            if (!$currentUser || $currentUser->role != 1) {
                return response()->json([
                    'success' => false,
                    'message' => 'Accès non autorisé. Seuls les administrateurs peuvent voir la liste des laveurs.'
                ], 403);
            }

            // Paramètres de pagination et filtres
            $perPage = $request->get('per_page', 15);
            $status = $request->get('status'); // 'active', 'inactive', ou null pour tous
            $search = $request->get('search'); // Recherche par nom ou mobile

            // Construire la requête
            $query = Lavage::where(['role' => 2, 'created_by' => $currentUser->id]); // Seuls les laveurs

            // Filtrer par statut
            if ($status === 'active') {
                $query->where('statut', 1);
            } elseif ($status === 'inactive') {
                $query->where('statut', 0);
            }

            // Recherche par nom ou mobile
            if ($search) {
                $query->where(function($q) use ($search) {
                    $q->where('first_name', 'LIKE', "%{$search}%")
                      ->orWhere('last_name', 'LIKE', "%{$search}%")
                      ->orWhere('mobile', 'LIKE', "%{$search}%")
                      ->orWhere('email', 'LIKE', "%{$search}%");
                });
            }

            // Trier par date de création (plus récent en premier)
            $laveurs = $query->orderBy('created_at', 'desc')
                           ->paginate($perPage);

            // Formater la réponse
            $formattedLaveurs = $laveurs->getCollection()->map(function($laveur) {
                return [
                    'id' => $laveur->id,
                    'first_name' => $laveur->first_name,
                    'last_name' => $laveur->last_name,
                    'full_name' => $laveur->first_name . ' ' . $laveur->last_name,
                    'mobile' => $laveur->mobile,
                    'email' => $laveur->email,
                    'statut' => $laveur->statut,
                    'statut_text' => $laveur->statut == 1 ? 'Actif' : 'Inactif',
                    'role' => $laveur->role,
                    'created_at' => $laveur->created_at,
                    'updated_at' => $laveur->updated_at,
                    'created_by' => $laveur->created_by
                ];
            });

            // Statistiques
            $stats = [
                'total_laveurs' => Lavage::where(['role' => 2, 'created_by' => $currentUser->id])->count(),
                'active_laveurs' => Lavage::where(['role' => 2, 'created_by' => $currentUser->id])->where('statut', 1)->count(),
                'inactive_laveurs' => Lavage::where(['role' => 2, 'created_by' => $currentUser->id])->where('statut', 0)->count()
            ];

            return response()->json([
                'success' => true,
                'message' => 'Liste des laveurs récupérée avec succès',
                'statistiques' => $stats,
                'laveurs' => $formattedLaveurs,
                'pagination' => [
                    'current_page' => $laveurs->currentPage(),
                    'last_page' => $laveurs->lastPage(),
                    'per_page' => $laveurs->perPage(),
                    'total' => $laveurs->total(),
                    'from' => $laveurs->firstItem(),
                    'to' => $laveurs->lastItem()
                ]
            ], 200);

        } catch (\Exception $e) {
            \Log::error('Erreur lors de la récupération des laveurs', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Une erreur est survenue lors de la récupération des laveurs.',
                'detail' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Obtenir les détails d'un laveur spécifique
     */
    public function getLaveurDetails($laveurId)
    {
        try {
            // Vérifier que l'utilisateur connecté est un admin
            $currentUser = Auth::guard('api')->user();
            if (!$currentUser || $currentUser->role != 1) {
                return response()->json([
                    'success' => false,
                    'message' => 'Accès non autorisé. Seuls les administrateurs peuvent voir les détails des laveurs.'
                ], 403);
            }

            // Trouver le laveur
            $laveur = Lavage::where('id', $laveurId)
                           ->where(['role' => 2, 'created_by' => $currentUser->id])
                           ->first();

            if (!$laveur) {
                return response()->json([
                    'success' => false,
                    'message' => 'Laveur non trouvé',
                    'detail' => 'Aucun laveur trouvé avec l\'ID ' . $laveurId . '.'
                ], 404);
            }

            return response()->json([
                'success' => true,
                'message' => 'Détails du laveur récupérés avec succès',
                'laveur' => [
                    'id' => $laveur->id,
                    'first_name' => $laveur->first_name,
                    'last_name' => $laveur->last_name,
                    'full_name' => $laveur->first_name . ' ' . $laveur->last_name,
                    'mobile' => $laveur->mobile,
                    'email' => $laveur->email,
                    'statut' => $laveur->statut,
                    'statut_text' => $laveur->statut == 1 ? 'Actif' : 'Inactif',
                    'role' => $laveur->role,
                    'role_text' => $laveur->role == 1 ? 'Administrateur' : 'Laveur',
                    'created_at' => $laveur->created_at,
                    'updated_at' => $laveur->updated_at,
                    'created_by' => $laveur->created_by,
                    'can_be_modified' => $laveur->id != $currentUser->id
                ]
            ], 200);

        } catch (\Exception $e) {
            \Log::error('Erreur lors de la récupération des détails du laveur', [
                'laveur_id' => $laveurId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Une erreur est survenue lors de la récupération des détails du laveur.',
                'detail' => $e->getMessage()
            ], 500);
        }
    }

    public function updateLaveur(Request $request, $laveurId)
    {
        try {
            $currentUser = Auth::guard('api')->user();
            if (!$currentUser || $currentUser->role != 1) {
                return response()->json([
                    'success' => false,
                    'message' => 'Accès non autorisé. Seuls les administrateurs peuvent modifier des laveurs.'
                ], 403);
            }

            $laveur = Lavage::where('id', $laveurId)
                ->where(['role' => 2, 'created_by' => $currentUser->id])
                ->first();

            if (!$laveur) {
                return response()->json([
                    'success' => false,
                    'message' => 'Laveur non trouvé',
                    'detail' => 'Aucun laveur trouvé avec l\'ID ' . $laveurId . '.'
                ], 404);
            }

            $validator = Validator::make($request->all(), [
                'first_name' => 'sometimes|required|string|max:100',
                'last_name' => 'sometimes|required|string|max:100',
                'mobile' => [
                    'sometimes',
                    'required',
                    'string',
                    'regex:/^[0-9]{10}$/',
                    Rule::unique('lavages', 'mobile')->ignore($laveur->id),
                ],
                'email' => [
                    'nullable',
                    'email',
                    Rule::unique('lavages', 'email')->ignore($laveur->id),
                ],
                'password' => 'nullable|string|min:6',
                'statut' => 'sometimes|required|integer|in:0,1',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation échouée.',
                    'errors' => $validator->errors(),
                ], 422);
            }

            $data = $request->only(['first_name', 'last_name', 'mobile', 'email', 'statut']);

            if ($request->filled('password')) {
                $data['password'] = Hash::make($request->password);
            }

            $laveur->update($data);

            return response()->json([
                'success' => true,
                'message' => 'Laveur mis à jour avec succès.',
                'laveur' => $this->formatLaveur($laveur->fresh()),
            ], 200);

        } catch (\Exception $e) {
            \Log::error('Erreur lors de la mise à jour du laveur', [
                'laveur_id' => $laveurId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Une erreur est survenue lors de la mise à jour du laveur.',
                'detail' => $e->getMessage()
            ], 500);
        }
    }

    public function deleteLaveur($laveurId)
    {
        try {
            $currentUser = Auth::guard('api')->user();
            if (!$currentUser || $currentUser->role != 1) {
                return response()->json([
                    'success' => false,
                    'message' => 'Accès non autorisé. Seuls les administrateurs peuvent supprimer des laveurs.'
                ], 403);
            }

            $laveur = Lavage::where('id', $laveurId)
                ->where(['role' => 2, 'created_by' => $currentUser->id])
                ->first();

            if (!$laveur) {
                return response()->json([
                    'success' => false,
                    'message' => 'Laveur non trouvé',
                    'detail' => 'Aucun laveur trouvé avec l\'ID ' . $laveurId . '.'
                ], 404);
            }

            $hasAttributions = AttributionVehicule::where('laveur_id', $laveur->id)->exists();

            if ($hasAttributions) {
                return response()->json([
                    'success' => false,
                    'message' => 'Ce laveur a déjà des attributions.',
                    'detail' => 'Désactivez plutôt ce laveur afin de conserver l\'historique des lavages.'
                ], 422);
            }

            $laveur->delete();

            return response()->json([
                'success' => true,
                'message' => 'Laveur supprimé avec succès.',
            ], 200);

        } catch (\Exception $e) {
            \Log::error('Erreur lors de la suppression du laveur', [
                'laveur_id' => $laveurId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Une erreur est survenue lors de la suppression du laveur.',
                'detail' => $e->getMessage()
            ], 500);
        }
    }

    public function getLaveurStats(Request $request, $laveurId)
    {
        try {
            $currentUser = Auth::guard('api')->user();
            if (!$currentUser) {
                return response()->json([
                    'success' => false,
                    'message' => 'Utilisateur non authentifié.'
                ], 401);
            }

            $validator = Validator::make($request->all(), [
                'date_debut' => 'nullable|date',
                'date_fin' => 'nullable|date',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation échouée.',
                    'errors' => $validator->errors(),
                ], 422);
            }

            if ($request->filled('date_debut') && $request->filled('date_fin')) {
                $dateDebut = \Carbon\Carbon::parse($request->date_debut);
                $dateFin = \Carbon\Carbon::parse($request->date_fin);

                if ($dateFin->lt($dateDebut)) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Validation échouée.',
                        'errors' => [
                            'date_fin' => ['La date de fin doit être supérieure ou égale à la date de début.'],
                        ],
                    ], 422);
                }
            }

            $laveur = Lavage::where('id', $laveurId)
                ->where('role', 2)
                ->first();

            if (!$laveur) {
                return response()->json([
                    'success' => false,
                    'message' => 'Laveur non trouvé',
                    'detail' => 'Aucun laveur trouvé avec l\'ID ' . $laveurId . '.'
                ], 404);
            }

            if ($currentUser->role == 1 && (int) $laveur->created_by !== (int) $currentUser->id) {
                return response()->json([
                    'success' => false,
                    'message' => 'Accès non autorisé.',
                    'detail' => 'Ce laveur ne vous appartient pas.'
                ], 403);
            }

            if ($currentUser->role == 2 && (int) $currentUser->id !== (int) $laveur->id) {
                return response()->json([
                    'success' => false,
                    'message' => 'Accès non autorisé.',
                    'detail' => 'Vous ne pouvez consulter que vos propres statistiques.'
                ], 403);
            }

            if (!in_array((int) $currentUser->role, [1, 2], true)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Accès non autorisé.'
                ], 403);
            }

            $query = AttributionVehicule::with(['typeLavage', 'vehicule.marque', 'vehicule.user'])
                ->where('laveur_id', $laveur->id);

            if ($request->filled('date_debut')) {
                $query->where('date_attribution', '>=', \Carbon\Carbon::parse($request->date_debut)->startOfDay());
            }

            if ($request->filled('date_fin')) {
                $query->where('date_attribution', '<=', \Carbon\Carbon::parse($request->date_fin)->endOfDay());
            }

            $attributions = $query->orderBy('date_attribution', 'desc')->get();
            $termines = $attributions->where('statut', 'termine');
            $now = now();
            $durees = $termines->filter(fn ($attribution) => $attribution->date_debut && $attribution->date_fin)
                ->map(fn ($attribution) => $attribution->date_debut->diffInMinutes($attribution->date_fin));

            return response()->json([
                'success' => true,
                'message' => 'Statistiques du laveur récupérées avec succès.',
                'laveur' => $this->formatLaveur($laveur),
                'periode' => [
                    'date_debut' => $request->date_debut,
                    'date_fin' => $request->date_fin,
                ],
                'global' => [
                    'total_lavages' => $attributions->count(),
                    'vehicules_uniques' => $attributions->pluck('matricule_vehicule')->unique()->count(),
                    'lavages_en_cours' => $attributions->where('statut', 'en_cours')->count(),
                    'lavages_termines' => $termines->count(),
                    'lavages_annules' => $attributions->where('statut', 'annule')->count(),
                    'revenu_genere' => $termines->sum(fn ($attribution) => (float) ($attribution->typeLavage?->montant ?? 0)),
                    'duree_moyenne_minutes' => $durees->isNotEmpty() ? round($durees->avg(), 2) : 0,
                ],
                'periodes' => [
                    'aujourdhui' => $attributions->filter(fn ($attribution) => $attribution->date_attribution?->isSameDay($now))->count(),
                    'cette_semaine' => $attributions->filter(fn ($attribution) => $attribution->date_attribution && $attribution->date_attribution->between($now->copy()->startOfWeek(), $now->copy()->endOfWeek()))->count(),
                    'ce_mois' => $attributions->filter(fn ($attribution) => $attribution->date_attribution?->isSameMonth($now))->count(),
                ],
                'par_type_lavage' => $attributions->groupBy('type_lavage_id')->map(function ($items, $typeLavageId) {
                    $typeLavage = $items->first()->typeLavage;
                    $itemsTermines = $items->where('statut', 'termine');

                    return [
                        'type_lavage_id' => $typeLavageId ? (int) $typeLavageId : null,
                        'libelle' => $typeLavage?->libelle,
                        'montant' => $typeLavage?->montant,
                        'total_lavages' => $items->count(),
                        'lavages_en_cours' => $items->where('statut', 'en_cours')->count(),
                        'lavages_termines' => $itemsTermines->count(),
                        'lavages_annules' => $items->where('statut', 'annule')->count(),
                        'revenu_genere' => $itemsTermines->sum(fn ($attribution) => (float) ($attribution->typeLavage?->montant ?? 0)),
                    ];
                })->values(),
                'dernieres_attributions' => $attributions->take(10)->map(function ($attribution) {
                    $vehicule = $attribution->vehicule;

                    return [
                        'id' => $attribution->id,
                        'matricule_vehicule' => $attribution->matricule_vehicule,
                        'vehicule' => [
                            'matricule' => $vehicule->matricule ?? $attribution->matricule_vehicule,
                            'marque' => $vehicule && $vehicule->marque ? $vehicule->marque->libelle : null,
                            'modele' => $vehicule->modele ?? null,
                        ],
                        ...$this->formatTypeLavageFields($attribution->typeLavage, $attribution->type_lavage_id),
                        'statut' => $attribution->statut,
                        'date_attribution' => $attribution->date_attribution,
                        'date_debut' => $attribution->date_debut,
                        'date_fin' => $attribution->date_fin,
                    ];
                })->values(),
            ], 200);

        } catch (\Exception $e) {
            \Log::error('Erreur lors de la récupération des statistiques du laveur', [
                'laveur_id' => $laveurId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Une erreur est survenue lors de la récupération des statistiques du laveur.',
                'detail' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Obtenir la liste des laveurs actifs pour attribution de véhicules
     */
    public function getLaveursActifs()
    {
        try {
            // Vérifier que l'utilisateur connecté est un manager (role = 1)
            $currentUser = Auth::guard('api')->user();
            if (!$currentUser || $currentUser->role != 1) {
                return response()->json([
                    'success' => false,
                    'message' => 'Accès non autorisé. Seuls les managers peuvent attribuer des véhicules.'
                ], 403);
            }

            // Récupérer tous les laveurs actifs
            $laveurs = Lavage::where(['role' => 2, 'created_by' => $currentUser->id])
                            ->where('statut', 1) // Seuls les laveurs actifs
                            ->select('id', 'first_name', 'last_name', 'mobile')
                            ->orderBy('first_name')
                            ->get();

            return response()->json([
                'success' => true,
                'message' => 'Liste des laveurs actifs récupérée avec succès',
                'total_laveurs_actifs' => $laveurs->count(),
                'laveurs' => $laveurs->map(function($laveur) {
                    return [
                        'id' => $laveur->id,
                        'nom_complet' => $laveur->first_name . ' ' . $laveur->last_name,
                        'mobile' => $laveur->mobile
                    ];
                })
            ], 200);

        } catch (\Exception $e) {
            \Log::error('Erreur lors de la récupération des laveurs actifs', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Une erreur est survenue lors de la récupération des laveurs actifs.',
                'detail' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Attribuer un véhicule à un laveur
     */
    public function attribuerVehicule(Request $request)
    {
        try {
            // Vérifier que l'utilisateur connecté est un manager
            $manager = Auth::guard('api')->user();
            if (!$manager || $manager->role != 1) {
                return response()->json([
                    'success' => false,
                    'message' => 'Accès non autorisé. Seuls les managers peuvent attribuer des véhicules.'
                ], 403);
            }

            // Valider la requête
            $validator = Validator::make($request->all(), [
                'matricule_vehicule' => 'required|string|exists:vehicules,matricule',
                'laveur_id' => 'required_without:laveur_ids|exists:lavages,id',
                'laveur_ids' => 'required_without:laveur_id|array|min:1',
                'laveur_ids.*' => 'integer|distinct|exists:lavages,id',
                'type_lavage_id' => [
                    'required',
                    'integer',
                    Rule::exists('type_lavages', 'id')->where(function ($query) use ($manager) {
                        $query->where('lavage_id', $manager->id);
                    }),
                ],
                'notes' => 'nullable|string|max:500'
            ], [
                'matricule_vehicule.required' => 'Le matricule du véhicule est obligatoire.',
                'matricule_vehicule.exists' => 'Le véhicule avec ce matricule n\'existe pas.',
                'laveur_id.required' => 'L\'ID du laveur est obligatoire.',
                'laveur_id.required_without' => 'L\'ID du laveur est obligatoire si aucun tableau de laveurs n\'est fourni.',
                'laveur_id.exists' => 'Le laveur sélectionné n\'existe pas.',
                'laveur_ids.required_without' => 'Au moins un laveur est obligatoire.',
                'laveur_ids.array' => 'La liste des laveurs doit être un tableau.',
                'laveur_ids.min' => 'Au moins un laveur est obligatoire.',
                'laveur_ids.*.integer' => 'Chaque laveur doit être identifié par un ID valide.',
                'laveur_ids.*.distinct' => 'La liste des laveurs contient des doublons.',
                'laveur_ids.*.exists' => 'Un des laveurs sélectionnés n\'existe pas.',
                'type_lavage_id.required' => 'Le type de lavage est obligatoire.',
                'type_lavage_id.exists' => 'Le type de lavage sélectionné n\'existe pas ou ne vous appartient pas.'
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation échouée.',
                    'errors' => $validator->errors(),
                ], 422);
            }

            $stationLavage = StationLavage::where('created_by', $manager->id)->first();
            if (!$stationLavage) {
                return response()->json([
                    'success' => false,
                    'message' => 'Station de lavage non trouvée. Seuls les administrateurs peuvent attribuer des véhicules.'
                ], 404);
            }

            $typeLavage = Type_lavage::where('id', $request->type_lavage_id)
                ->where('lavage_id', $manager->id)
                ->first();

            $laveurIds = $request->filled('laveur_ids')
                ? collect($request->laveur_ids)->map(fn ($id) => (int) $id)->unique()->values()
                : collect([(int) $request->laveur_id]);

            // Vérifier que les laveurs sont bien des laveurs actifs du manager
            $laveurs = Lavage::whereIn('id', $laveurIds)
                           ->where(['role' => 2, 'created_by' => $manager->id])
                           ->where('statut', 1)
                           ->get()
                           ->keyBy('id');

            if ($laveurs->count() !== $laveurIds->count()) {
                $laveursInvalides = $laveurIds->diff($laveurs->keys())->values();

                return response()->json([
                    'success' => false,
                    'message' => 'Laveur non trouvé ou inactif',
                    'detail' => 'Un ou plusieurs laveurs sélectionnés n\'existent pas, ne sont pas actifs ou ne vous appartiennent pas.',
                    'laveur_ids_invalides' => $laveursInvalides
                ], 404);
            }

            // Vérifier que le véhicule existe
            $vehicule = Vehicule::where('matricule', $request->matricule_vehicule)->first();
            if (!$vehicule) {
                return response()->json([
                    'success' => false,
                    'message' => 'Véhicule non trouvé',
                    'detail' => 'Aucun véhicule trouvé avec le matricule ' . $request->matricule_vehicule . '.'
                ], 404);
            }

            // Vérifier si le véhicule est déjà attribué aux mêmes laveurs
            $attributionsExistantes = AttributionVehicule::with(['laveur', 'typeLavage'])
                                                      ->where('matricule_vehicule', $request->matricule_vehicule)
                                                      ->whereIn('laveur_id', $laveurIds)
                                                      ->where('statut', 'en_cours')
                                                      ->get();

            if ($attributionsExistantes->isNotEmpty()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Véhicule déjà attribué à un ou plusieurs laveurs sélectionnés',
                    'detail' => 'Ce véhicule est déjà en cours de lavage pour un ou plusieurs laveurs sélectionnés.',
                    'attributions_existantes' => $attributionsExistantes->map(function ($attribution) {
                        return [
                            'laveur_id' => $attribution->laveur_id,
                            'laveur' => $attribution->laveur
                                ? $attribution->laveur->first_name . ' ' . $attribution->laveur->last_name
                                : 'Laveur non trouvé',
                            'date_attribution' => $attribution->created_at,
                            ...$this->formatTypeLavageFields($attribution->typeLavage, $attribution->type_lavage_id)
                        ];
                    })->values()
                ], 422);
            }

            DB::beginTransaction();
            try {
                $now = now();
                $attributions = collect();

                foreach ($laveurIds as $laveurId) {
                    $laveur = $laveurs->get($laveurId);

                    $attributions->push(AttributionVehicule::create([
                        'matricule_vehicule' => $vehicule->matricule,
                        'laveur_id' => $laveur->id,
                        'manager_id' => $manager->id,
                        'type_lavage_id' => $typeLavage->id,
                        'notes' => $request->notes,
                        'statut' => 'en_cours',
                        'date_attribution' => $now,
                        'date_debut' => $now,
                        'station_lavage_id' => $stationLavage->id
                    ]));
                }

                // Log de l'action
                \Log::info('Véhicule attribué à un ou plusieurs laveurs', [
                    'manager_id' => $manager->id,
                    'manager_name' => $manager->first_name . ' ' . $manager->last_name,
                    'laveur_ids' => $laveurIds,
                    'matricule_vehicule' => $vehicule->matricule,
                    'type_lavage_id' => $typeLavage->id,
                    'attribution_ids' => $attributions->pluck('id')
                ]);

                DB::commit();

                $formattedAttributions = $attributions->map(function ($attribution) use ($vehicule, $laveurs, $manager, $typeLavage) {
                    $laveur = $laveurs->get($attribution->laveur_id);

                    return [
                        'id' => $attribution->id,
                        'matricule_vehicule' => $vehicule->matricule,
                        'vehicule' => [
                            'marque' => $vehicule->marque->libelle ?? 'Non spécifiée',
                            'modele' => $vehicule->modele ?? 'Non spécifié'
                        ],
                        'laveur' => [
                            'id' => $laveur->id,
                            'nom_complet' => $laveur->first_name . ' ' . $laveur->last_name,
                            'mobile' => $laveur->mobile
                        ],
                        'manager' => [
                            'id' => $manager->id,
                            'nom_complet' => $manager->first_name . ' ' . $manager->last_name
                        ],
                        ...$this->formatTypeLavageFields($typeLavage, $attribution->type_lavage_id),
                        'notes' => $attribution->notes,
                        'statut' => $attribution->statut,
                        'date_attribution' => $attribution->date_attribution,
                        'date_debut' => $attribution->date_debut
                    ];
                })->values();

                return response()->json([
                    'success' => true,
                    'message' => $formattedAttributions->count() > 1
                        ? 'Véhicule attribué avec succès aux laveurs'
                        : 'Véhicule attribué avec succès',
                    'total_attributions' => $formattedAttributions->count(),
                    'attribution' => $formattedAttributions->first(),
                    'attributions' => $formattedAttributions
                ], 201);

            } catch (\Exception $e) {
                DB::rollBack();
                throw $e;
            }

        } catch (\Exception $e) {
            \Log::error('Erreur lors de l\'attribution du véhicule', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Une erreur est survenue lors de l\'attribution du véhicule.',
                'detail' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Marquer un véhicule comme terminé
     */
    public function terminerLavage(Request $request, $attributionId)
    {
        try {
            // Vérifier que l'utilisateur connecté est un manager
            $manager = Auth::guard('api')->user();
            if (!$manager || $manager->role != 1) {
                return response()->json([
                    'success' => false,
                    'message' => 'Accès non autorisé. Seuls les managers peuvent terminer les lavages.'
                ], 403);
            }

            $stationLavage = StationLavage::where('created_by', $manager->id)->first();
            if (!$stationLavage) {
                return response()->json([
                    'success' => false,
                    'message' => 'Station de lavage non trouvée. Seuls les administrateurs peuvent terminer les lavages.'
                ], 404);
            }

            // Trouver l'attribution
            $attribution = AttributionVehicule::with(['laveur', 'vehicule', 'typeLavage'])
                                            ->where('id', $attributionId)
                                            ->where('statut', 'en_cours')
                                            ->where('station_lavage_id', $stationLavage->id)
                                            ->first();

            if (!$attribution) {
                return response()->json([
                    'success' => false,
                    'message' => 'Attribution non trouvée',
                    'detail' => 'Aucune attribution en cours trouvée avec l\'ID ' . $attributionId . '.'
                ], 404);
            }

            $stationLavage = StationLavage::where('id', $attribution->station_lavage_id)->first();
            if (!$stationLavage) {
                return response()->json([
                    'success' => false,
                    'message' => 'Station de lavage non trouvée. Seuls les administrateurs peuvent terminer les lavages.'
                ], 404);
            }

            DB::beginTransaction();
            try {
                // Marquer comme terminé
                $attribution->statut = 'termine';
                $attribution->date_fin = now();
                $attribution->save();

                // Ajouter une case à la carte de fidélité
                $fidelite = Fidelite::where('usager_id', $attribution->vehicule->user_id)
                                   ->where('station_lavage_id', $stationLavage->id)
                                   ->where('lavage_id', $manager->id)
                                   ->where('matricule_vehicule', $attribution->matricule_vehicule)
                                   ->first();

                if (!$fidelite) {
                    $fidelite = Fidelite::create([
                        'usager_id' => $attribution->vehicule->user_id,
                        'lavage_id' => $manager->id,
                        'matricule_vehicule' => $attribution->matricule_vehicule,
                        'cases_remplies' => 0,
                        'total_cases' => 10,
                        'recompenses_gagnees' => 0,
                        'derniere_recompense' => null,
                        'station_lavage_id' => $stationLavage->id
                    ]);
                }

                $fidelite->cases_remplies++;
                $fidelite->save();

                // Vérifier si une récompense doit être attribuée
                $recompenseAttribuee = false;
                if ($fidelite->isCarteComplete()) {
                    $recompense = Recompense::create([
                        'usager_id' => $attribution->vehicule->user_id,
                        'lavage_id' => $manager->id,
                        'matricule_vehicule' => $attribution->matricule_vehicule,
                        'type_recompense' => 'lavage_gratuit',
                        'description' => 'Récompense pour fidélité - Carte complète',
                        'valeur' => 0.00,
                        'statut' => 'attribuee',
                        'date_attribution' => now(),
                        'utilisee' => false
                    ]);

                    $fidelite->recompenses_gagnees++;
                    $fidelite->derniere_recompense = now();
                    $fidelite->cases_remplies = 0; // Reset pour nouvelle carte
                    $fidelite->save();

                    $recompenseAttribuee = true;
                }

                // Log de l'action
                \Log::info('Lavage terminé', [
                    'manager_id' => $manager->id,
                    'manager_name' => $manager->first_name . ' ' . $manager->last_name,
                    'laveur_id' => $attribution->laveur->id,
                    'laveur_name' => $attribution->laveur->first_name . ' ' . $attribution->laveur->last_name,
                    'matricule_vehicule' => $attribution->matricule_vehicule,
                    'attribution_id' => $attribution->id,
                    'recompense_attribuee' => $recompenseAttribuee
                ]);

                DB::commit();

                return response()->json([
                    'success' => true,
                    'message' => 'Lavage terminé avec succès',
                    'attribution' => [
                        'id' => $attribution->id,
                        'matricule_vehicule' => $attribution->matricule_vehicule,
                        'laveur' => $attribution->laveur->first_name . ' ' . $attribution->laveur->last_name,
                        ...$this->formatTypeLavageFields($attribution->typeLavage, $attribution->type_lavage_id),
                        'date_debut' => $attribution->date_debut,
                        'date_fin' => $attribution->date_fin,
                        'duree' => $attribution->date_debut->diffInMinutes($attribution->date_fin) . ' minutes'
                    ],
                    'fidelite' => [
                        'cases_remplies' => $fidelite->cases_remplies,
                        'total_cases' => $fidelite->total_cases,
                        'pourcentage' => $fidelite->getProgressionPourcentage(),
                        'recompense_attribuee' => $recompenseAttribuee
                    ]
                ], 200);

            } catch (\Exception $e) {
                DB::rollBack();
                throw $e;
            }

        } catch (\Exception $e) {
            \Log::error('Erreur lors de la finalisation du lavage', [
                'attribution_id' => $attributionId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Une erreur est survenue lors de la finalisation du lavage.',
                'detail' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Obtenir les attributions en cours
     */
    public function getAttributionsEnCours()
    {
        try {
            // Vérifier que l'utilisateur connecté est un manager
            $manager = Auth::guard('api')->user();
            if (!$manager || $manager->role != 1) {
                return response()->json([
                    'success' => false,
                    'message' => 'Accès non autorisé. Seuls les managers peuvent voir les attributions.'
                ], 403);
            }
            $stationLavage = StationLavage::where('created_by', $manager->id)->first();
            if (!$stationLavage) {
                return response()->json([
                    'success' => false,
                    'message' => 'Station de lavage non trouvée. Seuls les administrateurs peuvent voir les attributions.'
                ], 404);
            }

            // Récupérer les attributions en cours
            $attributions = AttributionVehicule::with(['laveur', 'vehicule.marque', 'typeLavage'])
                                              ->where('statut', 'en_cours')
                                              ->where('station_lavage_id', $stationLavage->id)
                                              ->orderBy('date_attribution', 'desc')
                                              ->get();

            return response()->json([
                'success' => true,
                'message' => 'Attributions en cours récupérées avec succès',
                'total_attributions' => $attributions->count(),
                'attributions' => $attributions->map(function($attribution) {
                    return [
                        'id' => $attribution->id,
                        'matricule_vehicule' => $attribution->matricule_vehicule,
                        'vehicule' => [
                            'marque' => $attribution->vehicule->marque->libelle ?? 'Non spécifiée',
                            'modele' => $attribution->vehicule->modele ?? 'Non spécifié'
                        ],
                        'laveur' => [
                            'id' => $attribution->laveur->id,
                            'nom_complet' => $attribution->laveur->first_name . ' ' . $attribution->laveur->last_name,
                            'mobile' => $attribution->laveur->mobile
                        ],
                        ...$this->formatTypeLavageFields($attribution->typeLavage, $attribution->type_lavage_id),
                        'notes' => $attribution->notes,
                        'date_attribution' => $attribution->date_attribution,
                        'date_debut' => $attribution->date_debut,
                        'duree_ecoulee' => $attribution->date_debut->diffInMinutes(now()) . ' minutes'
                    ];
                })
            ], 200);

        } catch (\Exception $e) {
            \Log::error('Erreur lors de la récupération des attributions', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Une erreur est survenue lors de la récupération des attributions.',
                'detail' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Obtenir la liste des véhicules attribués à un laveur
     */
    public function getVehiculesByLaveur(Request $request, $laveurId)
    {
        try {
            $currentUser = Auth::guard('api')->user();
            if (!$currentUser) {
                return response()->json([
                    'success' => false,
                    'message' => 'Utilisateur non authentifié.'
                ], 401);
            }

            $validator = Validator::make(
                array_merge($request->all(), ['laveur_id' => $laveurId]),
                [
                    'laveur_id' => 'required|integer|exists:lavages,id',
                    'statut' => 'nullable|string|in:en_cours,termine,annule,tous'
                ],
                [
                    'laveur_id.required' => 'L\'ID du laveur est obligatoire.',
                    'laveur_id.integer' => 'L\'ID du laveur doit être un nombre entier.',
                    'laveur_id.exists' => 'Le laveur sélectionné n\'existe pas.',
                    'statut.in' => 'Le statut doit être : en_cours, termine, annule ou tous.'
                ]
            );

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation échouée.',
                    'errors' => $validator->errors(),
                ], 422);
            }

            $laveur = Lavage::where('id', $laveurId)
                ->where('role', 2)
                ->first();

            if (!$laveur) {
                return response()->json([
                    'success' => false,
                    'message' => 'Laveur non trouvé',
                    'detail' => 'Aucun laveur trouvé avec l\'ID ' . $laveurId . '.'
                ], 404);
            }

            if ($currentUser->role == 1) {
                if ($laveur->created_by != $currentUser->id) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Accès non autorisé.',
                        'detail' => 'Ce laveur ne vous appartient pas.'
                    ], 403);
                }
            } elseif ($currentUser->role == 2) {
                if ((int) $currentUser->id !== (int) $laveur->id) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Accès non autorisé.',
                        'detail' => 'Vous ne pouvez consulter que vos propres véhicules.'
                    ], 403);
                }
            } else {
                return response()->json([
                    'success' => false,
                    'message' => 'Accès non autorisé.'
                ], 403);
            }

            $statut = $request->get('statut', 'en_cours');

            $query = AttributionVehicule::with(['vehicule.marque', 'vehicule.user', 'typeLavage'])
                ->where('laveur_id', $laveur->id)
                ->orderBy('date_attribution', 'desc');

            if ($statut !== 'tous') {
                $query->where('statut', $statut);
            }

            $attributions = $query->get();

            return response()->json([
                'success' => true,
                'message' => 'Liste des véhicules du laveur récupérée avec succès',
                'laveur' => [
                    'id' => $laveur->id,
                    'nom_complet' => $laveur->first_name . ' ' . $laveur->last_name,
                    'mobile' => $laveur->mobile
                ],
                'statut_filtre' => $statut,
                'total_vehicules' => $attributions->count(),
                'vehicules' => $attributions->map(function ($attribution) {
                    $vehicule = $attribution->vehicule;

                    return [
                        'attribution_id' => $attribution->id,
                        'matricule_vehicule' => $attribution->matricule_vehicule,
                        'vehicule' => [
                            'matricule' => $vehicule->matricule ?? $attribution->matricule_vehicule,
                            'marque' => $vehicule && $vehicule->marque ? $vehicule->marque->libelle : 'Non spécifiée',
                            'modele' => $vehicule->modele ?? 'Non spécifié',
                            'couleur' => $vehicule->couleur ?? null,
                            'proprietaire' => $vehicule && $vehicule->user ? [
                                'id' => $vehicule->user->id,
                                'nom' => $vehicule->user->nom,
                                'prenoms' => $vehicule->user->prenoms,
                                'mobile' => $vehicule->user->mobile
                            ] : null
                        ],
                        ...$this->formatTypeLavageFields($attribution->typeLavage, $attribution->type_lavage_id),
                        'notes' => $attribution->notes,
                        'statut' => $attribution->statut,
                        'date_attribution' => $attribution->date_attribution,
                        'date_debut' => $attribution->date_debut,
                        'date_fin' => $attribution->date_fin,
                        'duree_ecoulee' => $attribution->date_debut
                            ? $attribution->date_debut->diffInMinutes($attribution->date_fin ?? now()) . ' minutes'
                            : null
                    ];
                })->values()
            ], 200);

        } catch (\Exception $e) {
            \Log::error('Erreur lors de la récupération des véhicules du laveur', [
                'laveur_id' => $laveurId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Une erreur est survenue lors de la récupération des véhicules du laveur.',
                'detail' => $e->getMessage()
            ], 500);
        }
    }

    protected function findLavageByPhone(string $indicatif, string $mobile): ?Lavage
    {
        return Lavage::whereIn('mobile', $this->mobileVariants($indicatif, $mobile))->first();
    }

    protected function formatTypeLavageFields(?Type_lavage $typeLavage, ?int $typeLavageId = null): array
    {
        return [
            'type_lavage_id' => $typeLavage?->id ?? $typeLavageId,
            'libelle' => $typeLavage?->libelle,
            'montant' => $typeLavage?->montant,
        ];
    }

    protected function formatLaveur(Lavage $laveur): array
    {
        return [
            'id' => $laveur->id,
            'first_name' => $laveur->first_name,
            'last_name' => $laveur->last_name,
            'full_name' => $laveur->first_name . ' ' . $laveur->last_name,
            'mobile' => $laveur->mobile,
            'email' => $laveur->email,
            'statut' => $laveur->statut,
            'statut_text' => $laveur->statut == 1 ? 'Actif' : 'Inactif',
            'role' => $laveur->role,
            'created_at' => $laveur->created_at,
            'updated_at' => $laveur->updated_at,
            'created_by' => $laveur->created_by,
        ];
    }

    protected function normalizeIndicatif(string $indicatif): string
    {
        return ltrim(trim($indicatif), '+');
    }

    protected function mobileVariants(string $indicatif, string $mobile): array
    {
        $mobile = trim($mobile);
        $mobileWithoutPlus = ltrim($mobile, '+');
        $mobileWithoutIndicatif = $mobileWithoutPlus;

        if (str_starts_with($mobileWithoutPlus, $indicatif)) {
            $mobileWithoutIndicatif = substr($mobileWithoutPlus, strlen($indicatif));
        }

        $mobileWithoutLeadingZero = ltrim($mobileWithoutIndicatif, '0');

        return array_values(array_unique(array_filter([
            $mobile,
            $mobileWithoutPlus,
            $mobileWithoutIndicatif,
            $mobileWithoutLeadingZero,
            '0' . $mobileWithoutLeadingZero,
            $indicatif . $mobileWithoutIndicatif,
            $indicatif . $mobileWithoutLeadingZero,
            '+' . $indicatif . $mobileWithoutIndicatif,
            '+' . $indicatif . $mobileWithoutLeadingZero,
        ])));
    }
}
