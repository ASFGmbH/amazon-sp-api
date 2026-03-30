<?php
declare(strict_types=1);

namespace App\Services;

use RuntimeException;
use SellingPartnerApi\Api\FeedsV20210630Api;
use SellingPartnerApi\Api\ListingsV20210801Api;
use SellingPartnerApi\Api\ProductTypeDefinitionsV20200901Api;
use SellingPartnerApi\Configuration;
use SellingPartnerApi\Document;
use SellingPartnerApi\Endpoint;
use SellingPartnerApi\Model\FeedsV20210630\CreateFeedDocumentSpecification;
use SellingPartnerApi\Model\FeedsV20210630\CreateFeedSpecification;

final class AmazonService
{
    private const JSON_LISTINGS_FEED_TYPE = 'JSON_LISTINGS_FEED';
    private const JSON_CONTENT_TYPE = 'application/json';

    private ListingsV20210801Api $listingsApi;
    private ProductTypeDefinitionsV20200901Api $definitionsApi;
    private FeedsV20210630Api $feedsApi;

    public function __construct()
    {
        $config = new Configuration([
            'lwaClientId' => (string) ($_ENV['LWA_CLIENT_ID'] ?? ''),
            'lwaClientSecret' => (string) ($_ENV['LWA_CLIENT_SECRET'] ?? ''),
            'lwaRefreshToken' => (string) ($_ENV['LWA_REFRESH_TOKEN'] ?? ''),
            'awsAccessKeyId' => (string) ($_ENV['AWS_ACCESS_KEY_ID'] ?? ''),
            'awsSecretAccessKey' => (string) ($_ENV['AWS_SECRET_ACCESS_KEY'] ?? ''),
            'roleArn' => (string) ($_ENV['AWS_ROLE_ARN'] ?? ''),
            'endpoint' => Endpoint::EU,
        ]);

        $this->listingsApi = new ListingsV20210801Api($config);
        $this->definitionsApi = new ProductTypeDefinitionsV20200901Api($config);
        $this->feedsApi = new FeedsV20210630Api($config);
    }

    public function putListingsItem(string $sellerSku, array $body, ?string $issueLocale = 'de_DE')
    {
        $sellerId = (string) ($_ENV['AMAZON_SELLER_ID'] ?? '');
        $marketplaceId = (string) ($_ENV['AMAZON_MARKETPLACE_ID'] ?? '');

        if ($sellerId === '') {
            throw new RuntimeException('Missing AMAZON_SELLER_ID in .env');
        }
        if ($marketplaceId === '') {
            throw new RuntimeException('Missing AMAZON_MARKETPLACE_ID in .env');
        }

        return $this->listingsApi->putListingsItem(
            $sellerId,
            $sellerSku,
            [$marketplaceId],
            $body,
            $issueLocale
        );
    }

    public function createOrUpdateListing(array $payload)
    {
        $sellerSku = (string) ($payload['sellerSku'] ?? '');
        if ($sellerSku === '') {
            throw new \InvalidArgumentException('Missing sellerSku in payload.');
        }
        unset($payload['sellerSku']);

        return $this->putListingsItem($sellerSku, $payload, 'de_DE');
    }

