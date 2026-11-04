<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\DepotArticle;
use App\Models\DepotArticleLigne;
use App\Models\DepotArticlePaiement;
use App\Models\Lavage;
use App\Models\StationLavage;
use App\Models\TypeArticle;
use App\Models\User;
use App\Services\ClientLavageService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/**
 * Dépôt d'articles sans plaque (tapis, moquette, bâche, fauteuil...).
 * Circuit séparé des lavages de véhicules : dépôt → lavage → retrait, sans fidélité.
 */
class ArticleController extends Controller
{
    // Orange Money, Wave, MTN Mobile Money, Moov Money, carte bancaire
    const OPERATEURS_MOBILE_MONEY = ['OM', 'WAVE', 'MTN', 'MOOV', 'CB'];

    protected $clientLavageService;

    public function __construct(ClientLavageService $clientLavageService)
    {
        $this->clientLavageService = $clientLavageService;
    }

    /*
    |--------------------------------------------------------------------------
    | Catalogue des articles
    |--------------------------------------------------------------------------
    */

    public function indexTypes(Request $request)
    {
        [$user, $station, $error] = $this->resolveContext();
        if ($error) {
            return $error;
        }

        $types = TypeArticle::where('station_lavage_id', $station->id)
            ->when($request->boolean('actifs'), fn ($query) => $query->where('statut', true))
            ->orderBy('libelle')
            ->get();

        return response()->json([
            'success' => true,
            'message' => 'Types d\'articles récupérés avec succès.',
            'data' => $types,
        ], 200);
    }

    public function storeType(Request $request)
    {
        [$manager, $station, $error] = $this->resolveContext(true);
        if ($error) {
            return $error;
        }

        $validator = Validator::make($request->all(), [
            'libelle' => [
                'required',
                'string',
                'max:200',
                Rule::unique('type_articles', 'libelle')->where(fn ($query) => $query->where('station_lavage_id', $station->id)),
            ],
            'prix' => 'required|numeric|min:0',
            'montant_laveur' => 'nullable|numeric|min:0',
            'unite' => 'nullable|in:piece,m2',
        ], [
            'libelle.unique' => 'Cet article existe déjà dans votre catalogue.',
            'unite.in' => 'L\'unité doit être piece ou m2.',
        ]);

        if ($validator->fails()) {
            return $this->validationError($validator);
        }

        $type = TypeArticle::create([
            'station_lavage_id' => $station->id,
            'libelle' => $request->libelle,
            'prix' => $request->prix,
            'montant_laveur' => $request->input('montant_laveur', 0),
            'unite' => $request->input('unite', 'piece'),
            'statut' => true,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Type d\'article créé avec succès.',
            'data' => $type,
        ], 201);
    }

    public function updateType(Request $request, $id)
    {
        [$manager, $station, $error] = $this->resolveContext(true);
        if ($error) {
            return $error;
        }

        $type = TypeArticle::where('station_lavage_id', $station->id)->find($id);
        if (!$type) {
            return $this->notFound('Type d\'article non trouvé.');
        }

        $validator = Validator::make($request->all(), [
            'libelle' => [
                'sometimes',
                'required',
                'string',
                'max:200',
                Rule::unique('type_articles', 'libelle')
                    ->where(fn ($query) => $query->where('station_lavage_id', $station->id))
                    ->ignore($type->id),
            ],
            'prix' => 'sometimes|required|numeric|min:0',
            'montant_laveur' => 'sometimes|required|numeric|min:0',
            'unite' => 'sometimes|required|in:piece,m2',
            'statut' => 'sometimes|required|boolean',
        ], [
            'libelle.unique' => 'Cet article existe déjà dans votre catalogue.',
            'unite.in' => 'L\'unité doit être piece ou m2.',
        ]);

        if ($validator->fails()) {
            return $this->validationError($validator);
        }

        // Les dépôts existants gardent le prix figé sur leurs lignes
        $type->update($request->only(['libelle', 'prix', 'montant_laveur', 'unite', 'statut']));

        return response()->json([
            'success' => true,
            'message' => 'Type d\'article mis à jour avec succès.',
            'data' => $type->fresh(),
        ], 200);
    }

