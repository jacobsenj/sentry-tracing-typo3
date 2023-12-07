<?php

declare(strict_types=1);

namespace Neusta\SentryTracing\Middlewares;

use Networkteam\SentryClient\Client;
use Networkteam\SentryClient\Service\SentryService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Sentry\ClientBuilder;
use Sentry\SentrySdk;

use function Sentry\startTransaction;

use Sentry\Tracing\Span;
use Sentry\Tracing\SpanContext;

use Sentry\Tracing\Transaction;

use Sentry\Tracing\TransactionContext;

use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;

final readonly class InitializerMiddleware implements MiddlewareInterface
{
    public function __construct(private ExtensionConfiguration $extensionConfiguration)
    {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if (SentryService::isEnabled() && (bool)$this->extensionConfiguration->get('sentry_tracing', 'enableTracing')) {
            $hub = SentrySdk::getCurrentHub();
            $options = $hub->getClient()?->getOptions();
            // modify options set by sentry client
            $options->setTracesSampleRate((float)$this->extensionConfiguration->get('sentry_tracing', 'tracesSampleRate'));
            $options->setEnableTracing(true);
            $client = (new ClientBuilder($options))->getClient();
            $hub->bindClient($client);

            $transactionContext = new TransactionContext();
            $transactionContext->setName($request->getMethod() . ' ' . (string)$request->getUri());
            $transactionContext->setOp('typo3.request');
            $transactionContext->setData([
                'request.method' => $request->getMethod(),
                'request.query' => $request->getQueryParams(),
                'request.body' => $request->getParsedBody(),
                'request.headers' => $request->getHeaders(),
                'request.cookies' => $request->getCookieParams(),
                'request.url' => (string)$request->getUri(),
            ]);

            $transaction = startTransaction($transactionContext);
            $mainSpan = $this->initTrace($transaction, $request);
            $request = $request
                ->withAttribute('sentry', [
                    'baggage' => $mainSpan->toBaggage(),
                    'trace' => $mainSpan->toTraceparent(),
                ]);
            $response = $handler->handle($request);
            $this->endTrace($mainSpan, $transaction);
            return $response;
        }

        return $handler->handle($request);
    }

    /**
     * @param Transaction $transaction
     * @param ServerRequestInterface $request
     * @return Span
     */
    private function initTrace(Transaction $transaction, ServerRequestInterface $request): Span
    {
        SentrySdk::getCurrentHub()->setSpan($transaction);
        $spanContext = new SpanContext();
        $spanContext->setOp('typo3.full_request');
        $spanContext->setDescription((string)$request->getUri());

        return $transaction->startChild($spanContext);
    }

    private function endTrace(Span $span, Transaction $transaction): void
    {
        $span->finish();
        SentrySdk::getCurrentHub()->setSpan($transaction);
        $transaction->finish();
    }
}
