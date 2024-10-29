<?php

namespace App\Controller;

use App\Entity\Etiquette;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Filesystem\Exception\IOExceptionInterface;
use Symfony\Component\Filesystem\Filesystem;
use OpenApi\Annotations as OA;
use Nelmio\ApiDocBundle\Annotation\Model;
use Nelmio\ApiDocBundle\Annotation\Security;

class PrintController extends AbstractController
{
    private EntityManagerInterface $entityManager;

    public function __construct(EntityManagerInterface $entityManager)
    {
        $this->entityManager = $entityManager;
    }

    #[Route('/print', name: 'print', methods: ['POST', 'OPTIONS'])]
    #[OA\Tag(name: 'Print')]
    public function printLabel(Request $request): JsonResponse
    {
        $filesystem = new Filesystem();
        // Gérer les en-têtes CORS
        $origin = $request->headers->get('Origin');
        $response = new JsonResponse();
        $response->headers->set('Access-Control-Allow-Origin', $origin ?? '*');
        $response->headers->set('Access-Control-Allow-Methods', 'GET, POST, OPTIONS');
        $response->headers->set('Access-Control-Allow-Headers', 'X-Requested-With');

        // Si la méthode est OPTIONS, répondre directement avec le code 200
        if ($request->isMethod('OPTIONS')) {
            return $response;
        }

        // Vérifier si l'extension sockets est chargée
        if (!extension_loaded('sockets')) {
            return $this->setResponse(false, 'The sockets extension is not loaded.');
        }

        // Lire et décoder le JSON envoyé
        $json = json_decode($request->getContent());
        if (json_last_error() !== JSON_ERROR_NONE) {
            return $this->setResponse(false, 'Malformed JSON: ' . json_last_error_msg());
        }

        // Vérifier les champs requis
        if (!isset($json->printer)) {
            return $this->setResponse(false, 'No printer specified.');
        }

        if (!isset($json->port)) {
            return $this->setResponse(false, 'Printer port not specified.');
        }

        // Créer la connexion socket si nécessaire
        $socket = null;
        if (isset($json->content) || isset($json->id_data)) {
            $socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
            if (!$socket) {
                return $this->setResponse(false, 'Socket creation failure: ' . socket_strerror(socket_last_error()));
            }
        } else {
            return $this->setResponse(false, 'No content to process.');
        }

        // Initialisation de la variable $LabelZpl

        // Utiliser les données ZPL RAW ou un template
        if (isset($json->content)) {
            // Cas où le content est directement fourni
            $LabelZpl = $json->content;
        } elseif (isset($json->id_data)) {
            // Cas où un id_data est fourni
            $etiquette = $this->entityManager->getRepository(Etiquette::class)->find($json->id_data);

            // Vérification si l'étiquette a été trouvée
            if (!$etiquette) {
                return $this->setResponse(false, 'Étiquette non trouvée.');
            }

            // Récupération des données de l'étiquette
            $nom = $etiquette->getNom();
            $date = $etiquette->getDate()->format('Y-m-d');
            $produit = $etiquette->getProduit();
            $quantity = $etiquette->getQuantity();
            $code_barre = $etiquette->getCodeBarre();

            // Charger le template ZPL
            $templatePath = __DIR__ . '/../../templates/Etiquette.txt';
            $ZplTemplate = $filesystem->readFile($templatePath);

            // Vérification si le template est trouvé
            if (!$ZplTemplate) {
                return $this->setResponse(false, 'Unable to find template.');
            }

            // Remplacer les placeholders dans le template avec les données de l'étiquette
            $patterns = ['/##nom##/', '/##date##/', '/##produit##/', '/##quantite##/', '/##code_barre##/'];
            $replacements = [$nom, $date, $produit, $quantity, $code_barre];

            $LabelZpl = preg_replace($patterns, $replacements, $ZplTemplate);
        }

        // Vérification si $LabelZpl est défini avant de procéder à l'impression
        if ($LabelZpl === null) {
            return $this->setResponse(false, 'Aucun content ou id_data valide fourni pour créer l\'étiquette.');
        }

        // Tenter la connexion au socket et l'envoi des données
        if (socket_connect($socket, $json->printer, $json->port)) { 
            $write = socket_write($socket, $LabelZpl);
            socket_close($socket);

            if ($write === false) {
                return $this->setResponse(false, 'Socket writing failure. Error: ' . socket_strerror(socket_last_error($socket)));
            }

            return $this->setResponse(true, 'Socket writing successful on ' . $json->printer . ':' . $json->port . '.', $LabelZpl);
        } else {
            return $this->setResponse(false, 'Socket connection failure. Error: ' . socket_strerror(socket_last_error($socket)));
        }
    }

    private function setResponse(bool $success, ?string $msg = null, $data = null): JsonResponse
    {
        $responseArray = [
            'success' => $success,
            'msg' => $msg,
            'data' => $data,
        ];

        $statusCode = $success ? 200 : 400;

        return new JsonResponse($responseArray, $statusCode);
    }
}
