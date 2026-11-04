<?php

namespace App\Services;

use App\Models\AbonnementUsager;
use App\Models\Forfait_usager;
use App\Models\Lavage;
use App\Models\Parrain;
use App\Models\ReferralCode;
use App\Models\StationLavage;
use App\Models\User;
use App\Models\Vehicule;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Création d'un client par un lavage.
 *
 * Le client bénéficie de tous les avantages d'un usager (abonnement, cartes de réduction,
 * parrainage, fidélité) mais n'est pas compté comme usager réel : activated_at reste NULL
 * jusqu'à sa première connexion / inscription sur l'app usager.
 */
class ClientLavageService
{
    public function __construct(
        protected WasabiService $wasabiService,
        protected ReductionCardService $reductionCardService
    ) {
    }

    public static function validationRules(): array
    {
        return [
            'immatriculation' => 'required|string|unique:vehicules,matricule',
            'indicatif' => 'nullable|string|max:10',
            'nom' => 'nullable|string|max:255',
            'prenoms' => 'nullable|string|max:255',
            'mobile' => 'nullable|numeric|unique:users,mobile',
            'is_whatsapp' => 'nullable|boolean',
            'carte_grise' => 'nullable|string|unique:vehicules,carte_grise',
            'photos' => 'nullable|array|size:4',
            'photos.*' => 'file|image|max:25048',
            'type_de_vehicule_id' => 'nullable|exists:type_de_vehicules,id',
            'marque_id' => 'nullable|exists:marques,id',
            'type_de_carburant_id' => 'nullable|exists:type_de_carburants,id',
            'couleur' => 'nullable|string|max:50',
            'modele' => 'nullable|string|max:255',
        ];
    }

    public static function validationMessages(): array
    {
        return [
            'immatriculation.required' => 'L’immatriculation du véhicule est obligatoire.',
            'immatriculation.unique' => 'Un véhicule est déjà enregistré avec cette immatriculation.',
            'mobile.unique' => 'Ce numéro de téléphone est déjà utilisé.',
        ];
    }

    public function resolveStation(Lavage $lavage): ?StationLavage
    {
        $ownerIds = collect([$lavage->id, $lavage->created_by])->filter()->unique()->values();

        return StationLavage::whereIn('created_by', $ownerIds)
            ->get()
            ->sortBy(fn ($station) => $ownerIds->search($station->created_by))
            ->first();
    }

    /**
     * @return array{user: User, vehicule: Vehicule, parrain: ?Parrain, abonnement: ?AbonnementUsager, password: string}
     */
    public function register(Request $request, Lavage $lavage, StationLavage $station): array
    {
        $parrain = Parrain::where('station_de_lavage_id', $station->id)->first();
        $rawPassword = (string) random_int(100000, 999999);

        [$user, $vehicule, $abonnement] = DB::transaction(function () use ($request, $lavage, $station, $parrain, $rawPassword) {
            $user = new User();
            $user->uuid = (string) Str::uuid();
            $user->indicatif = $request->input('indicatif');
            $user->mobile = $request->input('mobile');
            $user->nom = $request->input('nom');
            $user->prenoms = $request->input('prenoms');
            $user->password = bcrypt($rawPassword);
            $user->is_whatsapp = $request->boolean('is_whatsapp');
            $user->lavage_id = $lavage->id;
            $user->station_de_lavage_id = $station->id;
            $user->activated_at = null;
            $user->save();

            $vehicule = new Vehicule();
            $vehicule->matricule = $request->immatriculation;
            $vehicule->carte_grise = $request->input('carte_grise');
            $vehicule->type_de_vehicule_id = $request->input('type_de_vehicule_id');
            $vehicule->marque_id = $request->input('marque_id');
            $vehicule->type_de_carburant_id = $request->input('type_de_carburant_id');
            $vehicule->couleur = $request->input('couleur');
            $vehicule->modele = $request->input('modele');
            $vehicule->user_id = $user->id;
            $vehicule->created_by = $lavage->id;
            $vehicule->provenance = 'lavage';

            if ($request->hasFile('photos')) {
                $photosPaths = [];

                foreach ($request->file('photos') as $photo) {
                    $photosPaths[] = $this->wasabiService->uploadFile($photo, 'vehicules/photos', 'vehicule');
                }

                $vehicule->photos = json_encode($photosPaths);
            }

            $vehicule->save();

            if ($parrain) {
                ReferralCode::create([
                    'user_id' => $user->id,
                    'code' => $parrain->code,
                ]);
            }

            $abonnement = $this->createAutoAbonnement($user);

            return [$user, $vehicule, $abonnement];
        });

        return [
            'user' => $user->refresh(),
            'vehicule' => $vehicule,
            'parrain' => $parrain,
            'abonnement' => $abonnement?->load('forfait'),
            'password' => $rawPassword,
        ];
    }

    public function buildCredentialsMessage(string $mobileWithIndicatif, string $password, ?Parrain $parrain): string
    {
        $parrainLine = $parrain ? "\nCode parrain : {$parrain->code}" : '';

        return strtoupper(
            "Votre compte a ete cree avec succes\n" .
            "Voici vos identifiants de connexion :\n" .
            "Numero de telephone : $mobileWithIndicatif\n" .
            "Mot de passe : $password" . $parrainLine
        );
    }

    protected function createAutoAbonnement(User $user): ?AbonnementUsager
    {
        $enabled = filter_var(config('services.register_auto_abonnement.enabled'), FILTER_VALIDATE_BOOLEAN);

        if (!$enabled) {
            return null;
        }

        $forfaitCode = strtoupper(trim((string) config('services.register_auto_abonnement.forfait', 'FREEMIUM')));
        $allowedForfaits = ['FREEMIUM', 'PERSONNEL', 'FAMILLE'];

        if (!in_array($forfaitCode, $allowedForfaits, true)) {
            throw new \InvalidArgumentException("Forfait d'abonnement automatique invalide: {$forfaitCode}.");
        }

        $forfait = Forfait_usager::whereRaw('UPPER(TRIM(libelle)) = ?', [$forfaitCode])
            ->where('statut', 1)
            ->first();

        if (!$forfait) {
            throw new \InvalidArgumentException("Forfait d'abonnement automatique actif introuvable: {$forfaitCode}.");
        }

        $abonnement = AbonnementUsager::create([
            'user_id' => $user->id,
            'forfait_id' => $forfait->id,
            'date_debut' => now()->toDateString(),
            'date_fin' => now()->addMonths((int) $forfait->duree)->toDateString(),
            'statut' => 1,
            'is_free' => 1,
        ]);

        $this->reductionCardService->assignCardsToSubscription($abonnement);

        return $abonnement;
    }
}