    public function destroyType($id)
    {
        [$manager, $station, $error] = $this->resolveContext(true);
        if ($error) {
            return $error;
        }

        $type = TypeArticle::where('station_lavage_id', $station->id)->find($id);
        if (!$type) {
            return $this->notFound('Type d\'article non trouvé.');
        }

        if (DepotArticleLigne::where('type_article_id', $type->id)->exists()) {
            return response()->json([
                'success' => false,
                'message' => 'Cet article est déjà utilisé par un dépôt. Désactivez-le plutôt (statut = false).',
            ], 409);
        }

        $type->delete();

        return response()->json([
            'success' => true,
            'message' => 'Type d\'article supprimé avec succès.',
        ], 200);
    }

    /*
    |--------------------------------------------------------------------------
    | Dépôts
    |--------------------------------------------------------------------------
    */

    public function indexDepots(Request $request)
    {
        [$user, $station, $error] = $this->resolveContext();
        if ($error) {
            return $error;
        }

        $validator = Validator::make($request->all(), [
            'statut' => 'nullable|in:recu,en_cours,pret,livre,annule,tous',
            'date_debut' => 'nullable|date',
            'date_fin' => 'nullable|date|after_or_equal:date_debut',
            'numero_ticket' => 'nullable|string|max:30',
            'client' => 'nullable|string|max:255',
            'laveur_id' => 'nullable|integer',
            'type_article_id' => 'nullable|integer',
            'mode_paiement' => 'nullable|in:espece,mobile_money',
            'operateur_paiement' => ['nullable', Rule::in(self::OPERATEURS_MOBILE_MONEY)],
            'statut_paiement' => 'nullable|in:non_paye,partiel,paye',
            'per_page' => 'nullable|integer|min:1|max:100',
            'page' => 'nullable|integer|min:1',
        ], [
            'date_fin.after_or_equal' => 'La date de fin doit être supérieure ou égale à la date de début.',
            'mode_paiement.in' => 'Le moyen de paiement doit être espece ou mobile_money.',
            'operateur_paiement.in' => 'L’opérateur doit être OM, WAVE, MTN, MOOV ou CB.',
        ]);

        if ($validator->fails()) {
            return $this->validationError($validator);
        }

        $query = $this->filteredDepotsQuery($request, $user, $station);

        if ($request->filled('type_article_id')) {
            $query->whereHas('lignes', fn ($subQuery) => $subQuery->where('type_article_id', $request->type_article_id));
        }

        // Dépôts ayant au moins un paiement avec ce mode / cet opérateur
        if ($request->filled('mode_paiement') || $request->filled('operateur_paiement')) {
            $query->whereHas('paiements', function ($subQuery) use ($request) {
                $subQuery->when($request->filled('mode_paiement'), fn ($q) => $q->where('mode_paiement', $request->mode_paiement))
                    ->when($request->filled('operateur_paiement'), fn ($q) => $q->where('operateur_paiement', $request->operateur_paiement));
            });
        }

        match ($request->statut_paiement) {
            'non_paye' => $query->where('montant_paye', '<=', 0),
            'partiel' => $query->where('montant_paye', '>', 0)->whereColumn('montant_paye', '<', 'montant_total'),
            'paye' => $query->where('montant_paye', '>', 0)->whereColumn('montant_paye', '>=', 'montant_total'),
            default => null,
        };

        $statut = $request->get('statut', 'tous');
        if ($statut !== 'tous') {
            $query->where('statut', $statut);
        }

        if ($request->filled('numero_ticket')) {
            $query->where('numero_ticket', 'like', '%' . trim($request->numero_ticket) . '%');
        }

        if ($request->filled('client')) {
            $client = '%' . trim($request->client) . '%';
            $query->where(function ($query) use ($client) {
                $query->where('client_nom', 'like', $client)
                    ->orWhere('client_mobile', 'like', $client)
                    ->orWhereHas('user', function ($subQuery) use ($client) {
                        $subQuery->where('nom', 'like', $client)
                            ->orWhere('prenoms', 'like', $client)
                            ->orWhere('mobile', 'like', $client);
                    });
            });
        }

        $totauxQuery = (clone $query)->where('statut', '!=', DepotArticle::STATUT_ANNULE);
        $totaux = [
            'total_depots' => (clone $query)->count(),
            'montant_facture' => (float) (clone $totauxQuery)->sum('montant_total'),
            'montant_encaisse' => (float) (clone $totauxQuery)->sum('montant_paye'),
            'reste_a_encaisser' => (float) (clone $totauxQuery)->sum(DB::raw('GREATEST(montant_total - montant_paye, 0)')),
        ];

        $depots = $query->with(['lignes', 'paiements', 'laveurs', 'user', 'manager'])
            ->orderBy('date_depot', 'desc')
            ->paginate((int) $request->get('per_page', 20));

        return response()->json([
            'success' => true,
            'message' => $depots->isEmpty() ? 'Aucun dépôt trouvé' : 'Dépôts récupérés avec succès',
            'filtres' => $request->only([
                'statut', 'date_debut', 'date_fin', 'numero_ticket', 'client', 'laveur_id',
                'type_article_id', 'mode_paiement', 'operateur_paiement', 'statut_paiement',
            ]),
            'totaux' => $totaux,
            'pagination' => [
                'current_page' => $depots->currentPage(),
                'last_page' => $depots->lastPage(),
                'per_page' => $depots->perPage(),
                'total' => $depots->total(),
            ],
            'depots' => collect($depots->items())->map(fn ($depot) => $this->formatDepot($depot))->values(),
        ], 200);
    }

