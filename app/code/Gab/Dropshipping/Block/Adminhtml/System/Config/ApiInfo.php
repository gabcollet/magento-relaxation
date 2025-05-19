<?php
namespace Gab\Dropshipping\Block\Adminhtml\System\Config;

use Magento\Backend\Block\Template\Context;
use Magento\Config\Block\System\Config\Form\Field;
use Magento\Framework\Data\Form\Element\AbstractElement;
use Gab\Dropshipping\Model\Api\TokenManager;

class ApiInfo extends Field
{
    /**
     * @var TokenManager
     */
    protected $tokenManager;

    /**
     * @param Context $context
     * @param TokenManager $tokenManager
     * @param array $data
     */
    public function __construct(
        Context $context,
        TokenManager $tokenManager,
        array $data = []
    ) {
        $this->tokenManager = $tokenManager;
        parent::__construct($context, $data);
    }

    /**
     * Render info block
     *
     * @param AbstractElement $element
     * @return string
     */
    protected function _getElementHtml(AbstractElement $element)
    {
        $throttleInfo = $this->tokenManager->getAuthThrottleInfo();
        $waitTime = $throttleInfo['waitTime'];
        $canAuth = $throttleInfo['canAuthenticate'];
        $errorMessage = $throttleInfo['errorMessage'];

        $html = '<div class="dropshipping-api-info">';
        $html .= '<p><strong>' . __('Informations importantes sur l\'API CJ Dropshipping:') . '</strong></p>';
        $html .= '<ul>';
        $html .= '<li>' . __('L\'API limite les tentatives d\'authentification à 1 toutes les 5 minutes.') . '</li>';

        if (!$canAuth) {
            $html .= '<li><strong style="color: #e22626;">' .
                __('Vous devez attendre encore %1 secondes avant de pouvoir vous authentifier.', $waitTime) .
                '</strong></li>';
        }

        if ($errorMessage) {
            $html .= '<li><strong>' . __('Dernière erreur d\'authentification:') . '</strong> ' . $errorMessage . '</li>';
        }

        $html .= '<li>' . __('Utilisez votre adresse email CJ Dropshipping et votre clé API (pas votre mot de passe).') . '</li>';
        $html .= '<li>' . __('Vous pouvez générer ou récupérer votre clé API à l\'adresse:') . ' <a href="https://cjdropshipping.com/myCJ.html#/apikey" target="_blank">https://cjdropshipping.com/myCJ.html#/apikey</a></li>';
        $html .= '</ul>';
        $html .= '</div>';

        return $html;
    }
}
