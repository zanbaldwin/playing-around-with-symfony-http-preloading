<?php declare(strict_types=1);

namespace ZanBaldwin\HttpPreload;

use Psr\Log\LoggerInterface;
use Symfony\Component\HttpClient\HttpClientTrait;
use Symfony\Component\HttpClient\Response\AsyncContext;
use Symfony\Component\HttpClient\Response\AsyncResponse;
use Symfony\Component\WebLink\Link;
use Symfony\Contracts\HttpClient\ChunkInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;
use Symfony\Contracts\HttpClient\ResponseStreamInterface;

class Client implements HttpClientInterface
{
    private const METHOD_GET = 'GET';

    use HttpClientTrait;

    private HttpClientInterface $client;
    private ?LoggerInterface $logger;
    private array $defaultOptions = self::OPTIONS_DEFAULTS;

    /** @var array [
     *   '<primary-response-cache-id>' => [
     *     '<preload-repsonse-cache-id>' => \Symfony\Contracts\HttpClient\ResponseInterface,
     *   ]
     * ]
     */
    private array $cache = [];

    public function __construct(HttpClientInterface $client, array $defaultOptions = [], ?LoggerInterface $logger = null)
    {
        $this->client = $client;
        $this->logger = $logger;
        if ($defaultOptions) {
            [, $this->defaultOptions] = self::prepareRequest(null, null, $defaultOptions, $this->defaultOptions);
        }
    }

    public function request(
        string $method,
        string $url,
        array $options = [],
        ResponseInterface $originalResponse = null
    ): ResponseInterface {
        /** @var array|null $primaryRequestUrlParts */
        [$primaryRequestUrlParts, $options] = $this->prepareRequest($method, $url, $options, $this->defaultOptions, true);
        $primaryRequestUrlParts = $primaryRequestUrlParts ?? [];
        $url = implode('', $primaryRequestUrlParts);

        if ($originalResponse instanceof ResponseInterface && strtoupper($method) === self::METHOD_GET) {
            $originalCacheKey = $this->generateCacheKey($originalResponse->getInfo('http_method'), $originalResponse->getInfo('url'));
            $this->cache[$originalCacheKey] = $this->cache[$originalCacheKey] ?? [];
            $preloadCacheKey = $this->generateCacheKey($method, $url);
            if (array_key_exists($preloadCacheKey, $this->cache[$originalCacheKey])) {
                $this->logger && $this->logger->info(
                    'HTTP Client request matched one preloaded one from a Link header of a previous request.',
                    ['originalCacheKey' => $originalCacheKey, 'cacheKey' => $preloadCacheKey, 'url' => $url]
                );
                $response = $this->cache[$originalCacheKey][$preloadCacheKey];
                // TODO: Should we unset the returned response from the in-memory cache? It's just meant to preload a
                //       response, not turn into an HTTP Cache store.
                return $response;
            }
        }

        $passthru = function (ChunkInterface $chunk, AsyncContext $context) use ($primaryRequestUrlParts, $options): iterable {
            if ($chunk->isFirst()) {
                $preloads = [];
                $links = $this->determinePreloadHrefsFromLinkHeaders($context->getHeaders()['link'] ?? [], $primaryRequestUrlParts);
                $originalCacheKey = $this->generateCacheKey($context->getInfo('http_method'), $context->getInfo('url'));
                $this->cache[$originalCacheKey] = $this->cache[$originalCacheKey] ?? [];
                $options['extra']['fetch_preload'] = empty($options['extra']['fetch_preload_recursive']);
                foreach ($links as $href => $resolved) {
                    $preloadCacheKey = $this->generateCacheKey(self::METHOD_GET, $resolved);
                    // Only make the preload request if it isn't already in the in-memory cache (if it is already in the
                    // cache it means the main application pre-emptively made the request before the first chunk arrived).
                    $this->cache[$originalCacheKey][$preloadCacheKey] = $this->cache[$originalCacheKey][$preloadCacheKey]
                        ?? $this->request(self::METHOD_GET, $resolved, $options);
                    $preloads[$href] = $this->cache[$originalCacheKey][$preloadCacheKey];
                }
                // Make the list of linked requests that were preloaded available to the primary request info.
                $context->setInfo('preloads', $preloads);
                $this->logger && $this->logger->info('HTTP Client request resulted in additional preload requests from Link header(s).', [
                    'primary_url' => $context->getInfo('url'),
                    'preload_count' => count($preloads),
                ]);
            }
            yield $chunk;
        };
        // If "fetch_preload" has not been specified in the options, just make a normal request. If "fetch_preload" has
        // been specified in the options, return an async response with our special passthru closure for preloading Link
        // headers once the headers have arrived (on first chunk of the body).
        $response = empty($options['extra']['fetch_preload'])
            ? $this->client->request($method, $url, $options)
            : new AsyncResponse($this->client, $method, $url, $options, $passthru);
        // If the original response has been passed in it means that *this* request is a sub-request, as specified in a
        // Link header of the original. But we only cache GET requests (because that's the method in which to preload
        // resources).
        if ($originalResponse instanceof ResponseInterface && strtoupper($method) === self::METHOD_GET) {
            // In case the main application pre-emptively makes a preload request before the first chunk of the original
            // request arrives, save it in the in-memory cache so that it's not re-requested during the passthru closure.
            $originalCacheKey = $this->generateCacheKey($originalResponse->getInfo('http_method'), $originalResponse->getInfo('url'));
            $preloadCacheKey = $this->generateCacheKey($method, $url);
            $this->cache[$originalCacheKey][$preloadCacheKey] = $this->cache[$originalCacheKey][$preloadCacheKey]
                ?? $response;
        }

        // TODO: Somehow find a way to hook into the destructor of the original (this) request and make it
        //       delete the $this->cache[$originalCacheKey] entry automatically.
        // Wait, can that even be done? How would the destructor be called if there's still a reference to it in the
        // cache? I don't think it can, will have to make a clear cache method below.

        return $response;
    }

    public function clearPreloadedCache(ResponseInterface ...$responses): void
    {
        foreach ($responses as $response) {
            $cacheKey = $this->generateCacheKey($response->getInfo('http_method'), $response->getInfo('url'));
            unset($this->cache[$cacheKey]);
        }
    }

    protected function generateCacheKey(string $method, string $url): string
    {
        return hash('sha256', strtoupper($method) . $url);
    }

    /** @return array<string,string> */
    protected function determinePreloadHrefsFromLinkHeaders(array $headerValues, array $primaryRequestUrlParts = []): iterable
    {
        $linkParser = new LinkParser;
        $links = array_reduce($headerValues, function (array $links, string $headerValue) use ($linkParser): array {
            return array_merge($links, $linkParser->parse($headerValue));
        }, []);
        $links = array_filter($links, fn (Link $link): bool => in_array('preload', $link->getRels(), true));
        $hrefs = array_map(fn (Link $link): string => $link->getHref(), $links);
        foreach (array_unique($hrefs) as $href) {
            yield $href => implode('', self::resolveUrl(self::parseUrl($href), $primaryRequestUrlParts));
        }
    }

    public function stream($responses, float $timeout = null): ResponseStreamInterface
    {
        return $this->client->stream($responses, $timeout);
    }
}