    public function showDepot($id)
    {
        [$user, $station, $error] = $this->resolveContext();
        if ($error) {
            return $error;
        }

        $depot = $this->findDepot($id, $station, $user);
        if (!$depot) {
            return $this->notFound('Dépôt non trouvé.');
        }

        return response()->json([
            'success' => true,
            'depot' => $this->formatDepot($depot),
        ], 200);
    }

    public function storeDepot(Request $request)
    {
        [$manager, $station, $error] = $this->resolveContext(true);
        if ($error) {
            return $error;
        }

        $validator = Validator::make($request->all(), [
            'user_id' => 'nullable|integer|exists:users,id',
            'client_nom' => 'nullable|string|max:255',
            'client_mobile' => 'nullable|string|max:20',
            'lignes' => 'required|array|min:1',
            'lignes.*.type_article_id' => [
                'required',
                'integer',
                Rule::exists('type_articles', 'id')->where(fn ($query) => $query->where('station_lavage_id', $station->id)->where('statut', true)),
            ],
            'lignes.*.quantite' => 'nullable|numeric|min:0.01',
            'lignes.*.description' => 'nullable|string|max:255',
            'laveur_ids' => 'nullable|array',
            'laveur_ids.*' => 'integer|distinct',
            'date_retrait_prevue' => 'nullable|date',
            'notes' => 'nullable|string|max:1000',
            'avance' => 'nullable|array',
            'avance.montant' => 'required_with:avance|numeric|min:1',
            'avance.mode_paiement' => ['required_with:avance', Rule::in(['espece', 'mobile_money'])],
            'avance.operateur_paiement' => [
                'nullable',
                'required_if:avance.mode_paiement,mobile_money',
                'prohibited_unless:avance.mode_paiement,mobile_money',
                Rule::in(self::OPERATEURS_MOBILE_MONEY),
            ],
        ], [
            'lignes.required' => 'Au moins un article est obligatoire.',
            'lignes.*.type_article_id.exists' => 'Un des articles n\'existe pas, est désactivé ou ne vous appartient pas.',
            'avance.operateur_paiement.required_if' => 'L’opérateur est obligatoire pour un paiement mobile money.',
            'avance.operateur_paiement.in' => 'L’opérateur doit être OM, WAVE, MTN, MOOV ou CB.',
        ]);

        if ($validator->fails()) {
            return $this->validationError($validator);
        }

        $laveurError = $this->checkLaveurs($request->input('laveur_ids', []), $station);
        if ($laveurError) {
            return $laveurError;
        }

        try {
            $depot = DB::transaction(function () use ($request, $manager, $station) {
                $client = $request->filled('user_id') ? User::find($request->user_id) : null;

                $depot = DepotArticle::create([
                    'station_lavage_id' => $station->id,
                    'manager_id' => $manager->id,
                    'user_id' => $client?->id,
                    'client_nom' => $request->input('client_nom') ?? ($client ? trim($client->nom . ' ' . $client->prenoms) : null),
                    'client_mobile' => $request->input('client_mobile') ?? $client?->mobile,
                    'statut' => DepotArticle::STATUT_RECU,
                    'date_depot' => now(),
                    'date_retrait_prevue' => $request->date_retrait_prevue,
                    'notes' => $request->notes,
                ]);

                $depot->numero_ticket = 'DEP-' . str_pad((string) $depot->id, 6, '0', STR_PAD_LEFT);

                $types = TypeArticle::whereIn('id', collect($request->lignes)->pluck('type_article_id'))->get()->keyBy('id');
                $total = 0;

                foreach ($request->lignes as $ligne) {
                    $type = $types->get($ligne['type_article_id']);
                    $quantite = (float) ($ligne['quantite'] ?? 1);
                    $montant = round((float) $type->prix * $quantite, 2);
                    $total += $montant;

                    $depot->lignes()->create([
                        'type_article_id' => $type->id,
                        'libelle' => $type->libelle,
                        'description' => $ligne['description'] ?? null,
                        'quantite' => $quantite,
                        'unite' => $type->unite,
                        'prix_unitaire' => $type->prix,
                        'montant_laveur_unitaire' => $type->montant_laveur,
                        'montant' => $montant,
                    ]);
                }

                $depot->montant_total = $total;

                if ($request->filled('avance')) {
                    $montantAvance = min((float) $request->input('avance.montant'), $total);

                    $depot->paiements()->create([
                        'type' => 'avance',
                        'montant' => $montantAvance,
                        'mode_paiement' => $request->input('avance.mode_paiement'),
                        'operateur_paiement' => $request->input('avance.mode_paiement') === 'mobile_money'
                            ? $request->input('avance.operateur_paiement')
                            : null,
                        'encaisse_par' => $manager->id,
                        'date_paiement' => now(),
                    ]);

                    $depot->montant_paye = $montantAvance;
                }

                $depot->save();

                if ($request->filled('laveur_ids')) {
                    $depot->laveurs()->sync($request->laveur_ids);
                }

                return $depot;
            });

            return response()->json([
                'success' => true,
                'message' => 'Dépôt enregistré avec succès.',
                'depot' => $this->formatDepot($depot->fresh(['lignes', 'paiements', 'laveurs', 'user', 'manager'])),
            ], 201);

        } catch (\Exception $e) {
            Log::error('Erreur lors de la création d\'un dépôt d\'articles', [
                'error' => $e->getMessage(),
                'manager_id' => $manager->id,
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Une erreur est survenue lors de l\'enregistrement du dépôt.',
                'detail' => $e->getMessage(),
            ], 500);
        }
    }

