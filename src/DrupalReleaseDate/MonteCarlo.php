<?php
namespace DrupalReleaseDate;

use \DrupalReleaseDate\Sampling\RandomSampleSelectorInterface;
use \DrupalReleaseDate\MonteCarlo\IncreasingException;
use \DrupalReleaseDate\MonteCarlo\TimeoutException;

class MonteCarlo
{

    /**
     * The default number of iterations to perform when running an estimate.
     * @var number
     */
    const DEFAULT_ITERATIONS = 100000;

    /**
     * The default size for grouping distribution samples into buckets (One day)
     * in seconds.
     * @var number
     */
    const DEFAULT_BUCKET_SIZE = 86400;

    const DEFAULT_TIME_LIMIT = 3600;

    protected $sampleSelector;

    /**
     * The initial proportion of iterations that will be run without checking
     * the failure ratio afterwards.
     *
     * e.g. A minimum of 10% of requested iterations will always be processed
     *      before a large number of failures can cause the run to abort.
     *
     * @var float
     *   A value between 0 and 1
     */
    public $increasingFailureThresholdRatio = 0.1;

    /**
     * The maximum proportion of iterations that can fail before the entire run
     * returns as a failure.
     *
     * e.g. If 50% of iterations have failed, the run will be aborted.
     *
     * @var float
     *   A value between 0 and 1
     */
    public $increasingFailureRatio = 0.5;

    public function __construct(RandomSampleSelectorInterface $sampleSelector)
    {
        $this->sampleSelector = $sampleSelector;
    }

    /**
     * Get an estimated value from a single iteration.
     *
     * @param int $abortTime
     *   The Unix timestamp at which to abort running the iteration.
     * @return number
     */
    public function iteration($abortTime = null)
    {

        // Get the current number of issues from the last sample in the set.
        $issues = $highestIssues = $this->sampleSelector->getLastSample()->getCount();

        $duration = 0;

        do {
            $sample = $this->sampleSelector->getRandomSample();
            $duration += $sample->getDuration();
            $issues -= $sample->getResolved();


            $highestIssues = max($highestIssues, $sample->getCount(), $sample->getResolved());

            // Failsafe for if simulation goes in the wrong direction too far.
            if ($issues > $highestIssues * 3) {
                throw new IncreasingException("Iteration failed due to increasing issue count");
            }
            if ($abortTime && time() >= $abortTime) {
                throw new TimeoutException();
            }

        } while ($issues > 0);

        return $duration;
    }

    /**
     * Get the distribution of estimates from the specified number of
     * iterations, grouped into buckets of the specified size.
     *
     * @param int $iterations
     * @param int $bucketSize
     *   The period in seconds to group estimates by.
     * @param int $timeLimit
     *   The number of seconds to allow the estimation to run for.
     * @return EstimateDistribution
     */
    public function runDistribution($iterations = self::DEFAULT_ITERATIONS, $bucketSize = self::DEFAULT_BUCKET_SIZE, $timeLimit = self::DEFAULT_TIME_LIMIT)
    {
        $estimates = new EstimateDistribution();

        $abortTime = time() + $timeLimit;

        for ($run = 1; $run <= $iterations; $run++) {
            try {
                $estimate = $this->iteration($abortTime);

                $bucket = $estimate - $estimate % $bucketSize;

                $estimates->success($bucket);

            } catch (IncreasingException $e) {
                $estimates->failure();

                if (
                    $run > ($iterations * $this->increasingFailureThresholdRatio)
                    && ($estimates->getFailureCount() / $run) > $this->increasingFailureRatio
                ) {
                    $runException = new IncreasingException('Run aborted after iteration ' . $run, 0, $e);
                    $runException->setDistribution($estimates);
                    throw $runException;
                }
            } catch (TimeoutException $e) {
                $estimates->failure();

                $runException = new TimeoutException('Run aborted during iteration ' . $run, 0, $e);
                $runException->setDistribution($estimates);
                throw $runException;
            }
        }

        return $estimates;
    }

    /**
     * Get the average value of the specified number of iterations.
     *
     * @param int $iterations
     * @param int $bucketSize
     *   The period in seconds to group estimates by.
     * @param int $timeLimit
     *   The number of seconds to allow the estimation to run for.
     * @return number
     */
    public function runAverage($iterations = self::DEFAULT_ITERATIONS, $bucketSize = self::DEFAULT_BUCKET_SIZE, $timeLimit = self::DEFAULT_TIME_LIMIT)
    {
        return $this->runDistribution($iterations, $bucketSize, $timeLimit)->getAverage();
    }

    /**
     * Get the median estimate value from the specified number of iterations.
     *
     * @param int $iterations
     * @param int $bucketSize
     *   The period in seconds to group estimates by.
     * @param int $timeLimit
     *   The number of seconds to allow the estimation to run for.
     * @return number
     */
    public function runMedian($iterations = self::DEFAULT_ITERATIONS, $bucketSize = self::DEFAULT_BUCKET_SIZE, $timeLimit = self::DEFAULT_TIME_LIMIT)
    {
        return $this->runDistribution($iterations, $bucketSize, $timeLimit)->getMedian();
    }
}
