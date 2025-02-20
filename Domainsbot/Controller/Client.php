<?php

/**
 * Domainsbot Name Suggestion Module
 *
 * Provides domain name suggestions based on user queries using the DomainsBot API.
 *
 * Some functions in this module are adapted from the main FOSSBilling codebase.
 *
 * @package   DomainsbotModule
 * @author    Namingo Team <help@namingo.org>
 * @license   Apache-2.0
 * @link      https://namingo.org
 *
 * FOSSBilling.
 * @copyright FOSSBilling (https://www.fossbilling.org)
 * @license   Apache-2.0
 *
 * Copyright FOSSBilling 2022
 * This software may contain code previously used in the BoxBilling project.
 * Copyright BoxBilling, Inc 2011-2021
 *
 * This source file is subject to the Apache-2.0 License that is bundled
 * with this source code in the file LICENSE.
 */

namespace Box\Mod\Domainsbot\Controller;

use FOSSBilling\Config;
use GuzzleHttp\Client as GClient;
use GuzzleHttp\Exception\GuzzleException;

class Client implements \FOSSBilling\InjectionAwareInterface
{
    protected $di;

    public function setDi(\Pimple\Container|null $di): void
    {
        $this->di = $di;
    }

    public function getDi(): ?\Pimple\Container
    {
        return $this->di;
    }

    /**
     * Methods maps client areas urls to corresponding methods
     * Always use your module prefix to avoid conflicts with other modules
     * in future.
     *
     * @param \Box_App $app - returned by reference
     */
    public function register(\Box_App &$app): void
    {
        $app->get('/domainsbot', 'get_index', [], static::class);
    }

    public function get_index(\Box_App $app)
    {
        // Access GET parameters and sanitize the q
        $q = filter_input(INPUT_GET, 'q', FILTER_SANITIZE_FULL_SPECIAL_CHARS);

        // Check if 'q' is missing or empty
        if (empty($q)) {
            return $app->render('mod_domainsbot_suggest');
        }

        $c = $this->di['db']->findOne('ExtensionMeta', 'extension = :ext AND meta_key = :key', [':ext' => 'domainsbot', ':key' => 'config']);
        $meta_value = (string) $c->meta_value;
        $config = json_decode($meta_value, true);

        $apiToken = $config['domainsbot_token'];
        $response = $this->getDomainSuggestions($apiToken, $q);

        if (!$response) {
            return $app->render('mod_domainsbot_index', ['error' => 'Failed to fetch domain suggestions.']);
        }

        // Format data for Twig (only Domain & Status)
        $domainSuggestions = [];
        foreach ($response as $suggestion) {
            $domainSuggestions[] = [
                'domain' => $suggestion['Domain'],
                'status' => $suggestion['Data'][6]['Data'] ?? 'unknown',
                'confidence' => $suggestion['ConfidenceScore'], // Keep for sorting only
            ];
        }

        // Sort by Confidence Score (highest first)
        usort($domainSuggestions, function ($a, $b) {
            return $b['confidence'] <=> $a['confidence'];
        });

        // Remove confidence score from array after sorting
        foreach ($domainSuggestions as &$suggestion) {
            unset($suggestion['confidence']);
        }

        return $app->render('mod_domainsbot_index', [
            'domains' => $domainSuggestions,
            'query' => $q,
        ]);
    }
    
    /**
     * Fetch domain name suggestions from the DomainsBot API.
     *
     * @param string $apiToken Your API authentication token.
     * @param string $query The search term (keyword, phrase, or domain).
     * @param array $params Optional parameters for customization (e.g., TLD selection, languages, etc.).
     * @return array|null The API response as an associative array or null on failure.
     */
    function getDomainSuggestions(string $apiToken, string $query, array $params = []): ?array
    {
        $baseUrl = 'https://api5.domainsbot.com/v5/recommend';
        $httpClient = new GClient([
            'base_uri' => $baseUrl,
            'timeout'  => 2.0,
        ]);

        $defaultParams = [
            'authtoken' => $apiToken,
            'q' => $query,
            'func' => 5, // Enables "sections" functionality for improved results
            'max_results' => 25,
            'languages' => 'en',
        ];

        $queryParams = array_merge($defaultParams, $params);

        try {
            $response = $httpClient->get('', ['query' => $queryParams]);
            return json_decode($response->getBody()->getContents(), true);
        } catch (GuzzleException $e) {
            error_log('DomainsBot API request failed: ' . $e->getMessage());
            return null;
        }
    }

}