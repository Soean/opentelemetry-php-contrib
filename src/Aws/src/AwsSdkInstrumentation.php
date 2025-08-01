<?php

declare(strict_types=1);

namespace OpenTelemetry\Contrib\Aws;

use Aws\CommandInterface;
use Aws\Middleware;
use Aws\ResultInterface;
use Closure;
use GuzzleHttp\Promise;
use OpenTelemetry\API\Instrumentation\InstrumentationInterface;
use OpenTelemetry\API\Instrumentation\InstrumentationTrait;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\API\Trace\TracerInterface;
use OpenTelemetry\API\Trace\TracerProviderInterface;
use OpenTelemetry\Context\Propagation\TextMapPropagatorInterface;
use Psr\Http\Message\RequestInterface;
use Stringable;
use Throwable;

/**
 * @experimental
 */
class AwsSdkInstrumentation implements InstrumentationInterface
{
    use InstrumentationTrait;

    public const NAME = 'AWS SDK Instrumentation';
    public const VERSION = '0.0.1';
    public const SPAN_KIND = SpanKind::KIND_CLIENT;

    private array $clients = [];

    private array $instrumentedClients = [];

    private array $spanStorage = [];

    public function getName(): string
    {
        return self::NAME;
    }

    public function getVersion(): ?string
    {
        return self::VERSION;
    }

    public function getSchemaUrl(): ?string
    {
        return null;
    }

    public function init(): bool
    {
        return true;
    }

    public function setPropagator(TextMapPropagatorInterface $propagator): void
    {
        $this->propagator = $propagator;
    }

    public function getPropagator(): TextMapPropagatorInterface
    {
        return $this->propagator;
    }

    public function setTracerProvider(TracerProviderInterface $tracerProvider): void
    {
        $this->tracerProvider = $tracerProvider;
    }

    public function getTracerProvider(): TracerProviderInterface
    {
        return $this->tracerProvider;
    }

    public function getTracer(): TracerInterface
    {
        return $this->tracerProvider->getTracer('io.opentelemetry.contrib.php');
    }

    /** @psalm-api */
    public function instrumentClients($clientsArray): void
    {
        $this->clients = $clientsArray;
    }

    public function activate(): bool
    {
        try {
            foreach ($this->clients as $client) {
                $hash = spl_object_hash($client);
                if (isset($this->instrumentedClients[$hash])) {
                    continue;
                }

                $clientName = $client->getApi()->getServiceName();
                $region = $client->getRegion();

                $client->getHandlerList()->prependInit(Middleware::tap(function ($cmd, $_req) use ($clientName, $region, $hash) {
                    $tracer = $this->getTracer();
                    $propagator = $this->getPropagator();

                    $carrier = [];
                    /** @phan-suppress-next-line PhanTypeMismatchArgument */
                    $span = $tracer->spanBuilder($clientName)->setSpanKind(AwsSdkInstrumentation::SPAN_KIND)->startSpan();
                    $scope = $span->activate();
                    $this->spanStorage[$hash] = [$span, $scope];

                    $propagator->inject($carrier);

                    /** @psalm-suppress PossiblyInvalidArgument */
                    $span->setAttributes([
                        'rpc.method' => $cmd->getName(),
                        'rpc.service' => $clientName,
                        'rpc.system' => 'aws-api',
                        'aws.region' => $region,
                    ]);
                }), 'instrumentation');

                $client->getHandlerList()->appendSign(function (callable $handler) use ($hash) {
                    return $this->endSpanMiddleware($handler, $hash);
                }, 'end_instrumentation');

                $this->instrumentedClients[$hash] = 1;
            }
        } catch (Throwable $e) {
            return false;
        }

        return true;
    }

    private function endSpanMiddleware(callable $handler, string $hash): Closure
    {
        $onFulfilled = function (ResultInterface $result) use ($hash) {
            if (empty($this->spanStorage[$hash])) {
                return $result;
            }
            [$span, $scope] = $this->spanStorage[$hash];
            unset($this->spanStorage[$hash]);

            /*
             * Some AWS SDK Functions, such as S3Client->getObjectUrl() do not actually perform on the wire comms
             * with AWS Servers, and therefore do not return with a populated AWS\Result object with valid @metadata
             * Check for the presence of @metadata before extracting status code as these calls are still
             * instrumented.
             */
            if (isset($result['@metadata'])) {
                $span->setAttributes([
                    'http.status_code' => $result['@metadata']['statusCode'], // @phan-suppress-current-line PhanTypeMismatchDimFetch
                ]);
            }

            $span->end();
            $scope->detach();

            return $result;
        };

        $onRejected =  function ($reason) use ($hash) {
            if (empty($this->spanStorage[$hash])) {
                return Promise\Create::rejectionFor($reason);
            }
            [$span, $scope] = $this->spanStorage[$hash];
            unset($this->spanStorage[$hash]);

            $span->setStatus(StatusCode::STATUS_ERROR, $this->normalizeReason($reason));

            if ($reason instanceof Throwable) {
                $span->recordException($reason);
            }

            $span->end();
            $scope->detach();

            return Promise\Create::rejectionFor($reason);
        };

        return function (
            CommandInterface $command,
            ?RequestInterface $request = null,
        ) use ($handler, $onFulfilled, $onRejected) {
            return $handler($command, $request)->then(
                $onFulfilled,
                $onRejected,
            );
        };
    }

    private function normalizeReason(mixed $reason): ?string
    {
        if ($reason instanceof Throwable) {
            return $reason->getMessage();
        }

        if (is_object($reason) && ! $reason instanceof Stringable) {
            return null;
        }

        return (string) $reason;
    }
}
