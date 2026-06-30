<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\QrcodeGenerate;
use App\Models\QrcodeAssignment;
use App\Models\StationLavage;
use App\Models\Vehicule;
use App\Models\Parrain;
use App\Models\Marque;
use App\Models\Type_de_vehicule;
use App\Models\Type_de_carburant;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Log;
use App\Models\User;
use App\Models\Type_lavage;
use App\Models\ReferralCode;
use Illuminate\Support\Facades\DB;
use App\Services\WasabiService;

class StationLavageController extends Controller
{
    protected $wasabiService;

    public function __construct(WasabiService $wasabiService)
    {
        $this->wasabiService = $wasabiService;
    }

    public function index()
    {
        $stations = StationLavage::with('creator')->get()->map(function ($station) {
            return $this->attachStationLavageMediaUrls($station);
        });

        return response()->json($stations);
    }

	public function typeLavage()
    {
        $typeLavage = Type_lavage::all();
        return response()->json($typeLavage);
    }

	public function typeVehicule()
    {
        $typeVehicule = Type_de_vehicule::all();
        return response()->json($typeVehicule);
    }

	public function typeCarburant()
    {
        $type_de_carburant = Type_de_carburant::all();
        return response()->json($type_de_carburant);
    }

	public function marque()
    {
        $marque = Marque::all();
        return response()->json($marque);
    }

    private function generateUniqueCode()
    {
        do {
            $code = strtoupper(Str::random(8));
        } while (Parrain::where('code', $code)->exists());

        return $code;
    }

