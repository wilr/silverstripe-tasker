# silverstripe-tasker

A collection of SilverStripe `BuildTask` classes, helpers and traits for
managing SilverStripe installations, formatting and running dev tasks.

Work in progress while I adapt and grow the code to handle various project
specific implementations of these tasks and helpers.

## Installation

```
composer require wilr/silverstripe-tasker
```

## Usage

```php
<?php

use Wilr\SilverStripe\Tasker\Traits\TaskHelpers;
use Wilr\SilverStripe\Tasker\Traits\TaskerFormatter;
use SilverStripe\Dev\BuildTask;

class MyAppUpgradeTask extends BuildTask
{
    use TaskerFormatter;
    use TaskHelpers;

    public function run()
    {
        $this->echoHeading('My Heading');

        if ($wrong) {
            $this->echoWarning('Shows a warning message');
        } else {
            $this->echoSuccess('Outputs a tick');
        }

        while (true) {
            // displays progress dots
            $this->echoProgress();
        }
    }
}
```

### API Documentation

#### Tasks

 * `migrateTableToExistingTable ($tableName, $newClass, $mapping = [], $callback = null, $insert = true)`
 * `archivePage($pageID)`
 * `runInsertOrUpdate($table, $id, $fields)`
 * `migrateDataColumnTo($columnFrom, $tableFrom, $columnTo, $tableTo)`
 * `setInvalidEnumValuesTo ($table, $column, $defaultValue)`
 * `correctPageClass ($id, $newClassName)`
 * `renameColumn ($table, $oldColumn, $newColumn, $force = false)`
 * `removePagesOnLiveNotOnDraft()`

#### Helpers

 * `hasTable ($tableName)`
 * `query ($query)`
 * `tableHasCol ($table, $col)`
 * `echoWarning ($message)`
 * `echoSuccess ($message)`
 * `echoMessage ($message)`
 * `echoLine ()`
 * `echoProgress ()`

### SilverStripe Platform / CWP Deployment Tasks

`tasker` can be be used to 'hook' updates and other classes when `dev/build` is
run. To do this, create a `migration.yml` file in your project and include the
following:

```
SilverStripe\CMS\Model\SiteTree:
  migration_on_build: true
  latest_schema_version: 2
  migration_class: 'MyAppUpgradeTask'
```

Schema version is stored on SiteConfig, if a project has a Schema version less
than the value provided here, `tasker` will run the provided `migration_class`.

If you have a series of BuildTask jobs to execute (such as a `Solr_Reindex`)
then provide the class names under the key `tasker_jobs`.

```
SilverStripe\CMS\Model\SiteTree:
  tasker_jobs:
    - 'SilverStripe\FullTextSearch\Solr\Tasks\Solr_Configure'
    - 'SilverStripe\FullTextSearch\Solr\Tasks\Solr_Reindex'
```
