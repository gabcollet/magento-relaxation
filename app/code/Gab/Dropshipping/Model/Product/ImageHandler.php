<?php

namespace Gab\Dropshipping\Model\Product;

use Magento\Framework\Filesystem;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\HTTP\Client\Curl;
use Psr\Log\LoggerInterface;

class ImageHandler
{
    /**
     * @var Filesystem
     */
    protected $filesystem;

    /**
     * @var Curl
     */
    protected $curl;

    /**
     * @var LoggerInterface
     */
    protected $logger;

    /**
     * @param Filesystem $filesystem
     * @param Curl $curl
     * @param LoggerInterface $logger
     */
    public function __construct(
        Filesystem      $filesystem,
        Curl            $curl,
        LoggerInterface $logger
    )
    {
        $this->filesystem = $filesystem;
        $this->curl = $curl;
        $this->logger = $logger;
    }

    /**
     * Add product images from product data
     *
     * @param \Magento\Catalog\Api\Data\ProductInterface $product
     * @param array $productData
     * @return void
     */
    public function addProductImages($product, $productData)
    {
        try {
            $images = [];

            // Log les données du produit pour déboguer
            $this->logger->debug('Product image data', [
                'has_productImage' => isset($productData['productImage']),
                'has_productImages' => isset($productData['productImages'])
            ]);

            // Ajouter l'image principale si disponible
            if (isset($productData['productImage']) && !empty($productData['productImage'])) {
                // Vérifier si c'est une chaîne JSON
                if (is_string($productData['productImage']) && $this->isJson($productData['productImage'])) {
                    $decodedImages = json_decode($productData['productImage'], true);
                    if (is_array($decodedImages) && !empty($decodedImages)) {
                        foreach ($decodedImages as $img) {
                            if (!empty($img)) {
                                $images[] = $img;
                            }
                        }
                    }
                } else {
                    $images[] = $productData['productImage'];
                }
            }

            // Ajouter les images supplémentaires si disponibles
            if (isset($productData['productImages']) && !empty($productData['productImages'])) {
                // Vérifier si c'est une chaîne JSON
                if (is_string($productData['productImages']) && $this->isJson($productData['productImages'])) {
                    $decodedImages = json_decode($productData['productImages'], true);
                    if (is_array($decodedImages)) {
                        foreach ($decodedImages as $img) {
                            if (!empty($img) && !in_array($img, $images)) {
                                $images[] = $img;
                            }
                        }
                    }
                } elseif (is_array($productData['productImages'])) {
                    foreach ($productData['productImages'] as $image) {
                        if (!empty($image) && !in_array($image, $images)) {
                            $images[] = $image;
                        }
                    }
                }
            }

            $this->logger->debug('Images to process', [
                'count' => count($images),
                'urls' => $images
            ]);

            // Télécharger et ajouter les images au produit
            foreach ($images as $index => $imageUrl) {
                $isMain = ($index === 0);
                $this->addProductImageFromUrl($product, $imageUrl, $isMain);
            }
        } catch (\Exception $e) {
            $this->logger->error('Error adding product images: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString()
            ]);
        }
    }

    /**
     * Check if a string is valid JSON
     *
     * @param string $string
     * @return bool
     */
    public function isJson($string)
    {
        if (!is_string($string)) {
            return false;
        }

        json_decode($string);
        return (json_last_error() == JSON_ERROR_NONE);
    }

    /**
     * Add product image from URL
     *
     * @param \Magento\Catalog\Api\Data\ProductInterface $product
     * @param string $imageUrl
     * @param bool $isMain
     * @return void
     */
    public function addProductImageFromUrl($product, $imageUrl, $isMain = false)
    {
        try {
            $this->logger->debug('Processing image URL', ['url' => $imageUrl]);

            // S'assurer que l'URL est valide
            if (!filter_var($imageUrl, FILTER_VALIDATE_URL)) {
                $this->logger->error('Invalid image URL', ['url' => $imageUrl]);
                return;
            }

            // Télécharger l'image
            $this->curl->setOptions([
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_TIMEOUT => 30
            ]);
            $this->curl->get($imageUrl);
            $statusCode = $this->curl->getStatus();
            $imageContent = $this->curl->getBody();

            if ($statusCode == 200 && !empty($imageContent)) {
                // Extraire le nom du fichier de l'URL
                $fileName = basename(parse_url($imageUrl, PHP_URL_PATH));
                if (empty($fileName) || strlen($fileName) > 90) {
                    // Générer un nom de fichier s'il ne peut pas être extrait ou s'il est trop long
                    $fileName = 'image_' . md5($imageUrl) . '.jpg';
                }

                // Obtenir le répertoire media
                $mediaDirectory = $this->filesystem->getDirectoryWrite(DirectoryList::MEDIA);
                $tempFilePath = 'import/' . $fileName;

                // Enregistrer le contenu de l'image dans le répertoire media
                $mediaDirectory->writeFile($tempFilePath, $imageContent);

                // Ajouter l'image au produit
                $product->addImageToMediaGallery(
                    $mediaDirectory->getAbsolutePath($tempFilePath),
                    $isMain ? ['image', 'small_image', 'thumbnail'] : [],
                    false,
                    false
                );

                // Supprimer le fichier temporaire
                $mediaDirectory->delete($tempFilePath);

                $this->logger->debug('Image added to product', ['is_main' => $isMain]);
            }
        } catch (\Exception $e) {
            $this->logger->error('Error adding image from URL: ' . $e->getMessage(), [
                'url' => $imageUrl,
                'trace' => $e->getTraceAsString()
            ]);
        }
    }
}