    public function updateStatut(Request $request, $id)
    {
        [$manager, $station, $error] = $this->resolveContext(true);
        if ($error) {
            return $error;
        }

        $validator = Validator::make($request->all(), [
            'statut' => 'required|in:en_cours,pret,annule',
            'laveur_ids' => 'nullable|array',
            'laveur_ids.*' => 'integer|distinct',
        ], [
            'statut.in' => 'Le statut doit être : en_cours, pret ou annule. Utilisez /livrer pour remettre les articles.',
        ]);

        if ($validator->fails()) {
            return $this->validationError($validator);
        }

        $depot = $this->findDepot($id, $station, $manager);
        if (!$depot) {
            return $this->notFound('Dépôt non trouvé.');
        }

        if (!$depot->isModifiable()) {
            return response()->json([
                'success' => false,
                'message' => 'Ce dépôt est déjà ' . strtolower(DepotArticle::STATUTS[$depot->statut]) . ' et ne peut plus être modifié.',
            ], 409);
        }

        $laveurError = $this->checkLaveurs($request->input('laveur_ids', []), $station);
        if ($laveurError) {
            return $laveurError;
        }

        DB::transaction(function () use ($request, $depot) {
            $depot->statut = $request->statut;
            $depot->save();

            if ($request->filled('laveur_ids')) {
                $depot->laveurs()->sync($request->laveur_ids);
            }
        });

        return response()->json([
            'success' => true,
            'message' => 'Statut du dépôt mis à jour : ' . DepotArticle::STATUTS[$depot->statut] . '.',
            'depot' => $this->formatDepot($depot->fresh(['lignes', 'paiements', 'laveurs', 'user', 'manager'])),
        ], 200);
    }