    public function store(Request $request)
    {
        try {
            Log::info('Début de la création de la station de lavage', ['request' => $request->all()]);

            $validator = Validator::make($request->all(), [
                'name' => 'required|string|max:200',
                'adresse' => 'nullable|string|max:500',
                'contact' => 'nullable|string|max:20',
                'longitude' => 'nullable|string|max:20',
                'latitude' => 'nullable|string|max:20',
                'logo' => 'nullable|image|mimes:jpeg,png,jpg,gif,svg|max:2048'
            ]);

            if ($validator->fails()) {
                Log::error('Validation échouée', ['errors' => $validator->errors()]);
                return response()->json(['error' => $validator->errors()], 422);
            }

            $logoPath = null;
            if ($request->hasFile('logo')) {
                try {
                    Log::info('Traitement du logo', ['file' => $request->file('logo')]);
                    $logoPath = $this->wasabiService->uploadFile(
                        $request->file('logo'),
                        'stations/lavage/logos',
                        'station-lavage'
                    );
                    Log::info('Logo enregistre avec succes sur Wasabi', ['path' => $logoPath]);
                } catch (\Exception $e) {
                    Log::error('Erreur lors de l\'enregistrement du logo sur Wasabi', ['error' => $e->getMessage()]);
                    return response()->json([
                        'error' => 'Erreur lors de l\'enregistrement du logo sur Wasabi',
                        'dev' => $e->getMessage()
                    ], 500);
                }
            }

            $station = StationLavage::create([
                'name' => $request->name,
                'adresse' => $request->adresse,
                'contact' => $request->contact,
                'longitude' => $request->longitude,
                'latitude' => $request->latitude,
                'logo' => $logoPath,
                'statut' => 1,
                'created_by' => auth()->id()
            ]);

            Log::info('Station créée avec succès', ['station' => $station]);

            // Génération et création du code parrain
            $code = $this->generateUniqueCode();

            Parrain::create([
                'code' => $code,
                'station_de_lavage_id' => $station->id
            ]);

            Log::info('Code parrain créé avec succès', ['code' => $code]);

            return response()->json([
                'message' => 'Station de lavage créée avec succès',
                'station' => $this->attachStationLavageMediaUrls($station->load('creator')),
                'code_parrain' => $code
            ], 201);

        } catch (\Exception $e) {
            Log::error('Erreur lors de la création de la station', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return response()->json(['error' => 'Une erreur est survenue lors de la création de la station'], 500);
        }
    }

    public function show($id)
    {
        $station = StationLavage::with('creator')->find($id);

        if (!$station) {
            return response()->json(['error' => 'Station de lavage non trouvée'], 404);
        }

        return response()->json($this->attachStationLavageMediaUrls($station));
    }

    public function update(Request $request, $id)
    {
        $station = StationLavage::find($id);

        if (!$station) {
            return response()->json(['error' => 'Station de lavage non trouvée'], 404);
        }

        $validator = Validator::make($request->all(), [
            'name' => 'sometimes|string|max:200',
            'adresse' => 'nullable|string|max:500',
            'contact' => 'nullable|string|max:20',
            'longitude' => 'nullable|string|max:20',
            'latitude' => 'nullable|string|max:20',
            'logo' => 'nullable|image|mimes:jpeg,png,jpg,gif,svg|max:2048',
            'statut' => 'sometimes|integer|in:0,1'
        ]);

        if ($validator->fails()) {
            return response()->json(['error' => $validator->errors()], 422);
        }

        $data = $request->only(['name', 'adresse', 'contact', 'longitude', 'latitude', 'statut']);

        if ($request->hasFile('logo')) {
            try {
                if (!empty($station->logo)) {
                    $this->wasabiService->deleteFile($station->logo);
                }

                $data['logo'] = $this->wasabiService->uploadFile(
                    $request->file('logo'),
                    'stations/lavage/logos',
                    'station-lavage'
                );
            } catch (\Exception $e) {
                Log::error('Erreur lors de la mise a jour du logo sur Wasabi', ['error' => $e->getMessage()]);
                return response()->json([
                    'error' => 'Erreur lors de la mise a jour du logo sur Wasabi',
                    'dev' => $e->getMessage()
                ], 500);
            }
        }

        $station->update($data);

        return response()->json([
            'message' => 'Station de lavage mise à jour avec succès',
            'station' => $this->attachStationLavageMediaUrls($station->fresh('creator'))
        ]);
    }

    public function destroy($id)
    {
        $station = StationLavage::find($id);

        if (!$station) {
            return response()->json(['error' => 'Station de lavage non trouvée'], 404);
        }

        if (!empty($station->logo)) {
            $this->wasabiService->deleteFile($station->logo);
        }

        $station->delete();

        return response()->json([
            'message' => 'Station de lavage supprimée avec succès'
        ]);
    }

    public function registerUsager(Request $request)
	{
		// Validation des données d'entrée
		$validator = Validator::make($request->all(), [
			'indicatif' => 'required|string',
			'nom' => 'required|string',
			'prenoms' => 'required|string',
			'mobile' => 'required|numeric|unique:users',
			'is_whatsapp' => 'required|numeric'
		]);

		if ($validator->fails()) {
			return response()->json([
				'success' => false,
				'message' => 'Validation échouée.',
				'errors' => $validator->errors(),
			], 422);
		}

		// Utilisation d'une transaction pour garantir l'intégrité des données
		DB::beginTransaction();
		try {
			$rawPassword = strval(random_int(100000, 999999));

			$lavage = auth('api')->user();

			if (!$lavage) {
				return response()->json([
					'status' => 'error',
					'message' => 'lavage non authentifiée'
				], 401);
			}

			// Recherche de la station de lavage avec logging
			Log::info('Recherche de la station de lavage', ['lavage_id' => $lavage->id]);
			$stationDeLavage = StationLavage::where('created_by', $lavage->id)->first();
			Log::info('Résultat de la recherche', ['station' => $stationDeLavage]);

			if (!$stationDeLavage) {
				return response()->json([
					'status' => 'error',
					'message' => 'Station de lavage non trouvée'
				], 404);
			}

			// Récupération du code parrain de la station
			$parrain = Parrain::where('station_de_lavage_id', $stationDeLavage->id)->first();
			if (!$parrain) {
				return response()->json([
					'status' => 'error',
					'message' => 'Aucun code parrain trouvé pour cette station.'
				], 422);
			}

			// Création de l'utilisateur
			$user = new User();
			$user->uuid = (string) Str::uuid();
			$user->indicatif = $request->indicatif;
			$user->mobile = $request->mobile;
			$user->nom = $request->nom;
			$user->prenoms = $request->prenoms;
			$user->password = bcrypt($rawPassword);
			$user->is_whatsapp = $request->is_whatsapp;
			$user->lavage_id = $lavage->id;
			$user->station_de_lavage_id = $stationDeLavage->id;

			$user->save();

			// Enregistrement du code de parrainage utilisé
			ReferralCode::create([
				'user_id' => $user->id,
				'code' => $parrain->code
			]);

			// Commit de la transaction
			DB::commit();

			$mobileWithIndicatif = $request->indicatif . $request->mobile;
			$password = $rawPassword;

			// Construire le message
			$message = strtoupper(
				"Votre compte a ete cree avec succes\n" .
				"Voici vos identifiants de connexion :\n" .
				"Numero de telephone : $mobileWithIndicatif\n" .
				"Mot de passe : $password\n" .
				"Code parrain : " . $parrain->code
			);

			// Envoyer le SMS
			$smsResponse = $this->sendMessageConfirmOrder($message, $mobileWithIndicatif);

			return response()->json([
				'success' => true,
				'message' => 'Utilisateur enregistré avec succès.',
				'user' => $user,
				'parrain' => $parrain
			], 201);

		} catch (\Exception $e) {
			// Rollback de la transaction en cas d'erreur
			DB::rollBack();

			return response()->json([
				'success' => false,
				'message' => "Une erreur est survenue lors de l'enregistrement de l'utilisateur.",
				'dev' => $e->getMessage(),
			], 500);
		}
	}

	public function getUsagerByStation()
	{
		try {
			$lavage = auth('api')->user();

			if (!$lavage) {
				return response()->json([
					'status' => 'error',
					'message' => 'Station non authentifiée'
				], 401);
			}
			// Recherche de la station de lavage avec logging
			Log::info('Recherche de la station de lavage', ['lavage_id' => $lavage->id]);
			$stationDeLavage = StationLavage::where('created_by', $lavage->id)->first();
			Log::info('Résultat de la recherche', ['station' => $stationDeLavage]);

			if (!$stationDeLavage) {
				return response()->json([
					'status' => 'error',
					'message' => 'Station de lavage non trouvée'
				], 404);
			}

			// Récupérer les usagers associés à la station
			$usagers = User::where('station_de_lavage_id', $stationDeLavage->id)->get();
			Log::info('Usagers trouvés', ['count' => $usagers->count()]);

			// Vérifier s'il y a des usagers
			if ($usagers->isEmpty()) {
				return response()->json([
					'success' => false,
					'message' => "Aucun usager trouvé.",
				], 404);
			}

			return response()->json([
				'success' => true,
				'data' => $usagers,
			], 200);

		} catch (\Exception $e) {
			Log::error('Erreur dans getUsagerByStation', ['error' => $e->getMessage()]);
			return response()->json([
				'success' => false,
				'message' => "Une erreur est survenue lors de l'affichage.",
				'dev' => $e->getMessage(),
			], 500);
		}
	}

	public function sendMessageConfirmOrder($message, $reciever)
	{
		$url = "https://api.smscloud.ci/v1/campaigns/";
		$token = "XeETy7GtbpU7PwMwXk2HOPlZmgqhu9C57v4";

		$data = [
			'sender' => 'QLOWO',
			'content' => $message,
			'dlrUrl' => 'https://myreturnhost.com',
			'recipients' => [$reciever] // Utiliser directement le numéro passé en paramètre
		];

		$headers = [
			'Authorization: Bearer ' . $token,
			'Content-Type: application/json',
			'cache-control: no-cache'
		];

		$ch = curl_init($url);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
		curl_setopt($ch, CURLOPT_POST, true);
		curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));