    public function submitJsonListingsFeed(array $feedPayload): array
    {
        $marketplaceId = (string) ($_ENV['AMAZON_MARKETPLACE_ID'] ?? '');
        if ($marketplaceId === '') {
            throw new RuntimeException('Missing AMAZON_MARKETPLACE_ID in .env');
        }

        $feedJson = json_encode($feedPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($feedJson === false) {
            throw new RuntimeException('JSON_LISTINGS_FEED konnte nicht serialisiert werden.');
        }

        $documentSpec = new CreateFeedDocumentSpecification([
            'content_type' => self::JSON_CONTENT_TYPE,
        ]);

        $feedDocumentInfo = $this->feedsApi->createFeedDocument($documentSpec);
        $feedDocumentId = method_exists($feedDocumentInfo, 'getFeedDocumentId')
            ? (string) $feedDocumentInfo->getFeedDocumentId()
            : (string) (($this->toArray($feedDocumentInfo)['feedDocumentId'] ?? ''));

        if ($feedDocumentId === '') {
            throw new RuntimeException('Amazon lieferte keine feedDocumentId für JSON_LISTINGS_FEED.');
        }

        $document = new Document($feedDocumentInfo, [
            'name' => self::JSON_LISTINGS_FEED_TYPE,
            'contentType' => self::JSON_CONTENT_TYPE,
        ]);
        $document->upload($feedJson);

        $feedSpec = new CreateFeedSpecification([
            'feed_type' => self::JSON_LISTINGS_FEED_TYPE,
            'input_feed_document_id' => $feedDocumentId,
            'marketplace_ids' => [$marketplaceId],
        ]);

        $feedResponse = $this->feedsApi->createFeed($feedSpec);
        $feedArray = $this->toArray($feedResponse);
        $feedId = (string) ($feedArray['feedId'] ?? (method_exists($feedResponse, 'getFeedId') ? $feedResponse->getFeedId() : ''));

        return [
            'feedType' => self::JSON_LISTINGS_FEED_TYPE,
            'feedDocumentId' => $feedDocumentId,
            'feedId' => $feedId,
            'response' => $feedArray,
            'payload' => $feedPayload,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public function getFeed(string $feedId): array
    {
        $feedId = trim($feedId);
        if ($feedId === '') {
            throw new \InvalidArgumentException('feedId fehlt.');
        }

        $response = $this->feedsApi->getFeed($feedId);
        $data = $this->toArray($response);

        if (!isset($data['feedId']) && method_exists($response, 'getFeedId')) {
            $data['feedId'] = (string) $response->getFeedId();
        }
        if (!isset($data['processingStatus']) && method_exists($response, 'getProcessingStatus')) {
            $data['processingStatus'] = (string) $response->getProcessingStatus();
        }
        if (!isset($data['resultFeedDocumentId']) && method_exists($response, 'getResultFeedDocumentId')) {
            $data['resultFeedDocumentId'] = (string) $response->getResultFeedDocumentId();
        }

        return $data;
    }

    /**
     * @return array<string,mixed>
     */
    public function getFeedStatusWithResultDocument(string $feedId): array
    {
        $feed = $this->getFeed($feedId);

        $resultDocumentId = trim((string) (
            $feed['resultFeedDocumentId']
            ?? $feed['resultDocumentId']
            ?? ''
        ));

        $resultDocumentRaw = null;
        $resultDocumentDecoded = null;
        $downloadError = null;

        if ($resultDocumentId !== '') {
            try {
                $resultDocumentRaw = $this->downloadFeedResultDocument($resultDocumentId);
                $resultDocumentDecoded = $this->decodeJsonDocument($resultDocumentRaw);
            } catch (\Throwable $e) {
                $downloadError = $e->getMessage();
            }
        }

        return [
            'feed' => $feed,
            'result_document_id' => $resultDocumentId,
            'result_document_raw' => $resultDocumentRaw,
            'result_document' => $resultDocumentDecoded,
            'download_error' => $downloadError,
        ];
    }

    public function getProductTypeDefinition(
        string $productType,
        string $marketplaceId,
        string $requirements = 'LISTING',
        ?string $locale = null
    ) {
        $sellerId = (string) ($_ENV['AMAZON_SELLER_ID'] ?? '');
        if ($sellerId === '') {
            throw new RuntimeException('Missing AMAZON_SELLER_ID in .env');
        }
        if ($marketplaceId === '') {
            throw new \InvalidArgumentException('Missing marketplaceId.');
        }
        if ($productType === '') {
            throw new \InvalidArgumentException('Missing productType.');
        }

        $marketplaceIds = $marketplaceId;
        $productTypeVersion = null;

        return $this->definitionsApi->getDefinitionsProductType(
            $productType,
            $marketplaceIds,
            $sellerId,
            $productTypeVersion,
            $requirements,
            'ENFORCED',
            $locale
        );
    }

    public function searchProductTypes(string $marketplaceId, string $keyword = 'ring', ?string $locale = 'de_DE')
    {
        if ($marketplaceId === '') {
            throw new \InvalidArgumentException('Missing marketplaceId.');
        }

        $keyword = trim($keyword);
        if ($keyword === '') {
            $keyword = 'ring';
        }

        $marketplaceIds = $marketplaceId;

        $query = [
            'keywords' => $keyword,
            'itemName' => $keyword,
        ];

        if ($locale !== null && $locale !== '') {
            $query['locale'] = $locale;
        }

        return $this->definitionsApi->searchDefinitionsProductTypes($marketplaceIds, $query);
    }

    private function downloadFeedResultDocument(string $documentId): string
    {
        $documentId = trim($documentId);
        if ($documentId === '') {
            throw new \InvalidArgumentException('documentId fehlt.');
        }

        $documentInfo = $this->feedsApi->getFeedDocument($documentId);
        $attemptErrors = [];

        if (is_object($documentInfo) && method_exists($documentInfo, 'download')) {
            try {
                $downloaded = $documentInfo->download(self::JSON_LISTINGS_FEED_TYPE);
                return $this->normalizeDownloadedDocument($downloaded);
            } catch (\Throwable $e) {
                $attemptErrors[] = 'FeedDocument::download(feedType): ' . $e->getMessage();
            }

            try {
                $downloaded = $documentInfo->download();
                return $this->normalizeDownloadedDocument($downloaded);
            } catch (\Throwable $e) {
                $attemptErrors[] = 'FeedDocument::download(): ' . $e->getMessage();
            }
        }

        foreach ([
                     function () use ($documentInfo) {
                         $document = new Document($documentInfo);
                         return $document->download(self::JSON_LISTINGS_FEED_TYPE);
                     },
                     function () use ($documentInfo) {
                         $document = new Document($documentInfo, self::JSON_LISTINGS_FEED_TYPE);
                         return $document->download();
                     },
                     function () use ($documentInfo) {
                         $document = new Document($documentInfo, [
                             'name' => self::JSON_LISTINGS_FEED_TYPE,
                             'contentType' => self::JSON_CONTENT_TYPE,
                         ]);
                         return $document->download();
                     },
                     function () use ($documentInfo) {
                         $document = new Document($documentInfo);
                         return $document->download();
                     },
                 ] as $idx => $attempt) {
            try {
                $downloaded = $attempt();
                return $this->normalizeDownloadedDocument($downloaded);
            } catch (\Throwable $e) {
                $attemptErrors[] = 'Document attempt #' . ($idx + 1) . ': ' . $e->getMessage();
            }
        }

        throw new RuntimeException(
            'Feed-Result-Dokument konnte nicht heruntergeladen werden. ' . implode(' | ', $attemptErrors)
        );
    }

    private function normalizeDownloadedDocument(mixed $downloaded): string
    {
        if (is_string($downloaded)) {
            return $downloaded;
        }

        if (is_array($downloaded)) {
            $json = json_encode($downloaded, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
            if ($json !== false) {
                return $json;
            }
        }

        if (is_object($downloaded) && method_exists($downloaded, '__toString')) {
            return (string) $downloaded;
        }

        $json = json_encode($downloaded, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        if ($json !== false) {
            return $json;
        }

        return '';
    }

    private function decodeJsonDocument(?string $raw): mixed
    {
        if ($raw === null || trim($raw) === '') {
            return null;
        }

        $decoded = json_decode($raw, true);
        return json_last_error() === JSON_ERROR_NONE ? $decoded : null;
    }

    private function toArray(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }

        $json = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            return [];
        }

        $decoded = json_decode($json, true);
        return is_array($decoded) ? $decoded : [];
    }
}