    public function livrer(Request $request, $id)
    {
        [$manager, $station, $error] = $this->resolveContext(true);
        if ($error) {
            return $error;
        }

        $depot = $this->findDepot($id, $station, $manager);
        if (!$depot) {
            return $this->notFound('Dépôt non trouvé.');
        }

        if (!$depot->isModifiable()) {
            return response()->json([
                'success' => false,
                'message' => 'Ce dépôt est déjà ' . strtolower(DepotArticle::STATUTS[$depot->statut]) . '.',
            ], 409);
        }

        $resteAPayer = $depot->reste_a_payer;

        $validator = Validator::make($request->all(), [
            'mode_paiement' => [$resteAPayer > 0 ? 'required' : 'nullable', Rule::in(['espece', 'mobile_money'])],
            'operateur_paiement' => [
                'nullable',
                'required_if:mode_paiement,mobile_money',
                'prohibited_unless:mode_paiement,mobile_money',
                Rule::in(self::OPERATEURS_MOBILE_MONEY),
            ],
        ], [
            'mode_paiement.required' => 'Le moyen de paiement est obligatoire pour encaisser le reste (' . $resteAPayer . ').',
            'mode_paiement.in' => 'Le moyen de paiement doit être espece ou mobile_money.',
            'operateur_paiement.required_if' => 'L’opérateur est obligatoire pour un paiement mobile money.',
            'operateur_paiement.in' => 'L’opérateur doit être OM, WAVE, MTN, MOOV ou CB.',
        ]);

        if ($validator->fails()) {
            return $this->validationError($validator);
        }

        DB::transaction(function () use ($request, $depot, $manager, $resteAPayer) {
            $depot = DepotArticle::whereKey($depot->id)->lockForUpdate()->first();

            if ($resteAPayer > 0) {
                $depot->paiements()->create([
                    'type' => 'solde',
                    'montant' => $resteAPayer,
                    'mode_paiement' => $request->mode_paiement,
                    'operateur_paiement' => $request->mode_paiement === 'mobile_money' ? $request->operateur_paiement : null,
                    'encaisse_par' => $manager->id,
                    'date_paiement' => now(),
                ]);

                $depot->montant_paye = (float) $depot->montant_paye + $resteAPayer;
            }

            $depot->statut = DepotArticle::STATUT_LIVRE;
            $depot->date_retrait = now();
            $depot->save();
        });

        return response()->json([
            'success' => true,
            'message' => 'Articles remis au client avec succès.',
            'depot' => $this->formatDepot($depot->fresh(['lignes', 'paiements', 'laveurs', 'user', 'manager'])),
        ], 200);
    }

