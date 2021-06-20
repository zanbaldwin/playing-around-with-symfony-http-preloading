# Symfony HTTP Client: Preloading

> **WARNING**
> This was an experiment.
> There are no tests.
> Do not take this seriously.
> Do not use this in production.

Assuming the following HTTP requests:

```http request
GET /book/1 HTTP/1.1
Host: localhost:8000
Content-Type: application/json
Content-Length: 74
Link: </author/1>; rel="preload", </book/1/price>; rel="preload"

{"title":"The Wind in the Willows","author":1,"tags":["paperback","kids"]}
```

```http request
GET /book/1/price HTTP/1.1
Host: localhost:8000
Content-Type: application/json
Content-Length: 34

{"value":"14.99","currency":"EUR"}
```

```http request
GET /author/1 HTTP/1.1
Host: localhost:8000
Content-Type: application/json
Content-Length: 50

{"name":"Kenneth Grahame","born":1859,"died":1932}
```

Example usage of Preloading HTTP Client in a controller.

```php
use ZanBaldwin\HttpPreload\Client as PreloadHttpClient;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class MyController
{
    public function __construct(HttpCLientInterface $client)
    {
        $this->http = new PreloadHttpClient($client);

        // If using the CachingHttpClient, decorate it with the PreloadHttpClient
        // not the other way around. PreloadHttpClient caches ResponseInterface
        // references, not the content itself.
        # $this->http = new PreloadHttpClient(new CachingHttpClient(
        #     $client,
        #     new Store(sys_get_temp_dir())
        # ));
    }

    public function exampleAction(): Response
    {
        $bookRequest = $this->http->request(
            Request::METHOD_GET,
            'http://localhost:8000/book/1',
            // Specify you want Link header suggestions to be preloaded.
            ['extra' => ['fetch_preload' => true]]
        );

        // As soon as the first chunk of the body arrives, the headers are available
        // and PreloadHttpClient will parse all Link headers for preload requests.
        // Then make a background request for each preload URL which is then put in
        // an in-memory cache.

        // Blocking call, will wait for the whole request to complete (meaning preload
        // headers have already been parsed).
        $bookContent = $bookRequest->toArray();

        // Request to "http://localhost:8000/author/1" already made in background,
        // RequestInterface object returned from in-memory cache.
        $authorRequest = $this->http->request(
            Request::METHOD_GET,
            sprintf('http://localhost:8000/author/%d', $bookContent['author']),
            [],
            // In order to use the cache, specify the original request that this preload
            // came from. This is to ensure that preload requests aren't "global",
            // they belong to a specific original request.
            $bookRequest
        );
        // Blocking call, author request was made ahead of time but not guaranteed
        // to be complete by this point.
        $authorContent = $authorRequest->toArray();

        return new JsonResponse([
            'book_title' => $bookContent['title'],
            'author_name' => $authorContent['name'],
        ]);
    }

    public function alternativeAction(): Response
    {
        $bookResponse = $this->http->request(
            Request::METHOD_GET,
            'http://localhost:8000/book/1',
            // Specify in the options you want Link header suggestions to be preloaded.
            ['extra' => ['fetch_preload' => true]]
        );
        // We know every book has a price endpoint, so we can request it immediately
        // instead of waiting for the content to know which URL to request.
        $priceResponse = $this->http->request(
            Request::METHOD_GET,
            'http://localhost:8000/book/1/price',
            [],
            // In order to use the cache, specify the original request that this
            // preload came from. This is to ensure that preload requests aren't
            // "global", they belong to a specific original request.
            $bookResponse
        );

        // The first chunk of the book request body arrives, meaning the headers are
        // parsed for preload links. It sees that "http://localhost:8000/book/1/price"
        // has already been made as a sub-request of books, so uses that from the
        // in-memory cache instead of making a new request.

        // Once you're done making requests (bringing them into scope), remove references
        // from the cache so the destructors behave as expected ($bookResponse and
        // $priceResponse will still be usable in the scope of this method).
        $this->http->clearPreloadedCache($bookResponse, $priceResponse);

        return new JsonResponse([
            'book_title' => $bookResponse->toArray()['title'],
            'book_price' => $priceResponse->toArray()['value'],
        ]);
    }
}
```
