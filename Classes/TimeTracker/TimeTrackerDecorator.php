<?php

declare(strict_types=1);

namespace Neusta\SentryTracing\TimeTracker;

use Sentry\SentrySdk;
use Sentry\Tracing\Transaction;
use TYPO3\CMS\Core\TimeTracker\TimeTracker;

final class TimeTrackerDecorator extends TimeTracker
{
    private array $spanStack = [];

    /**
     * Pushes an element to the TypoScript tracking array
     *
     * @param string $tslabel Label string for the entry, eg. TypoScript property name
     * @param string $value Additional value(?)
     * @see \TYPO3\CMS\Frontend\ContentObject\ContentObjectRenderer::cObjGetSingle()
     * @see pull()
     */
    public function push($tslabel, $value = '')
    {
        parent::push($tslabel, $value);
        $parent = SentrySdk::getCurrentHub()->getSpan();

        if ($parent !== null) {
            $this->spanStack[] = $parent;
            $context = new \Sentry\Tracing\SpanContext();
            $context->setOp($tslabel);
            $context->setDescription($value);
            $span = $parent->startChild($context);

            SentrySdk::getCurrentHub()->setSpan($span);
        }
    }

    /**
     * Pulls an element from the TypoScript tracking array
     *
     * @param string $content The content string generated within the push/pull part.
     * @see \TYPO3\CMS\Frontend\ContentObject\ContentObjectRenderer::cObjGetSingle()
     * @see push()
     */
    public function pull($content = '')
    {
        parent::pull($content);

        $span = SentrySdk::getCurrentHub()->getSpan();
        if ($span !== null) {
            if (!($span instanceof Transaction)) {
                $span->setData(['content' => $content]);
                $span->finish();
            }
            $parent = array_pop($this->spanStack);
            SentrySdk::getCurrentHub()->setSpan($parent);
        }
    }
}