		$response = curl_exec($ch);

		if ($response === false) {
			// Gérer l'erreur de requête
			$error = curl_error($ch);
			return response()->json([
				'error' => true,
				'message' => 'Erreur cURL : ' . $error
			], 500);
		}

		// Traitement de la réponse
		$responseData = json_decode($response, true);
		return response()->json([
			'message' => 'Message envoyé avec succès',
			'body' => $responseData
		], 200);
	}


	public function assignByScanLavage(Request $request)
	{
		try {
			Log::info('Tentative d\'attribution de QR code', ['request' => $request->all()]);

			$validated = $request->validate([
				'qrcode' => 'required|string|max:255',
				'matricule' => 'required|string|max:50',
			]);

			$lavage = auth('api')->user();
			if (!$lavage) {
				Log::warning('Tentative d\'attribution sans authentification');
				return response()->json(['status' => 'error', 'message' => 'Lavage non authentifié'], 401);
			}

			$vehicule = Vehicule::firstWhere('matricule', $validated['matricule']);
			if (!$vehicule) {
				Log::warning('Véhicule non trouvé', ['matricule' => $validated['matricule']]);
				return response()->json(['status' => 'error', 'message' => 'Véhicule introuvable.'], 404);
			}

			$qrcode = QrcodeGenerate::where('qrcode', $validated['qrcode'])->first();
			if (!$qrcode) {
				Log::warning('QR code non trouvé', ['qrcode' => $validated['qrcode']]);
				return response()->json(['status' => 'error', 'message' => 'QR code invalide'], 404);
			}

			if ($qrcode->is_assigned) {
				Log::warning('QR code déjà attribué', ['qrcode' => $qrcode->qrcode]);
				return response()->json(['status' => 'error', 'message' => 'Ce QR code est déjà attribué'], 409);
			}

			$stationLavage = StationLavage::where('created_by', $lavage->id)
				->orWhere('created_by', $lavage->created_by)
				->first();

			if (!$stationLavage) {
				Log::warning('Station de lavage non trouvée', ['lavage_id' => $lavage->id]);
				return response()->json(['status' => 'error', 'message' => 'Station de lavage non trouvée.'], 404);
			}

			DB::beginTransaction();

			try {
				Log::info('Début de la transaction pour l\'attribution du QR code', [
					'lavage_id' => $lavage->id,
					'qrcode_id' => $qrcode->id,
					'vehicule_id' => $vehicule->id
				]);

				$assignment = QrcodeAssignment::create([
					'lavage_id' => $lavage->id,
					'qrcode_id' => $qrcode->id,
					'station_de_lavage_id' => $stationLavage->id,
					'user_id' => $vehicule->user_id,
					'assigned_at' => now(),
				]);

				$qrcode->update([
					'is_assigned' => true,
					'assigned_at' => now(),
				]);

				$vehicule->qrcode_generate_id = $qrcode->id;
				$vehicule->save();

				DB::commit();

				Log::info('QR code attribué avec succès', [
					'qrcode' => $qrcode->qrcode,
					'lavage' => $lavage->id,
					'vehicule' => $vehicule->matricule
				]);

				return response()->json([
					'status' => 'success',
					'message' => 'QR code attribué et historisé avec succès',
					'data' => [
						'qrcode' => $qrcode->qrcode,
						'lavage' => $lavage->first_name . ' ' . $lavage->last_name,
						'assigned_at' => $assignment->assigned_at,
						'vehicule' => $vehicule->matricule
					]
				], 201);

			} catch (\Exception $e) {
				DB::rollBack();
				Log::error('Erreur lors de l\'attribution du QR code', ['error' => $e->getMessage()]);
				return response()->json(['status' => 'error', 'message' => 'Erreur lors de l\'attribution du QR code', 'error' => $e->getMessage()], 500);
			}

		} catch (\Illuminate\Validation\ValidationException $e) {
			Log::warning('Validation échouée', ['errors' => $e->errors()]);
			return response()->json(['status' => 'error', 'message' => 'Données invalides', 'errors' => $e->errors()], 422);
		} catch (\Exception $e) {
			Log::error('Erreur inattendue', ['error' => $e->getMessage()]);
			return response()->json(['status' => 'error', 'message' => 'Une erreur inattendue est survenue', 'error' => $e->getMessage()], 500);
		}
	}


    public function historyByStationDeLavage($station_de_lavage_id)
	{
		try {
			$lavage = auth('api')->user();

			if (!$lavage) {
				return response()->json([
					'status' => 'error',
					'message' => 'Station non authentifiée'
				], 401);
			}

			$stationLavage = StationLavage::where('id', $station_de_lavage_id)
				->where(function ($query) use ($lavage) {
					$query->where('created_by', $lavage->id)
						  ->orWhere('created_by', $lavage->created_by);
				})->first();

			if (!$stationLavage) {
				return response()->json([
					'status' => 'error',
					'message' => 'Station de lavage non trouvé.'
				], 404);
			}

			$assignments = QrcodeAssignment::with(['qrcode', 'station_de_lavage', 'user.vehicules'])
				->where('station_de_lavage_id', $stationLavage->id)
				->orderBy('assigned_at', 'desc')
				->get()
				->map(function ($assignment) {
					return $this->attachHistoryMediaUrls($assignment);
				});

			return response()->json([
				'status' => 'success',
				'message' => $assignments->isEmpty() ? 'Aucune attribution trouvée' : 'Historique récupéré avec succès',
				'history' => $assignments
			], 200);

		} catch (\Exception $e) {
			Log::error('Erreur lors de la récupération de l\'historique', [
				'error' => $e->getMessage(),
				'trace' => $e->getTraceAsString()
			]);
			return response()->json([
				'status' => 'error',
				'message' => 'Erreur lors de la récupération de l\'historique',
				'error' => $e->getMessage()
			], 500);
		}
	}

    protected function attachStationLavageMediaUrls($station)
    {
        if (!$station) {
            return $station;
        }

        $station->logo = $station->logo
            ? $this->wasabiService->temporaryUrl($this->normalizeStationLavageLogoPath($station->logo))
            : null;

        return $station;
    }

    protected function normalizeStationLavageLogoPath($value)
    {
        if (empty($value)) {
            return null;
        }

        return ltrim((string) $value, '/');
    }

    protected function attachVehiculePhotoUrls($vehicule)
    {
        if (!$vehicule) {
            return $vehicule;
        }

        $photos = $vehicule->photos ?? [];

        if (is_string($photos)) {
            $decoded = json_decode($photos, true);
            $photos = json_last_error() === JSON_ERROR_NONE ? $decoded : [];
        }

        $vehicule->photos = collect(is_array($photos) ? $photos : [])->filter()->map(function ($photo) {
            return $this->wasabiService->temporaryUrl($photo);
        })->values()->all();

        return $vehicule;
    }

    protected function attachHistoryMediaUrls($assignment)
    {
        if (!$assignment) {
            return $assignment;
        }

        if ($assignment->relationLoaded('station_de_lavage') && $assignment->station_de_lavage) {
            $assignment->setRelation(
                'station_de_lavage',
                $this->attachStationLavageMediaUrls($assignment->station_de_lavage)
            );
        }

        if ($assignment->relationLoaded('user') && $assignment->user && $assignment->user->relationLoaded('vehicules')) {
            $assignment->user->setRelation(
                'vehicules',
                $assignment->user->vehicules->map(function ($vehicule) {
                    return $this->attachVehiculePhotoUrls($vehicule);
                })
            );
        }

        return $assignment;
    }

}
