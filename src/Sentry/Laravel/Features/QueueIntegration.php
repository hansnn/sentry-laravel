<?php

namespace Sentry\Laravel\Features;

use Closure;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Queue\Events\JobExceptionOccurred;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Events\JobQueued;
use Illuminate\Queue\Events\JobQueueing;
use Illuminate\Queue\Events\WorkerStopping;
use Illuminate\Queue\Queue;
use Sentry\Breadcrumb;
use Sentry\Laravel\Features\Concerns\TracksPushedScopesAndSpans;
use Sentry\Laravel\Integration;
use Sentry\SentrySdk;
use Sentry\State\Scope;
use Sentry\Tracing\PropagationContext;
use Sentry\Tracing\SpanContext;
use Sentry\Tracing\SpanStatus;
use Sentry\Tracing\TransactionContext;
use Sentry\Tracing\TransactionSource;

use function Sentry\continueTrace;
use function Sentry\getBaggage;
use function Sentry\getTraceparent;

class QueueIntegration extends Feature
{
    use TracksPushedScopesAndSpans {
        pushScope as private pushScopeTrait;
    }

    private const QUEUE_SPAN_OP_QUEUE_PUBLISH = 'queue.publish';

    private const QUEUE_PAYLOAD_BAGGAGE_DATA = 'sentry_baggage_data';
    private const QUEUE_PAYLOAD_TRACE_PARENT_DATA = 'sentry_trace_parent_data';

    public function isApplicable(): bool
    {
        if (!$this->container()->bound('queue')) {
            return false;
        }

        return $this->isBreadcrumbFeatureEnabled('queue_info')
            || $this->isTracingFeatureEnabled('queue_jobs')
            || $this->isTracingFeatureEnabled('queue_job_transactions');
    }

    public function onBoot(Dispatcher $events): void
    {
        $events->listen(JobQueueing::class, [$this, 'handleJobQueueingEvent']);
        $events->listen(JobQueued::class, [$this, 'handleJobQueuedEvent']);

        $events->listen(JobProcessed::class, [$this, 'handleJobProcessedQueueEvent']);
        $events->listen(JobProcessing::class, [$this, 'handleJobProcessingQueueEvent']);
        $events->listen(WorkerStopping::class, [$this, 'handleWorkerStoppingQueueEvent']);
        $events->listen(JobExceptionOccurred::class, [$this, 'handleJobExceptionOccurredQueueEvent']);

        if ($this->isTracingFeatureEnabled('queue_jobs') || $this->isTracingFeatureEnabled('queue_job_transactions')) {
            Queue::createPayloadUsing(function (?string $connection, ?string $queue, ?array $payload): ?array {
                $parentSpan = SentrySdk::getCurrentHub()->getSpan();

                if ($parentSpan !== null && $parentSpan->getSampled()) {
                    $context = (new SpanContext)
                        ->setOp(self::QUEUE_SPAN_OP_QUEUE_PUBLISH)
                        ->setData([
                            'messaging.system' => 'laravel',
                            'messaging.destination.name' => $queue,
                            'messaging.destination.connection' => $connection,
                        ])
                        ->setDescription($queue);

                    $this->pushSpan($parentSpan->startChild($context));
                }

                if ($payload !== null) {
                    $payload[self::QUEUE_PAYLOAD_BAGGAGE_DATA] = getBaggage();
                    $payload[self::QUEUE_PAYLOAD_TRACE_PARENT_DATA] = getTraceparent();
                }

                return $payload;
            });
        }
    }

    public function handleJobQueueingEvent(JobQueueing $event): void
    {
        $currentSpan = SentrySdk::getCurrentHub()->getSpan();

        // If there is no tracing span active there is no need to handle the event
        if ($currentSpan === null || $currentSpan->getOp() !== self::QUEUE_SPAN_OP_QUEUE_PUBLISH) {
            return;
        }

        $jobName = $event->job;

        if ($jobName instanceof Closure) {
            $jobName = 'Closure';
        } elseif (is_object($jobName)) {
            $jobName = get_class($jobName);
        }

        $currentSpan
            ->setData([
                'messaging.laravel.job' => $jobName,
            ])
            ->setDescription($jobName);
    }

