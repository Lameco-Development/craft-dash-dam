<?php

namespace lameco\dash\queue\jobs;

use Craft;
use craft\queue\BaseJob;
use lameco\dash\Plugin;

/**
 * Runs a reconcile from the queue, so the control panel's sync button returns immediately.
 *
 * A reconcile over a real library takes minutes, which is well past what a web request
 * should hold. It is also already safe to run concurrently with the cron: reconcileAndAdvance()
 * takes a mutex, and a run that cannot get it exits without touching anything.
 */
class SyncJob extends BaseJob
{
    /** Reconcile even when the probe reports nothing changed — what the button implies. */
    public bool $force = false;

    public function execute($queue): void
    {
        $sync = Plugin::getInstance()->getDashSync();
        $probe = $sync->probe();

        if (!$probe['changed'] && !$this->force) {
            return;
        }

        $this->setProgress($queue, 0.1, Craft::t('dash-dam', 'Reconciling with Dash'));

        $counts = $sync->reconcileAndAdvance($probe['now']);

        if ($counts === null) {
            // The cron holds the lock. Its run covers the same window, so there is nothing
            // to retry and nothing lost.
            Craft::info('Dash sync skipped: another run holds the lock.', __METHOD__);

            return;
        }

        $this->setProgress($queue, 1);
        Craft::info('Dash sync finished: ' . json_encode($counts), __METHOD__);
    }

    protected function defaultDescription(): ?string
    {
        return Craft::t('dash-dam', 'Syncing assets from Dash');
    }
}
