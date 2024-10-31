<?php

namespace App\Controller;

use App\Entity\Etiquette;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\Response;

class PrintController extends AbstractController
{
    private EntityManagerInterface $entityManager;

    public function __construct(EntityManagerInterface $entityManager)
    {
        $this->entityManager = $entityManager;
    }

    #[Route('/test-print', name: 'test_print')]
    public function testPrint(): Response
    {
        return $this->render('print/index.html.twig');
    }

    #[Route('/test-prn', name: 'test_prn')]
    public function testPrn(): Response
    {
        return $this->render('print/test.prn.html.twig');
    }

    #[Route('/print', name: 'print', methods: ['POST', 'OPTIONS'])]
    public function printLabel(Request $request): JsonResponse
    {
        $filesystem = new Filesystem();

        $origin = $request->headers->get('Origin');
        
        $response = new JsonResponse();
        $response->headers->set('Access-Control-Allow-Origin', $origin ?? '*');
        $response->headers->set('Access-Control-Allow-Methods', 'GET, POST, OPTIONS');
        $response->headers->set('Access-Control-Allow-Headers', 'X-Requested-With');

        // Check si la method est option
        if ($request->isMethod('OPTIONS')) {
            return $response;
        }
        // Vérifié si l'extension socket est bien chargée
        if (!extension_loaded('sockets')) {
            return $this->setResponse(false, 'The sockets extension is not loaded.');
        }

        // Vérifier le json
        $json = json_decode($request->getContent());
        if (json_last_error() !== JSON_ERROR_NONE) {
            return $this->setResponse(false, 'Malformed JSON: ' . json_last_error_msg());
        }

        // Vérifier qu'il y est une IP
        if (!isset($json->printer)) {
            return $this->setResponse(false, 'No printer specified.');
        }

        // Vérifier qu'il y est un Port
        if (!isset($json->port)) {
            return $this->setResponse(false, 'Printer port not specified.');
        }

        // Créer une connexion socket que si il y a un content OU un id_data
        $socket = null;
        if (isset($json->content) || isset($json->id_data)) {
            $socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
            if (!$socket) {
                return $this->setResponse(false, 'Socket creation failure: ' . socket_strerror(socket_last_error()));
            }
        } else {
            return $this->setResponse(false, 'No content to process.');
        }

        // Vérifie si le json contient un content
        if (isset($json->content)) {
            $LabelZpl = $json->content;
        // Vérifie si le json contient un id_data
        } elseif (isset($json->id_data)) {
            // Récupère l'étiquette relié à id_data
            $etiquette = $this->entityManager->getRepository(Etiquette::class)->find($json->id_data);

            if (!$etiquette) {
                return $this->setResponse(false, 'Étiquette non trouvée.');
            }
            // récupération des données
            $nom = $etiquette->getNom();
            $date = $etiquette->getDate()->format('Y-m-d');
            $produit = $etiquette->getProduit();
            $quantity = $etiquette->getQuantity();
            $code_barre = $etiquette->getCodeBarre();

            // définition du chemin et récupératoin du template.
            $templatePath = __DIR__ . '/../../templates/'.$json->nameTemplate.'.txt';
            $ZplTemplate = $filesystem->readFile($templatePath);
            // verification du template
            if (!$ZplTemplate) {
                return $this->setResponse(false, 'Unable to find template.');
            }
            // replace le template
            if (!isset($json->patterns)) {
                return $this->setResponse(false, 'Patterns non trouvés.');
            }
            // boucle qui récupere les patterns et replacements
            foreach ($json->patterns as $key) {
                switch ($key) {
                    case "nom":
                        $patterns[] = '/##nom##/';
                        $replacements[] = $nom;
                        break;
                    case "date":
                        $patterns[] = '/##date##/';
                        $replacements[] = $date;
                        break;
                    case "produit":
                        $patterns[] = '/##produit##/';
                        $replacements[] = $produit;
                        break;
                    case "quantite":
                        $patterns[] = '/##quantite##/';
                        $replacements[] = $quantity;
                        break;
                    case "code_barre":
                        $patterns[] = '/##code_barre##/';
                        $replacements[] = $code_barre;
                        break;
                }
            }
            $LabelZpl = preg_replace($patterns, $replacements, $ZplTemplate);
        }

        // vérifi que le label est pas vide pour éviter d'imprimer une page blanche dans le cas ou une erreur est survenu
        if ($LabelZpl === null) {
            return $this->setResponse(false, 'Aucun content ou id_data valide fourni pour créer l\'étiquette.');
        }

        // Vérifier si la prévisualisation est demandée
        if (isset($json->previsualizer) && $json->previsualizer === true) {
            // Retourner le ZPL pour prévisualisation
            return new JsonResponse(['success' => true, 'zpl' => $LabelZpl]);
        }

        // connexion au socket, imperssion et fermeture du socket
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
