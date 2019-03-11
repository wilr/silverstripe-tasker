<?php

namespace Wilr\SilverStripe\Tasker\Extensions;

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

class TaskerSiteTreeExtension extends DataExtension
{
    private $run = false;

    public function isSilverStripeHosted()
    {
        return (
            Environment::getEnv('CWP_ENVIRONMENT') ||
            Environment::getEnv('PLATFORM_ENVIRONMENT')
        );
    }

    public function requireDefaultRecords()
    {
        // script only needs to run once per dev/build.
        if ($this->run) {
            return;
        }

        $this->run = true;

        $state = Config::inst()->get(SiteTree::class, 'migration_on_build');

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

        if ($always || $this->isSilverStripeHosted() || !Director::isDev() || Controller::curr()->getRequest()->getVar('doCheckSchema')) {
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
                    DB::alteration_message('[Tasker] Upgrading project to schema '. $latestSchema .'....', 'created');

                    $upgrader = Injector::inst()->create($className);

                    try {
                        $upgrader->run(new HTTPRequest('GET', '/', [
                            'quiet' => true
                        ]));

                        // update schema
                        DB::query(sprintf(
                            'UPDATE SiteConfig SET SchemaVersion = %s',
                            $latestSchema
                        ));

                        DB::alteration_message('[Tasker] Upgraded project to schema '. $latestSchema, 'created');
                    } catch (Exception $e) {
                        DB::alteration_message('[Tasker] Error upgrading project to schema: '. $e->getMessage(), 'error');
                    }
                } else {
                    DB::alteration_message('[Tasker] Could not upgrade project to schema '. $latestSchema
                    .' from '. $currentSchema. '. No migration_class provided', 'deleted');
                }
            } else {
                DB::alteration_message('[Tasker] Project already running schema '. $latestSchema, 'created');
            }

            // trigger the image update
            if (Config::inst()->get(SiteTree::class, 'tasker_should_migrate_files')) {
                DB::alteration_message('[Tasker] Syncing files', 'created');

                // correct any PDF files to the
                DB::query(sprintf(
                    "
                    UPDATE File SET ClassName = '%s'
                    WHERE Filename LIKE '%s'",
                    File::class,
                    '%.pdf'
                ));

                try {
                    $task = new MigrateFileTask();

                    $task->run(new HTTPRequest('GET', '/', [
                        'quiet' => true
                    ]));
                } catch (Exception $e) {
                    DB::alteration_message('[Tasker] error syncing files '. $e->getMessage(), 'created');
                }
            }
        } else {
            DB::alteration_message('[Tasker] Migration on environment is disabled.', 'deleted');
        }
    }
}
