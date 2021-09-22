<?php

namespace Wilr\SilverStripe\Tasker\Extensions;

use Exception;
use SilverStripe\ORM\DB;
use SilverStripe\ORM\DataExtension;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Control\Controller;
use SilverStripe\CMS\Model\SiteTree;
use SilverStripe\Core\Config\Config;
use SilverStripe\Control\Director;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\Core\Environment;
use SilverStripe\Dev\Tasks\MigrateFileTask;
use Symbiote\QueuedJobs\Jobs\RunBuildTaskJob;
use Symbiote\QueuedJobs\Services\QueuedJobService;
use Wilr\SilverStripe\Tasker\Traits\TaskerFormatter;

class TaskerSiteTreeExtension extends DataExtension
{
    use TaskerFormatter;

    private $run = false;

    public function isSilverStripeHosted()
    {
        return (Environment::getEnv('CWP_ENVIRONMENT') ||
            Environment::getEnv('PLATFORM_ENVIRONMENT'));
    }

    public function requireDefaultRecords()
    {
        Environment::increaseMemoryLimitTo(-1);
        Environment::increaseTimeLimitTo(-1);

        // script only needs to run once per dev/build.
        if ($this->run) {
            return;
        }

        $this->run = true;

        $state = Config::inst()->get(SiteTree::class, 'migration_on_build');

        $runJobsOnDeploy = Config::inst()->get(SiteTree::class, 'tasker_jobs');

        // run any queued job tasks
        if ($runJobsOnDeploy) {
            foreach ($runJobsOnDeploy as $job) {
                $inst = Injector::inst()->create($job);

                if (!$inst->isEnabled()) {
                    return;
                }

                $job = new RunBuildTaskJob($job);
                $jobID = Injector::inst()->get(QueuedJobService::class)->queueJob($job);

                DB::alteration_message(sprintf(
                    '[Tasker] Migration queuing task %s # %s',
                    $inst->getTitle(),
                    $jobID
                ));
            }
        }

        if ($state === false) {
            DB::alteration_message('[Tasker] Migration on build is disabled by config flag.', 'deleted');

            return;
        } else {
            DB::alteration_message(sprintf(
                '[Tasker] CWP (%s), Environment (%s)',
                ($this->isSilverStripeHosted()) ? 'Yes' : 'No',
                Director::get_environment_type()
            ), 'created');
        }

        $always = Config::inst()->get(SiteTree::class, 'run_update_schema_always');

        if ($always || $this->isSilverStripeHosted() || !Director::isDev() || (Controller::has_curr() && Controller::curr()->getRequest()->getVar('doCheckSchema'))) {
            // check to see if the upgrade script has run. If it has run then
            // ignore the upgrader, if it hasn't updated to the latest version
            // then trigger the update.
            //
            // Does this on dev/build since Dash will rollback the deploy if
            // accessing the homepage errors out and it usually will if the
            // schema isn't up to date.
            $currentSchema = DB::query("SELECT MAX(SchemaVersion) FROM SiteConfig")->value();
            $latestSchema = Config::inst()->get(SiteTree::class, 'latest_schema_version');

            if (!$currentSchema || $currentSchema < $latestSchema || $always) {
                // clear caches
                SiteTree::reset();

                // run upgrade
                if ($className = Config::inst()->get(SiteTree::class, 'migration_class')) {
                    DB::alteration_message('[Tasker] Upgrading project to schema ' . $latestSchema . '....', 'created');

                    $upgrader = Injector::inst()->create($className);

                    try {
                        // update schema
                        DB::query(sprintf(
                            'UPDATE SiteConfig SET SchemaVersion = %s',
                            $latestSchema
                        ));

                        $upgrader->run(new HTTPRequest('GET', '/', [
                            'quiet' => true
                        ]));

                        DB::alteration_message('[Tasker] Upgraded project to schema ' . $latestSchema, 'created');
                    } catch (Exception $e) {
                        DB::alteration_message('[Tasker] Error upgrading project to schema: ' . $e->getMessage(), 'error');
                    }
                } else {
                    DB::alteration_message('[Tasker] Could not upgrade project to schema ' . $latestSchema
                        . ' from ' . $currentSchema . '. No migration_class provided', 'deleted');
                }
            } else {
                DB::alteration_message('[Tasker] Project already running schema ' . $latestSchema, 'created');
            }

            // trigger the image update
            if (Config::inst()->get(SiteTree::class, 'tasker_should_migrate_files')) {
                DB::alteration_message('[Tasker] Syncing files', 'created');

                try {
                    $task = new MigrateFileTask();

                    $task->run(new HTTPRequest('GET', '/', [
                        'quiet' => true
                    ]));
                } catch (Exception $e) {
                    DB::alteration_message('[Tasker] error syncing files ' . $e->getMessage(), 'created');
                }
            }
        } else {
            DB::alteration_message('[Tasker] Migration on environment is disabled.', 'deleted');
        }
    }
}
