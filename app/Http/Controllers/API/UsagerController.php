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
use App\Services\ClientLavageService;

class UsagerController extends Controller
{
	protected $wasabiService;
	protected $clientLavageService;

	public function __construct(WasabiService $wasabiService, ClientLavageService $clientLavageService)
	{
		$this->wasabiService = $wasabiService;
		$this->clientLavageService = $clientLavageService;
	}

	public function registerUsager(Request $request)
	{
		$validator = Validator::make(
			$request->all(),
			ClientLavageService::validationRules(),
			ClientLavageService::validationMessages()
		);

		if ($validator->fails()) {
			return response()->json([
				'success' => false,
				'message' => 'Validation échouée.',
				'errors' => $validator->errors(),
			], 422);
		}

		try {
			$lavage = auth('api')->user();

			$station = $this->clientLavageService->resolveStation($lavage);
			if (!$station) {
				return response()->json([
					'success' => false,
					'message' => 'Station de lavage non trouvée.',
				], 404);
			}

			$result = $this->clientLavageService->register($request, $lavage, $station);

			$credentialsSent = false;
			if ($request->filled('indicatif') && $request->filled('mobile')) {
				$mobileWithIndicatif = $request->indicatif . $request->mobile;
				$message = $this->clientLavageService->buildCredentialsMessage($mobileWithIndicatif, $result['password'], $result['parrain']);
				$smsResponse = $this->sendMessageConfirmOrder($message, $mobileWithIndicatif);
				$credentialsSent = $smsResponse->getStatusCode() === 200;
			}

			$response = [
				'success' => true,
				'message' => 'Utilisateur enregistré avec succès.',
				'user' => $result['user'],
				'vehicule' => $this->attachVehiculePhotoUrls($result['vehicule']),
				'parrain' => $result['parrain'],
				'identifiants_envoyes_par_sms' => $credentialsSent,
			];

			if ($result['abonnement']) {
				$response['abonnement'] = $result['abonnement'];
			}

			return response()->json($response, 201);

		} catch (\InvalidArgumentException $e) {
			return response()->json([
				'success' => false,
				'message' => $e->getMessage(),
			], 422);
		} catch (\Exception $e) {
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
