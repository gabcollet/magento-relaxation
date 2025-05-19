<?php
namespace Gab\Dropshipping\Controller\Adminhtml\System\Config;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\Controller\Result\JsonFactory;
use Gab\Dropshipping\Model\Api\TokenManager;
use Gab\Dropshipping\Model\Api\Client;
use Psr\Log\LoggerInterface;

class TestConnection extends Action
{
    /**
     * Authorization level
     */
    const ADMIN_RESOURCE = 'Gab_Dropshipping::config';

    /**
     * @var JsonFactory
     */
    protected $resultJsonFactory;

    /**
     * @var TokenManager
     */
    protected $tokenManager;

    /**
     * @var Client
     */
    protected $apiClient;

    /**
     * @var LoggerInterface
     */
    protected $logger;

    /**
     * @param Context $context
     * @param JsonFactory $resultJsonFactory
     * @param TokenManager $tokenManager
     * @param Client $apiClient
     * @param LoggerInterface $logger
     */
    public function __construct(
        Context $context,
        JsonFactory $resultJsonFactory,
        TokenManager $tokenManager,
        Client $apiClient,
        LoggerInterface $logger
    ) {
        $this->resultJsonFactory = $resultJsonFactory;
        $this->tokenManager = $tokenManager;
        $this->apiClient = $apiClient;
        $this->logger = $logger;
        parent::__construct($context);
    }

    /**
     * Test connection
     *
     * @return \Magento\Framework\Controller\Result\Json
     */
    public function execute()
    {
        $result = $this->resultJsonFactory->create();

        try {
            $params = $this->getRequest()->getParams();
            $this->logger->debug('Test connection params', $params);

            // Vérifier la limitation des requêtes
            $throttleInfo = $this->tokenManager->getAuthThrottleInfo();
            if (!$throttleInfo['canAuthenticate']) {
                return $result->setData([
                    'success' => false,
                    'message' => __('Limitation des requêtes API: veuillez attendre %1 secondes avant de réessayer. CJ Dropshipping limite les demandes d\'authentification à une toutes les 5 minutes.', $throttleInfo['waitTime'])
                ]);
            }

            // Si une erreur d'authentification précédente existe, l'afficher
            if ($throttleInfo['errorMessage']) {
                $errorNote = __('Erreur précédente: %1', $throttleInfo['errorMessage']);
            } else {
                $errorNote = '';
            }

            // Effacer le cache des tokens pour forcer une nouvelle génération
            $this->tokenManager->clearTokenCache();

            // Essayer d'obtenir un token avec les identifiants fournis
            $accessToken = $this->tokenManager->getAccessToken();

            if (!$accessToken) {
                // Obtenir les infos après la tentative
                $throttleInfo = $this->tokenManager->getAuthThrottleInfo();
                $errorMessage = $throttleInfo['errorMessage']
                    ? $throttleInfo['errorMessage']
                    : __('Impossible d\'obtenir un token d\'accès. Veuillez vérifier votre clé API et votre email.');

                return $result->setData([
                    'success' => false,
                    'message' => $errorMessage . ' ' . $errorNote
                ]);
            }

            // Tester un appel API simple
            $response = $this->apiClient->getProducts(1, 10);

            if (isset($response['data'])) {
                return $result->setData([
                    'success' => true,
                    'message' => __('Connexion API réussie! L\'authentification et la liste des produits fonctionnent.')
                ]);
            } else {
                $error = isset($response['error']) ? $response['error'] :
                    (isset($response['message']) ? $response['message'] : __('Erreur inconnue'));

                // Vérifier si l'erreur est liée au paramètre pageSize
                if (isset($response['code']) && $response['code'] == 1600300 &&
                    strpos($error, 'pageSize') !== false) {

                    // C'est juste une erreur de paramètre, pas d'authentification
                    return $result->setData([
                        'success' => false,
                        'message' => __('Échec du test de l\'API : %1. Veuillez ajuster la taille de page minimale à 10 dans votre code.', $error)
                    ]);
                }

                return $result->setData([
                    'success' => false,
                    'message' => __('Échec de la connexion API. Erreur: %1', $error) . ' ' . $errorNote
                ]);
            }
        } catch (\Exception $e) {
            $this->logger->error('Erreur lors du test de connexion à l\'API CJ Dropshipping: ' . $e->getMessage());

            return $result->setData([
                'success' => false,
                'message' => __('Une erreur est survenue: %1', $e->getMessage())
            ]);
        }
    }
}