    public function stats(Request $request)
    {
        [$user, $station, $error] = $this->resolveContext();
        if ($error) {
            return $error;
        }

        $validator = Validator::make($request->all(), [
            'date_debut' => 'nullable|date',
            'date_fin' => 'nullable|date|after_or_equal:date_debut',
        ], [
            'date_fin.after_or_equal' => 'La date de fin doit être supérieure ou égale à la date de début.',
        ]);

        if ($validator->fails()) {
            return $this->validationError($validator);
        }

        $depots = $this->filteredDepotsQuery($request, $user, $station)
            ->with(['lignes', 'laveurs'])
            ->get();
        $actifs = $depots->where('statut', '!=', DepotArticle::STATUT_ANNULE);
        $livres = $depots->where('statut', DepotArticle::STATUT_LIVRE);

        // Encaissements de la période, quelle que soit la date du dépôt
        $paiements = DepotArticlePaiement::whereHas('depot', fn ($query) => $query->where('station_lavage_id', $station->id))
            ->when($user->role == 2, fn ($query) => $query->whereHas('depot.laveurs', fn ($subQuery) => $subQuery->where('lavages.id', $user->id)))
            ->when($request->filled('date_debut'), fn ($query) => $query->where('date_paiement', '>=', \Carbon\Carbon::parse($request->date_debut)->startOfDay()))
            ->when($request->filled('date_fin'), fn ($query) => $query->where('date_paiement', '<=', \Carbon\Carbon::parse($request->date_fin)->endOfDay()))
            ->get();

        return response()->json([
            'success' => true,
            'message' => 'Statistiques des dépôts d\'articles récupérées avec succès.',
            'station' => [
                'id' => $station->id,
                'name' => $station->name,
            ],
            'periode' => [
                'date_debut' => $request->date_debut,
                'date_fin' => $request->date_fin,
            ],
            'global' => [
                'total_depots' => $depots->count(),
                'par_statut' => collect(DepotArticle::STATUTS)->map(fn ($libelle, $statut) => $depots->where('statut', $statut)->count()),
                'total_articles' => (float) $actifs->sum(fn ($depot) => $depot->lignes->sum('quantite')),
                'montant_facture' => (float) $actifs->sum('montant_total'),
                'montant_encaisse' => (float) $paiements->sum('montant'),
                'reste_a_encaisser' => (float) $actifs->sum(fn ($depot) => $depot->reste_a_payer),
                'gains_laveurs_realises' => (float) $livres->sum(fn ($depot) => $depot->montant_laveur * $depot->laveurs->count()),
            ],
            'par_article' => $actifs->flatMap->lignes->groupBy('libelle')->map(fn ($lignes, $libelle) => [
                'libelle' => $libelle,
                'quantite' => (float) $lignes->sum('quantite'),
                'montant' => (float) $lignes->sum('montant'),
            ])->values(),
            'par_mode_paiement' => $paiements->groupBy('mode_paiement')->map(fn ($items, $mode) => [
                'mode_paiement' => $mode,
                'total_paiements' => $items->count(),
                'montant' => (float) $items->sum('montant'),
                'par_operateur' => $mode === 'mobile_money'
                    ? $items->groupBy(fn ($paiement) => $paiement->operateur_paiement ?? 'non_renseigne')
                        ->map(fn ($itemsOperateur, $operateur) => [
                            'operateur_paiement' => $operateur,
                            'total_paiements' => $itemsOperateur->count(),
                            'montant' => (float) $itemsOperateur->sum('montant'),
                        ])->values()
                    : [],
            ])->values(),
        ], 200);
    }

    /*
    |--------------------------------------------------------------------------
    | Helpers
    |--------------------------------------------------------------------------
    */

    /**
     * @return array{0: ?Lavage, 1: ?StationLavage, 2: ?\Illuminate\Http\JsonResponse}
     */
    protected function resolveContext(bool $managerOnly = false): array
    {
        $user = Auth::guard('api')->user();

        if (!$user) {
            return [null, null, response()->json([
                'success' => false,
                'message' => 'Utilisateur non authentifié.',
            ], 401)];
        }

        $allowedRoles = $managerOnly ? [1] : [1, 2];
        if (!in_array((int) $user->role, $allowedRoles, true)) {
            return [null, null, response()->json([
                'success' => false,
                'message' => $managerOnly
                    ? 'Accès non autorisé. Seuls les managers peuvent effectuer cette action.'
                    : 'Accès non autorisé.',
            ], 403)];
        }

        $station = $this->clientLavageService->resolveStation($user);
        if (!$station) {
            return [null, null, response()->json([
                'success' => false,
                'message' => 'Station de lavage non trouvée.',
            ], 404)];
        }

        return [$user, $station, null];
    }

    protected function filteredDepotsQuery(Request $request, Lavage $user, StationLavage $station)
    {
        $query = DepotArticle::where('station_lavage_id', $station->id);

        // Un laveur ne voit que les dépôts qui lui sont attribués
        $laveurId = $user->role == 2 ? $user->id : $request->laveur_id;
        if ($laveurId) {
            $query->whereHas('laveurs', fn ($subQuery) => $subQuery->where('lavages.id', $laveurId));
        }

        if ($request->filled('date_debut')) {
            $query->where('date_depot', '>=', \Carbon\Carbon::parse($request->date_debut)->startOfDay());
        }

        if ($request->filled('date_fin')) {
            $query->where('date_depot', '<=', \Carbon\Carbon::parse($request->date_fin)->endOfDay());
        }

        return $query;
    }