    public function handleJobQueuedEvent(JobQueued $event): void
    {
        $span = $this->maybePopSpan();

        if ($span !== null) {
            $span->finish();
        }
    }

    public function handleJobProcessedQueueEvent(JobProcessed $event): void
    {
        $this->finishJobWithStatus(SpanStatus::ok());

        $this->maybePopScope();
    }

    public function handleJobProcessingQueueEvent(JobProcessing $event): void
    {
        $this->maybePopScope();

        $this->pushScope();

        if ($this->isBreadcrumbFeatureEnabled('queue_info')) {
            $job = [
                'job' => $event->job->getName(),
                'queue' => $event->job->getQueue(),
                'attempts' => $event->job->attempts(),
                'connection' => $event->connectionName,
            ];

            // Resolve name exists only from Laravel 5.3+
            if (method_exists($event->job, 'resolveName')) {
                $job['resolved'] = $event->job->resolveName();
            }

            Integration::addBreadcrumb(new Breadcrumb(
                Breadcrumb::LEVEL_INFO,
                Breadcrumb::TYPE_DEFAULT,
                'queue.job',
                'Processing queue job',
                $job
            ));
        }

        $parentSpan = SentrySdk::getCurrentHub()->getSpan();

        // If there is no tracing span active and we don't trace jobs as transactions there is no need to handle the event
        if ($parentSpan === null && !$this->isTracingFeatureEnabled('queue_job_transactions')) {
            return;
        }

        // If there is a parent span we can record that job as a child unless configured to not do so
        if ($parentSpan !== null && !$this->isTracingFeatureEnabled('queue_jobs')) {
            return;
        }

        if ($parentSpan === null) {
            $baggage = $event->job->payload()[self::QUEUE_PAYLOAD_BAGGAGE_DATA] ?? null;
            $traceParent = $event->job->payload()[self::QUEUE_PAYLOAD_TRACE_PARENT_DATA] ?? null;

            $context = continueTrace($traceParent ?? '', $baggage ?? '');

            // If the parent transaction was not sampled we also stop the queue job from being recorded
            if ($context->getParentSampled() === false) {
                return;
            }
        } else {
            $context = new SpanContext;
        }

        $resolvedJobName = $event->job->resolveName();

        $job = [
            'job' => $event->job->getName(),
            'queue' => $event->job->getQueue(),
            'resolved' => $resolvedJobName,
            'attempts' => $event->job->attempts(),
            'connection' => $event->connectionName,
        ];

        if ($context instanceof TransactionContext) {
            $context->setName($resolvedJobName);
            $context->setSource(TransactionSource::task());
        }

        $context->setOp('queue.process');
        $context->setData($job);
        $context->setStartTimestamp(microtime(true));

        // When the parent span is null we start a new transaction otherwise we start a child of the current span
        if ($parentSpan === null) {
            $span = SentrySdk::getCurrentHub()->startTransaction($context);
        } else {
            $span = $parentSpan->startChild($context);
        }

        $this->pushSpan($span);
    }

    public function handleWorkerStoppingQueueEvent(WorkerStopping $event): void
    {
        Integration::flushEvents();
    }

    public function handleJobExceptionOccurredQueueEvent(JobExceptionOccurred $event): void
    {
        $this->finishJobWithStatus(SpanStatus::internalError());

        Integration::flushEvents();
    }

    private function finishJobWithStatus(SpanStatus $status): void
    {
        $span = $this->maybePopSpan();

        if ($span !== null) {
            $span->setStatus($status);
            $span->finish();
        }
    }

    protected function pushScope(): void
    {
        $this->pushScopeTrait();

        // When a job starts, we want to make sure the scope is cleared of breadcrumbs
        // as well as setting a new propagation context.
        SentrySdk::getCurrentHub()->configureScope(static function (Scope $scope) {
            $scope->clearBreadcrumbs();
            $scope->setPropagationContext(PropagationContext::fromDefaults());
        });
    }
}
