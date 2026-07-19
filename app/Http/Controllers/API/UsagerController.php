<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\User;
use App\Models\StationLavage;
use App\Models\QrcodeGenerate;
use App\Models\QrcodeAssignment;
use App\Models\Vehicule;
use App\Models\Lavage;
use App\Models\Parrain;
use App\Models\ReferralCode;
use Validator;
use Tymon\JWTAuth\Facades\JWTAuth;
use Tymon\JWTAuth\Exceptions\JWTException;
use Illuminate\Support\Facades\Hash;
use Carbon\Carbon;
use DateTime;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Http\Response;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Process;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Auth;
use App\Services\WasabiService;

class UsagerController extends Controller
{
	protected $wasabiService;

	public function __construct(WasabiService $wasabiService)
	{
		$this->wasabiService = $wasabiService;
	}

	public function registerUsager(Request $request)
	{
		// Validation des données d'entrée
		$validator = Validator::make($request->all(), [
			'indicatif' => 'required|string',
			'nom' => 'required|string',
			'prenoms' => 'required|string',
			'mobile' => 'required|numeric|unique:users',
			'is_whatsapp' => 'required|numeric',
			'immatriculation' => 'nullable|string|unique:vehicules,matricule',
			'carte_grise' => 'nullable|string|unique:vehicules,carte_grise',
			'photos' => 'nullable|array|size:4',
			'photos.*' => 'file|image|max:25048',
			'type_de_vehicule_id' => 'nullable|exists:type_de_vehicules,id',
			'marque_id' => 'nullable|exists:marques,id',
			'type_de_carburant_id' => 'nullable|exists:type_de_carburants,id',
			'couleur' => 'nullable|string|max:50',
			'modele' => 'nullable|string',
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

			$commercial = auth()->user();

			// Création de l'utilisateur
			$user = new User();
			$user->uuid = (string) Str::uuid();
			$user->indicatif = $request->indicatif;
			$user->mobile = $request->mobile;
			$user->nom = $request->nom;
			$user->prenoms = $request->prenoms;
			$user->password = bcrypt($rawPassword); // Hash sécurisé du mot de passe
			$user->is_whatsapp = $request->is_whatsapp;
			$user->commercial_id = $commercial->id;

			$user->save();

			$vehicule = null;

			if ($request->filled('immatriculation')) {
				$vehicule = new Vehicule();
				$vehicule->matricule = $request->immatriculation;
				$vehicule->carte_grise = $request->carte_grise;
				$vehicule->type_de_vehicule_id = $request->type_de_vehicule_id;
				$vehicule->marque_id = $request->marque_id;
				$vehicule->type_de_carburant_id = $request->type_de_carburant_id;
				$vehicule->couleur = $request->couleur;
				$vehicule->modele = $request->modele;
				$vehicule->user_id = $user->id;
				$vehicule->created_by = $commercial->id;
				$vehicule->provenance = "commerciaux";

				if ($request->hasFile('photos')) {
					$photosPaths = [];

					foreach ($request->file('photos') as $photo) {
						$photosPaths[] = $this->wasabiService->uploadFile(
							$photo,
							'vehicules/photos',
							'vehicule'
						);
					}

					$vehicule->photos = json_encode($photosPaths);
				}

				$vehicule->save();
			}

			// Commit de la transaction
			DB::commit();

			$mobileWithIndicatif = $request->indicatif . $request->mobile;
			$password = $rawPassword;

			// Construire le message
			$message = strtoupper(
				"Votre compte a ete cree avec succes\n" .
				"Voici vos identifiants de connexion :\n" .
				"Numero de telephone : $mobileWithIndicatif\n" .
				"Mot de passe : $password"
			);

			// Envoyer le SMS
			$smsResponse = $this->sendMessageConfirmOrder($message, $mobileWithIndicatif);

			return response()->json([
				'success' => true,
				'message' => 'Utilisateur enregistré avec succès.',
				'user' => $user,
				'vehicule' => $vehicule ? $this->attachVehiculePhotoUrls($vehicule) : null,
			], 201); // Utilisation du code HTTP 201 pour "Created"

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

			$stationDeLavage = StationLavage::where('created_by', $lavage->id)
				->orWhere('created_by', $lavage->created_by)
				->first();

			if (!$stationDeLavage) {
				return response()->json([
					'status' => 'error',
					'message' => 'Station de lavage non trouvé.'
				], 404);
			}

			$usagers = User::with('vehicules')
				->where('station_de_lavage_id', $stationDeLavage->id)
				->get()
				->map(function ($usager) {
					return $this->attachUsagerVehiculePhotoUrls($usager);
				});

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

	protected function attachUsagerVehiculePhotoUrls($usager)
	{
		if (!$usager) {
			return $usager;
		}

		if ($usager->relationLoaded('vehicules')) {
			$usager->setRelation(
				'vehicules',
				$usager->vehicules->map(function ($vehicule) {
					return $this->attachVehiculePhotoUrls($vehicule);
				})
			);
		}

		return $usager;
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

}