    protected function findDepot($id, StationLavage $station, Lavage $user): ?DepotArticle
    {
        return DepotArticle::with(['lignes', 'paiements', 'laveurs', 'user', 'manager'])
            ->where('station_lavage_id', $station->id)
            ->when($user->role == 2, fn ($query) => $query->whereHas('laveurs', fn ($subQuery) => $subQuery->where('lavages.id', $user->id)))
            ->find($id);
    }

    protected function checkLaveurs(array $laveurIds, StationLavage $station)
    {
        if (empty($laveurIds)) {
            return null;
        }

        $valides = Lavage::whereIn('id', $laveurIds)
            ->where(['role' => 2, 'created_by' => $station->created_by, 'statut' => 1])
            ->pluck('id');

        $invalides = collect($laveurIds)->map(fn ($id) => (int) $id)->diff($valides)->values();

        if ($invalides->isNotEmpty()) {
            return response()->json([
                'success' => false,
                'message' => 'Laveur non trouvé ou inactif',
                'detail' => 'Un ou plusieurs laveurs sélectionnés n\'existent pas, ne sont pas actifs ou ne vous appartiennent pas.',
                'laveur_ids_invalides' => $invalides,
            ], 404);
        }

        return null;
    }

    protected function formatDepot(DepotArticle $depot): array
    {
        return [
            'id' => $depot->id,
            'numero_ticket' => $depot->numero_ticket,
            'statut' => $depot->statut,
            'statut_text' => DepotArticle::STATUTS[$depot->statut] ?? 'Inconnu',
            'client' => [
                'user_id' => $depot->user_id,
                'nom' => $depot->client_nom,
                'mobile' => $depot->client_mobile,
            ],
            'lignes' => $depot->lignes->map(fn ($ligne) => [
                'id' => $ligne->id,
                'type_article_id' => $ligne->type_article_id,
                'libelle' => $ligne->libelle,
                'description' => $ligne->description,
                'quantite' => $ligne->quantite,
                'unite' => $ligne->unite,
                'prix_unitaire' => $ligne->prix_unitaire,
                'montant' => $ligne->montant,
            ])->values(),
            'total_articles' => (float) $depot->lignes->sum('quantite'),
            'paiement' => [
                'montant_total' => $depot->montant_total,
                'montant_paye' => $depot->montant_paye,
                'reste_a_payer' => $depot->reste_a_payer,
                'statut_paiement' => $depot->statut_paiement,
                'paiements' => $depot->paiements->map(fn ($paiement) => [
                    'id' => $paiement->id,
                    'type' => $paiement->type,
                    'montant' => $paiement->montant,
                    'mode_paiement' => $paiement->mode_paiement,
                    'operateur_paiement' => $paiement->operateur_paiement,
                    'date_paiement' => $paiement->date_paiement,
                ])->values(),
            ],
            'laveurs' => $depot->laveurs->map(fn ($laveur) => [
                'id' => $laveur->id,
                'nom_complet' => $laveur->first_name . ' ' . $laveur->last_name,
                'mobile' => $laveur->mobile,
            ])->values(),
            'montant_laveur' => $depot->montant_laveur,
            'manager' => $depot->manager ? [
                'id' => $depot->manager->id,
                'nom_complet' => $depot->manager->first_name . ' ' . $depot->manager->last_name,
            ] : null,
            'dates' => [
                'date_depot' => $depot->date_depot,
                'date_retrait_prevue' => $depot->date_retrait_prevue,
                'date_retrait' => $depot->date_retrait,
            ],
            'notes' => $depot->notes,
        ];
    }

    protected function validationError($validator)
    {
        return response()->json([
            'success' => false,
            'message' => 'Validation échouée.',
            'errors' => $validator->errors(),
        ], 422);
    }

    protected function notFound(string $message)
    {
        return response()->json([
            'success' => false,
            'message' => $message,
        ], 404);
    }
}
